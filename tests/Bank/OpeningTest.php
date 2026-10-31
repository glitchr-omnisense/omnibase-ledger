<?php

namespace Tests\Base\Ledger\Bank;

use Base\Ledger\Bank\Connections;
use Base\Ledger\Bank\Opening;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankConnection;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankConnectionRepository;
use Base\Ledger\Repository\BankLineRepository;
use Base\Ledger\Repository\EntryLineRepository;
use Base\Ledger\Security\StateCipher;
use Omnibank\Model\Account as BankAccountModel;
use Omnibank\Model\AccountKind;
use Tests\Base\Ledger\InMemoryLedger;

/**
 * The opening entry that makes the books hold what the bank says, and the
 * ledger account a bank account belongs under (a loan on 164, not 512).
 */
class OpeningTest extends InMemoryLedger
{
    /** @var list<array{bookedOn: \DateTimeImmutable, amount: int, category: ?string, counterpartyName: ?string, counterpartyIban: ?string, label: string}> */
    private array $movements = [];

    private function opening(): Opening
    {
        $lines = $this->createStub(BankLineRepository::class);
        $lines->method('movements')->willReturnCallback(fn () => $this->movements);

        return new Opening($this->entityManager(), $this->posting(), $lines, $this->entryRepository(), $this->accountRepository(), $this->journalRepository());
    }

    private function move(string $date, int $amount): void
    {
        $this->movements[] = ['bookedOn' => new \DateTimeImmutable($date), 'amount' => $amount, 'category' => null, 'counterpartyName' => null, 'counterpartyIban' => null, 'label' => 'x'];
        usort($this->movements, static fn ($a, $b) => $a['bookedOn'] <=> $b['bookedOn']);
    }

    public function testTheBanksBalanceLessTheLinesTheDayBeforeTheFirst(): void
    {
        $account = $this->bankAccount();
        $account->setBankBalance(770499, new \DateTimeImmutable('2026-10-30 08:00'));
        $this->move('2026-09-02', -238000);
        $this->move('2026-10-15', 120000);
        $this->move('2026-10-31', -5000); // after the balance's date: not in it

        $entry = $this->opening()->open($account);

        self::assertNotNull($entry);
        self::assertSame('2026-09-01', $entry->getDate()->format('Y-m-d'));
        self::assertSame('AN', $entry->getJournal()->getCode(), 'the à-nouveaux journal, created');
        self::assertTrue($entry->isValidated());
        self::assertSame('bank:opening:1', $entry->getSource());
        $lines = [];
        foreach ($entry->getLines() as $line) {
            $lines[$line->getAccount()->getNumber()] = [$line->getDebit(), $line->getCredit()];
        }
        self::assertSame(['5121' => [888499, 0], '890' => [0, 888499]], $lines, '7 704,99 - (-2 380,00 + 1 200,00) on the bank, against 890, created');
        self::assertSame(888499, $this->opening()->amountOf($entry, $account));
    }

    public function testOncePerAccountThenAdjustedWhenOlderLinesCome(): void
    {
        $account = $this->bankAccount();
        $account->setBankBalance(100000, new \DateTimeImmutable('2026-10-30'));
        $this->move('2026-10-01', 30000);
        $first = $this->opening()->open($account);
        self::assertSame($first, $this->opening()->open($account), 'idempotent');
        self::assertSame($first, $this->opening()->adjust($account), 'still right: kept');
        $count = \count($this->entries);

        // An older line imported since: the opening was too high by it.
        $this->move('2026-08-10', 10000);
        $second = $this->opening()->adjust($account);

        self::assertNotSame($first, $second);
        self::assertSame('bank:opening:1/2', $second->getSource());
        self::assertSame('2026-08-09', $second->getDate()->format('Y-m-d'));
        self::assertSame(60000, $this->opening()->amountOf($second, $account));
        self::assertCount($count + 2, $this->entries, 'the first reversed, a second posted');
        self::assertSame($second, $this->opening()->current($account));
    }

    public function testNoBalanceNoEntryAndADebtOnTheOtherSide(): void
    {
        $account = $this->bankAccount();
        self::assertNull($this->opening()->open($account), 'the bank gave no balance');

        $account->setBankBalance(-50000, new \DateTimeImmutable('2026-10-30'));
        $entry = $this->opening()->open($account);
        $sides = [];
        foreach ($entry->getLines() as $line) {
            $sides[$line->getAccount()->getNumber()] = [$line->getDebit(), $line->getCredit()];
        }
        self::assertSame(['890' => [50000, 0], '5121' => [0, 50000]], $sides, 'no line: the balance itself, on its day');
        self::assertSame('2026-10-30', $entry->getDate()->format('Y-m-d'));
    }

    /** Entry lines that answer balance() from the entries kept in memory. */
    private function entryLines(): EntryLineRepository
    {
        $repository = $this->createStub(EntryLineRepository::class);
        $repository->method('balance')->willReturnCallback(function (\Base\Ledger\Entity\Account $account) {
            $balance = 0;
            foreach ($this->allLines() as $line) {
                if ($line->getAccount() === $account) {
                    $balance += $line->getDebit() - $line->getCredit();
                }
            }

            return $balance;
        });

        return $repository;
    }

    private function connections(?BankAccountRepository $bankAccounts = null): Connections
    {
        return new Connections($this->entityManager(), new StateCipher('s'), $this->accountRepository(), $this->journalRepository(),
            $bankAccounts ?? $this->createStub(BankAccountRepository::class), $this->createStub(BankConnectionRepository::class), null, $this->entryLines());
    }

    public function testACreditPostsOn164AndARevolvingOn519(): void
    {
        self::assertSame(['164', 'Emprunt'], Connections::ledgerPrefix(AccountKind::LOAN));
        self::assertSame('164', Connections::ledgerPrefix(AccountKind::CONSUMER_CREDIT)[0]);
        self::assertSame('519', Connections::ledgerPrefix(AccountKind::REVOLVING)[0]);
        self::assertSame('512', Connections::ledgerPrefix(AccountKind::CARD)[0]);

        $connection = new BankConnection($this->book, 'powens');
        $loan = $this->connections()->attach($connection, new BankAccountModel('L1', null, null, 'Prêt immobilier', 'EUR', 'loan', [], AccountKind::LOAN));
        self::assertSame('1641', $loan->getAccount()->getNumber());
        self::assertSame(AccountKind::LOAN, $loan->getKind());
        self::assertSame('loan', $loan->getProviderType());
    }

    public function testAnAccountMovesUnderItsKindWithWhatItHolds(): void
    {
        $this->account('5124', 'Banque - Prêt');
        $account = new BankAccount(new BankConnection($this->book, 'files'), 'P1', 'Prêt immobilier', $this->accounts['5124'], $this->journals['BQ']);
        self::withId($account, 4);
        $account->setKind(AccountKind::LOAN);
        // The loan's balance sat on 5124: -1 000,00.
        $this->posting()->post((new \Base\Ledger\Model\EntryDraft($this->book, 'OD', new \DateTimeImmutable('2026-10-01'), 'x', 'x'))->line('5124', 0, 100000)->line('455', 100000, 0));

        $moved = $this->connections()->reclassify($account, $this->posting());

        self::assertSame('1641', $moved->getNumber());
        self::assertSame($moved, $account->getAccount());
        $reclass = end($this->entries);
        self::assertSame('bank:reclass:4:1641', $reclass->getSource());
        self::assertSame(0, $this->entryLines()->balance($this->accounts['5124']), 'nothing left on 512x');
        self::assertSame(-100000, $this->entryLines()->balance($moved));
        self::assertNull($this->connections()->reclassify($account, $this->posting()), 'already where it belongs');
    }
}
