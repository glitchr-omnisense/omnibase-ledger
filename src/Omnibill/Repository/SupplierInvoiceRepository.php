<?php

namespace Base\Ledger\Omnibill\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Omnibill\Entity\SupplierInvoice;
use Base\Ledger\Omnibill\Enum\SupplierInvoiceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SupplierInvoice> */
class SupplierInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplierInvoice::class);
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
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.book = :book')->setParameter('book', $book)
            ->orderBy('i.receivedAt', 'DESC')->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit);
        if ([] !== $statuses) {
            $qb->andWhere('i.status IN (:statuses)')->setParameter('statuses', array_map(static fn (SupplierInvoiceStatus $s) => $s->value, $statuses));
        }

        return $qb->getQuery()->getResult();
    }

    /** @return array<string, int> status => how many, for the book */
    public function counts(Book $book): array
    {
        $counts = [];
        foreach ($this->createQueryBuilder('i')->select('i.status AS status, COUNT(i.id) AS n')->andWhere('i.book = :book')->setParameter('book', $book)->groupBy('i.status')->getQuery()->getArrayResult() as $row) {
            $status = $row['status'] instanceof SupplierInvoiceStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['n'];
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
        return $this->createQueryBuilder('i')
            ->andWhere('i.gateway IS NOT NULL AND i.flowReference IS NOT NULL')
            ->orderBy('i.id', 'DESC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
