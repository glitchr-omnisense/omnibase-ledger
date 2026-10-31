<?php

namespace Tests\Base\Ledger\Report;

use Base\Ledger\Dimension\DimensionLabelerInterface;
use Base\Ledger\Dimension\Dimensions;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Report\GeneralLedger;
use Base\Ledger\Report\IncomeStatement;
use Tests\Base\Ledger\InMemoryLedger;

/**
 * Two homes' rents and repairs posted with their unit, a bank fee with
 * none: the income statement of one home, and per home, named by a labeler
 * the way an application names its units.
 */
class DimensionsTest extends InMemoryLedger
{
    private Dimensions $dimensions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dimensions = new Dimensions([new class implements DimensionLabelerInterface {
            public function supports(string $axis): bool
            {
                return 'unit' === $axis;
            }

            public function labels(string $axis, array $values): array
            {
                return array_intersect_key(['3' => 'Appartement Krutenau', '5' => 'Studio Neudorf'], array_flip($values));
            }
        }]);

        $tenant = $this->party('Léa Muller', reference: 'U3');
        $other = $this->party('Paul Kern', reference: 'U5');
        $plumber = $this->party('Plomberie Ott', PartyKind::SUPPLIER);
        $date = new \DateTimeImmutable('2026-10-01');
        $posting = $this->posting();
        foreach ([[$tenant, '3', 85000], [$other, '5', 52000]] as [$party, $unit, $rent]) {
            $posting->post((new EntryDraft($this->book, 'VT', $date, 'Loyer', 'rent:'.$unit))
                ->line($party->getAccountNumber(), $rent, 0, $party, dimensions: ['unit' => $unit])
                ->line('706', 0, $rent, dimensions: ['unit' => $unit]));
        }
        $posting->post((new EntryDraft($this->book, 'AC', $date, 'Fuite', 'work:1'))
            ->line('615', 30000, 0, dimensions: ['unit' => '3'])
            ->line($plumber->getAccountNumber(), 0, 30000, $plumber, dimensions: ['unit' => '3']));
        $posting->post((new EntryDraft($this->book, 'BQ', $date, 'Frais', 'fees:1'))
            ->line('627', 1200, 0)
            ->line('5121', 0, 1200));
    }

    public function testAFilterKeepsOneHomesLines(): void
    {
        $report = (new IncomeStatement($this->lineRepository(), $this->dimensions))->compute($this->book, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), false, ['unit' => '3']);

        self::assertSame(['income' => 85000, 'expenses' => 30000, 'result' => 55000], $report['totals']);

        $ledger = (new GeneralLedger($this->lineRepository(), $this->dimensions))->compute($this->book, null, null, '6', ['unit' => '3']);
        self::assertSame(['615'], array_column($ledger, 'number'));
    }

    public function testTheResultPerHomeNamedByTheLabeler(): void
    {
        $report = (new IncomeStatement($this->lineRepository(), $this->dimensions))->byDimension($this->book, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), 'unit');

        self::assertSame('unit', $report['axis']);
        self::assertSame([['3', 'Appartement Krutenau', 55000], ['5', 'Studio Neudorf', 52000], [null, null, -1200]], array_map(fn (array $group) => [$group['value'], $group['label'], $group['report']['totals']['result']], $report['groups']));
        self::assertSame(['income' => 137000, 'expenses' => 31200, 'result' => 105800], $report['totals'], 'the whole: the groups add up');

        $groups = (new GeneralLedger($this->lineRepository(), $this->dimensions))->byDimension($this->book, null, null, 'unit', '7');
        self::assertSame([['Appartement Krutenau', 85000], ['Studio Neudorf', 52000]], array_map(fn (array $group) => [$group['label'], $group['accounts'][0]['credit']], $groups));
        self::assertSame(['unit' => 2], $this->lineRepository()->axes($this->book));
    }

    public function testAValueNobodyNamesIsItsOwnLabel(): void
    {
        self::assertSame(['9' => '9', '3' => 'Appartement Krutenau'], $this->dimensions->labels('unit', ['9', '3', null]));
        self::assertSame(['1' => '1'], (new Dimensions())->labels('owner', ['1']));
    }
}
