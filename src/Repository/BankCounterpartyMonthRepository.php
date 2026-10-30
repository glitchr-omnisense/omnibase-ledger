<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankCounterpartyMonth;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BankCounterpartyMonth> */
class BankCounterpartyMonthRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankCounterpartyMonth::class);
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
     * @return list<BankCounterpartyMonth>
     */
    public function forAccounts(array $accounts, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $accounts) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.bankAccount IN (:accounts)')->setParameter('accounts', $accounts)
            ->andWhere('r.month >= :from AND r.month <= :to')->setParameter('from', $from->modify('first day of this month')->setTime(0, 0))->setParameter('to', $to->setTime(0, 0))
            ->orderBy('r.month', 'ASC')
            ->getQuery()->getResult();
    }
}
