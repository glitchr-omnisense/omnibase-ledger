<?php

namespace Base\Ledger\Admin\Widget\Bank;

use Base\Ledger\Admin\Widget\Chart;

/**
 * Where the reconciliation stands: one stacked bar of the lines reconciled,
 * suggested, still to reconcile and ignored, and the way to the account
 * with the most to do.
 */
final class ReconciliationWidgetType extends AbstractBankWidgetType
{
    protected const COMPACT = true;

    public static function getName(): string
    {
        return 'ledger_bank_reconciliation';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.reconciliation';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-scale-balanced';
    }

    protected function template(): string
    {
        return 'reconciliation';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        $state = $this->dashboard->reconciliation($accounts);
        $shares = [];
        foreach ($state['counts'] as $status => $count) {
            $shares[$status] = Chart::percent($count, $state['total']);
        }

        return ['state' => $state, 'shares' => $shares];
    }
}
