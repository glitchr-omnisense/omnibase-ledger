<?php

namespace Base\Ledger\Admin\Widget\Bank;

use Base\Ledger\Admin\Widget\Chart;

/**
 * Accounts by kind: a donut of how many accounts of each kind (checking,
 * savings, loan, card...), its legend with the share, the count and what
 * they hold. The eight most usual kinds keep a colour of their own, the
 * others share one, so a kind's colour is the same on every dashboard.
 */
final class KindsWidgetType extends AbstractBankWidgetType
{
    /** Each kind's slot of the categorical palette (1-8); kinds not listed share the neutral "other". */
    public const SLOTS = [
        'checking' => 1, 'savings' => 2, 'loan' => 3, 'card' => 4,
        'life_insurance' => 5, 'market' => 6, 'consumer_credit' => 7, 'revolving' => 8,
    ];

    public static function getName(): string
    {
        return 'ledger_bank_kinds';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.kinds';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-chart-pie';
    }

    protected function template(): string
    {
        return 'kinds';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        $kinds = $this->dashboard->byKind($accounts);
        $total = array_sum(array_map(static fn ($k) => $k['count'], $kinds));
        // Ordered by slot, so neighbouring arcs keep the palette's validated order.
        usort($kinds, static fn ($a, $b) => [self::SLOTS[$a['kind']->value] ?? 9, $a['kind']->value] <=> [self::SLOTS[$b['kind']->value] ?? 9, $b['kind']->value]);
        $arcs = Chart::donut(array_map(static fn ($k) => $k['count'], $kinds));
        foreach ($kinds as $i => $kind) {
            $kinds[$i] += $arcs[$i] + ['slot' => self::SLOTS[$kind['kind']->value] ?? 0];
        }

        return ['kinds' => $kinds, 'total' => $total];
    }
}
