<?php

namespace Base\Ledger\Omnibill\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Omnibill\Entity\AccountingExport;
use Base\Ledger\Omnibill\Enum\ExportState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AccountingExport> */
class AccountingExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountingExport::class);
    }

    /** The run of this period that did not finish (stopped, or cut short): the one to resume. */
    public function unfinished(Book $book, string $gateway, \DateTimeImmutable $from, \DateTimeImmutable $to): ?AccountingExport
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.book = :book')->setParameter('book', $book)
            ->andWhere('r.gateway = :gateway')->setParameter('gateway', $gateway)
            ->andWhere('r.fromDate = :from')->setParameter('from', $from->setTime(0, 0), 'date_immutable')
            ->andWhere('r.toDate = :to')->setParameter('to', $to->setTime(0, 0), 'date_immutable')
            ->andWhere('r.state <> :done')->setParameter('done', ExportState::DONE->value)
            ->orderBy('r.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<AccountingExport> the book's runs, the latest first */
    public function recent(Book $book, int $limit = 30): array
    {
        return $this->findBy(['book' => $book], ['id' => 'DESC'], $limit);
    }
}
