<?php

namespace Tests\Base\Ledger\Report;

use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Report\BalanceSheet;
use Base\Ledger\Report\IncomeStatement;
use Base\Ledger\Report\TrialBalance;
use Tests\Base\Ledger\InMemoryLedger;

/**
 * The compte de résultat and the bilan of an SCI over two years, posted
 * through Posting as the application would: 2025 buys the building with
 * the associates' money and a loan and lets it for a year; 2026 is a full
 * year of rents, charges, taxe foncière, insurance, a repair still unpaid,
 * the loan, bank fees, an accountant's draft invoice and depreciation. No
 * closing entry: 2025's result is still in its accounts in 2026.
 */
class StatementsTest extends InMemoryLedger
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['101' => 'Capital social', '211' => 'Terrains', '213' => 'Constructions', '2813' => 'Amortissements des constructions',
            '6226' => 'Honoraires', '6231' => 'Annonces et insertions', '63512' => 'Taxes foncières', '616' => 'Primes d\'assurance',
            '681' => 'Dotations aux amortissements', '7083' => 'Locations diverses (parkings)', '768' => 'Autres produits financiers'] as $number => $label) {
            $this->account((string) $number, $label);
        }
        $this->twoYears();
    }

    public function testTheIncomeStatementGroupsAnSciYear(): void
    {
        $report = $this->incomeStatement()->compute($this->book, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));

        self::assertSame(['rents' => 1032000, 'recovered_charges' => 60000, 'other_income' => 1500], array_column($report['income'], 'total', 'key'));
        self::assertSame([
            'rental_charges' => 0, 'repairs' => 60000, 'insurance' => 45000, 'fees' => 36000, 'taxes' => 120000,
            'interest' => 300000, 'depreciation' => 440000, 'bank_fees' => 14400, 'other_expenses' => 9000,
        ], array_column($report['expenses'], 'total', 'key'));
        self::assertSame(['706', '7083'], array_column($report['income']['rents']['lines'], 'number'));
        self::assertSame(['63512'], array_column($report['expenses']['taxes']['lines'], 'number'));
        self::assertSame([], $report['expenses']['rental_charges']['lines']);
        self::assertNull($report['previous']);
        self::assertNull($report['income']['rents']['previous']);

        // Résultat = produits - charges, and the trial balance agrees.
        self::assertSame(['income' => 1093500, 'expenses' => 1024400, 'result' => 69100], $report['totals']);
        self::assertSame(69100, TrialBalance::fromTotals($this->lineRepository()->totalsByAccount($this->book, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')))['result']);
    }

    public function testAnAccountOutsideEveryGroupLandsInOthers(): void
    {
        $report = $this->incomeStatement()->compute($this->book, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));

        self::assertSame([['number' => '6231', 'label' => 'Annonces et insertions', 'amount' => 9000, 'previous' => null]], $report['expenses']['other_expenses']['lines']);
        self::assertSame(['768'], array_column($report['income']['other_income']['lines'], 'number'));

        // The longest prefix wins: 7083 is a rent, 7088 an other income, 708 recovered charges.
        $report = IncomeStatement::fromTotals([
            ['number' => '7083', 'label' => 'Parkings', 'type' => 'income', 'debit' => 0, 'credit' => 100],
            ['number' => '7088', 'label' => 'Divers', 'type' => 'income', 'debit' => 0, 'credit' => 200],
            ['number' => '7084', 'label' => 'Charges', 'type' => 'income', 'debit' => 0, 'credit' => 400],
            ['number' => '695', 'label' => 'Impôt', 'type' => 'expense', 'debit' => 800, 'credit' => 0],
        ]);
        self::assertSame(['rents' => 100, 'recovered_charges' => 400, 'other_income' => 200], array_column($report['income'], 'total', 'key'));
        self::assertSame(800, $report['expenses']['other_expenses']['total']);
        self::assertSame(-100, $report['totals']['result']);
    }

    public function testTheIncomeStatementPutsTheYearBeforeBesideIt(): void
    {
        $report = $this->incomeStatement()->compute($this->book, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), true);

        self::assertSame(['income' => 1020000, 'expenses' => 740000, 'result' => 280000], $report['previous']);
        self::assertSame(69100, $report['totals']['result']);
        self::assertSame(1020000, $report['income']['rents']['previous']);
        self::assertSame(0, $report['income']['recovered_charges']['previous']);
        self::assertSame([['number' => '706', 'label' => 'Loyers', 'amount' => 960000, 'previous' => 1020000], ['number' => '7083', 'label' => 'Locations diverses (parkings)', 'amount' => 72000, 'previous' => 0]], $report['income']['rents']['lines']);
        self::assertSame(['amount' => 440000, 'previous' => 440000], array_intersect_key($report['expenses']['depreciation']['lines'][0], ['amount' => 0, 'previous' => 0]));
    }

    public function testTheYearBefore(): void
    {
        self::assertSame('2025-01-01', IncomeStatement::yearBefore(new \DateTimeImmutable('2026-01-01'))->format('Y-m-d'));
        self::assertSame('2027-02-28', IncomeStatement::yearBefore(new \DateTimeImmutable('2028-02-29'))->format('Y-m-d'));
        self::assertSame('2028-02-29', IncomeStatement::yearBefore(new \DateTimeImmutable('2029-02-28'))->format('Y-m-d'));
        self::assertSame('2025-06-30', IncomeStatement::yearBefore(new \DateTimeImmutable('2026-06-30'))->format('Y-m-d'));
    }

    public function testTheBalanceSheetBalancesWithTheYearsResult(): void
    {
        $report = $this->balanceSheet()->compute($this->book, new \DateTimeImmutable('2026-12-31'), true);

        self::assertTrue($report['balanced']);
        self::assertSame(['assets' => 25805100, 'liabilities' => 25805100, 'result' => 69100], $report['totals']);
        self::assertSame(['assets' => 27280000, 'liabilities' => 27280000, 'result' => 280000], $report['previous']);

        self::assertSame(['fixed_assets' => 25120000, 'receivables' => 91000, 'other_assets' => 0, 'cash' => 594100], array_column($report['assets'], 'total', 'key'));
        self::assertSame(['211' => 4000000, '213' => 22000000, '2813' => -880000], array_column($report['assets']['fixed_assets']['lines'], 'amount', 'number'));
        self::assertSame(['equity' => 1349100, 'loans' => 18200000, 'deposits' => 160000, 'suppliers' => 96000, 'associates' => 6000000, 'other_liabilities' => 0], array_column($report['liabilities'], 'total', 'key'));

        // The capitaux propres: the capital, 2025's result left in its accounts, and 2026's.
        self::assertSame([
            ['number' => '101', 'label' => 'Capital social', 'amount' => 1000000, 'previous' => 1000000],
            ['number' => null, 'label' => BalanceSheet::EARLIER_RESULTS, 'amount' => 280000, 'previous' => 0],
            ['number' => null, 'label' => BalanceSheet::RESULT, 'amount' => 69100, 'previous' => 280000],
        ], $report['liabilities']['equity']['lines']);

        // The supplier paid in full (the Trésor public) is not on it; the two unpaid ones are.
        self::assertSame(['401EXPERT', '401MULLER'], array_column($report['liabilities']['suppliers']['lines'], 'number'));
    }

    public function testTheBalanceSheetBalancesAtAnyDate(): void
    {
        foreach (['2025-01-10', '2025-06-30', '2026-03-31', '2026-10-15'] as $day) {
            $report = $this->balanceSheet()->compute($this->book, new \DateTimeImmutable($day), true);
            self::assertTrue($report['balanced'], $day);
            self::assertSame($report['totals']['assets'], $report['totals']['liabilities'], $day);
        }
    }

    public function testBalancesGoToTheSideTheySitOn(): void
    {
        $report = BalanceSheet::fromTotals([
            ['number' => '411U3', 'label' => 'Dupont', 'type' => 'asset', 'debit' => 85000, 'credit' => 0],
            ['number' => '411U4', 'label' => 'Martin (trop-perçu)', 'type' => 'asset', 'debit' => 0, 'credit' => 5000],
            ['number' => '409', 'label' => 'Acompte fournisseur', 'type' => 'asset', 'debit' => 12000, 'credit' => 0],
            ['number' => '455M1', 'label' => 'A. Martin', 'type' => 'liability', 'debit' => 3000, 'credit' => 0],
            ['number' => '512', 'label' => 'Banque (découvert)', 'type' => 'asset', 'debit' => 0, 'credit' => 40000],
            ['number' => '706', 'label' => 'Loyers', 'type' => 'income', 'debit' => 0, 'credit' => 55000],
        ]);

        self::assertSame(['411U3'], array_column($report['assets']['receivables']['lines'], 'number'));
        self::assertSame(['409', '455M1'], array_column($report['assets']['other_assets']['lines'], 'number'));
        self::assertSame(['411U4', '512'], array_column($report['liabilities']['other_liabilities']['lines'], 'number'));
        self::assertSame(55000, $report['totals']['result']);
        self::assertSame(100000, $report['totals']['assets']);
        self::assertTrue($report['balanced']);
    }

    private function incomeStatement(): IncomeStatement
    {
        return new IncomeStatement($this->lineRepository());
    }

    private function balanceSheet(): BalanceSheet
    {
        return new BalanceSheet($this->lineRepository(), $this->fiscalYearRepository());
    }

    private function post(string $journal, string $date, string $label, array $lines, bool $validate = true): void
    {
        $draft = new EntryDraft($this->book, $journal, new \DateTimeImmutable($date), $label, 'test:'.\count($this->entries));
        foreach ($lines as [$account, $debit, $credit]) {
            $draft->line($account, $debit, $credit, $this->partyOf($account));
        }
        $this->posting()->post($draft, $validate);
    }

    private function partyOf(string $account): ?\Base\Ledger\Entity\Party
    {
        foreach ($this->parties as $party) {
            if ($party->getAccountNumber() === $account) {
                return $party;
            }
        }

        return null;
    }

    private function twoYears(): void
    {
        $this->party('Jean Dupont', reference: 'U3');
        $this->party('Alex Martin', PartyKind::ASSOCIATE, 'M1');
        $this->party('Plomberie Muller', PartyKind::SUPPLIER, 'MULLER');
        $this->party('Cabinet comptable', PartyKind::SUPPLIER, 'EXPERT');
        $this->party('Trésor public', PartyKind::SUPPLIER, 'TRESOR');

        // 2025: the building, bought with the associates' money and a loan, let for a year.
        $this->post('OD', '2025-01-10', 'Apports des associés', [['512', 7000000, 0], ['101', 0, 1000000], ['455M1', 0, 6000000]]);
        $this->post('BQ', '2025-01-15', 'Déblocage du prêt', [['512', 20000000, 0], ['164', 0, 20000000]]);
        $this->post('OD', '2025-01-20', 'Acquisition de l\'immeuble', [['211', 4000000, 0], ['213', 22000000, 0], ['512', 0, 26000000]]);
        $this->post('VT', '2025-06-30', 'Loyers 2025', [['411U3', 1020000, 0], ['706', 0, 1020000]]);
        $this->post('BQ', '2025-07-05', 'Loyers 2025 encaissés', [['512', 1020000, 0], ['411U3', 0, 1020000]]);
        $this->post('BQ', '2025-12-15', 'Intérêts 2025', [['6611', 300000, 0], ['512', 0, 300000]]);
        $this->post('OD', '2025-12-31', 'Dotation 2025', [['681', 440000, 0], ['2813', 0, 440000]]);

        // 2026: a full year.
        $this->post('BQ', '2026-01-05', 'Dépôt de garantie Dupont', [['512', 160000, 0], ['165', 0, 160000]]);
        for ($month = 1; $month <= 12; ++$month) {
            $day = sprintf('2026-%02d-01', $month);
            $this->post('VT', $day, 'Avis d\'échéance', [['411U3', 91000, 0], ['706', 0, 80000], ['7083', 0, 6000], ['708', 0, 5000]]);
            if ($month < 12) { // December's rent is still owed at year end
                $this->post('BQ', sprintf('2026-%02d-05', $month), 'Loyer encaissé', [['512', 91000, 0], ['411U3', 0, 91000]]);
            }
            $this->post('BQ', sprintf('2026-%02d-10', $month), 'Échéance de prêt', [['164', 150000, 0], ['6611', 25000, 0], ['512', 0, 175000]]);
            $this->post('BQ', sprintf('2026-%02d-28', $month), 'Frais de tenue de compte', [['627', 1200, 0], ['512', 0, 1200]]);
        }
        $this->post('BQ', '2026-02-15', 'Assurance PNO', [['616', 45000, 0], ['512', 0, 45000]]);
        $this->post('BQ', '2026-03-20', 'Annonce de location', [['6231', 9000, 0], ['512', 0, 9000]]);
        $this->post('BQ', '2026-06-30', 'Intérêts du livret', [['512', 1500, 0], ['768', 0, 1500]]);
        $this->post('AC', '2026-09-15', 'Taxe foncière 2026', [['63512', 120000, 0], ['401TRESOR', 0, 120000]]);
        $this->post('BQ', '2026-10-15', 'Taxe foncière payée', [['401TRESOR', 120000, 0], ['512', 0, 120000]]);
        $this->post('AC', '2026-11-20', 'Réparation fuite', [['615', 60000, 0], ['401MULLER', 0, 60000]]);
        $this->post('AC', '2026-12-20', 'Honoraires comptables', [['6226', 36000, 0], ['401EXPERT', 0, 36000]], validate: false);
        $this->post('OD', '2026-12-31', 'Dotation 2026', [['681', 440000, 0], ['2813', 0, 440000]]);
    }
}
