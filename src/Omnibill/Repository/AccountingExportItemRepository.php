<?php

namespace Base\Ledger\Omnibill\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Omnibill\Entity\AccountingExport;
use Base\Ledger\Omnibill\Entity\AccountingExportItem;
use Base\Ledger\Omnibill\Enum\ExportState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AccountingExportItem> */
class AccountingExportItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountingExportItem::class);
    }

    public function findOneByKey(Book $book, string $gateway, string $kind, string $key): ?AccountingExportItem
    {
        return $this->findOneBy(['book' => $book, 'gateway' => $gateway, 'kind' => $kind, 'recordKey' => mb_substr($key, 0, 191)]);
    }

    /**
     * What waits for a person on this gateway: refused, failed, sent with
     * its outcome unknown, changed.
     *
     * @return list<AccountingExportItem>
     */
    public function attention(Book $book, ?string $gateway = null, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.book = :book')->setParameter('book', $book)
            ->andWhere('i.state IN (:states)')->setParameter('states', [ExportState::REFUSED->value, ExportState::FAILED->value, ExportState::UNKNOWN->value, ExportState::CHANGED->value])
            ->orderBy('i.updatedAt', 'DESC')->setMaxResults($limit);
        if (null !== $gateway) {
            $qb->andWhere('i.gateway = :gateway')->setParameter('gateway', $gateway);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<AccountingExportItem> what a run touched */
    public function forRun(AccountingExport $run): array
    {
        return $this->findBy(['run' => $run], ['kind' => 'ASC', 'id' => 'ASC']);
    }
}
