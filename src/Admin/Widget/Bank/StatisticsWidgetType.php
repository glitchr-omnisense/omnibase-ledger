<?php

namespace Base\Ledger\Admin\Widget\Bank;

use Base\Ledger\Admin\Widget\Chart;
use Base\Ledger\Bank\Dashboard;

/**
 * General statistics: what came in and went out per day, week or month as
 * bars, and the balance as a line above them on its own scale, for 10 days,
 * 1, 3, 6 or 12 months - every period drawn at once and switched in the
 * card, the "period" parameter choosing the one shown first; inflow,
 * outflow and balance each shown or hidden; a tooltip per bucket.
 */
final class StatisticsWidgetType extends AbstractBankWidgetType
{
    protected const CASH = true;
    protected const PERIOD = '3m';

    public static function getName(): string
    {
        return 'ledger_bank_statistics';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.statistics';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-chart-column';
    }

    protected function template(): string
    {
        return 'statistics';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        $accounts = Dashboard::inCurrency($accounts, $this->dashboard->currency($accounts));
        $charts = [];
        foreach (array_keys(Dashboard::PERIODS) as $key) {
            $p = Dashboard::period($key);
            $buckets = $this->dashboard->flows($accounts, $p);
            // One heavy month (a term deposit opened) would flatten every other: the scale stops at the
            // largest of the others, the towering bars are clipped and marked, their value in full.
            $scale = Chart::scale(Chart::robustMax(array_map(static fn ($b) => max($b['in'], $b['out']), $buckets)) * 1.08);
            $clipped = false;
            foreach ($buckets as $i => $bucket) {
                $buckets[$i]['inHeight'] = Chart::percent($bucket['in'], $scale['max']);
                $buckets[$i]['outHeight'] = Chart::percent($bucket['out'], $scale['max']);
                $buckets[$i]['inClipped'] = $bucket['in'] > $scale['max'];
                $buckets[$i]['outClipped'] = $bucket['out'] > $scale['max'];
                $clipped = $clipped || $buckets[$i]['inClipped'] || $buckets[$i]['outClipped'];
            }
            $charts[$key] = [
                'period' => $p,
                'buckets' => $buckets,
                'scale' => $scale,
                'clipped' => $clipped,
                'line' => Chart::line(array_map(static fn ($b) => $b['balance'], $buckets), 0.12, true),
                'in' => array_sum(array_column($buckets, 'in')),
                'out' => array_sum(array_column($buckets, 'out')),
                'count' => array_sum(array_column($buckets, 'inCount')) + array_sum(array_column($buckets, 'outCount')),
            ];
        }

        return ['charts' => $charts];
    }
}
