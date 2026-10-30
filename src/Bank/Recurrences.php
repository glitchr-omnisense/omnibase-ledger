<?php

namespace Base\Ledger\Bank;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankRecurring;
use Omnibank\Model\TransactionCategory;

/**
 * Finds the flows that come back on a bank account at a steady rhythm: the
 * lines of one counterparty in one direction, at least three, whose median
 * interval is a week, a month, a quarter or a year and most of whose
 * intervals stay near it, whose amounts stay within a quarter of their
 * median, and whose next date is not long past. Pure: given the lines,
 * returns BankRecurring rows.
 */
final class Recurrences
{
    /** rhythm => [shortest median interval, longest, tolerance in days around the median] */
    private const RHYTHMS = [
        BankRecurring::WEEKLY => [5, 9, 2],
        BankRecurring::MONTHLY => [25, 36, 6],
        BankRecurring::QUARTERLY => [80, 100, 12],
        BankRecurring::YEARLY => [340, 390, 20],
    ];

    private const MIN_OCCURRENCES = 3;
    private const AMOUNT_TOLERANCE = 0.25;
    private const REGULAR_SHARE = 0.75;

    /**
     * @param list<array{bookedOn: \DateTimeImmutable, amount: int, category: ?string, counterpartyName: ?string, counterpartyIban: ?string, label: string}> $lines
     *
     * @return list<BankRecurring>
     */
    public static function detect(BankAccount $account, array $lines, \DateTimeImmutable $today): array
    {
        $groups = [];
        foreach ($lines as $line) {
            if (0 === $line['amount']) {
                continue;
            }
            $key = ($line['amount'] > 0 ? '+' : '-').Counterparty::key($line['counterpartyName'], $line['counterpartyIban'], $line['label']);
            $groups[$key][] = $line;
        }

        $found = [];
        foreach ($groups as $key => $all) {
            // One counterparty may hold several flows (a bank: the loan's instalment and its fees): split by amount first.
            $clusters = self::byAmount($all);
            $flows = [];
            foreach ($clusters as $i => $group) {
                $flow = self::flow($group, $today);
                if (null !== $flow) {
                    $flows[$i] = $flow;
                }
            }
            foreach ($flows as $i => [$steady, $rhythm, $next]) {
                $last = end($steady);
                $amounts = array_map(static fn ($l) => $l['amount'], $steady);
                $categories = array_count_values(array_filter(array_map(static fn ($l) => $l['category'], $steady), static fn ($c) => null !== $c));
                arsort($categories);
                $found[] = new BankRecurring(
                    $account,
                    substr($key, 1).(\count($clusters) > 1 ? '#'.$i : ''),
                    // Named after the counterparty, or after its label when the counterparty has more than one flow.
                    \count($flows) > 1 ? Counterparty::name(null, $last['label']) : Counterparty::name($last['counterpartyName'], $last['label']),
                    (int) round(self::median($amounts)),
                    $rhythm,
                    \count($steady),
                    $last['bookedOn'],
                    $next,
                    [] === $categories ? null : TransactionCategory::of((string) array_key_first($categories)),
                );
            }
        }
        usort($found, static fn (BankRecurring $a, BankRecurring $b) => [$a->getNextOn(), $a->getLabel()] <=> [$b->getNextOn(), $b->getLabel()]);

        return $found;
    }

    /**
     * Lines of one counterparty and direction in clusters of close amounts (at most half as much again as the cluster's smallest).
     *
     * @return list<list<array>>
     */
    private static function byAmount(array $lines): array
    {
        usort($lines, static fn ($a, $b) => abs($a['amount']) <=> abs($b['amount']));
        $clusters = [];
        foreach ($lines as $line) {
            $n = \count($clusters);
            if ($n > 0 && abs($line['amount']) <= abs($clusters[$n - 1][0]['amount']) * 1.5) {
                $clusters[$n - 1][] = $line;
            } else {
                $clusters[] = [$line];
            }
        }

        return $clusters;
    }

    /**
     * A cluster's flow, when it is one: its steady lines oldest first, its rhythm and its next date.
     *
     * @return array{0: list<array>, 1: string, 2: \DateTimeImmutable}|null
     */
    private static function flow(array $group, \DateTimeImmutable $today): ?array
    {
        if (\count($group) < self::MIN_OCCURRENCES) {
            return null;
        }
        usort($group, static fn ($a, $b) => $a['bookedOn'] <=> $b['bookedOn']);

        // The amount: lines far from the median are not this flow (a one-off at the same counterparty).
        $median = self::median(array_map(static fn ($l) => abs($l['amount']), $group));
        $steady = array_values(array_filter($group, static fn ($l) => abs(abs($l['amount']) - $median) <= $median * self::AMOUNT_TOLERANCE));
        if (\count($steady) < self::MIN_OCCURRENCES || \count($steady) < \count($group) * self::REGULAR_SHARE) {
            return null;
        }

        $intervals = [];
        for ($i = 1, $n = \count($steady); $i < $n; ++$i) {
            $intervals[] = (int) $steady[$i - 1]['bookedOn']->diff($steady[$i]['bookedOn'])->days;
        }
        $interval = self::median($intervals);
        $rhythm = null;
        foreach (self::RHYTHMS as $name => [$min, $max, $tolerance]) {
            if ($interval >= $min && $interval <= $max) {
                $regular = \count(array_filter($intervals, static fn ($d) => abs($d - $interval) <= $tolerance));
                if ($regular >= \count($intervals) * self::REGULAR_SHARE) {
                    $rhythm = $name;
                }
                break;
            }
        }
        if (null === $rhythm) {
            return null;
        }

        $last = end($steady);
        $next = BankRecurring::step($last['bookedOn'], $rhythm, $last['bookedOn']);
        // Stopped: the next one is overdue by more than the rhythm's tolerance.
        if ($next->modify(sprintf('+%d days', self::RHYTHMS[$rhythm][2])) < $today->setTime(0, 0)) {
            return null;
        }

        return [$steady, $rhythm, $next];
    }

    /** @param list<int|float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = \count($values);
        if (0 === $n) {
            return 0.0;
        }

        return 0 === $n % 2 ? ($values[$n / 2 - 1] + $values[$n / 2]) / 2 : (float) $values[intdiv($n, 2)];
    }
}
