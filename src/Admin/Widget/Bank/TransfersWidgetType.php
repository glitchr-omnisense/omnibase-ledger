<?php

namespace Base\Ledger\Admin\Widget\Bank;

use Base\Ledger\Bank\Dashboard;

/**
 * The transfers of this calendar month (lines the gateway or the statement
 * says are transfers, or that carry the counterparty's IBAN - a rent paid by
 * SEPA transfer is one): how many and how much came in and went out, beside
 * last month's.
 */
final class TransfersWidgetType extends AbstractBankWidgetType
{
    protected const CASH = true;
    protected const COMPACT = true;

    public static function getName(): string
    {
        return 'ledger_bank_transfers';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.transfers';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-right-left';
    }

    protected function template(): string
    {
        return 'transfers';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        return ['transfers' => $this->dashboard->transfers(Dashboard::inCurrency($accounts, $this->dashboard->currency($accounts)))];
    }
}
