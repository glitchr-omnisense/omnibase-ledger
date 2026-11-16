<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankRecurring;
use Base\Database\Attribute\Backend\Backends;
use Base\Database\Backend\BulkDeletes;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<BankRecurring> */
class BankRecurringRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, BankRecurring::class);
    }

    /** Removes a bank account's rows: Bank\Aggregates writes them again. */
    public function deleteFor(BankAccount $account): void
    {
        Backends::of($this->unitOfWork(), BulkDeletes::class)->deleteWhere(BankRecurring::class, ['bankAccount' => $account]);
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

        return $this->findBy(['bankAccount' => Key::of(array_values($accounts))], ['nextOn' => 'ASC']);
    }
}
