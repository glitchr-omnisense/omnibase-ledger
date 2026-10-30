<?php

namespace Base\Ledger\Bank;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\Book;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankCounterpartyMonthRepository;
use Base\Ledger\Repository\BankDayCategoryRepository;
use Base\Ledger\Repository\BankDayRepository;
use Base\Ledger\Repository\BankLineRepository;
use Base\Ledger\Repository\BankRecurringRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes a bank account's precomputed rows (Aggregator) again from its
 * lines: after each sync (Sync) and statement upload (StatementUpload), and
 * for every account with `ledger:bank:aggregate`. The dashboard's widgets
 * read these rows, never every line.
 */
class Aggregates
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BankLineRepository $lines,
        private readonly BankAccountRepository $bankAccounts,
        private readonly BankDayRepository $days,
        private readonly BankDayCategoryRepository $categories,
        private readonly BankCounterpartyMonthRepository $counterparties,
        private readonly BankRecurringRepository $recurring,
    ) {
    }

    /** @return array{days: int, categories: int, counterparties: int, recurring: int} how many rows written */
    public function rebuild(BankAccount $account, ?\DateTimeImmutable $today = null): array
    {
        $rows = Aggregator::compute($account, $this->lines->movements($account), $today);

        $this->days->deleteFor($account);
        $this->categories->deleteFor($account);
        $this->counterparties->deleteFor($account);
        $this->recurring->deleteFor($account);
        foreach ($rows as $list) {
            foreach ($list as $row) {
                $this->entityManager->persist($row);
            }
        }
        $this->entityManager->flush();

        return array_map('count', $rows);
    }

    /** @return int how many accounts */
    public function rebuildAll(?Book $book = null, ?\DateTimeImmutable $today = null): int
    {
        $accounts = $this->bankAccounts->forBook($book);
        foreach ($accounts as $account) {
            $this->rebuild($account, $today);
        }

        return \count($accounts);
    }
}
