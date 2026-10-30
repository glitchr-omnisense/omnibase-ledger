<?php

namespace Base\Ledger\Admin\Widget\Bank;

use Base\Ledger\Admin\Widget\Chart;
use Base\Ledger\Entity\BankConnection;

/**
 * The connections' state: one stacked bar of the bank accesses that work,
 * want the user (strong authentication, credentials, a consent to renew),
 * wait for the bank, or failed, with each share; the consents to renew
 * within 30 days; "See more" opens the connections.
 */
final class ConnectionsWidgetType extends AbstractBankWidgetType
{
    public static function getName(): string
    {
        return 'ledger_bank_connections';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.connections';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-plug-circle-check';
    }

    protected function template(): string
    {
        return 'connections';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        // Every connection when the reader picked no account: one still being connected has none yet.
        $state = $this->dashboard->connections($accounts, withoutAccounts: !isset($params['accounts']));
        $shares = [];
        foreach ($state['groups'] as $group => $count) {
            $shares[$group] = Chart::percent($count, $state['total']);
        }

        $attention = array_values(array_filter($state['connections'], static fn ($row) => 'ok' !== $row['state']->group()));
        $flagged = array_map(static fn ($row) => spl_object_id($row['connection']), $attention);
        $renewals = array_values(array_filter($state['renewals'], static fn ($connection) => !\in_array(spl_object_id($connection), $flagged, true)));

        return ['state' => $state, 'shares' => $shares, 'listed' => \array_slice($state['connections'], 0, 4), 'renewals' => \array_slice($renewals, 0, 2), 'connections_url' => $this->listUrl(BankConnection::class)];
    }
}
