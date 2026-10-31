<?php

namespace Base\Ledger\Report;

use Base\Ledger\Dimension\Dimensions;
use Base\Ledger\Entity\Book;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Repository\EntryLineRepository;

/**
 * The compte de résultat over a period: income (class 7) and expenses
 * (class 6) in the headings an SCI's accounts are read under, their totals
 * and the result (bénéfice when positive, perte when negative) - and, when
 * asked, the same period a year earlier beside it (N-1).
 *
 * An account goes to the group of the longest prefix it starts with on its
 * side (7083 to the rents, 708 to the recovered charges), and to "Autres
 * produits" / "Autres charges" when none matches. Which side is the
 * account's type (income, expense), as for the trial balance's result.
 *
 * Draft entries count, as in the trial balance: the statement is what the
 * books say today, before the accountant validates them.
 *
 * Analytic axes: compute() keeps the lines carrying the dimensions asked
 * (['unit' => '12']: one home's result), byDimension() gives the result per
 * value of an axis (per unit, per owner), named by the labelers.
 */
class IncomeStatement
{
    /** key => [heading, account prefixes] */
    public const INCOME = [
        'rents' => ['Loyers', ['706', '7083']],
        'recovered_charges' => ['Charges récupérées', ['708']],
        'other_income' => ['Autres produits', ['7088']],
    ];

    /** key => [heading, account prefixes] */
    public const EXPENSES = [
        'rental_charges' => ['Charges locatives et de copropriété', ['614', '6061']],
        'repairs' => ['Entretien et réparations', ['615', '6063']],
        'insurance' => ['Assurances', ['616']],
        'fees' => ['Honoraires', ['622', '611']],
        'taxes' => ['Impôts et taxes', ['63']],
        'interest' => ['Intérêts des emprunts et dettes', ['661']],
        'depreciation' => ['Dotations aux amortissements', ['681']],
        'bank_fees' => ['Frais bancaires', ['627']],
        'other_expenses' => ['Autres charges', []],
    ];

    /** Where an account no prefix matches goes, per side. */
    private const OTHER = ['income' => 'other_income', 'expenses' => 'other_expenses'];

    public function __construct(private readonly EntryLineRepository $lines, private readonly ?Dimensions $dimensions = null)
    {
    }

    /**
     * Amounts are in minor units, income and expenses both positive when
     * normal (a refund larger than the charge makes it negative); previous
     * is null throughout unless $withPrevious. $dimensions (axis => value)
     * keeps the lines carrying them.
     *
     * @param array<string, string|int> $dimensions
     *
     * @return array{
     *     income: array<string, array{key: string, label: string, lines: list<array{number: string, label: string, amount: int, previous: ?int}>, total: int, previous: ?int}>,
     *     expenses: array<string, array{key: string, label: string, lines: list<array{number: string, label: string, amount: int, previous: ?int}>, total: int, previous: ?int}>,
     *     totals: array{income: int, expenses: int, result: int},
     *     previous: ?array{income: int, expenses: int, result: int}
     * }
     */
    public function compute(Book $book, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $withPrevious = false, array $dimensions = []): array
    {
        $previous = $withPrevious ? $this->lines->totalsByAccount($book, self::yearBefore($from), self::yearBefore($to), false, $dimensions) : null;

        return self::fromTotals($this->lines->totalsByAccount($book, $from, $to, false, $dimensions), $previous);
    }

    /**
     * The statement per value of $axis over the period - one per unit, per
     * owner -, the values named by the labelers and in their order, the
     * lines without the axis last (value null), and the whole beside them.
     *
     * @param array<string, string|int> $dimensions kept lines, as for compute()
     *
     * @return array{axis: string, groups: list<array{value: ?string, label: ?string, report: array}>, totals: array{income: int, expenses: int, result: int}}
     */
    public function byDimension(Book $book, \DateTimeImmutable $from, \DateTimeImmutable $to, string $axis, array $dimensions = []): array
    {
        $rows = $this->lines->totalsByAccount($book, $from, $to, false, $dimensions, $axis);
        $byValue = [];
        foreach ($rows as $row) {
            $byValue[null === ($row['dimension'] ?? null) ? '' : (string) $row['dimension']][] = $row;
        }
        $values = array_values(array_filter(array_map('strval', array_keys($byValue)), fn (string $value) => '' !== $value));
        $labels = $this->dimensions?->labels($axis, $values) ?? array_combine($values, $values);

        $groups = [];
        foreach ($byValue as $value => $totals) {
            $value = (string) $value;
            $groups[] = ['value' => '' === $value ? null : $value, 'label' => '' === $value ? null : ($labels[$value] ?? $value), 'report' => self::fromTotals($totals)];
        }
        usort($groups, fn (array $a, array $b) => [null === $a['value'], (string) $a['label']] <=> [null === $b['value'], (string) $b['label']]);

        return ['axis' => $axis, 'groups' => $groups, 'totals' => self::fromTotals($rows)['totals']];
    }

    /**
     * @param list<array{number: string, label: string, type: mixed, debit: int, credit: int}>      $totals
     * @param list<array{number: string, label: string, type: mixed, debit: int, credit: int}>|null $previous the N-1 period's
     */
    public static function fromTotals(array $totals, ?array $previous = null): array
    {
        $compare = null !== $previous;
        $report = ['income' => self::groups(self::INCOME, $compare), 'expenses' => self::groups(self::EXPENSES, $compare)];
        foreach (['amount' => $totals, 'previous' => $previous ?? []] as $column => $rows) {
            foreach ($rows as $row) {
                $type = TrialBalance::typeOf($row);
                if (!$type->isResult()) {
                    continue;
                }
                $side = AccountType::INCOME === $type ? 'income' : 'expenses';
                $number = (string) $row['number'];
                $amount = 'income' === $side ? (int) $row['credit'] - (int) $row['debit'] : (int) $row['debit'] - (int) $row['credit'];
                $key = self::groupOf($number, 'income' === $side ? self::INCOME : self::EXPENSES) ?? self::OTHER[$side];
                $group = &$report[$side][$key];
                $group['lines'][$number] ??= ['number' => $number, 'label' => (string) $row['label'], 'amount' => 0, 'previous' => $compare ? 0 : null];
                $group['lines'][$number][$column] += $amount;
                $group['amount' === $column ? 'total' : 'previous'] += $amount;
                unset($group);
            }
        }

        $sum = fn (string $side, string $field) => array_sum(array_column($report[$side], $field));
        foreach ($report as $side => $groups) {
            foreach ($groups as $key => $group) {
                $lines = array_filter($group['lines'], fn (array $line) => 0 !== $line['amount'] || 0 !== ($line['previous'] ?? 0));
                usort($lines, fn (array $a, array $b) => strcmp($a['number'], $b['number']));
                $report[$side][$key]['lines'] = $lines;
            }
        }
        $report['totals'] = ['income' => $sum('income', 'total'), 'expenses' => $sum('expenses', 'total')];
        $report['totals']['result'] = $report['totals']['income'] - $report['totals']['expenses'];
        $report['previous'] = null;
        if ($compare) {
            $report['previous'] = ['income' => $sum('income', 'previous'), 'expenses' => $sum('expenses', 'previous')];
            $report['previous']['result'] = $report['previous']['income'] - $report['previous']['expenses'];
        }

        return $report;
    }

    /**
     * The same day a year earlier, a month's last day staying its last day
     * (28/02/2029 → 29/02/2028, 29/02/2028 → 28/02/2027): the N-1 period of
     * a twelve-month fiscal year is the fiscal year before.
     */
    public static function yearBefore(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $year = (int) $date->format('Y') - 1;
        $month = (int) $date->format('n');
        $days = (int) $date->setDate($year, $month, 1)->format('t');
        $day = $date->format('j') === $date->format('t') ? $days : min((int) $date->format('j'), $days);

        return $date->setDate($year, $month, $day);
    }

    /** The key of the group whose longest prefix $number starts with. */
    private static function groupOf(string $number, array $groups): ?string
    {
        $found = null;
        $length = 0;
        foreach ($groups as $key => [, $prefixes]) {
            foreach ($prefixes as $prefix) {
                if (\strlen($prefix) > $length && str_starts_with($number, $prefix)) {
                    $found = $key;
                    $length = \strlen($prefix);
                }
            }
        }

        return $found;
    }

    private static function groups(array $definitions, bool $compare): array
    {
        $groups = [];
        foreach ($definitions as $key => [$label]) {
            $groups[$key] = ['key' => $key, 'label' => $label, 'lines' => [], 'total' => 0, 'previous' => $compare ? 0 : null];
        }

        return $groups;
    }
}
