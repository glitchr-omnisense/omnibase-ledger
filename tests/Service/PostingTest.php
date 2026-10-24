<?php

namespace Tests\Base\Ledger\Service;

use Base\Ledger\Entity\FiscalYear;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Exception\LockedPeriodException;
use Base\Ledger\Exception\PostingException;
use Base\Ledger\Model\EntryDraft;
use Tests\Base\Ledger\InMemoryLedger;

class PostingTest extends InMemoryLedger
{
    private function draft(string $source = 'notice:1', string $date = '2026-10-01', string $journal = 'VT'): EntryDraft
    {
        return new EntryDraft($this->book, $journal, new \DateTimeImmutable($date), 'Loyer octobre', $source, 'AVIS-2026-10-U3');
    }

    public function testABalancedDraftIsPostedValidatedAndNumbered(): void
    {
        $tenant = $this->party('Jean Dupont', reference: 'U3');
        $entry = $this->posting()->post($this->draft()->line('411U3', 85000, 0, $tenant)->line('706', 0, 85000));

        self::assertTrue($entry->isValidated());
        self::assertSame('2026-000001', $entry->getNumber());
        self::assertSame('VT', $entry->getJournal()->getCode());
        self::assertSame('AVIS-2026-10-U3', $entry->getPiece());
        self::assertCount(2, $entry->getLines());
        self::assertSame(85000, $entry->getTotalDebit());
        self::assertSame($tenant, $entry->getLines()->first()->getParty());

        $next = $this->posting()->post($this->draft('notice:2')->line('411U3', 1000, 0, $tenant)->line('706', 0, 1000));
        self::assertSame('2026-000002', $next->getNumber());
    }

    public function testNumbersRunPerFiscalYearNamedByItsClosingYear(): void
    {
        $this->fiscalYears[] = new FiscalYear($this->book, new \DateTimeImmutable('2025-07-01'), new \DateTimeImmutable('2026-06-30'));
        $entry = $this->posting()->post($this->draft('a', '2025-12-01')->line('512', 100, 0)->line('706', 0, 100));

        self::assertSame('2026-000001', $entry->getNumber());
    }

    public function testADraftIsNotNumberedUntilValidated(): void
    {
        $posting = $this->posting();
        $entry = $posting->post($this->draft()->line('512', 100, 0)->line('706', 0, 100), false);
        self::assertFalse($entry->isValidated());
        self::assertNull($entry->getNumber());

        $posting->validate($entry);
        self::assertSame('2026-000001', $entry->getNumber());
    }

    public function testTheSameSourceTwiceGivesTheSameEntry(): void
    {
        $first = $this->posting()->post($this->draft()->line('512', 100, 0)->line('706', 0, 100));
        $again = $this->posting()->post($this->draft()->line('512', 999, 0)->line('706', 0, 999));

        self::assertSame($first, $again);
        self::assertCount(1, $this->entries);
        self::assertSame(100, $again->getTotalDebit());
    }

    /** @return iterable<string, array{0: callable(EntryDraft): EntryDraft, 1: string}> */
    public static function refusals(): iterable
    {
        yield 'unbalanced' => [fn (EntryDraft $d) => $d->line('512', 100, 0)->line('706', 0, 90), 'not balanced'];
        yield 'negative' => [fn (EntryDraft $d) => $d->line('512', -100, 0)->line('706', -100, 0), 'never negative'];
        yield 'both sides' => [fn (EntryDraft $d) => $d->line('512', 100, 100)->line('706', 0, 0), 'not both'];
        yield 'empty line' => [fn (EntryDraft $d) => $d->line('512', 100, 0)->line('706', 0, 100)->line('708', 0, 0), 'no amount'];
        yield 'one line' => [fn (EntryDraft $d) => $d->line('512', 0, 0), 'at least two lines'];
        yield 'unknown account' => [fn (EntryDraft $d) => $d->line('512', 100, 0)->line('7061', 0, 100), 'no account 7061'];
    }

    /** @dataProvider refusals */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testInvalidDraftsAreRefusedBeforeAnythingIsWritten(callable $build, string $message): void
    {
        try {
            $this->posting()->post($build($this->draft()));
            self::fail('Posted an invalid draft.');
        } catch (PostingException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame([], $this->entries);
    }

    public function testAnUnknownJournalIsRefused(): void
    {
        $this->expectException(PostingException::class);
        $this->posting()->post($this->draft(journal: 'XX')->line('512', 100, 0)->line('706', 0, 100));
    }

    public function testASubAccountIsCreatedUnderItsParentOnlyWhenAutoAccountsIsOn(): void
    {
        $entry = $this->posting(autoAccounts: true)->post($this->draft()->line('512', 100, 0)->line('7061', 0, 100, null, 'Loyer parking'));

        $account = $entry->getLines()->last()->getAccount();
        self::assertSame('7061', $account->getNumber());
        self::assertSame(AccountType::INCOME, $account->getType());
        self::assertSame('Loyer parking', $account->getLabel());
        self::assertSame($account, $this->accounts['7061']);
    }

    public function testNothingIsPostedInTheLockedPeriodNorAClosedYear(): void
    {
        $this->book->setLockedUntil(new \DateTimeImmutable('2026-09-30'));
        try {
            $this->posting()->post($this->draft(date: '2026-09-30')->line('512', 100, 0)->line('706', 0, 100));
            self::fail('Posted in the locked period.');
        } catch (LockedPeriodException) {
        }
        self::assertTrue($this->posting()->post($this->draft(date: '2026-10-01')->line('512', 100, 0)->line('706', 0, 100))->isValidated());

        $this->fiscalYears[] = (new FiscalYear($this->book, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-12-31')))->setClosedAt(new \DateTimeImmutable());
        $this->expectException(LockedPeriodException::class);
        $this->posting()->post($this->draft('b', '2027-03-01')->line('512', 100, 0)->line('706', 0, 100));
    }

    public function testAReversalMirrorsTheEntryOnce(): void
    {
        $tenant = $this->party('Jean Dupont', reference: 'U3');
        $entry = $this->posting()->post($this->draft()->line('411U3', 85000, 0, $tenant)->line('706', 0, 85000));

        $reversal = $this->posting()->reverse($entry, 'cancel:1');
        self::assertSame($entry, $reversal->getReversalOf());
        self::assertEquals($entry->getDate(), $reversal->getDate());
        self::assertSame([0, 85000], [$reversal->getLines()->first()->getDebit(), $reversal->getLines()->first()->getCredit()]);
        self::assertSame($tenant, $reversal->getLines()->first()->getParty());
        self::assertSame([85000, 0], [$reversal->getLines()->last()->getDebit(), $reversal->getLines()->last()->getCredit()]);
        self::assertSame('2026-000002', $reversal->getNumber());

        self::assertSame($reversal, $this->posting()->reverse($entry, 'cancel:1'));
        $this->expectException(PostingException::class);
        $this->posting()->reverse($entry, 'cancel:2');
    }
}
