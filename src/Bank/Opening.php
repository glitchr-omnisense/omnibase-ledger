<?php

namespace Base\Ledger\Bank;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\Journal;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\BankLineRepository;
use Base\Ledger\Repository\EntryRepository;
use Base\Ledger\Repository\JournalRepository;
use Base\Ledger\Service\Posting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A bank account's opening entry: a connected account only brings its
 * recent lines, so what came before them is written once - the bank's
 * balance less the lines imported up to its date, dated the day before the
 * first line (the balance's own day when there is no line), on the bank
 * account's ledger account against the opening account (890, Bilan
 * d'ouverture) in the à-nouveaux journal (AN). Its lines then reconciled,
 * the books hold what the bank says. Posted once per account (its source,
 * "bank:opening:<id>"); adjust() replaces one the bank's balance no longer
 * agrees with (a reversal, then a new one, "bank:opening:<id>/2"...).
 */
class Opening
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Posting $posting,
        private readonly BankLineRepository $lines,
        private readonly EntryRepository $entries,
        private readonly AccountRepository $accounts,
        private readonly JournalRepository $journals,
        #[Autowire('%ledger.bank.opening_account%')] private readonly string $openingAccount = '890',
        #[Autowire('%ledger.bank.opening_journal%')] private readonly string $openingJournal = 'AN',
    ) {
    }

    /**
     * What the opening entry should be: its amount (signed, positive when the
     * account held money: a debit on it) and its date; null without a bank
     * balance.
     *
     * @return array{amount: int, date: \DateTimeImmutable}|null
     */
    public function compute(BankAccount $account): ?array
    {
        $balance = $account->getBankBalance();
        if (null === $balance) {
            return null;
        }
        $at = ($account->getBankBalanceAt() ?? new \DateTimeImmutable())->setTime(0, 0);
        $first = null;
        $imported = 0;
        foreach ($this->lines->movements($account) as $line) {
            $first ??= $line['bookedOn'];
            if ($line['bookedOn'] <= $at) {
                $imported += $line['amount'];
            }
        }

        return ['amount' => $balance - $imported, 'date' => null === $first ? $at : $first->modify('-1 day')];
    }

    /** The account's current opening entry (the last of its sources not reversed), or null. */
    public function current(BankAccount $account): ?Entry
    {
        $found = null;
        for ($n = 1; null !== ($entry = $this->entries->findOneBySource($account->getBook(), self::source($account, $n))); ++$n) {
            $found = $entry;
        }

        return $found;
    }

    /** Posts the opening entry when the account has none and the bank gave a balance; returns it (null when none is due: no balance, or nothing before the lines). */
    public function open(BankAccount $account): ?Entry
    {
        if (null !== ($current = $this->current($account))) {
            return $current;
        }
        $opening = $this->compute($account);
        if (null === $opening || 0 === $opening['amount']) {
            return null;
        }

        return $this->post($account, $opening, 1);
    }

    /**
     * Like open(), and replaces an opening entry that no longer says what the
     * bank's balance and the lines say (older lines imported since, a balance
     * corrected): reversed on its own date, then posted again.
     */
    public function adjust(BankAccount $account): ?Entry
    {
        $current = $this->current($account);
        if (null === $current) {
            return $this->open($account);
        }
        $opening = $this->compute($account);
        if (null === $opening || ($this->amountOf($current, $account) === $opening['amount'] && $current->getDate()->format('Y-m-d') === $opening['date']->format('Y-m-d'))) {
            return $current;
        }
        $n = 1;
        while (null !== $this->entries->findOneBySource($account->getBook(), self::source($account, $n + 1))) {
            ++$n;
        }
        if ($current->isValidated()) {
            $this->posting->reverse($current, self::source($account, $n).':reversal', $current->getDate());
        } else {
            $this->entityManager->remove($current);
            $this->entityManager->flush();
        }
        if (0 === $opening['amount']) {
            return null;
        }

        return $this->post($account, $opening, $n + 1);
    }

    /** What an opening entry puts on the bank account's ledger account: debit less credit. */
    public function amountOf(Entry $entry, BankAccount $account): int
    {
        $amount = 0;
        foreach ($entry->getLines() as $line) {
            if ($line->getAccount() === $account->getAccount() || $line->getAccount()->getNumber() === $account->getAccount()->getNumber()) {
                $amount += $line->getDebit() - $line->getCredit();
            }
        }

        return $amount;
    }

    public static function source(BankAccount $account, int $n = 1): string
    {
        return 'bank:opening:'.$account->getId().(1 === $n ? '' : '/'.$n);
    }

    /** @param array{amount: int, date: \DateTimeImmutable} $opening */
    private function post(BankAccount $account, array $opening, int $n): Entry
    {
        $book = $account->getBook();
        $this->ensure($account);
        $own = $account->getAccount()->getNumber();
        $amount = $opening['amount'];
        $draft = new EntryDraft($book, $this->openingJournal, $opening['date'], sprintf('Solde d\'ouverture - %s', $account->getName()), self::source($account, $n));
        $draft = $amount > 0
            ? $draft->line($own, $amount, 0)->line($this->openingAccount, 0, $amount)
            : $draft->line($this->openingAccount, -$amount, 0)->line($own, 0, -$amount);

        return $this->posting->post($draft);
    }

    /** The opening account and journal, created when the book has none. */
    private function ensure(BankAccount $account): void
    {
        $book = $account->getBook();
        $created = false;
        if (null === $this->accounts->findOneByNumber($book, $this->openingAccount)) {
            $this->entityManager->persist(new Account($book, $this->openingAccount, 'Bilan d\'ouverture', AccountType::EQUITY));
            $created = true;
        }
        if (null === $this->journals->findOneByCode($book, $this->openingJournal)) {
            $this->entityManager->persist(new Journal($book, $this->openingJournal, Journal::OPENING === $this->openingJournal ? 'À-nouveaux' : $this->openingJournal));
            $created = true;
        }
        if ($created) {
            $this->entityManager->flush();
        }
    }
}
