<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankDay;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Attribute\Backend\Backends;
use Base\Database\Backend\BulkDeletes;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<BankDay> */
class BankDayRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, BankDay::class);
    }

    /** Removes a bank account's rows: Bank\Aggregates writes them again. */
    public function deleteFor(BankAccount $account): void
    {
        Backends::of($this->unitOfWork(), BulkDeletes::class)->deleteWhere(BankDay::class, ['bankAccount' => $account]);
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

        return $this->findBy([
            'bankAccount' => Key::of(array_values($accounts)),
            Group::of(Parser::SEPARATOR_AND,
                Criterion::of('day', $from->setTime(0, 0), Parser::OPTION_GREATER_EQUAL),
                Criterion::of('day', $to->setTime(0, 0), Parser::OPTION_LOWER_EQUAL),
            ),
        ], ['day' => 'ASC']);
    }

    /** The last row of $account before $day (its balance then), or null when it has none. */
    public function lastBefore(BankAccount $account, \DateTimeImmutable $day): ?BankDay
    {
        return $this->findOneBy([
            'bankAccount' => Key::of($account),
            Group::of(Parser::SEPARATOR_AND, Criterion::of('day', $day->setTime(0, 0), Parser::OPTION_LOWER)),
        ], ['day' => 'DESC']);
    }

    /** The last row of $account (its latest balance), or null when it has none. */
    public function latest(BankAccount $account): ?BankDay
    {
        return $this->findOneBy(['bankAccount' => $account], ['day' => 'DESC']);
    }
}
