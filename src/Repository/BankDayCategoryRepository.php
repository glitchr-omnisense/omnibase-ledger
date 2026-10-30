<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankDayCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BankDayCategory> */
class BankDayCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankDayCategory::class);
    }

    /** Removes a bank account's rows: Bank\Aggregates writes them again. */
    public function deleteFor(BankAccount $account): void
    {
        $this->createQueryBuilder('r')->delete()->andWhere('r.bankAccount = :account')->setParameter('account', $account)->getQuery()->execute();
    }

    /**
     * The rows of $accounts from $from to $to included, oldest first.
     *
     * @param list<BankAccount> $accounts
     *
     * @return list<BankDayCategory>
     */
    public function forAccounts(array $accounts, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $accounts) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.bankAccount IN (:accounts)')->setParameter('accounts', $accounts)
            ->andWhere('r.day >= :from AND r.day <= :to')->setParameter('from', $from->setTime(0, 0))->setParameter('to', $to->setTime(0, 0))
            ->orderBy('r.day', 'ASC')
            ->getQuery()->getResult();
    }
}
