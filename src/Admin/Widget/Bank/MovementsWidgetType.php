<?php

namespace Base\Ledger\Admin\Widget\Bank;


/**
 * The largest movements of the period, in or out: date, label,
 * counterparty, account, amount and where its reconciliation stands. The
 * one card that reads lines - the eight largest, by an indexed query.
 */
final class MovementsWidgetType extends AbstractBankWidgetType
{
    protected const CASH = true;
    protected const PERIOD = '1m';

    public static function getName(): string
    {
        return 'ledger_bank_movements';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.movements';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-arrow-up-wide-short';
    }

    protected function template(): string
    {
        return 'movements';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        return ['movements' => $this->dashboard->largest($accounts, $period['from'], $period['to'])];
    }
}
