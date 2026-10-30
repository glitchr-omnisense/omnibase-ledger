<?php

namespace Base\Ledger\Admin\Widget\Bank;

use Base\Ledger\Admin\Widget\Chart;
use Base\Ledger\Bank\Dashboard;

/**
 * Spending by category over the period: a bar per category, the largest
 * first, with its amount and share of what went out; the uncategorised
 * last. Says so when the gateway gives no categories.
 */
final class CategoriesWidgetType extends AbstractBankWidgetType
{
    protected const CASH = true;
    protected const PERIOD = '3m';

    public static function getName(): string
    {
        return 'ledger_bank_categories';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.categories';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-tags';
    }

    protected function template(): string
    {
        return 'categories';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        $rows = $this->dashboard->byCategory(Dashboard::inCurrency($accounts, $this->dashboard->currency($accounts)), $period['from'], $period['to']);
        $spending = array_values(array_filter($rows, static fn ($r) => $r['out'] > 0));
        usort($spending, static fn ($a, $b) => [$a['category'] === 'none', $b['out']] <=> [$b['category'] === 'none', $a['out']]);
        $total = array_sum(array_column($spending, 'out'));
        $max = max([0, ...array_column($spending, 'out')]);
        foreach ($spending as $i => $row) {
            $spending[$i]['share'] = Chart::percent($row['out'], $total);
            $spending[$i]['width'] = Chart::percent($row['out'], $max);
        }

        return ['spending' => \array_slice($spending, 0, 9), 'total' => $total, 'categorised' => Dashboard::hasCategories($rows)];
    }
}
