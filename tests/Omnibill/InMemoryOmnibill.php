<?php

namespace Tests\Base\Ledger\Omnibill;

use Base\Ledger\Bank\Receipts;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\Party;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Omnibill\Entity\AccountingExport;
use Base\Ledger\Omnibill\Entity\AccountingExportItem;
use Base\Ledger\Omnibill\Entity\InvoiceInbox;
use Base\Ledger\Omnibill\Entity\SupplierInvoice;
use Base\Ledger\Omnibill\Enum\ExportState;
use Base\Ledger\Omnibill\Export\AccountingExporter;
use Base\Ledger\Omnibill\Invoice\InvoiceProposer;
use Base\Ledger\Omnibill\Invoice\LifecycleMessageInterface;
use Base\Ledger\Omnibill\Invoice\SupplierInvoices;
use Base\Ledger\Omnibill\Reading\InvoiceReader;
use Base\Ledger\Omnibill\Repository\AccountingExportItemRepository;
use Base\Ledger\Omnibill\Repository\AccountingExportRepository;
use Base\Ledger\Omnibill\Repository\InvoiceInboxRepository;
use Base\Ledger\Omnibill\Repository\SupplierInvoiceRepository;
use Base\Ledger\Repository\BookRepository;
use Base\Ledger\Repository\EntryLineRepository;
use Base\Ledger\Repository\PartyRepository;
use Base\Ledger\Service\Parties;
use Doctrine\ORM\EntityManagerInterface;
use Omnibill\Registry;
use Tests\Base\Ledger\InMemoryLedger;

/**
 * The ledger in memory (InMemoryLedger) with the omnibill side's records
 * kept in arrays too: the suppliers' invoices, the inboxes, the export
 * runs and their items. The gateways are omnibill's own (afnor, pennylane)
 * on a MockHttpClient that records each request: nothing leaves.
 */
abstract class InMemoryOmnibill extends InMemoryLedger
{
    /** @var list<SupplierInvoice> */
    protected array $supplierInvoices = [];
    /** @var list<InvoiceInbox> */
    protected array $inboxes = [];
    /** @var list<AccountingExport> */
    protected array $runs = [];
    /** @var list<AccountingExportItem> */
    protected array $items = [];
    protected string $receiptsDirectory;
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        if (!class_exists(Registry::class)) {
            self::markTestSkipped('omnisense/omnibill is not on the autoloader (OMNIBILL_PATH, ~/Sites/omnibill or /srv/omnibill).');
        }
        parent::setUp();
        $this->receiptsDirectory = sys_get_temp_dir().'/ledger-omnibill-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->receiptsDirectory) && is_dir($this->receiptsDirectory)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->receiptsDirectory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->receiptsDirectory);
        }
    }

    protected function entityManager(): EntityManagerInterface
    {
        if (null !== $this->em) {
            return $this->em;
        }
        $ledger = parent::entityManager();
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use ($ledger): void {
            match (true) {
                $entity instanceof SupplierInvoice => \in_array($entity, $this->supplierInvoices, true) || $this->supplierInvoices[] = $this->persisted($entity),
                $entity instanceof InvoiceInbox => \in_array($entity, $this->inboxes, true) || $this->inboxes[] = $this->persisted($entity),
                $entity instanceof AccountingExport => \in_array($entity, $this->runs, true) || $this->runs[] = $this->persisted($entity),
                $entity instanceof AccountingExportItem => \in_array($entity, $this->items, true) || $this->items[] = $this->persisted($entity),
                default => $ledger->persist($entity),
            };
        });
        $em->method('flush')->willReturnCallback(fn () => $ledger->flush());
        $em->method('find')->willReturnCallback(fn (string $class, mixed $id) => null);

        return $this->em = $em;
    }

    protected function partyRepository(): PartyRepository
    {
        $repository = parent::partyRepository();
        $repository->method('ofKind')->willReturnCallback(fn (Book $book, PartyKind $kind) => array_values(array_filter($this->parties, fn (Party $p) => $p->getKind() === $kind)));

        return $repository;
    }

    protected function lineRepository(): EntryLineRepository
    {
        $repository = parent::lineRepository();
        $repository->method('accountsWith')->willReturnCallback(function (Book $book, Party $party, array $prefixes = ['6', '2']) {
            $counts = [];
            foreach ($this->entries as $entry) {
                if ([] === array_filter($entry->getLines()->toArray(), fn ($l) => $l->getParty() === $party) || null !== $entry->getReversalOf()) {
                    continue;
                }
                foreach ($entry->getLines() as $line) {
                    foreach ($prefixes as $prefix) {
                        if ($line->getAccount()->isUnder($prefix)) {
                            $counts[$line->getAccount()->getNumber()] = ($counts[$line->getAccount()->getNumber()] ?? 0) + 1;
                        }
                    }
                }
            }
            arsort($counts);

            return $counts;
        });

        return $repository;
    }

    protected function supplierInvoiceRepository(): SupplierInvoiceRepository
    {
        $repository = $this->createStub(SupplierInvoiceRepository::class);
        $find = fn (callable $where) => array_values(array_filter($this->supplierInvoices, $where))[0] ?? null;
        $repository->method('findOriginal')->willReturnCallback(fn (Book $book, string $dedupe) => $find(fn (SupplierInvoice $i) => $i->getBook() === $book && $i->getDedupe() === $dedupe));
        $repository->method('findOneBySha')->willReturnCallback(fn (Book $book, string $sha) => $find(fn (SupplierInvoice $i) => $i->getBook() === $book && $i->getSha256() === $sha));
        $repository->method('findOneByFlow')->willReturnCallback(fn (string $gateway, string $reference) => $find(fn (SupplierInvoice $i) => $i->getGateway() === $gateway && $i->getFlowReference() === $reference));
        $repository->method('findOneByEntry')->willReturnCallback(fn (Entry $entry) => $find(fn (SupplierInvoice $i) => $i->getEntry() === $entry));
        $repository->method('fromGateways')->willReturnCallback(fn () => array_values(array_filter($this->supplierInvoices, fn (SupplierInvoice $i) => null !== $i->getGateway())));

        return $repository;
    }

    protected function inboxRepository(): InvoiceInboxRepository
    {
        $repository = $this->createStub(InvoiceInboxRepository::class);
        $repository->method('findOneByGateway')->willReturnCallback(fn (string $gateway) => array_values(array_filter($this->inboxes, fn (InvoiceInbox $i) => $i->getGateway() === $gateway))[0] ?? null);

        return $repository;
    }

    protected function bookRepository(): BookRepository
    {
        $repository = $this->createStub(BookRepository::class);
        $repository->method('resolve')->willReturnCallback(fn (string $handle) => preg_replace('/\D/', '', $handle) === $this->book->getSiren() ? $this->book : null);
        $repository->method('findOneBy')->willReturnCallback(fn () => $this->book);

        return $repository;
    }

    protected function receipts(): Receipts
    {
        return new Receipts($this->entityManager(), 'Comptabilité/Justificatifs', 'staff', $this->receiptsDirectory);
    }

    protected function proposer(?string $defaultAccount = null): InvoiceProposer
    {
        return new InvoiceProposer($this->partyRepository(), $this->lineRepository(), $this->taxRepository(), $defaultAccount);
    }

    protected function supplierInvoices(?Registry $registry = null, ?string $gateway = null, ?LifecycleMessageInterface $messages = null): SupplierInvoices
    {
        return new SupplierInvoices(
            $this->entityManager(), $this->supplierInvoiceRepository(), $this->inboxRepository(), $this->bookRepository(), $this->proposer(), new InvoiceReader(),
            $this->posting(), new Parties($this->entityManager(), $this->partyRepository(), $this->accountRepository()), $this->receipts(), $registry, $gateway, null, $messages,
        );
    }

    protected function exporter(Registry $registry, ?string $gateway = null): AccountingExporter
    {
        $runs = $this->createStub(AccountingExportRepository::class);
        $runs->method('unfinished')->willReturnCallback(fn (Book $book, string $gw, \DateTimeImmutable $from, \DateTimeImmutable $to) => array_values(array_filter(
            array_reverse($this->runs),
            fn (AccountingExport $r) => $r->getGateway() === $gw && $r->getFromDate() == $from->setTime(0, 0) && $r->getToDate() == $to->setTime(0, 0) && ExportState::DONE !== $r->getState(),
        ))[0] ?? null);
        $items = $this->createStub(AccountingExportItemRepository::class);
        $items->method('findOneByKey')->willReturnCallback(fn (Book $book, string $gw, string $kind, string $key) => array_values(array_filter(
            $this->items,
            fn (AccountingExportItem $i) => $i->getGateway() === $gw && $i->getKind() === $kind && $i->getRecordKey() === $key,
        ))[0] ?? null);

        return new AccountingExporter($this->entityManager(), $this->entryRepository(), $runs, $items, registry: $registry, gateway: $gateway);
    }
}
