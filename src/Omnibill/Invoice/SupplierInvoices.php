<?php

namespace Base\Ledger\Omnibill\Invoice;

use Base\Ledger\Bank\Receipts;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\Party;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Omnibill\Entity\InvoiceInbox;
use Base\Ledger\Omnibill\Entity\SupplierInvoice;
use Base\Ledger\Omnibill\Enum\SupplierInvoiceStatus;
use Base\Ledger\Omnibill\Reading\InvoiceReader;
use Base\Ledger\Omnibill\Reading\InvoiceReadException;
use Base\Ledger\Omnibill\Repository\InvoiceInboxRepository;
use Base\Ledger\Omnibill\Repository\SupplierInvoiceRepository;
use Base\Ledger\Repository\BookRepository;
use Base\Ledger\Service\Parties;
use Base\Ledger\Service\Posting;
use Doctrine\ORM\EntityManagerInterface;
use Omnibill\Exception\OmnibillException;
use Omnibill\GatewayInterface;
use Omnibill\Model\Direction;
use Omnibill\Model\Flow;
use Omnibill\Model\FlowKind;
use Omnibill\Model\LifecycleStatus;
use Omnibill\Model\StatusChange;
use Omnibill\Registry;
use Omnibill\Request\Receive;
use Omnibill\Request\SetStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * Suppliers' invoices into the book. They arrive through the receiving
 * gateway of omnisense/omnibill (receive(): an approved platform's flows,
 * from the cursor kept in InvoiceInbox), or a person drops the file a
 * supplier mailed (import(): omnibill/email's case, a gateway that carries
 * no incoming flow). Each is read (InvoiceReader), checked against those
 * already received (the same supplier and number: a DUPLICATE, never
 * proposed), filed with the receipts (Bank\Receipts), and proposed
 * (InvoiceProposer). A person then books it - its entry posted and
 * validated - or refuses it with a reason; a bank line settling its entry
 * makes it paid (PaidInvoiceListener).
 *
 * Each step is declared to the supplier's platform as a lifecycle status
 * through the gateway it came by: taken over on arrival, approved once
 * booked, refused with its reason, paid with its amount. A status the
 * gateway could not take stays in the invoice, unsent, and sendPending()
 * tries it again; an invoice dropped by a person has no gateway and its
 * statuses are the book's alone.
 */
class SupplierInvoices
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SupplierInvoiceRepository $invoices,
        private readonly InvoiceInboxRepository $inboxes,
        private readonly BookRepository $books,
        private readonly InvoiceProposer $proposer,
        private readonly InvoiceReader $reader,
        private readonly Posting $posting,
        private readonly Parties $parties,
        private readonly Receipts $receipts,
        private readonly ?Registry $registry = null,
        #[Autowire('%ledger.invoices.gateway%')] private readonly ?string $gateway = null,
        private readonly ?LockFactory $locks = null,
        private readonly ?LifecycleMessageInterface $messages = null,
    ) {
    }

    /** The gateway invoices are received through: ledger.invoices.gateway, else the first one that receives; null when none does. */
    public function receivingGateway(): ?string
    {
        if (null !== $this->gateway) {
            return $this->gateway;
        }
        foreach ($this->registry?->names() ?? [] as $name) {
            try {
                if ($this->registry->get($name)->supports(Receive::class)) {
                    return $name;
                }
            } catch (OmnibillException) {
            }
        }

        return null;
    }

    /**
     * What arrived on the gateway since the last time, page after page (at
     * most $pages): its invoices taken in, the cursor kept after each page.
     * Statuses about the application's own invoices are left to whoever
     * sent them (omnibase/marketplace).
     *
     * @return array{gateway: ?string, received: int, duplicates: int, unreadable: int, skipped: int, error: ?string}
     */
    public function receive(?string $gateway = null, int $pages = 10): array
    {
        $gateway ??= $this->receivingGateway();
        $report = ['gateway' => $gateway, 'received' => 0, 'duplicates' => 0, 'unreadable' => 0, 'skipped' => 0, 'error' => null];
        if (null === $gateway || null === $this->registry) {
            $report['error'] = 'No gateway receives invoices: configure omnibill with an approved platform (omnibill/afnor), or drop the files by hand.';

            return $report;
        }

        $lock = $this->locks?->createLock('ledger-invoices-receive-'.$gateway);
        if (null !== $lock && !$lock->acquire()) {
            $report['error'] = 'Another process is receiving on this gateway.';

            return $report;
        }
        try {
            $inbox = $this->inboxes->findOneByGateway($gateway);
            if (null === $inbox) {
                $inbox = new InvoiceInbox($gateway);
                $this->entityManager->persist($inbox);
            }
            try {
                $channel = $this->registry->get($gateway);
                if (!$channel->supports(Receive::class)) {
                    throw new LedgerException(sprintf('The "%s" gateway receives no invoices.', $gateway));
                }
                for ($page = 0; $page < $pages; ++$page) {
                    $box = $channel->receive($inbox->getCursor(), 25);
                    foreach ($box->items as $received) {
                        if (null === $received->invoice) {
                            continue;
                        }
                        if (null !== $this->invoices->findOneByFlow($gateway, $received->flow->reference)) {
                            ++$report['skipped'];
                            continue;
                        }
                        $invoice = $this->import($received->invoice->content, $received->invoice->filename, null, $received->invoice->syntax->value, $gateway, $received->flow->reference);
                        match ($invoice->getStatus()) {
                            SupplierInvoiceStatus::DUPLICATE => ++$report['duplicates'],
                            SupplierInvoiceStatus::UNREADABLE => ++$report['unreadable'],
                            default => ++$report['received'],
                        };
                    }
                    $inbox->advance($box->cursor);
                    $this->entityManager->flush();
                    if (!$box->more) {
                        break;
                    }
                }
            } catch (OmnibillException|LedgerException $e) {
                $inbox->fail($e->getMessage());
                $this->entityManager->flush();
                $report['error'] = $e->getMessage();
            }
        } finally {
            $lock?->release();
        }

        return $report;
    }

    /**
     * Takes a supplier's invoice file in: read, checked against those
     * received, filed and proposed. The same file dropped again by a person
     * gives back the invoice already there; the same supplier and number
     * from another file (or another flow) is recorded as a DUPLICATE.
     *
     * @param Book|null   $book   the buyer's book on the invoice (its SIREN), else the first
     * @param string|null $syntax Factur-X, CII, UBL; told by the file when null
     */
    public function import(string $content, string $filename, ?Book $book = null, ?string $syntax = null, ?string $gateway = null, ?string $flowReference = null): SupplierInvoice
    {
        $syntax ??= InvoiceReader::guessSyntax($content);
        $read = null;
        $error = null;
        try {
            $read = $this->reader->read($content, $syntax);
        } catch (InvoiceReadException $e) {
            $error = $e->getMessage();
        }
        $book ??= $this->bookFor($read?->buyerSiren);
        $sha = hash('sha256', $content);

        $lock = $this->locks?->createLock('ledger-invoices-'.($book->getId() ?? spl_object_id($book)));
        $lock?->acquire(true);
        try {
            $same = $this->invoices->findOneBySha($book, $sha);
            if (null !== $same && null === $gateway) {
                return $same;
            }

            $invoice = new SupplierInvoice($book, $syntax, $filename, $sha, $gateway, $flowReference);
            if (null !== $read) {
                $invoice->describe($read->number, $read->issuedOn, $read->dueOn, $read->currency, $read->sellerName, $read->sellerSiren, $read->sellerSiret, $read->sellerVat, $read->sellerIban, $read->totalExcl, $read->totalVat, $read->totalIncl, $read->vat, $read->creditNote);
            } else {
                $invoice->markUnreadable((string) $error);
            }

            $key = $invoice->dedupeKey();
            $original = null !== $key ? $this->invoices->findOriginal($book, $key) : null;
            $original ??= $same;
            if (null !== $original) {
                $invoice->markDuplicateOf($original);
            } else {
                $invoice->markOriginal();
                if (null !== $read) {
                    $this->proposer->propose($invoice);
                }
            }

            $invoice->setFile($this->file($invoice, $content));
            $this->entityManager->persist($invoice);
            $this->entityManager->flush();
        } finally {
            $lock?->release();
        }

        if (SupplierInvoiceStatus::DUPLICATE !== $invoice->getStatus()) {
            $this->declare($invoice, LifecycleStatus::TAKEN_OVER);
        }

        return $invoice;
    }

    /**
     * Books the invoice: the supplier ($party, else the one proposed, else
     * created from the invoice), the entry posted on $account and validated
     * - a person's decision, never taken on arrival.
     *
     * @throws LedgerException the invoice is not waiting to be booked, or its entry is refused (PostingException)
     */
    public function book(SupplierInvoice $invoice, string $account, ?Party $party = null, ?string $by = null, ?\DateTimeImmutable $date = null): Entry
    {
        if (!$invoice->getStatus()->isOpen()) {
            throw new LedgerException(sprintf('Invoice %s is %s: only an invoice waiting for a person is booked.', $invoice, $invoice->getStatus()->value));
        }
        if (SupplierInvoiceStatus::UNREADABLE === $invoice->getStatus()) {
            throw new LedgerException('This invoice could not be read: refuse it, or post its entry by hand.');
        }
        $party ??= $invoice->getParty() ?? $this->parties->partyFor(
            $invoice->getBook(), PartyKind::SUPPLIER, $this->proposer->reference($invoice), $invoice->getSupplierName() ?? 'Fournisseur', $invoice->getSupplierIban(),
            InvoiceProposer::accountSuffix($invoice->getSupplierName() ?? ''),
        );
        $invoice->setParty($party)->setAccount($account);

        $draft = $this->proposer->draft($invoice, (string) $invoice->getAccount(), $party, 'supplier-invoice:'.($invoice->getId() ?? $invoice->getSha256()), $date);
        $entry = $this->posting->post($draft);
        $invoice->book($entry, $by);
        $this->entityManager->flush();
        $this->declare($invoice, LifecycleStatus::APPROVED);

        return $entry;
    }

    /** Refuses it, the reason sent to the supplier's platform. */
    public function refuse(SupplierInvoice $invoice, string $reason, ?string $by = null): void
    {
        if (!$invoice->getStatus()->isOpen()) {
            throw new LedgerException(sprintf('Invoice %s is %s: only an invoice waiting for a person is refused.', $invoice, $invoice->getStatus()->value));
        }
        if ('' === trim($reason)) {
            throw new LedgerException('A refusal says why.');
        }
        $invoice->refuse(trim($reason), $by);
        $this->entityManager->flush();
        $this->declare($invoice, LifecycleStatus::REFUSED, trim($reason));
    }

    /** Paid on $on (its entry's supplier line settled): declared with its amount. */
    public function paid(SupplierInvoice $invoice, \DateTimeImmutable $on): void
    {
        if (SupplierInvoiceStatus::BOOKED !== $invoice->getStatus()) {
            return;
        }
        $invoice->markPaid($on);
        $this->entityManager->flush();
        $amount = null === $invoice->getTotalIncl() ? null : number_format(abs($invoice->getTotalIncl()) / 100, 2, '.', '');
        $this->declare($invoice, LifecycleStatus::PAID, null, $amount);
    }

    /**
     * Sends again the statuses a gateway could not take: how many went.
     */
    public function sendPending(): int
    {
        $sent = 0;
        foreach ($this->invoices->fromGateways() as $invoice) {
            foreach ($invoice->unsentStatuses() as $index => $status) {
                $sent += (int) $this->send($invoice, $index);
            }
        }
        $this->entityManager->flush();

        return $sent;
    }

    /** @return resource|null the invoice's file */
    public function open(SupplierInvoice $invoice)
    {
        return $this->receipts->read($invoice->getFile());
    }

    /** The book the invoice is addressed to: the one whose SIREN is the buyer's, else the first. */
    private function bookFor(?string $buyerSiren): Book
    {
        $book = null !== $buyerSiren ? $this->books->resolve($buyerSiren) : null;

        return $book ?? $this->books->findOneBy([], ['id' => 'ASC']) ?? throw new LedgerException('No book to receive the invoice: create one first.');
    }

    /** Files the invoice with the receipts, under its date: « 2026-11-03 EDF FA-77.pdf ». */
    private function file(SupplierInvoice $invoice, string $content): ?string
    {
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            return null;
        }
        fwrite($stream, $content);
        rewind($stream);
        $label = trim((string) preg_replace('/[^\p{L}\p{N} .,&\'-]+/u', ' ', trim(($invoice->getSupplierName() ?? 'Fournisseur').' '.($invoice->getNumber() ?? ''))));
        $extension = strtolower(pathinfo($invoice->getFilename(), \PATHINFO_EXTENSION)) ?: ('Factur-X' === $invoice->getSyntax() ? 'pdf' : 'xml');
        $on = $invoice->getIssuedOn() ?? new \DateTimeImmutable('today');
        try {
            return $this->receipts->file($on, sprintf('%s %s.%s', $on->format('Y-m-d'), mb_substr($label, 0, 80), $extension), $stream);
        } catch (LedgerException) {
            return null;
        } finally {
            fclose($stream);
        }
    }

    /** Records the status and sends it when the invoice came through a gateway. */
    private function declare(SupplierInvoice $invoice, LifecycleStatus $status, ?string $reason = null, ?string $amount = null): void
    {
        if (null === $invoice->getGateway() || null === $invoice->getFlowReference() || $invoice->hasDeclared($status->value)) {
            return;
        }
        $index = $invoice->declare($status->value, $reason, $amount);
        $this->send($invoice, $index);
        $this->entityManager->flush();
    }

    /** Sends one declared status; whether it went. */
    private function send(SupplierInvoice $invoice, int $index): bool
    {
        $declared = $invoice->getStatuses()[$index] ?? null;
        if (null === $declared || $declared['sent'] || null === $this->registry) {
            return false;
        }
        try {
            $gateway = $this->registry->get((string) $invoice->getGateway());
            if (!$gateway->supports(SetStatus::class)) {
                $invoice->statusFailed($index, sprintf('The "%s" gateway declares no status.', $invoice->getGateway()));

                return false;
            }
            $status = LifecycleStatus::from($declared['status']);
            $message = $this->messages?->write($invoice, $status->value, $declared['reason'] ?? null, $declared['amount'] ?? null);
            $flow = $this->flow($gateway, $invoice);
            $answer = $gateway->setStatus(new StatusChange(
                $status, $invoice->getNumber(), new \DateTimeImmutable($declared['at']), $declared['reason'] ?? null, $declared['amount'] ?? null,
                file: $message['content'] ?? null, filename: $message['filename'] ?? null,
            ), $flow);
            $invoice->statusSent($index, $answer->reference);

            return true;
        } catch (OmnibillException|\ValueError $e) {
            $invoice->statusFailed($index, $e->getMessage());

            return false;
        }
    }

    private function flow(GatewayInterface $gateway, SupplierInvoice $invoice): Flow
    {
        return new Flow((string) $invoice->getFlowReference(), FlowKind::INVOICE, Direction::IN, invoiceNumber: $invoice->getNumber(), name: $invoice->getFilename());
    }
}
