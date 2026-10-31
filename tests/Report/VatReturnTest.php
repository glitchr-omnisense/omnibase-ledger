<?php

namespace Tests\Base\Ledger\Report;

use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\VatRegime;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Report\VatReturn;
use Tests\Base\Ledger\InMemoryLedger;

/**
 * The VAT return of a month in which a company let commercial premises
 * (20 %), sold a 10 % service and an exempt one, bought supplies (20 %) and
 * a fixture (20 %, fixed asset), acquired goods from Germany (reverse
 * charge), and issued a credit note.
 */
class VatReturnTest extends InMemoryLedger
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['6061' => 'Fournitures', '2135' => 'Agencements', '404' => 'Fournisseurs d\'immobilisations', '7088' => 'Autres produits'] as $number => $label) {
            $this->account((string) $number, $label);
        }
        $this->frenchTaxes();
        $this->book->setVatRegime(VatRegime::NORMAL_MONTHLY);

        $tenant = $this->party('Cabinet Weber', reference: 'COM1');
        $supplier = $this->party('EDF', PartyKind::SUPPLIER);
        $german = $this->party('Bauhaus GmbH', PartyKind::SUPPLIER);
        $date = new \DateTimeImmutable('2026-10-05');
        $post = fn (EntryDraft $draft) => $this->posting()->post($draft);

        // Sales: 1 200,00 + 240,00 VAT; 100,00 + 10,00 at 10 %; 50,00 exempt; a credit note of 100,00 + 20,00.
        $post((new EntryDraft($this->book, 'VT', $date, 'Loyer commercial', 's1'))->taxed('706', 0, 144000, $this->taxes['V20'])->line($tenant->getAccountNumber(), 144000, 0, $tenant));
        $post((new EntryDraft($this->book, 'VT', $date, 'Prestation', 's2'))->taxed('7088', 0, 11000, $this->taxes['V10'])->line($tenant->getAccountNumber(), 11000, 0, $tenant));
        $post((new EntryDraft($this->book, 'VT', $date, 'Exonéré', 's3'))->taxed('7088', 0, 5000, $this->taxes['EXO'])->line($tenant->getAccountNumber(), 5000, 0, $tenant));
        $post((new EntryDraft($this->book, 'VT', $date, 'Avoir', 's4'))->taxed('706', 12000, 0, $this->taxes['V20'])->line($tenant->getAccountNumber(), 0, 12000, $tenant));
        // Purchases: 300,00 + 60,00; a fixture 1 000,00 + 200,00; 500,00 from Germany (VAT 100,00 due and deducted).
        $post((new EntryDraft($this->book, 'AC', $date, 'EDF', 'p1'))->taxed('6061', 36000, 0, $this->taxes['A20'])->line($supplier->getAccountNumber(), 0, 36000, $supplier));
        $post((new EntryDraft($this->book, 'AC', $date, 'Agencement', 'p2'))->taxed('2135', 120000, 0, $this->taxes['I20'])->line('404', 0, 120000));
        $post((new EntryDraft($this->book, 'AC', $date, 'Bauhaus', 'p3'))->taxed('6061', 50000, 0, $this->taxes['AIC20'])->line($german->getAccountNumber(), 0, 50000, $german));
        // Another month: left out.
        $post((new EntryDraft($this->book, 'VT', new \DateTimeImmutable('2026-11-02'), 'Loyer novembre', 's5'))->taxed('706', 0, 144000, $this->taxes['V20'])->line($tenant->getAccountNumber(), 144000, 0, $tenant));
    }

    private function october(): array
    {
        return (new VatReturn($this->lineRepository()))->compute($this->book, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
    }

    public function testTheCa3Boxes(): void
    {
        $report = $this->october();
        $boxes = array_column($report['boxes'], null, 'box');

        self::assertTrue($report['liable']);
        self::assertSame('CA3', $report['form']);
        self::assertSame(['sales' => 120000, 'acquisitions' => 50000, 'exempt' => 5000], $report['operations']);
        self::assertSame(110000 + 10000, $boxes['01']['base'], 'sales before tax: 1 100,00 at 20 % less the credit note, 100,00 at 10 %');
        self::assertSame(['base' => 160000, 'amount' => 32000], ['base' => $boxes['08']['base'], 'amount' => $boxes['08']['amount']], '20 %: the sales less the credit note, and the acquisition');
        self::assertSame(['base' => 10000, 'amount' => 1000], ['base' => $boxes['9B']['base'], 'amount' => $boxes['9B']['amount']]);
        self::assertSame(33000, $boxes['16']['amount'], 'gross VAT');
        self::assertSame(10000, $boxes['17']['amount'], 'of which on intra-EU acquisitions');
        self::assertSame(20000, $boxes['19']['amount'], 'deductible on fixed assets');
        self::assertSame(6000 + 10000, $boxes['20']['amount'], 'deductible on goods and services, the reverse charge with it');
        self::assertSame(36000, $boxes['23']['amount']);
        self::assertSame(3000, $boxes['25']['amount'], 'a credit: more deducted than due');
        self::assertSame(0, $boxes['28']['amount']);
        self::assertSame(['08', '9B'], array_column($report['rates'], 'box'));
    }

    public function testTheCa12TotalsAndTheNetDue(): void
    {
        $this->book->setVatRegime(VatRegime::SIMPLIFIED);
        $report = (new VatReturn($this->lineRepository()))->compute($this->book, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));

        self::assertSame('CA12', $report['form']);
        self::assertSame(['collected' => 57000, 'deductible_goods_services' => 16000, 'deductible_fixed_assets' => 20000, 'deductible_total' => 36000, 'net_due' => 21000, 'credit' => 0], $report['ca12']);
    }

    public function testABookOutsideVatSaysSo(): void
    {
        $this->book->setVatRegime(VatRegime::NONE);
        $report = (new VatReturn($this->lineRepository()))->compute($this->book, new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-31'));

        self::assertFalse($report['liable']);
        self::assertNull($report['form']);
        self::assertSame(0, $report['net_due']);
        self::assertSame([], $report['taxes']);
        self::assertNull(VatRegime::NONE->lastPeriod(new \DateTimeImmutable('2026-10-31')));
    }

    public function testTheLastPeriodOfEachRegime(): void
    {
        $today = new \DateTimeImmutable('2026-10-31');
        self::assertSame(['2026-09-01', '2026-09-30'], array_map(fn ($d) => $d->format('Y-m-d'), VatRegime::NORMAL_MONTHLY->lastPeriod($today)));
        self::assertSame(['2026-07-01', '2026-09-30'], array_map(fn ($d) => $d->format('Y-m-d'), VatRegime::NORMAL_QUARTERLY->lastPeriod($today)));
        self::assertSame(['2025-10-01', '2025-12-31'], array_map(fn ($d) => $d->format('Y-m-d'), VatRegime::NORMAL_QUARTERLY->lastPeriod(new \DateTimeImmutable('2026-01-15'))));
        self::assertSame(['2025-01-01', '2025-12-31'], array_map(fn ($d) => $d->format('Y-m-d'), VatRegime::SIMPLIFIED->lastPeriod($today)));
    }
}
