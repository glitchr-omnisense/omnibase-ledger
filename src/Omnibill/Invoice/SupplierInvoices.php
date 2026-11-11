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
use Omnibill\Exception\InvalidConfigException;
use Omnibill\Exception\InvalidKeyException;
use Omnibill\Exception\NotSupportedException;
use Omnibill\Exception\OmnibillException;
use Omnibill\Exception\OutcomeUnknownException;
use Omnibill\Exception\ProviderException;
use Omnibill\Exception\UnreachableException;
use Omnibill\Exception\UsageLimitReachedException;
use Omnibill\FlowLookupInterface;
use Omnibill\Model\Direction;
use Omnibill\Model\Flow;
use Omnibill\Model\FlowKind;
use Omnibill\Model\LifecycleStatus;
use Omnibill\Model\StatusChange;
use Omnibill\Registry;
use Omnibill\Request\FindFlow;
use Omnibill\Request\Receive;
use Omnibill\Request\SetStatus;
use Omnibill\Writer\LifecycleMessageWriterInterface;
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
 * booked, refused with its reason, paid with its amount and its amount at
 * each VAT rate (StatusChange::$amounts). An approved platform carries a
 * status as a lifecycle message (CDAR) the application writes: omnibill's
 * Writer\LifecycleMessageWriterInterface, registered as a service, gives
 * the StatusChange its file; neither omnibill nor the ledger writes one. A
 * status the gateway could not take stays in the invoice, unsent, and
 * sendPending() tries it again. One whose answer was lost after it left
 * (omnibill's OutcomeUnknownException) may be on the platform already: it
 * is never sent again blindly. Since omnibill 1.3 each status goes with a
 * key of its own (SetStatus::$trackingId, trackingId()), and the next pass
 * asks the platform for it (findFlow(), where the gateway supports FindFlow)
 * before anything is sent again: found, it is sent; absent once the search
 * had time to show it (SEARCH_DELAY), it is sent again, once. A gateway
 * that cannot look it up (chorus-pro; email records its statuses itself), a
 * search whose answer is none to rely on, or a status sent before the key
 * existed, leave it to a person (awaitsConfirmation(), confirmStatus()):
 * « Reçu par la plateforme », or « Absent : renvoyer ». An invoice dropped
 * by a person has no gateway and its statuses are the book's alone.
 */
class SupplierInvoices
{
    /** How long a status's message just deposited may take to show in the platform's search: before, "none" proves nothing. */
    public const SEARCH_DELAY = 'PT10M';

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
        private readonly ?LifecycleMessageWriterInterface $messages = null,
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
     * Only the invoices are asked for (Receive's kinds): the statuses about
     * the application's own invoices stay for whoever sent them
     * (omnibase/marketplace), read with a cursor of their own.
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
                    $box = $channel->execute(new Receive($inbox->getCursor(), 25, [FlowKind::INVOICE]))->getInbox()
                        ?? throw new LedgerException(sprintf('The "%s" gateway answered no inbox.', $gateway));
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
        $amount = null === $invoice->getTotalIncl() ? null : self::decimal($invoice->getTotalIncl());
        $amounts = [];
        foreach ($invoice->getVat() as $rate) {
            $amounts[$rate['rate']] = ($amounts[$rate['rate']] ?? 0) + $rate['base'] + $rate['vat'];
        }
        $this->declare($invoice, LifecycleStatus::PAID, null, $amount, array_map(self::decimal(...), $amounts));
    }

    /**
     * A status whose outcome was unknown and that the platform cannot settle
     * (awaitsConfirmation()), settled by a person who checked on the
     * platform: there ($taken), it is marked sent; not there, it is sent
     * again now. Whether it went; false too for a status the next pass looks
     * up by its key, a person's answer not needed.
     */
    public function confirmStatus(SupplierInvoice $invoice, int $index, bool $taken): bool
    {
        if (!$this->awaitsConfirmation($invoice, $index)) {
            return false;
        }
        if ($taken) {
            $invoice->statusSent($index, null);
            $this->entityManager->flush();

            return true;
        }
        $invoice->statusRetried($index);
        $sent = $this->send($invoice, $index);
        $this->entityManager->flush();

        return $sent;
    }

    /**
     * Whether a status whose outcome is unknown waits for a person: sent
     * without a key (before omnibill 1.3), on a gateway that cannot find a
     * flow again (no FindFlow), or looked up and answered nothing to rely
     * on. Otherwise the next pass looks it up (sendPending()).
     */
    public function awaitsConfirmation(SupplierInvoice $invoice, int $index): bool
    {
        $status = $invoice->getStatuses()[$index] ?? null;
        if (null === $status || $status['sent'] || !($status['unknown'] ?? false)) {
            return false;
        }

        return ($status['inconclusive'] ?? false) || null === ($status['tracking'] ?? null) || null === $this->lookup($invoice);
    }

    /** @return list<int> the indexes of the invoice's statuses waiting for a person (awaitsConfirmation()) */
    public function awaitingConfirmation(SupplierInvoice $invoice): array
    {
        return array_values(array_filter(array_keys($invoice->getStatuses()), fn (int $index) => $this->awaitsConfirmation($invoice, $index)));
    }

    /**
     * Sends again the statuses a gateway could not take: how many went.
     * First, before anything is sent, a status whose outcome is unknown is
     * asked of the platform by its key (findAgain()): there, it is sent;
     * absent, it goes with the others. One the platform cannot settle waits
     * for a person (confirmStatus()).
     */
    public function sendPending(): int
    {
        $sent = 0;
        foreach ($this->invoices->fromGateways() as $invoice) {
            foreach ($invoice->unsentStatuses() as $index => $status) {
                if ($status['unknown'] ?? false) {
                    $this->findAgain($invoice, $index);
                }
            }
            foreach ($invoice->unsentStatuses() as $index => $status) {
                if ($status['unknown'] ?? false) {
                    continue;
                }
                $sent += (int) $this->send($invoice, $index);
            }
        }
        $this->entityManager->flush();

        return $sent;
    }

    /** A sum in minor units as a decimal string, its sign dropped: « 120.00 ». */
    private static function decimal(int $minor): string
    {
        return number_format(abs($minor) / 100, 2, '.', '');
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

    /**
     * Records the status and sends it when the invoice came through a gateway.
     *
     * @param array<int, string> $amounts by VAT rate in basis points, for PAID
     */
    private function declare(SupplierInvoice $invoice, LifecycleStatus $status, ?string $reason = null, ?string $amount = null, array $amounts = []): void
    {
        if (null === $invoice->getGateway() || null === $invoice->getFlowReference() || $invoice->hasDeclared($status->value)) {
            return;
        }
        $index = $invoice->declare($status->value, $reason, $amount, $amounts);
        $this->send($invoice, $index);
        $this->entityManager->flush();
    }

    /**
     * The key a status's message goes with (SetStatus::$trackingId), unique
     * among the application's flows on the platform: « status:supplier-invoice-7:1 »,
     * the invoice's id qualified as the ledger's entries' source is, so that
     * another sender's statuses on the same account (omnibase/marketplace)
     * never answer for it.
     */
    public static function trackingId(SupplierInvoice $invoice, int $index): ?string
    {
        return null === $invoice->getId() ? null : sprintf('status:supplier-invoice-%d:%d', $invoice->getId(), $index);
    }

    /**
     * A status whose outcome is unknown, asked of the platform by its key:
     * found, it is sent (the flow's reference kept); absent once
     * SEARCH_DELAY has passed since its answer was lost, it is to be sent
     * again (no longer unknown); absent sooner, or the platform not reached,
     * it is asked again by the next pass. A search the platform refuses
     * leaves it to a person.
     */
    private function findAgain(SupplierInvoice $invoice, int $index): void
    {
        $status = $invoice->getStatuses()[$index] ?? null;
        if (null === $status || ($status['inconclusive'] ?? false) || null === ($tracking = $status['tracking'] ?? null) || null === ($gateway = $this->lookup($invoice))) {
            return;
        }
        try {
            $flow = $gateway->findFlow($tracking);
        } catch (UnreachableException|InvalidKeyException|UsageLimitReachedException) {
            // The platform could not be asked (both are ProviderExceptions, caught first): asked again by the next pass.
            return;
        } catch (ProviderException|InvalidConfigException|NotSupportedException $e) {
            // Asked, and no answer to rely on: a person checks.
            $invoice->statusInconclusive($index, $e->getMessage());

            return;
        } catch (OmnibillException) {
            return;
        }
        if (null !== $flow) {
            $invoice->statusSent($index, $flow->reference);

            return;
        }
        $lost = new \DateTimeImmutable($status['lost'] ?? $status['at']);
        if ($lost > (new \DateTimeImmutable())->sub(new \DateInterval(self::SEARCH_DELAY))) {
            return;
        }
        $invoice->statusRetried($index);
    }

    /** The invoice's gateway when it can find a status's message again by its key (FindFlow); null when it cannot. */
    private function lookup(SupplierInvoice $invoice): ?FlowLookupInterface
    {
        if (null === $this->registry || null === $invoice->getGateway()) {
            return null;
        }
        try {
            $gateway = $this->registry->get($invoice->getGateway());
        } catch (OmnibillException) {
            return null;
        }

        return $gateway instanceof FlowLookupInterface && $gateway->supports(FindFlow::class) ? $gateway : null;
    }

    /** Whether omnibill gives a status's message a key of its own (SetStatus::$trackingId, since 1.3): before, it goes without, and a person checks. */
    private static function tracks(): bool
    {
        return property_exists(SetStatus::class, 'trackingId');
    }

    /** Sends one declared status; whether it went. */
    private function send(SupplierInvoice $invoice, int $index): bool
    {
        $declared = $invoice->getStatuses()[$index] ?? null;
        if (null === $declared || $declared['sent'] || null === $this->registry) {
            return false;
        }
        $tracking = self::tracks() ? self::trackingId($invoice, $index) : null;
        try {
            $gateway = $this->registry->get((string) $invoice->getGateway());
            if (!$gateway->supports(SetStatus::class)) {
                $invoice->statusFailed($index, sprintf('The "%s" gateway declares no status.', $invoice->getGateway()));

                return false;
            }
            $flow = $this->flow($invoice);
            $change = new StatusChange(
                LifecycleStatus::from($declared['status']), $invoice->getNumber(), new \DateTimeImmutable($declared['at']), $declared['reason'] ?? null, $declared['amount'] ?? null,
                amounts: $declared['amounts'] ?? [],
            );
            // Through execute(): GatewayInterface::setStatus() keeps its two arguments, the key is the request's (omnibill 1.3).
            $request = new SetStatus($this->messages?->write($change, $flow) ?? $change, $flow, $tracking);
            $gateway->execute($request);
            $answer = $request->getFlow() ?? throw new ProviderException((string) $invoice->getGateway(), 'No flow answered.');
            $invoice->statusSent($index, $answer->reference);

            return true;
        } catch (OutcomeUnknownException $e) {
            $invoice->statusUnknown($index, $e->getMessage(), $tracking);

            return false;
        } catch (OmnibillException|\ValueError $e) {
            $invoice->statusFailed($index, $e->getMessage());

            return false;
        }
    }

    /** The invoice's flow, as the gateway gave it: what the status is about, and what the message writer finds the invoice by. */
    private function flow(SupplierInvoice $invoice): Flow
    {
        return new Flow((string) $invoice->getFlowReference(), FlowKind::INVOICE, Direction::IN, invoiceNumber: $invoice->getNumber(), name: $invoice->getFilename());
    }
}
