<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankDay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BankDay> */
class BankDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankDay::class);
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
     * @return list<BankDay>
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

    /** The last row of $account before $day (its balance then), or null when it has none. */
    public function lastBefore(BankAccount $account, \DateTimeImmutable $day): ?BankDay
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.bankAccount = :account')->setParameter('account', $account)
            ->andWhere('r.day < :day')->setParameter('day', $day->setTime(0, 0))
            ->orderBy('r.day', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** The last row of $account (its latest balance), or null when it has none. */
    public function latest(BankAccount $account): ?BankDay
    {
        return $this->findOneBy(['bankAccount' => $account], ['day' => 'DESC']);
    }
}
