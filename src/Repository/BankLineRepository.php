<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Enum\BankLineStatus;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<BankLine> */
class BankLineRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, BankLine::class);
    }

    public function findOneByExternalId(BankAccount $account, string $externalId): ?BankLine
    {
        return $this->findOneBy(['bankAccount' => $account, 'externalId' => $externalId]);
    }

    /** @return list<BankLine> the lines still to reconcile (unmatched or suggested), oldest first */
    public function pending(BankAccount $account): array
    {
        return $this->findBy(['bankAccount' => $account, 'status' => [BankLineStatus::UNMATCHED, BankLineStatus::SUGGESTED]], ['bookedOn' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<BankLine> a bank account's lines, newest first */
    public function latest(?BankAccount $account = null, int $limit = 50, ?BankLineStatus $status = null): array
    {
        $criteria = array_filter(['bankAccount' => $account, 'status' => $status], fn ($value) => null !== $value);

        return $this->findBy($criteria, ['bookedOn' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /**
     * A bank account's lines as plain values, oldest first, for Bank\Aggregates (read as entities
     * by the finder language, which selects no columns, then turned into values).
     *
     * @return list<array{bookedOn: \DateTimeImmutable, amount: int, category: ?string, counterpartyName: ?string, counterpartyIban: ?string, label: string}>
     */
    public function movements(BankAccount $account): array
    {
        return array_map(static fn (BankLine $line) => [
            'bookedOn' => $line->getBookedOn(),
            'amount' => (int) $line->getAmount(),
            'category' => $line->getCategory(),
            'counterpartyName' => $line->getCounterpartyName(),
            'counterpartyIban' => $line->getCounterpartyIban(),
            'label' => (string) $line->getLabel(),
        ], $this->findBy(['bankAccount' => Key::of($account)], ['bookedOn' => 'ASC', 'id' => 'ASC']));
    }

    /**
     * The largest movements of $accounts from $from to $to, by amount whatever its direction: the
     * largest credits and the largest debits read apart (the finder language orders by no
     * expression such as ABS()), merged by size, then the latest booked.
     *
     * @param list<BankAccount> $accounts
     *
     * @return list<BankLine>
     */
    public function largest(array $accounts, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 8): array
    {
        if ([] === $accounts || $limit < 1) {
            return [];
        }
        $criteria = ['bankAccount' => Key::of(array_values($accounts)), $this->booked($from->setTime(0, 0), $to->setTime(0, 0))];
        $credits = $this->findBy($criteria + [Group::of(Parser::SEPARATOR_AND, Criterion::of('amount', 0, Parser::OPTION_GREATER_EQUAL))], ['amount' => 'DESC', 'bookedOn' => 'DESC'], $limit);
        $debits = $this->findBy($criteria + [Group::of(Parser::SEPARATOR_AND, Criterion::of('amount', 0, Parser::OPTION_LOWER))], ['amount' => 'ASC', 'bookedOn' => 'DESC'], $limit);
        $lines = [...$credits, ...$debits];
        usort($lines, static fn (BankLine $a, BankLine $b) => [abs($b->getAmount()), $b->getBookedOn()] <=> [abs($a->getAmount()), $a->getBookedOn()]);

        return \array_slice($lines, 0, $limit);
    }

    /**
     * The lines of $accounts the front office lists, newest first: by state
     * ("todo": unmatched or suggested, "reconciled", "ignored", "missing":
     * a receipt said missing, null: all), within a month ("2026-10"), found
     * by words of the label, the counterparty or the reference, or by an
     * amount (« 89,16 », either sign).
     *
     * @param list<BankAccount> $accounts
     *
     * @return list<BankLine>
     */
    public function review(array $accounts, ?string $state = null, ?string $month = null, ?string $search = null, int $offset = 0, int $limit = 100): array
    {
        if ([] === $accounts) {
            return [];
        }

        return $this->findBy($this->reviewCriteria($accounts, $state, $month, $search), ['bookedOn' => 'DESC', 'id' => 'DESC'], $limit, $offset);
    }

    /**
     * How many lines of $accounts each state counts, in the month and the
     * search given: todo, reconciled, ignored, missing, all (a count per
     * status - the finder language has no GROUP BY).
     *
     * @param list<BankAccount> $accounts
     *
     * @return array{todo: int, reconciled: int, ignored: int, missing: int, all: int}
     */
    public function reviewCounts(array $accounts, ?string $month = null, ?string $search = null): array
    {
        $counts = ['todo' => 0, 'reconciled' => 0, 'ignored' => 0, 'missing' => 0, 'all' => 0];
        if ([] === $accounts) {
            return $counts;
        }
        $criteria = $this->reviewCriteria($accounts, null, $month, $search);
        foreach (BankLineStatus::cases() as $status) {
            $n = $this->count($criteria + ['status' => $status]);
            $counts[$status->isPending() ? 'todo' : $status->value] += $n;
            $counts['all'] += $n;
        }
        $counts['missing'] = $this->count($criteria + ['receiptMissing' => true]);

        return $counts;
    }

    /**
     * The first and the last day $accounts have lines on, for the months to choose from.
     *
     * @param list<BankAccount> $accounts
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    public function span(array $accounts): array
    {
        if ([] === $accounts) {
            return [null, null];
        }
        $criteria = ['bankAccount' => Key::of(array_values($accounts))];
        $date = static fn ($value) => null === $value ? null : ($value instanceof \DateTimeImmutable ? $value : new \DateTimeImmutable((string) $value));

        return [$date($this->minOfBookedOnBy($criteria)), $date($this->maxOfBookedOnBy($criteria))];
    }

    /**
     * The ids of $account's lines: what a sync added is the difference.
     *
     * @return list<int>
     */
    public function ids(BankAccount $account): array
    {
        return array_map(static fn (BankLine $line) => (int) $line->getId(), $this->findBy(['bankAccount' => Key::of($account)]));
    }

    /**
     * review()'s and reviewCounts()'s conditions.
     *
     * @param list<BankAccount> $accounts
     *
     * @return array<int|string, mixed>
     */
    private function reviewCriteria(array $accounts, ?string $state, ?string $month, ?string $search): array
    {
        $criteria = ['bankAccount' => Key::of(array_values($accounts))];
        match ($state) {
            'todo' => $criteria['status'] = [BankLineStatus::UNMATCHED, BankLineStatus::SUGGESTED],
            'reconciled' => $criteria['status'] = BankLineStatus::RECONCILED,
            'ignored' => $criteria['status'] = BankLineStatus::IGNORED,
            'missing' => $criteria['receiptMissing'] = true,
            default => null,
        };
        if (null !== $month && preg_match('/^(\d{4})-(\d{2})$/', $month)) {
            $first = new \DateTimeImmutable($month.'-01');
            $criteria[] = $this->booked($first, $first->modify('last day of this month'));
        }
        $search = trim((string) $search);
        if ('' !== $search) {
            if (preg_match('/^-?\s*(\d{1,9})(?:[.,](\d{1,2}))?\s*€?$/u', $search, $m)) {
                $minor = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
                $criteria['amount'] = [$minor, -$minor];
            } else {
                foreach (\array_slice(preg_split('/\s+/', $search), 0, 5) as $word) {
                    $like = '%'.addcslashes($word, '%_').'%';
                    $criteria[] = Group::of(Parser::SEPARATOR_OR,
                        Criterion::of('label', $like, Parser::OPTION_PARTIAL),
                        Criterion::of('counterpartyName', $like, Parser::OPTION_PARTIAL),
                        Criterion::of('reference', $like, Parser::OPTION_PARTIAL),
                    );
                }
            }
        }

        return $criteria;
    }

    /** l.bookedOn >= :from AND l.bookedOn <= :to */
    private function booked(\DateTimeImmutable $from, \DateTimeImmutable $to): Group
    {
        return Group::of(Parser::SEPARATOR_AND,
            Criterion::of('bookedOn', $from, Parser::OPTION_GREATER_EQUAL),
            Criterion::of('bookedOn', $to, Parser::OPTION_LOWER_EQUAL),
        );
    }

    /** @return array<string, int> status value => count, for one bank account (the statuses it has lines in) */
    public function countByStatus(BankAccount $account): array
    {
        $counts = [];
        foreach (BankLineStatus::cases() as $status) {
            if (0 < $n = $this->count(['bankAccount' => Key::of($account), 'status' => $status])) {
                $counts[$status->value] = $n;
            }
        }

        return $counts;
    }
}
