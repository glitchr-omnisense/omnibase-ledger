<?php

namespace Tests\Base\Ledger\Bank;

use Base\Ledger\Bank\Aggregator;
use Base\Ledger\Bank\Dashboard;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankConnection;
use Base\Ledger\Entity\BankCounterpartyMonth;
use Base\Ledger\Entity\BankDay;
use Base\Ledger\Entity\BankDayCategory;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Entity\BankRecurring;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankConnectionRepository;
use Base\Ledger\Repository\BankCounterpartyMonthRepository;
use Base\Ledger\Repository\BankDayCategoryRepository;
use Base\Ledger\Repository\BankDayRepository;
use Base\Ledger\Repository\BankLineRepository;
use Base\Ledger\Repository\BankRecurringRepository;
use Base\Ledger\Repository\BookRepository;
use Omnibank\Model\AccountKind;
use Omnibank\Model\TransactionCategory;
use Tests\Base\Ledger\InMemoryLedger;

/**
 * A year of a property company's banking in memory - a current account
 * (rents, the loan's instalment, fees, a works invoice), a Livret A and a
 * mortgage -, folded by the Aggregator into the rows a database would keep,
 * and the Dashboard reading them through repositories answering from arrays.
 * Every date is relative to today, as the cards' periods are.
 */
abstract class InMemoryDashboard extends InMemoryLedger
{
    /** @var list<BankAccount> */
    protected array $bankAccounts = [];
    /** @var list<BankDay> */
    protected array $days = [];
    /** @var list<BankDayCategory> */
    protected array $dayCategories = [];
    /** @var list<BankCounterpartyMonth> */
    protected array $counterpartyMonths = [];
    /** @var list<BankRecurring> */
    protected array $recurringFlows = [];
    /** @var list<BankConnection> */
    protected array $connectionsList = [];

    protected function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $checking = $this->bankAccount()->setKind(AccountKind::CHECKING);
        $checking->setBankBalance(2500000, $this->today()->modify('-1 day 06:00'));
        $checking->setLastSyncedAt($this->today()->modify('-1 day 06:00'));
        $checking->getConnection()->setConnectionState(\Omnibank\Model\ConnectionState::OK);

        $cmConnection = self::withId(new BankConnection($this->book, 'powens', 'Crédit Mutuel'), 2);
        $cmConnection->setConsentStatus(\Base\Ledger\Enum\ConsentState::ACTIVE)->setConnectionState(\Omnibank\Model\ConnectionState::ACTION_NEEDED);
        $livret = self::withId(new BankAccount($cmConnection, 'L1', 'Livret A', $this->account('5122', 'Banque - Livret A'), $this->journals['BQ']), 2);
        $livret->setKind(AccountKind::SAVINGS)->setBankBalance(1520000, $this->today()->modify('-20 days'));
        $livret->setLastSyncedAt($this->today()->modify('-10 days'));
        $loan = self::withId(new BankAccount($cmConnection, 'P1', 'Prêt immobilier', $this->account('5123', 'Banque - Prêt'), $this->journals['BQ']), 3);
        $loan->setKind(AccountKind::LOAN)->setBankBalance(-16900000, $this->today()->modify('-20 days'));
        $this->bankAccounts = [$checking, $livret, $loan];
        $this->connectionsList = [$checking->getConnection(), $cmConnection];

        $lines = [];
        for ($m = 11; $m >= 0; --$m) {
            $month = $this->today()->modify('first day of this month')->modify("-$m months");
            foreach ([
                [3, 1600_00, 'VIR SEPA WEBER THOMAS LOYER', 'Thomas Weber', 'FR7610278000000000000000001', TransactionCategory::INCOME],
                [9, -1265_00, 'ECHEANCE PRET IMMOBILIER 00412', 'Crédit Mutuel', null, TransactionCategory::LOAN],
                [10, -12_50, 'FRAIS TENUE DE COMPTE', 'Crédit Mutuel', null, TransactionCategory::BANK_FEES],
                [14, -500_00, 'VIR LIVRET A', 'Meyers', 'FR7610278010000000000067890', TransactionCategory::TRANSFER],
            ] as [$day, $amount, $label, $name, $iban, $category]) {
                if ($month->modify("+$day days") <= $this->today()) {
                    $lines[] = $this->line($amount, $label, $name, $iban, $category, $month->modify("+$day days"));
                }
            }
        }
        // A one-off, and a subscription that stopped in the spring.
        $lines[] = $this->line(-2350_00, 'VIR PLOMBERIE KIEFFER FA-2026-118', 'Plomberie Kieffer', 'FR7617806000000000000000777', null, $this->today()->modify('-6 days'));
        foreach ([9, 8, 7, 6] as $ago) {
            $lines[] = $this->line(-29_99, 'CB OLD SOFTWARE', null, null, TransactionCategory::SUBSCRIPTIONS, $this->today()->modify('first day of this month')->modify("-$ago months +19 days"));
        }
        $this->fold($checking, $lines);
    }

    /** @return array{bookedOn: \DateTimeImmutable, amount: int, category: ?string, counterpartyName: ?string, counterpartyIban: ?string, label: string} */
    protected function line(int $amount, string $label, ?string $name, ?string $iban, ?TransactionCategory $category, \DateTimeImmutable $on): array
    {
        $line = $this->bankLine($amount, $label, $iban, $name, null, $on->format('Y-m-d'));
        $line->setCategory($category);

        return ['bookedOn' => $line->getBookedOn(), 'amount' => $amount, 'category' => $category?->value, 'counterpartyName' => $name, 'counterpartyIban' => $line->getCounterpartyIban(), 'label' => $label];
    }

    /** The Aggregator's rows for $account's $lines, kept as a database would. */
    protected function fold(BankAccount $account, array $lines): void
    {
        $rows = Aggregator::compute($account, $lines, $this->today());
        $this->days = [...array_filter($this->days, static fn ($r) => $r->getBankAccount() !== $account), ...$rows['days']];
        $this->dayCategories = [...$this->dayCategories, ...$rows['categories']];
        $this->counterpartyMonths = [...$this->counterpartyMonths, ...$rows['counterparties']];
        $this->recurringFlows = [...$this->recurringFlows, ...$rows['recurring']];
    }

    protected function dashboard(): Dashboard
    {
        $books = $this->createStub(BookRepository::class);
        $books->method('findBy')->willReturn([$this->book]);
        $accounts = $this->createStub(BankAccountRepository::class);
        $accounts->method('forBook')->willReturnCallback(fn () => $this->bankAccounts);
        $within = static fn (array $rows, array $in, \DateTimeImmutable $from, \DateTimeImmutable $to, string $field) => array_values(array_filter($rows, static function ($row) use ($in, $from, $to, $field) {
            $day = 'month' === $field ? $row->getMonth() : $row->getDay();

            return \in_array($row->getBankAccount(), $in, true) && $day >= ('month' === $field ? $from->modify('first day of this month') : $from->setTime(0, 0)) && $day <= $to->setTime(0, 0);
        }));
        $days = $this->createStub(BankDayRepository::class);
        $days->method('forAccounts')->willReturnCallback(fn (array $in, $from, $to) => $within($this->days, $in, $from, $to, 'day'));
        $days->method('lastBefore')->willReturnCallback(function (BankAccount $account, \DateTimeImmutable $day) {
            $found = null;
            foreach ($this->days as $row) {
                if ($row->getBankAccount() === $account && $row->getDay() < $day->setTime(0, 0)) {
                    $found = $row;
                }
            }

            return $found;
        });
        $days->method('latest')->willReturnCallback(function (BankAccount $account) {
            $rows = array_values(array_filter($this->days, static fn ($r) => $r->getBankAccount() === $account));

            return [] === $rows ? null : end($rows);
        });
        $categories = $this->createStub(BankDayCategoryRepository::class);
        $categories->method('forAccounts')->willReturnCallback(fn (array $in, $from, $to) => $within($this->dayCategories, $in, $from, $to, 'day'));
        $counterparties = $this->createStub(BankCounterpartyMonthRepository::class);
        $counterparties->method('forAccounts')->willReturnCallback(fn (array $in, $from, $to) => $within($this->counterpartyMonths, $in, $from, $to, 'month'));
        $recurring = $this->createStub(BankRecurringRepository::class);
        $recurring->method('forAccounts')->willReturnCallback(fn (array $in) => array_values(array_filter($this->recurringFlows, static fn ($r) => \in_array($r->getBankAccount(), $in, true))));
        $lines = $this->createStub(BankLineRepository::class);
        $lines->method('countByStatus')->willReturnCallback(function (BankAccount $account) {
            $counts = [];
            foreach ($this->bankLines as $line) {
                if ($line->getBankAccount() === $account) {
                    $counts[$line->getStatus()->value] = ($counts[$line->getStatus()->value] ?? 0) + 1;
                }
            }

            return $counts;
        });
        $lines->method('largest')->willReturnCallback(function (array $in, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit) {
            $rows = array_values(array_filter($this->bankLines, static fn (BankLine $l) => \in_array($l->getBankAccount(), $in, true) && $l->getBookedOn() >= $from && $l->getBookedOn() <= $to));
            usort($rows, static fn ($a, $b) => abs($b->getAmount()) <=> abs($a->getAmount()));

            return \array_slice($rows, 0, $limit);
        });
        $connections = $this->createStub(BankConnectionRepository::class);
        $connections->method('findBy')->willReturnCallback(fn () => $this->connectionsList);

        return new Dashboard($books, $accounts, $lines, $days, $categories, $counterparties, $recurring, null, $connections);
    }
}
