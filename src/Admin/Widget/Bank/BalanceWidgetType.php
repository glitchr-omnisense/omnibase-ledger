<?php

namespace Base\Ledger\Admin\Widget\Bank;

use Base\Ledger\Admin\Widget\Chart;

/**
 * The aggregated balance, per currency: what the accounts hold together
 * (cash, savings, investments), its change over the period and the
 * period's balance as a small line; beside it what the credits owe (a
 * loan's balance, negative as the bank gives it) and the net of both.
 */
final class BalanceWidgetType extends AbstractBankWidgetType
{
    protected const COMPACT = true;
    protected const PERIOD = '1m';

    public static function getName(): string
    {
        return 'ledger_bank_balance';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.balance';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-sack-dollar';
    }

    protected function template(): string
    {
        return 'balance';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        $currencies = [];
        foreach ($accounts as $account) {
            $currencies[$account->getCurrency()][] = $account;
        }
        uasort($currencies, static fn ($a, $b) => \count($b) <=> \count($a));
        $totals = [];
        foreach ($currencies as $currency => $group) {
            // What the company has (cash, savings, investments) leads; what it owes (loans, credits) beside it.
            $assets = array_values(array_filter($group, static fn ($a) => !$a->getKind()->isLiability()));
            $credits = array_values(array_filter($group, static fn ($a) => $a->getKind()->isLiability()));
            $series = $this->dashboard->balanceSeries($assets, $period['from'], $period['to']);
            $now = array_sum(array_map(fn ($a) => $this->dashboard->balance($a), $assets));
            $owed = array_sum(array_map(fn ($a) => $this->dashboard->balance($a), $credits));
            $start = [] === $series ? $now : reset($series);
            $totals[] = [
                'currency' => (string) $currency,
                'balance' => $now,
                'change' => $now - $start,
                'changePercent' => 0 !== $start ? round(($now - $start) / abs($start) * 100, 1) : null,
                'accounts' => \count($assets),
                'credits' => $owed,
                'creditAccounts' => \count($credits),
                'net' => $now + $owed,
                'line' => Chart::line(array_values($series)),
            ];
        }

        return ['totals' => $totals];
    }
}
