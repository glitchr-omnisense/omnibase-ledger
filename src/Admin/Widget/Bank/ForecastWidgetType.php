<?php

namespace Base\Ledger\Admin\Widget\Bank;

use Base\Ledger\Admin\Widget\Chart;
use Base\Ledger\Bank\Dashboard;

/**
 * The cash forecast: today's balance carried 90 days ahead by the
 * recurring flows on the dates they fall, the balance at 30, 60 and 90
 * days, and the lowest point.
 */
final class ForecastWidgetType extends AbstractBankWidgetType
{
    protected const CASH = true;

    public static function getName(): string
    {
        return 'ledger_bank_forecast';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.forecast';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-chart-line';
    }

    protected function template(): string
    {
        return 'forecast';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        $forecast = $this->dashboard->forecast(Dashboard::inCurrency($accounts, $this->dashboard->currency($accounts)));
        $line = Chart::line(array_values($forecast['series']));
        $marks = [];
        $days = array_keys($forecast['series']);
        foreach (array_keys($forecast['horizons']) as $horizon) {
            $marks[$horizon] = $line['coords'][min($horizon, \count($days) - 1)] ?? null;
        }

        return ['forecast' => $forecast, 'line' => $line, 'marks' => $marks, 'zero' => $line['max'] > 0 && $line['min'] < 0];
    }
}
