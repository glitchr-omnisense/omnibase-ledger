<?php

namespace Tests\Base\Ledger\Bank;

use Base\Ledger\Bank\Dashboard;
use Omnibank\Model\AccountKind;

/** What the bank cards show, read from the precomputed rows. */
class DashboardTest extends InMemoryDashboard
{
    public function testPeriodsAndTheirBuckets(): void
    {
        $today = new \DateTimeImmutable('2026-10-30');
        self::assertSame('2026-10-21', Dashboard::period('10d', '1m', $today)['from']->format('Y-m-d'));
        self::assertSame('2025-11-01', Dashboard::period('12m', '1m', $today)['from']->format('Y-m-d'));
        self::assertSame('month', Dashboard::period('6m', '1m', $today)['bucket']);
        self::assertSame('1m', Dashboard::period('nonsense', '1m', $today)['key'], 'an unknown period falls back on the card\'s');

        $dashboard = $this->dashboard();
        $checking = [$this->bankAccount()];
        $buckets = $dashboard->flows($checking, Dashboard::period('12m'));
        self::assertCount(12, $buckets, 'twelve months');
        self::assertSame(2500000, end($buckets)['balance'], 'the last bucket ends on today\'s balance');
        $net = array_sum(array_map(static fn ($b) => $b['in'] - $b['out'], \array_slice($buckets, 1)));
        self::assertSame(end($buckets)['balance'] - $buckets[0]['balance'], $net, 'in minus out is the balance\'s change');
        self::assertCount(13, $dashboard->flows($checking, Dashboard::period('3m')), 'weeks');
    }

    public function testAccountsTheReaderPicksAndCashCards(): void
    {
        $dashboard = $this->dashboard();
        self::assertCount(3, $dashboard->accounts([])['shown']);
        self::assertCount(2, $dashboard->accounts([], true)['shown'], 'the loan left out of a card about cash');
        self::assertSame([3], array_map(static fn ($a) => $a->getId(), $dashboard->accounts(['accounts' => [3]], true)['shown']), 'picked, the reader\'s choice stands');
        self::assertCount(3, $dashboard->accounts(['accounts' => [999]])['shown'], 'an account gone: all of them');
    }

    public function testKindsConnectionsReconciliationFreshness(): void
    {
        $dashboard = $this->dashboard();
        $kinds = $dashboard->byKind($this->bankAccounts);
        self::assertSame([AccountKind::CHECKING, AccountKind::LOAN, AccountKind::SAVINGS], array_map(static fn ($k) => $k['kind'], $kinds));
        self::assertSame(-16900000, $kinds[1]['balance']);

        $connections = $dashboard->connections($this->bankAccounts);
        self::assertSame(['ok' => 1, 'action' => 1, 'unavailable' => 0, 'error' => 0], $connections['groups']);
        self::assertSame([], $connections['abandoned'], 'with the accounts only, no unfinished connection');

        $all = $dashboard->connections($this->bankAccounts, withoutAccounts: true);
        self::assertSame(['ok' => 1, 'action' => 2, 'unavailable' => 0, 'error' => 0], $all['groups'], 'the page opened an hour ago waits for the user; the one left three days ago is not counted');
        self::assertSame(3, $all['total']);
        self::assertSame([3], array_map(static fn ($c) => $c->getId(), $all['abandoned']));
        self::assertTrue($all['abandoned'][0]->isAbandoned());
        self::assertFalse($this->connectionsList[3]->isAbandoned(), 'less than 24 hours');
        self::assertTrue($this->connectionsList[3]->isUnfinished());
        self::assertFalse($this->connectionsList[1]->isUnfinished(), 'a consent given, accounts');
        self::assertSame('Crédit Mutuel', (string) $connections['connections'][0]['connection'], 'the one to act on first');

        $state = $dashboard->reconciliation([$this->bankAccount()]);
        self::assertSame(\count($this->bankLines), $state['pending'], 'nothing reconciled yet');
        self::assertSame($this->bankAccount(), $state['first']);

        $fresh = $dashboard->freshness($this->bankAccounts, $this->today()->setTime(12, 0));
        self::assertSame(['Prêt immobilier', 'Livret A', 'Compte courant'], array_map(static fn ($r) => $r['account']->getName(), $fresh), 'stale first: never synced, then 10 days without a sync');
        self::assertTrue($fresh[1]['stale']);
        self::assertFalse(end($fresh)['stale']);
    }

    public function testOnlyAnUnfinishedConnectionIsRemoved(): void
    {
        $removed = [];
        $em = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(function (object $entity) use (&$removed): void { $removed[] = $entity; });
        $connections = new \Base\Ledger\Bank\Connections($em, new \Base\Ledger\Security\StateCipher('s'), $this->accountRepository(), $this->journalRepository(),
            $this->createStub(\Base\Ledger\Repository\BankAccountRepository::class), $this->createStub(\Base\Ledger\Repository\BankConnectionRepository::class));

        $connections->remove($this->connectionsList[2]);
        self::assertSame([$this->connectionsList[2]], $removed);

        $this->expectException(\Base\Ledger\Exception\BankException::class);
        $connections->remove($this->bankAccount()->getConnection());
    }

    public function testCategoriesCounterpartiesTransfersForecast(): void
    {
        $dashboard = $this->dashboard();
        $checking = [$this->bankAccount()];
        $period = Dashboard::period('12m');
        $categories = $dashboard->byCategory($checking, $period['from'], $period['to']);
        self::assertSame('loan', $categories[0]['category'], 'the largest outflow first');
        self::assertTrue(Dashboard::hasCategories($categories));

        $top = $dashboard->topCounterparties($checking, $period['from'], $period['to']);
        self::assertSame('Thomas Weber', $top['in'][0]['name']);
        self::assertSame('Crédit Mutuel', $top['out'][0]['name']);

        $transfers = $dashboard->transfers($checking);
        self::assertGreaterThan(0, $transfers['previous']['in'] + $transfers['previous']['out'], 'last month: the rent by transfer, the saving');

        $forecast = $dashboard->forecast($checking, 90);
        self::assertSame(2500000, $forecast['start']);
        self::assertSame([30, 60, 90], array_keys($forecast['horizons']));
        self::assertGreaterThan(0, $forecast['flows']);
        self::assertCount(91, $forecast['series']);
        // Three months of the rent in, the instalment, the fees and the saving out.
        self::assertSame(2500000 + 3 * (160000 - 126500 - 1250 - 50000), $forecast['horizons'][90] ?? null, 'each flow three times in 90 days');
    }
}
