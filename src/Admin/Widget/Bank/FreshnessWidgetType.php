<?php

namespace Base\Ledger\Admin\Widget\Bank;

use Base\Ledger\Bank\Dashboard;

/**
 * Freshness: each account's last sync or statement, the stale ones first
 * (an API account not synced for 3 days, a statement account for 35).
 */
final class FreshnessWidgetType extends AbstractBankWidgetType
{
    public static function getName(): string
    {
        return 'ledger_bank_freshness';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.freshness';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-clock-rotate-left';
    }

    protected function template(): string
    {
        return 'freshness';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        $rows = $this->dashboard->freshness($accounts);

        return ['rows' => $rows, 'stale' => \count(array_filter($rows, static fn ($r) => $r['stale'])), 'staleDays' => Dashboard::STALE_DAYS, 'staleFilesDays' => Dashboard::STALE_FILES_DAYS];
    }
}
