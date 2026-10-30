<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankRecurring;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BankRecurring> */
class BankRecurringRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankRecurring::class);
    }

    /** Removes a bank account's rows: Bank\Aggregates writes them again. */
    public function deleteFor(BankAccount $account): void
    {
        $this->createQueryBuilder('r')->delete()->andWhere('r.bankAccount = :account')->setParameter('account', $account)->getQuery()->execute();
    }

    /**
     * @param list<BankAccount> $accounts
     *
     * @return list<BankRecurring> by next date
     */
    public function forAccounts(array $accounts): array
    {
        if ([] === $accounts) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.bankAccount IN (:accounts)')->setParameter('accounts', $accounts)
            ->orderBy('r.nextOn', 'ASC')
            ->getQuery()->getResult();
    }
}
