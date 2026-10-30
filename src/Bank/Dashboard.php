<?php

namespace Base\Ledger\Bank;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankDay;
use Base\Ledger\Entity\BankDayCategory;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Entity\BankRecurring;
use Base\Ledger\Entity\Book;
use Base\Ledger\Enum\BankLineStatus;
use Base\Ledger\Enum\ConsentState;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankConnectionRepository;
use Base\Ledger\Repository\BankCounterpartyMonthRepository;
use Base\Ledger\Repository\BankDayCategoryRepository;
use Base\Ledger\Repository\BankDayRepository;
use Base\Ledger\Repository\BankLineRepository;
use Base\Ledger\Repository\BankRecurringRepository;
use Base\Ledger\Repository\BookRepository;
use Base\Ledger\Security\Voter\LedgerVoter;
use Omnibank\Model\AccountKind;
use Omnibank\Model\ConnectionState;
use Omnibank\Model\TransactionCategory;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * What the bank widgets show, read from the precomputed rows (Aggregates):
 * the accounts a reader may see (their books' LEDGER_VIEW) narrowed to a
 * widget's own pick, periods, balances and their history, flows by bucket,
 * categories, counterparties, recurring flows and the forecast they make,
 * the connections' and the reconciliation's state, freshness. Every amount
 * in minor units; a figure that adds accounts adds those of one currency
 * (the widget's, else the one most accounts are in).
 */
class Dashboard
{
    /** period key => [the first day relative to today, bucket] */
    public const PERIODS = [
        '10d' => ['-9 days', 'day'],
        '1m' => ['-29 days', 'day'],
        '3m' => ['-12 weeks monday this week', 'week'],
        '6m' => ['first day of -5 months', 'month'],
        '12m' => ['first day of -11 months', 'month'],
    ];

    /** Days without a sync after which an account fed through an API is stale; a statement-file account after STALE_FILES_DAYS. */
    public const STALE_DAYS = 3;
    public const STALE_FILES_DAYS = 35;

    public function __construct(
        private readonly BookRepository $books,
        private readonly BankAccountRepository $bankAccounts,
        private readonly BankLineRepository $lines,
        private readonly BankDayRepository $days,
        private readonly BankDayCategoryRepository $categories,
        private readonly BankCounterpartyMonthRepository $counterparties,
        private readonly BankRecurringRepository $recurring,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
        private readonly ?BankConnectionRepository $bankConnections = null,
    ) {
    }

    /** @return list<Book> the books the reader may see */
    public function books(): array
    {
        return array_values(array_filter($this->books->findBy([], ['id' => 'ASC']), fn (Book $book) => null === $this->authorization || $this->authorization->isGranted(LedgerVoter::VIEW, $book)));
    }

    /**
     * Every bank account the reader may see, and those a widget shows: its
     * "accounts" parameter (ids) when it names some still there, else all -
     * but the credits (loans...) for a card about cash ($cash).
     *
     * @return array{all: list<BankAccount>, shown: list<BankAccount>}
     */
    public function accounts(array $params, bool $cash = false): array
    {
        $all = [];
        foreach ($this->books() as $book) {
            foreach ($this->bankAccounts->forBook($book) as $account) {
                $all[] = $account;
            }
        }
        $picked = array_map('intval', array_filter((array) ($params['accounts'] ?? []), 'is_numeric'));
        $shown = [] === $picked ? $all : array_values(array_filter($all, static fn (BankAccount $a) => \in_array($a->getId(), $picked, true)));
        if ([] === $shown) {
            $shown = $all;
        }
        // Unpicked, a card about cash leaves the credits out; picked, the reader's choice stands.
        if ($cash && ([] === $picked || \count($shown) === \count($all))) {
            $shown = array_values(array_filter($shown, static fn (BankAccount $a) => !$a->getKind()->isLiability())) ?: $shown;
        }

        return ['all' => $all, 'shown' => $shown];
    }

    /** The currency most of $accounts are in (the first book's when none). @param list<BankAccount> $accounts */
    public function currency(array $accounts): string
    {
        $counts = array_count_values(array_map(static fn (BankAccount $a) => $a->getCurrency(), $accounts));
        arsort($counts);

        return (string) (array_key_first($counts) ?? ($this->books()[0] ?? null)?->getCurrency() ?? 'EUR');
    }

    /** @param list<BankAccount> $accounts @return list<BankAccount> those in $currency */
    public static function inCurrency(array $accounts, string $currency): array
    {
        return array_values(array_filter($accounts, static fn (BankAccount $a) => $a->getCurrency() === $currency));
    }

    /** @return array{key: string, from: \DateTimeImmutable, to: \DateTimeImmutable, bucket: string} */
    public static function period(?string $key, string $default = '1m', ?\DateTimeImmutable $today = null): array
    {
        $key = isset(self::PERIODS[$key]) ? $key : $default;
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        [$start, $bucket] = self::PERIODS[$key];

        return ['key' => $key, 'from' => $today->modify($start)->setTime(0, 0), 'to' => $today, 'bucket' => $bucket];
    }

    /** The balance of an account now: the bank's last one, else what its precomputed days end on, else zero. */
    public function balance(BankAccount $account): int
    {
        return $account->getBankBalance() ?? $this->days->latest($account)?->getClosingBalance() ?? 0;
    }

    /**
     * Each day's total balance of $accounts from $from to $to, the days
     * without a movement carrying the day before's.
     *
     * @param list<BankAccount> $accounts
     *
     * @return array<string, int> Y-m-d => balance
     */
    public function balanceSeries(array $accounts, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $series = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $series[$day->format('Y-m-d')] = 0;
        }
        foreach ($accounts as $account) {
            $before = $this->days->lastBefore($account, $from);
            $rows = [];
            foreach ($this->days->forAccounts([$account], $from, $to) as $row) {
                $rows[$row->getDay()->format('Y-m-d')] = $row;
            }
            // Before its first row in the period: the last one before, else the opening of that first row.
            $carry = $before?->getClosingBalance() ?? ([] !== $rows ? reset($rows)->getOpeningBalance() : $this->balance($account));
            foreach (array_keys($series) as $day) {
                if (isset($rows[$day])) {
                    $carry = $rows[$day]->getClosingBalance();
                }
                $series[$day] += $carry;
            }
        }

        return $series;
    }

    /**
     * In and out by bucket over a period (days, ISO weeks or months), with
     * the balance at each bucket's end.
     *
     * @param list<BankAccount> $accounts
     *
     * @return list<array{key: string, from: \DateTimeImmutable, to: \DateTimeImmutable, in: int, out: int, inCount: int, outCount: int, balance: int}>
     */
    public function flows(array $accounts, array $period): array
    {
        $buckets = [];
        for ($start = $period['from']; $start <= $period['to'];) {
            $end = match ($period['bucket']) {
                'week' => $start->modify('sunday this week'),
                'month' => $start->modify('last day of this month'),
                default => $start,
            };
            $end = min($end, $period['to']);
            $buckets[$start->format('Y-m-d')] = ['key' => $start->format('Y-m-d'), 'from' => $start, 'to' => $end, 'in' => 0, 'out' => 0, 'inCount' => 0, 'outCount' => 0, 'balance' => 0];
            $start = $end->modify('+1 day');
        }
        $keys = array_keys($buckets);
        $bucketOf = static function (\DateTimeImmutable $day) use ($keys): ?string {
            $found = null;
            foreach ($keys as $key) {
                if ($key <= $day->format('Y-m-d')) {
                    $found = $key;
                }
            }

            return $found;
        };
        foreach ($this->days->forAccounts($accounts, $period['from'], $period['to']) as $row) {
            $key = $bucketOf($row->getDay());
            if (null === $key) {
                continue;
            }
            $buckets[$key]['in'] += $row->getInflow();
            $buckets[$key]['out'] += $row->getOutflow();
            $buckets[$key]['inCount'] += $row->getInflowCount();
            $buckets[$key]['outCount'] += $row->getOutflowCount();
        }
        $balances = $this->balanceSeries($accounts, $period['from'], $period['to']);
        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['balance'] = $balances[$bucket['to']->format('Y-m-d')] ?? 0;
        }

        return array_values($buckets);
    }

    /**
     * Out and in by category over a period, the largest outflow first; the
     * lines the gateway did not categorise under "none".
     *
     * @param list<BankAccount> $accounts
     *
     * @return list<array{category: string, in: int, out: int, count: int}>
     */
    public function byCategory(array $accounts, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $totals = [];
        foreach ($this->categories->forAccounts($accounts, $from, $to) as $row) {
            $key = $row->getCategory();
            $totals[$key] ??= ['category' => $key, 'in' => 0, 'out' => 0, 'count' => 0];
            $totals[$key]['in'] += $row->getInflow();
            $totals[$key]['out'] += $row->getOutflow();
            $totals[$key]['count'] += $row->getCount();
        }
        usort($totals, static fn ($a, $b) => [$b['out'], $b['in']] <=> [$a['out'], $a['in']]);

        return $totals;
    }

    /** Whether rows of byCategory() hold a category. */
    public static function hasCategories(array $rows): bool
    {
        foreach ($rows as $row) {
            if (BankDayCategory::NONE !== $row['category']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The counterparties that paid in and took out the most over the months
     * of a period.
     *
     * @param list<BankAccount> $accounts
     *
     * @return array{in: list<array{name: string, amount: int, count: int}>, out: list<array{name: string, amount: int, count: int}>}
     */
    public function topCounterparties(array $accounts, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 5): array
    {
        $totals = [];
        foreach ($this->counterparties->forAccounts($accounts, $from, $to) as $row) {
            $key = $row->getCounterparty();
            $totals[$key] ??= ['name' => $row->getName(), 'in' => 0, 'out' => 0, 'inCount' => 0, 'outCount' => 0];
            $totals[$key]['in'] += $row->getInflow();
            $totals[$key]['out'] += $row->getOutflow();
            $totals[$key]['inCount'] += $row->getInflowCount();
            $totals[$key]['outCount'] += $row->getOutflowCount();
        }
        $side = static function (string $amount, string $count) use ($totals, $limit): array {
            $rows = array_values(array_filter($totals, static fn ($t) => $t[$amount] > 0));
            usort($rows, static fn ($a, $b) => $b[$amount] <=> $a[$amount]);

            return array_map(static fn ($t) => ['name' => $t['name'], 'amount' => $t[$amount], 'count' => $t[$count]], \array_slice($rows, 0, $limit));
        };

        return ['in' => $side('in', 'inCount'), 'out' => $side('out', 'outCount')];
    }

    /** @param list<BankAccount> $accounts @return list<BankRecurring> by next date */
    public function recurring(array $accounts): array
    {
        return $this->recurring->forAccounts($accounts);
    }

    /**
     * The balance ahead: today's, then each recurring flow on the dates it
     * falls on, day by day up to $days days.
     *
     * @param list<BankAccount> $accounts
     *
     * @return array{start: int, series: array<string, int>, horizons: array<int, int>, low: array{day: string, balance: int}, flows: int, upcoming: list<array{day: \DateTimeImmutable, label: string, amount: int, currency: string}>}
     */
    public function forecast(array $accounts, int $days = 90, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $start = array_sum(array_map(fn (BankAccount $a) => $this->balance($a), $accounts));
        $until = $today->modify(sprintf('+%d days', $days));

        $moves = [];
        $flows = 0;
        $upcoming = [];
        foreach ($this->recurring($accounts) as $flow) {
            // A flow overdue within its tolerance is still expected: it counts from tomorrow.
            $first = true;
            foreach ($flow->occurrencesBetween($today->modify('-60 days'), $until) as $date) {
                if ($date <= $today) {
                    if (!$first) {
                        continue;
                    }
                    $date = $today->modify('+1 day');
                }
                $first = false;
                $moves[$date->format('Y-m-d')] = ($moves[$date->format('Y-m-d')] ?? 0) + $flow->getAmount();
                ++$flows;
                $upcoming[] = ['day' => $date, 'label' => $flow->getLabel(), 'amount' => $flow->getAmount(), 'currency' => $flow->getBankAccount()->getCurrency()];
            }
        }

        $series = [];
        $balance = $start;
        $low = ['day' => $today->format('Y-m-d'), 'balance' => $start];
        for ($day = $today; $day <= $until; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $balance += $moves[$key] ?? 0;
            $series[$key] = $balance;
            if ($balance < $low['balance']) {
                $low = ['day' => $key, 'balance' => $balance];
            }
        }
        $horizons = [];
        foreach ([30, 60, 90] as $horizon) {
            if ($horizon <= $days) {
                $horizons[$horizon] = $series[$today->modify(sprintf('+%d days', $horizon))->format('Y-m-d')] ?? $balance;
            }
        }

        usort($upcoming, static fn ($a, $b) => [$a['day'], -abs($a['amount'])] <=> [$b['day'], -abs($b['amount'])]);

        return ['start' => $start, 'series' => $series, 'horizons' => $horizons, 'low' => $low, 'flows' => $flows, 'upcoming' => \array_slice($upcoming, 0, 6)];
    }

    /**
     * The accounts by kind: how many, and what they hold.
     *
     * @param list<BankAccount> $accounts
     *
     * @return list<array{kind: AccountKind, count: int, balance: int}> the most accounts first
     */
    public function byKind(array $accounts): array
    {
        $kinds = [];
        foreach ($accounts as $account) {
            $kind = $account->getKind();
            $kinds[$kind->value] ??= ['kind' => $kind, 'count' => 0, 'balance' => 0];
            ++$kinds[$kind->value]['count'];
            $kinds[$kind->value]['balance'] += $this->balance($account);
        }
        usort($kinds, static fn ($a, $b) => [$b['count'], $a['kind']->value] <=> [$a['count'], $b['kind']->value]);

        return $kinds;
    }

    /**
     * The connections behind $accounts by state group (ok, action,
     * unavailable, error), and those whose consent ends soon; with
     * $withoutAccounts, the books' connections that have no account yet too
     * (a bank being connected, a consent never completed).
     *
     * @param list<BankAccount> $accounts
     *
     * @return array{groups: array<string, int>, total: int, connections: list<array{connection: \Base\Ledger\Entity\BankConnection, state: ConnectionState}>, renewals: list<\Base\Ledger\Entity\BankConnection>}
     */
    public function connections(array $accounts, int $renewalDays = 30, ?\DateTimeImmutable $now = null, bool $withoutAccounts = false): array
    {
        $now ??= new \DateTimeImmutable();
        $seen = [];
        foreach ($accounts as $account) {
            $connection = $account->getConnection();
            $seen[spl_object_id($connection)] = $connection;
        }
        if ($withoutAccounts && null !== $this->bankConnections && [] !== ($books = $this->books())) {
            foreach ($this->bankConnections->findBy(['book' => $books], ['id' => 'ASC']) as $connection) {
                if ($connection->getAccounts()->isEmpty()) {
                    $seen[spl_object_id($connection)] = $connection;
                }
            }
        }
        $groups = ['ok' => 0, 'action' => 0, 'unavailable' => 0, 'error' => 0];
        $rows = [];
        $renewals = [];
        foreach ($seen as $connection) {
            // A consent the bank wants renewed, or one never given through a provider's page, is the user's action, whatever the last sync said.
            $neverGiven = StatementUpload::GATEWAY !== $connection->getGateway() && ConsentState::NONE === $connection->getConsentStatus() && $connection->getAccounts()->isEmpty();
            $state = ($connection->needsRenewal($now) || $neverGiven) && !$connection->getConnectionState()->needsUser() ? ConnectionState::ACTION_NEEDED : $connection->getConnectionState();
            ++$groups[$state->group()];
            $rows[] = ['connection' => $connection, 'state' => $state];
            if (StatementUpload::GATEWAY !== $connection->getGateway() && ($connection->needsRenewal($now) || (null !== $connection->getExpiresAt() && $connection->getExpiresAt() <= $now->modify(sprintf('+%d days', $renewalDays))))) {
                $renewals[] = $connection;
            }
        }
        usort($rows, static fn ($a, $b) => [$a['state']->group() === 'ok', (string) $a['connection']] <=> [$b['state']->group() === 'ok', (string) $b['connection']]);

        return ['groups' => $groups, 'total' => \count($seen), 'connections' => $rows, 'renewals' => $renewals];
    }

    /**
     * Where the reconciliation stands: lines by status, and the account with the most to reconcile.
     *
     * @param list<BankAccount> $accounts
     *
     * @return array{counts: array<string, int>, total: int, pending: int, first: ?BankAccount, perAccount: list<array{account: BankAccount, pending: int}>}
     */
    public function reconciliation(array $accounts): array
    {
        $counts = array_fill_keys(array_map(static fn (BankLineStatus $s) => $s->value, BankLineStatus::cases()), 0);
        $perAccount = [];
        foreach ($accounts as $account) {
            $byStatus = $this->lines->countByStatus($account);
            foreach ($byStatus as $status => $n) {
                $counts[$status] = ($counts[$status] ?? 0) + $n;
            }
            $pending = ($byStatus[BankLineStatus::UNMATCHED->value] ?? 0) + ($byStatus[BankLineStatus::SUGGESTED->value] ?? 0);
            $perAccount[] = ['account' => $account, 'pending' => $pending];
        }
        usort($perAccount, static fn ($a, $b) => $b['pending'] <=> $a['pending']);
        $pending = $counts[BankLineStatus::UNMATCHED->value] + $counts[BankLineStatus::SUGGESTED->value];

        return ['counts' => $counts, 'total' => array_sum($counts), 'pending' => $pending, 'first' => $pending > 0 ? $perAccount[0]['account'] : null, 'perAccount' => $perAccount];
    }

    /**
     * Each account's last sync, the stale ones first.
     *
     * @param list<BankAccount> $accounts
     *
     * @return list<array{account: BankAccount, at: ?\DateTimeImmutable, age: ?int, stale: bool, files: bool}>
     */
    public function freshness(array $accounts, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $rows = [];
        foreach ($accounts as $account) {
            $at = $account->getLastSyncedAt();
            $files = StatementUpload::GATEWAY === $account->getConnection()->getGateway();
            $age = null === $at ? null : (int) floor(($now->getTimestamp() - $at->getTimestamp()) / 86400);
            $rows[] = ['account' => $account, 'at' => $at, 'age' => $age, 'stale' => null === $age || $age > ($files ? self::STALE_FILES_DAYS : self::STALE_DAYS), 'files' => $files];
        }
        usort($rows, static fn ($a, $b) => [!$a['stale'], -($a['age'] ?? \PHP_INT_MAX)] <=> [!$b['stale'], -($b['age'] ?? \PHP_INT_MAX)]);

        return $rows;
    }

    /** @param list<BankAccount> $accounts @return list<BankLine> */
    public function largest(array $accounts, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 8): array
    {
        return $this->lines->largest($accounts, $from, $to, $limit);
    }

    /**
     * What came in and went out as transfers (lines categorised so, or
     * carrying the counterparty's IBAN) in the calendar month of $today, and
     * the month before for comparison.
     *
     * @param list<BankAccount> $accounts
     *
     * @return array{current: array{in: int, out: int, inCount: int, outCount: int}, previous: array{in: int, out: int, inCount: int, outCount: int}, month: \DateTimeImmutable}
     */
    public function transfers(array $accounts, ?\DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $first = $today->modify('first day of this month');
        $previousFirst = $first->modify('-1 month');
        $sum = function (\DateTimeImmutable $from, \DateTimeImmutable $to) use ($accounts): array {
            $totals = ['in' => 0, 'out' => 0, 'inCount' => 0, 'outCount' => 0];
            foreach ($this->days->forAccounts($accounts, $from, $to) as $row) {
                $totals['in'] += $row->getTransferIn();
                $totals['out'] += $row->getTransferOut();
                $totals['inCount'] += $row->getTransferInCount();
                $totals['outCount'] += $row->getTransferOutCount();
            }

            return $totals;
        };

        return ['current' => $sum($first, $today), 'previous' => $sum($previousFirst, $first->modify('-1 day')), 'month' => $first];
    }
}
