<?php

namespace Tests\Base\Ledger\Service;

use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\RuleSign;
use Base\Ledger\Exception\PostingException;
use Base\Ledger\Model\EntryDraft;
use Tests\Base\Ledger\InMemoryLedger;

/**
 * Taxed amounts through EntryDraft::taxed() and Posting, as an application
 * posts a supplier's invoice or a sale: the base and its VAT on their
 * accounts, rounded to the cent half up, the base taking the remainder; the
 * VAT line knows its tax and its base; a reversal and a reconciliation rule
 * carry them too.
 */
class TaxedPostingTest extends InMemoryLedger
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->account('6061', 'Fournitures non stockables');
        $this->account('2135', 'Agencements');
        $this->account('404', 'Fournisseurs d\'immobilisations');
        $this->frenchTaxes();
    }

    /** @return array<string, array{debit: int, credit: int, tax: ?string, base: ?int}> by account */
    private static function lines(iterable $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            /* @var EntryLine $line */
            $rows[$line->getAccount()->getNumber()] = ['debit' => $line->getDebit(), 'credit' => $line->getCredit(), 'tax' => $line->getTax()?->getCode(), 'base' => $line->getTaxBase()];
        }

        return $rows;
    }

    public function testATaxedPurchaseSplitsAndRounds(): void
    {
        $edf = $this->party('EDF', PartyKind::SUPPLIER);

        // 99,99 all taxes included at 20 %: VAT 16,665 rounds up to 16,67, the base takes the rest, 83,32.
        $entry = $this->posting()->post((new EntryDraft($this->book, 'AC', new \DateTimeImmutable('2026-10-12'), 'Facture EDF octobre', 'invoice:EDF-10', 'EDF-10'))
            ->taxed('6061', 9999, 0, $this->taxes['A20'])
            ->line($edf->getAccountNumber(), 0, 9999, $edf));

        self::assertTrue($entry->isBalanced());
        self::assertSame([
            '6061' => ['debit' => 8332, 'credit' => 0, 'tax' => null, 'base' => null],
            '44566' => ['debit' => 1667, 'credit' => 0, 'tax' => 'A20', 'base' => 8332],
            $edf->getAccountNumber() => ['debit' => 0, 'credit' => 9999, 'tax' => null, 'base' => null],
        ], self::lines($entry->getLines()));

        // 5,5 %: 10,55 all taxes included is 10,00 and 0,55; 10,00 before tax gives 0,55 more.
        self::assertSame([1000, 55], $this->taxes['A55']->split(1055));
        self::assertSame([1000, 55], $this->taxes['A55']->split(1000, false));
        // To the nearest cent: 12 cents before tax at 20 % give 2,4 → 2 cents of VAT, 13 give 2,6 → 3; 1,25 € at 10 % gives 12,5 → 13 (half up).
        self::assertSame([125, 13], $this->taxes['V10']->split(125, false));
        self::assertSame([12, 2], $this->taxes['V20']->split(12, false));
        self::assertSame([13, 3], $this->taxes['V20']->split(13, false));
        self::assertSame([1000, 0], $this->taxes['EXO']->split(1000));
    }

    public function testBeforeTaxTheVatIsAddedAndASaleIsOnTheCreditSide(): void
    {
        $tenant = $this->party('Cabinet Weber', reference: 'COM1');
        $draft = (new EntryDraft($this->book, 'VT', new \DateTimeImmutable('2026-10-01'), 'Loyer commercial octobre', 'notice:COM1-10'))
            ->taxed('706', 0, 120000, $this->taxes['V20'], false, dimensions: ['unit' => '7']);
        $draft->line($tenant->getAccountNumber(), $draft->getTotalCredit(), 0, $tenant, dimensions: ['unit' => '7']);
        $entry = $this->posting()->post($draft);

        self::assertSame([
            '706' => ['debit' => 0, 'credit' => 120000, 'tax' => null, 'base' => null],
            '44571' => ['debit' => 0, 'credit' => 24000, 'tax' => 'V20', 'base' => 120000],
            $tenant->getAccountNumber() => ['debit' => 144000, 'credit' => 0, 'tax' => null, 'base' => null],
        ], self::lines($entry->getLines()));
        foreach ($entry->getLines() as $line) {
            self::assertSame(['unit' => '7'], $line->getDimensions(), 'the dimensions go on every line');
        }
    }

    public function testAReverseChargeIsDueAndDeductedAtOnce(): void
    {
        $supplier = $this->party('Bauhaus GmbH', PartyKind::SUPPLIER);
        $entry = $this->posting()->post((new EntryDraft($this->book, 'AC', new \DateTimeImmutable('2026-10-20'), 'Agencements Bauhaus', 'invoice:BAU-1'))
            ->taxed('2135', 50000, 0, $this->taxes['AIC20'])
            ->line($supplier->getAccountNumber(), 0, 50000, $supplier));

        self::assertSame([
            '2135' => ['debit' => 50000, 'credit' => 0, 'tax' => null, 'base' => null],
            '44566' => ['debit' => 10000, 'credit' => 0, 'tax' => 'AIC20', 'base' => 50000],
            '4452' => ['debit' => 0, 'credit' => 10000, 'tax' => 'AIC20', 'base' => 50000],
            $supplier->getAccountNumber() => ['debit' => 0, 'credit' => 50000, 'tax' => null, 'base' => null],
        ], self::lines($entry->getLines()));
    }

    public function testATaxIsNamedByItsCodeAndAnUnknownCodeIsRefused(): void
    {
        $entry = $this->posting()->post((new EntryDraft($this->book, 'OD', new \DateTimeImmutable('2026-10-01'), 'TVA à la main', 'od:1'))
            ->line('44566', 2000, 0, tax: 'a20', taxBase: 10000)
            ->line('615', 10000, 0)
            ->line('401', 0, 12000));
        self::assertSame('A20', $entry->getLines()[0]->getTax()->getCode());

        $this->expectException(PostingException::class);
        $this->expectExceptionMessage('has no tax X99');
        $this->posting()->post((new EntryDraft($this->book, 'OD', new \DateTimeImmutable('2026-10-01'), 'Code inconnu', 'od:2'))
            ->line('44566', 2000, 0, tax: 'X99', taxBase: 10000)
            ->line('401', 0, 2000));
    }

    public function testAnAxisMustBeAKey(): void
    {
        $this->expectException(PostingException::class);
        $this->posting()->post((new EntryDraft($this->book, 'OD', new \DateTimeImmutable('2026-10-01'), 'Axe invalide', 'od:3'))
            ->line('615', 100, 0, dimensions: ['Mon axe' => '1'])
            ->line('401', 0, 100));
    }

    public function testAReversalCarriesTheTaxAndTheDimensions(): void
    {
        $edf = $this->party('EDF', PartyKind::SUPPLIER);
        $entry = $this->posting()->post((new EntryDraft($this->book, 'AC', new \DateTimeImmutable('2026-10-12'), 'Facture EDF', 'invoice:EDF-11'))
            ->taxed('6061', 12000, 0, $this->taxes['A20'], dimensions: ['unit' => '3'])
            ->line($edf->getAccountNumber(), 0, 12000, $edf, dimensions: ['unit' => '3']));
        $reversal = $this->posting()->reverse($entry, 'reversal:EDF-11');

        $vat = self::lines($reversal->getLines())['44566'];
        self::assertSame(['debit' => 0, 'credit' => 2000, 'tax' => 'A20', 'base' => 10000], $vat);
        foreach ($reversal->getLines() as $line) {
            self::assertSame(['unit' => '3'], $line->getDimensions());
        }
    }

    public function testARuleSplitPartWithATaxCodePostsItsVat(): void
    {
        $rule = new ReconciliationRule($this->book, 'Électricité', 'PRLV EDF', $this->accounts['6061'], RuleSign::OUT);
        $rule->setSplit([['account' => '6061', 'rest' => true, 'tax' => 'A20']]);
        $this->rules[] = $this->persisted($rule);
        $line = $this->bankLine(-8400, 'PRLV SEPA EDF CLIENTS PARTICULIERS');

        $suggestions = $this->matcher()->suggest($line, []);
        $entry = $this->reconciler()->apply($line, $suggestions[0]);

        self::assertSame([
            '5121' => ['debit' => 0, 'credit' => 8400, 'tax' => null, 'base' => null],
            '6061' => ['debit' => 7000, 'credit' => 0, 'tax' => null, 'base' => null],
            '44566' => ['debit' => 1400, 'credit' => 0, 'tax' => 'A20', 'base' => 7000],
        ], self::lines($entry->getLines()));
        self::assertSame([['6061', 8400, null, 'A20']], $this->reconciler()->parts($rule, 8400));
        self::assertSame([['6061', 8400, null]], $this->reconciler()->split($rule, 8400), 'split() unchanged');
    }
}
