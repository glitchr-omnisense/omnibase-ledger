<?php

namespace Tests\Base\Ledger\Bank;

use Base\Ledger\Bank\Aggregator;
use Base\Ledger\Bank\Counterparty;
use Base\Ledger\Entity\BankDayCategory;
use Base\Ledger\Entity\BankRecurring;
use Omnibank\Model\TransactionCategory;

/**
 * The precomputation: a bank account's lines folded into days, categories,
 * counterparties and recurring flows, as Bank\Aggregates writes them.
 */
class AggregatesTest extends InMemoryDashboard
{
    public function testEachDayInOutCountsAndTheClosingBalanceAnchoredOnTheBanks(): void
    {
        $days = array_values(array_filter($this->days, fn ($d) => $d->getBankAccount() === $this->bankAccount()));
        $last = end($days);
        self::assertSame(2500000, $last->getClosingBalance(), 'the bank said 25 000,00 € yesterday, no line since');

        $byDay = [];
        foreach ($days as $day) {
            $byDay[$day->getDay()->format('Y-m-d')] = $day;
        }
        $works = $byDay[$this->today()->modify('-6 days')->format('Y-m-d')];
        self::assertSame(235000, $works->getOutflow());
        self::assertSame(1, $works->getOutflowCount());
        self::assertSame(235000, $works->getTransferOut(), 'a line with the counterparty\'s IBAN is a transfer');
        self::assertSame($works->getClosingBalance() + 235000, $works->getOpeningBalance());

        // Each day's closing follows from the one before.
        for ($i = 1; $i < \count($days); ++$i) {
            self::assertSame($days[$i - 1]->getClosingBalance() + $days[$i]->getNet(), $days[$i]->getClosingBalance());
        }
    }

    public function testWithoutABankBalanceTheLinesStartFromZero(): void
    {
        $account = $this->bankAccount();
        $account->setBankBalance(null);
        $rows = Aggregator::compute($account, [
            ['bookedOn' => new \DateTimeImmutable('2026-01-02'), 'amount' => 1000, 'category' => null, 'counterpartyName' => null, 'counterpartyIban' => null, 'label' => 'A'],
            ['bookedOn' => new \DateTimeImmutable('2026-01-01'), 'amount' => -300, 'category' => null, 'counterpartyName' => null, 'counterpartyIban' => null, 'label' => 'B'],
        ], new \DateTimeImmutable('2026-01-10'));

        self::assertSame([-300, 700], array_map(static fn ($d) => $d->getClosingBalance(), $rows['days']), 'oldest first');
        self::assertSame([BankDayCategory::NONE, BankDayCategory::NONE], array_map(static fn ($c) => $c->getCategory(), $rows['categories']));
    }

    public function testCategoriesAndCounterpartiesPerDayAndMonth(): void
    {
        $loan = array_filter($this->dayCategories, static fn ($c) => TransactionCategory::LOAN->value === $c->getCategory());
        self::assertGreaterThanOrEqual(11, \count($loan));
        self::assertSame([126500], array_values(array_unique(array_map(static fn ($c) => $c->getOutflow(), $loan))));
        self::assertSame(1, array_values($loan)[0]->getOutflowCount());

        $weber = array_filter($this->counterpartyMonths, static fn ($c) => 'Thomas Weber' === $c->getName());
        self::assertGreaterThanOrEqual(11, \count($weber), 'one row a month');
        self::assertSame(160000, array_values($weber)[0]->getInflow());
        self::assertSame('n:CREDIT MUTUEL', Counterparty::key('Crédit Mutuel', null, 'x'));
        self::assertSame('i:FR761234', Counterparty::key(null, 'FR761234', 'VIR'));
        self::assertSame('l:EDF PARTIES COMMUNES', Counterparty::key(null, null, 'PRLV SEPA EDF PARTIES COMMUNES 12 ORPHELINS'));
        self::assertSame('Edf Parties Communes', Counterparty::name(null, 'PRLV SEPA EDF PARTIES COMMUNES 12 ORPHELINS'));
    }

    public function testRecurringFlowsTheirRhythmAndNextDate(): void
    {
        $flows = [];
        foreach ($this->recurringFlows as $flow) {
            $flows[$flow->getLabel()] = $flow;
        }

        self::assertArrayHasKey('Thomas Weber', $flows);
        self::assertSame(BankRecurring::MONTHLY, $flows['Thomas Weber']->getRhythm());
        self::assertSame(160000, $flows['Thomas Weber']->getAmount());
        self::assertSame(TransactionCategory::INCOME, $flows['Thomas Weber']->getCategory());
        self::assertSame('04', $flows['Thomas Weber']->getNextOn()->format('d'), 'the 4th of the month, as it came');
        self::assertGreaterThan($this->today()->modify('-7 days'), $flows['Thomas Weber']->getNextOn());

        // One counterparty, two flows: the bank's instalment and its fees, told apart by their amount and named by their label.
        self::assertArrayHasKey('Pret Immobilier', $flows);
        self::assertArrayHasKey('Frais Tenue Compte', $flows);
        self::assertSame(-126500, $flows['Pret Immobilier']->getAmount());
        self::assertSame(-1250, $flows['Frais Tenue Compte']->getAmount());

        self::assertArrayNotHasKey('Old Software', $flows, 'stopped in the spring: no longer recurring');
        self::assertArrayNotHasKey('Plomberie Kieffer', $flows, 'once is not a rhythm');

        $next = $flows['Pret Immobilier']->occurrencesBetween($this->today(), $this->today()->modify('+90 days'));
        self::assertCount(3, $next, 'three instalments in 90 days');
        self::assertSame(BankRecurring::step(new \DateTimeImmutable('2026-01-31'), BankRecurring::MONTHLY)->format('Y-m-d'), '2026-02-28', 'a 31st falls on the month\'s last day');
    }
}
