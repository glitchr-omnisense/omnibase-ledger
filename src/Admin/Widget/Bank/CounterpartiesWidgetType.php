<?php

namespace Base\Ledger\Admin\Widget\Bank;

use Base\Ledger\Admin\Widget\Chart;
use Base\Ledger\Bank\Dashboard;

/**
 * The counterparties that paid in and took out the most over the months
 * of the period, five each way, with a bar against the first.
 */
final class CounterpartiesWidgetType extends AbstractBankWidgetType
{
    protected const CASH = true;
    protected const PERIOD = '3m';

    public static function getName(): string
    {
        return 'ledger_bank_counterparties';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.counterparties';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-people-arrows';
    }

    protected function template(): string
    {
        return 'counterparties';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        $top = $this->dashboard->topCounterparties(Dashboard::inCurrency($accounts, $this->dashboard->currency($accounts)), $period['from'], $period['to']);
        foreach (['in', 'out'] as $side) {
            $max = $top[$side][0]['amount'] ?? 0;
            foreach ($top[$side] as $i => $row) {
                $top[$side][$i]['width'] = Chart::percent($row['amount'], $max);
            }
        }

        return ['top' => $top];
    }
}
