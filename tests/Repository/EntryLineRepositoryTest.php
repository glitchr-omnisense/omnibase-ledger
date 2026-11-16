<?php

namespace Tests\Base\Ledger\Repository;

use Base\Database\Repository\Finders;
use Base\Testing\Engines;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\EntryLineDimension;
use Base\Ledger\Entity\Journal;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Repository\EntryLineRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EntryLineRepository's queries (the books' reports, written once in the language) give the same answers on both
 * engines, from the same SQLite rows - in the style of omnibridge/eloquent's RepositoryParityTest. Each engine's answer is compared with the expected one, so both agree.
 */
final class EntryLineRepositoryTest extends TestCase
{
    private const CLASSES = [Book::class, Journal::class, Account::class, Party::class, Tax::class, Entry::class, EntryLine::class, EntryLineDimension::class];

    private static Engines $engines;
    /** @var array<string, int> */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to run both engines.');
        }
        try {
            self::$engines = Engines::of(self::CLASSES);
        } catch (\Error $e) {
            self::markTestSkipped('omnibridge/doctrine and omnibridge/eloquent are needed to run both engines ('.$e->getMessage().').');
        }

        $book = new Book('Livre');
        $journal = new Journal($book, 'BQ', 'Banque');
        $bank = new Account($book, '512', 'Banque', AccountType::ASSET);
        $fees = new Account($book, '627', 'Services bancaires', AccountType::EXPENSE);
        $energy = new Account($book, '6061', 'Energie', AccountType::EXPENSE);
        $rents = new Account($book, '706', 'Loyers', AccountType::INCOME);
        $vat = new Account($book, '44571', 'TVA collectee', AccountType::LIABILITY);
        $deductible = new Account($book, '44566', 'TVA deductible', AccountType::ASSET);
        $supplier = new Party($book, PartyKind::SUPPLIER, 'EDF', '401EDF', 'EDF');
        $tax = new Tax($book, 'V20', 'TVA 20 %', 2000, TaxKind::COLLECTED, $vat, $deductible);

        $paid = new Entry($book, $journal, new \DateTimeImmutable('2026-10-05'), 'Frais', 'bank:1:fees', 'P1');
        new EntryLine($paid, $fees, 1200, 0, null, null, null, null, ['site' => 'A']);
        (new EntryLine($paid, $bank, 0, 1200))->setLetter('AAB');
        $energyBill = new Entry($book, $journal, new \DateTimeImmutable('2026-10-08'), 'EDF', 'bank:2:edf', 'P2');
        new EntryLine($energyBill, $energy, 5000, 0, $supplier, null, null, null, ['site' => 'A', 'lot' => '3']);
        (new EntryLine($energyBill, $bank, 0, 5000, $supplier))->setLetter('AAAA');
        $rent = new Entry($book, $journal, new \DateTimeImmutable('2026-10-12'), 'Loyer', 'notice:3', 'P3');
        new EntryLine($rent, $bank, 12000, 0);
        new EntryLine($rent, $rents, 0, 10000, null, null, $tax, 10000, ['site' => 'B']);
        new EntryLine($rent, $vat, 0, 2000, null, null, $tax, 10000);
        $undo = new Entry($book, $journal, new \DateTimeImmutable('2026-10-20'), 'Annulation', 'bank:2:edf:undo', 'P4');
        new EntryLine($undo, $bank, 5000, 0);
        new EntryLine($undo, $energy, 0, 5000, $supplier);
        $undo->setReversalOf($energyBill);
        $paid->validate('2026-0001');
        $energyBill->validate('2026-0002');
        self::$engines->seed($book, $journal, $bank, $fees, $energy, $rents, $vat, $deductible, $supplier, $tax, $paid, $energyBill, $rent, $undo);
        self::$ids = ['book' => (int) $book->getId(), 'supplier' => (int) $supplier->getId()];
    }

    /** @return array<string, array{0: string}> */
    public static function backends(): array
    {
        return Engines::backends();
    }

    private function finders(string $backend): Finders
    {
        return self::$engines->finders($backend);
    }

    /** @return array{0: EntryLineRepository, 1: Book, 2: Party} */
    private function repository(string $backend): array
    {
        $finders = $this->finders($backend);
        $lines = new EntryLineRepository($finders);

        return [$lines, $finders->of(Book::class)->find(self::$ids['book']), $finders->of(Party::class)->find(self::$ids['supplier'])];
    }

    #[DataProvider('backends')]
    public function testTheCountsAndTheLastLetter(string $backend): void
    {
        [$lines, $book, $supplier] = $this->repository($backend);

        self::assertSame(['512' => 2, '627' => 1, '6061' => 1], self::sorted($lines->bankUsage($book, new \DateTimeImmutable('2026-10-01'))), 'the bank entries, their undoing left out');
        self::assertSame([], $lines->bankUsage($book, new \DateTimeImmutable('2026-11-01')));
        self::assertSame(['6061' => 1], $lines->accountsWith($book, $supplier), 'the other lines of the party\'s entries, under 6 and 2, reversals left out');
        self::assertSame('AAAA', $lines->lastLetter($book), 'the longest, then the greatest');
        self::assertSame(['lot' => 1, 'site' => 2], $lines->axes($book));
    }

    #[DataProvider('backends')]
    public function testTheTotals(string $backend): void
    {
        [$lines, $book] = $this->repository($backend);
        $from = new \DateTimeImmutable('2026-10-01');
        $to = new \DateTimeImmutable('2026-10-31');

        $totals = $lines->totalsByAccount($book, $from, $to);
        self::assertSame(['44571', '512', '6061', '627', '706'], array_column($totals, 'number'));
        self::assertSame([['debit' => 17000, 'credit' => 6200]], array_values(array_map(static fn (array $t) => ['debit' => $t['debit'], 'credit' => $t['credit']], array_filter($totals, static fn (array $t) => '512' === $t['number']))));
        self::assertSame(AccountType::ASSET, $totals[1]['type'], 'the type as its enum');
        self::assertSame(['512', '6061', '627'], array_column($lines->totalsByAccount($book, $from, $to, true), 'number'), 'validated entries only');
        self::assertSame(['6061', '627'], array_column($lines->totalsByAccount($book, $from, $to, false, ['site' => 'A']), 'number'), 'one dimension');
        self::assertSame(['6061'], array_column($lines->totalsByAccount($book, $from, $to, false, ['site' => 'A', 'lot' => '3']), 'number'), 'every dimension given');
        $bySite = array_map(static fn (array $t): string => $t['number'].'/'.($t['dimension'] ?? '-'), $lines->totalsByAccount($book, $from, $to, false, [], 'site'));
        sort($bySite);
        self::assertSame(['44571/-', '512/-', '6061/-', '6061/A', '627/A', '706/B'], $bySite, 'per value of an axis, null for the lines without it');
        $lotsOfSiteA = array_map(static fn (array $t): string => $t['number'].'/'.($t['dimension'] ?? '-'), $lines->totalsByAccount($book, $from, $to, false, ['site' => 'A'], 'lot'));
        sort($lotsOfSiteA);
        self::assertSame(['6061/3', '627/-'], $lotsOfSiteA, 'the lines carrying a dimension, per value of another axis (the income statement by axis)');

        $vat = $lines->taxTotals($book, $from, $to);
        self::assertCount(2, $vat);
        self::assertSame(['V20', '44571', 0, 2000, 0, 10000, TaxKind::COLLECTED, '44571', '44566'], [$vat[0]['code'], $vat[0]['account'], $vat[0]['debit'], $vat[0]['credit'], $vat[0]['base_debit'], $vat[0]['base_credit'], $vat[0]['kind'], $vat[0]['tax_account'], $vat[0]['deductible_account']]);
        self::assertSame(['V20', '706', 2000], [$vat[1]['code'], $vat[1]['account'], $vat[1]['rate']]);
    }

    #[DataProvider('backends')]
    public function testTheGeneralLedgersLines(string $backend): void
    {
        [$lines, $book] = $this->repository($backend);
        $read = static fn (array $found): array => array_map(static fn (EntryLine $line): string => $line->getAccount()->getNumber().':'.$line->getEntry()->getDate()->format('m-d'), $found);

        self::assertSame(['512:10-05', '512:10-08', '512:10-12', '512:10-20'], $read($lines->forLedger($book, null, null, '51')));
        self::assertSame(['6061:10-08', '627:10-05'], $read($lines->forLedger($book, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), null, ['site' => 'A'])));
        self::assertSame([], $lines->forLedger($book, new \DateTimeImmutable('2026-11-01')));
    }

    /** @param array<string, int> $usage the counts by account, equal counts in the account's order */
    private static function sorted(array $usage): array
    {
        uksort($usage, static fn (string $a, string $b) => [-$usage[$a], $a] <=> [-$usage[$b], $b]);

        return $usage;
    }
}
