<?php

namespace Base\Ledger\Omnibill\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Omnibill\Entity\SupplierInvoice;
use Base\Ledger\Omnibill\Enum\SupplierInvoiceStatus;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<SupplierInvoice> */
class SupplierInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, SupplierInvoice::class);
    }

    /** The invoice that holds this "<supplier>|<number>" in the book: the first one received. */
    public function findOriginal(Book $book, string $dedupe): ?SupplierInvoice
    {
        return $this->findOneBy(['book' => $book, 'dedupe' => $dedupe]);
    }

    /** The same file, received before in the book. */
    public function findOneBySha(Book $book, string $sha256): ?SupplierInvoice
    {
        return $this->findOneBy(['book' => $book, 'sha256' => $sha256], ['id' => 'ASC']);
    }

    /** What a gateway's flow brought already. */
    public function findOneByFlow(string $gateway, string $reference): ?SupplierInvoice
    {
        return $this->findOneBy(['gateway' => $gateway, 'flowReference' => $reference]);
    }

    public function findOneByEntry(Entry $entry): ?SupplierInvoice
    {
        return $this->findOneBy(['entry' => $entry]);
    }

    /**
     * The book's invoices in these states, the latest received first.
     *
     * @param list<SupplierInvoiceStatus> $statuses none: all
     *
     * @return list<SupplierInvoice>
     */
    public function forBook(Book $book, array $statuses = [], int $limit = 200): array
    {
        $criteria = ['book' => Key::of($book)];
        if ([] !== $statuses) {
            $criteria['status'] = array_values($statuses);
        }

        return $this->findBy($criteria, ['receivedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** @return array<string, int> status => how many, for the book (the statuses it has invoices in; a count per status) */
    public function counts(Book $book): array
    {
        $counts = [];
        foreach (SupplierInvoiceStatus::cases() as $status) {
            if (0 < $n = $this->count(['book' => Key::of($book), 'status' => $status])) {
                $counts[$status->value] = $n;
            }
        }

        return $counts;
    }

    /**
     * The invoices that came through a gateway: those whose statuses may wait to be sent.
     *
     * @return list<SupplierInvoice>
     */
    public function fromGateways(int $limit = 500): array
    {
        return $this->findBy([Group::of(Parser::SEPARATOR_AND,
            Criterion::of('gateway', null, Parser::OPTION_NOT_NULL),
            Criterion::of('flowReference', null, Parser::OPTION_NOT_NULL),
        )], ['id' => 'DESC'], $limit);
    }
}
