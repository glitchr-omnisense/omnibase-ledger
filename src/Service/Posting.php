<?php

namespace Base\Ledger\Service;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\EntryLineDimension;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Exception\LockedPeriodException;
use Base\Ledger\Exception\PostingException;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\EntryRepository;
use Base\Ledger\Repository\FiscalYearRepository;
use Base\Ledger\Repository\JournalRepository;
use Base\Ledger\Repository\TaxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * The one way entries get into a book. post() checks what double-entry
 * bookkeeping requires - balanced, one-sided non-negative lines, accounts
 * and a journal the book has, a date outside the locked period - and
 * refuses anything else with a PostingException, before writing a thing.
 * The same (book, source) twice gives back the entry already posted.
 * A line's tax code is the book's (TaxRepository), its dimensions valid
 * axes and values (EntryLineDimension).
 *
 * Validated, an entry is numbered "<fiscal year>-<000001>", in sequence per
 * book and fiscal year, and frozen (ValidatedEntryGuard): it is corrected
 * by reverse(), which posts its mirror image.
 */
class Posting
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accounts,
        private readonly JournalRepository $journals,
        private readonly EntryRepository $entries,
        private readonly FiscalYearRepository $fiscalYears,
        #[Autowire('%ledger.auto_accounts%')] private readonly bool $autoAccounts = false,
        private readonly ?LockFactory $locks = null,
        private readonly ?TaxRepository $taxes = null,
    ) {
    }

    /**
     * @throws PostingException      the draft is not a valid entry of its book
     * @throws LockedPeriodException its date is in the locked period or a closed fiscal year
     */
    public function post(EntryDraft $draft, bool $validate = true): Entry
    {
        return $this->record($draft, $validate);
    }

    /**
     * Cancels a validated entry by its mirror image (debits and credits
     * swapped), dated $date or the entry's own date, and returns it. The
     * same $source twice gives back the reversal already posted.
     */
    public function reverse(Entry $entry, string $source, ?\DateTimeImmutable $date = null): Entry
    {
        if (null !== ($existing = $this->entries->findOneBySource($entry->getBook(), $source))) {
            return $existing;
        }
        if (!$entry->isValidated()) {
            throw new PostingException(sprintf('Entry "%s" is a draft: change or delete it rather than reversing it.', $entry->getLabel()));
        }
        if (null !== $entry->getId() && null !== $this->entries->findReversal($entry)) {
            throw new PostingException(sprintf('Entry %s is already reversed.', $entry->getNumber()));
        }

        $draft = new EntryDraft($entry->getBook(), $entry->getJournal()->getCode(), $date ?? $entry->getDate(), 'Extourne '.($entry->getNumber() ?? '').' '.$entry->getLabel(), $source, $entry->getPiece());
        foreach ($entry->getLines() as $line) {
            $draft->line($line->getAccount()->getNumber(), $line->getCredit(), $line->getDebit(), $line->getParty(), $line->getLabel(), $line->getDimensions(), $line->getTax(), $line->getTaxBase());
        }

        return $this->record($draft, true, $entry);
    }

    /** Numbers and freezes a draft entry. */
    public function validate(Entry $entry): Entry
    {
        if ($entry->isValidated()) {
            return $entry;
        }
        if (!$entry->isBalanced() || $entry->getLines()->count() < 2) {
            throw new PostingException(sprintf('Entry "%s" is not balanced.', $entry->getLabel()));
        }
        $this->assertOpen($entry->getBook(), $entry->getDate());

        $this->numbered($entry->getBook(), function () use ($entry) {
            $entry->validate($this->nextNumber($entry->getBook(), $entry->getDate()));
            $this->entityManager->flush();
        });

        return $entry;
    }

    /** Throws when nothing may be posted on $date in $book. */
    public function assertOpen(Book $book, \DateTimeInterface $date): void
    {
        if ($book->isLocked($date)) {
            throw new LockedPeriodException(sprintf('%s is locked until %s: nothing can be posted on %s.', $book->getName(), $book->getLockedUntil()->format('d/m/Y'), $date->format('d/m/Y')));
        }
        $fiscalYear = $this->fiscalYears->containing($book, $date);
        if (null !== $fiscalYear && $fiscalYear->isClosed()) {
            throw new LockedPeriodException(sprintf('The fiscal year %s of %s is closed.', $fiscalYear, $book->getName()));
        }
    }

    /** The number the next validated entry of $book dated $date gets. */
    public function nextNumber(Book $book, \DateTimeInterface $date): string
    {
        $fiscalYear = $this->fiscalYears->containing($book, $date);
        $prefix = ($fiscalYear?->getEnd()->format('Y') ?? $date->format('Y')).'-';
        $last = $this->entries->lastNumber($book, $prefix);

        return $prefix.str_pad((string) (null === $last ? 1 : (int) substr($last, \strlen($prefix)) + 1), 6, '0', \STR_PAD_LEFT);
    }

    private function record(EntryDraft $draft, bool $validate, ?Entry $reversalOf = null): Entry
    {
        $book = $draft->book;
        if (null !== ($existing = $this->entries->findOneBySource($book, $draft->source))) {
            return $existing;
        }

        $this->check($draft);
        $this->assertOpen($book, $draft->date);
        $journal = $this->journals->findOneByCode($book, $draft->journal)
            ?? throw new PostingException(sprintf('%s has no journal %s.', $book->getName(), $draft->journal));

        // Every account and tax resolved (or created) before anything is persisted.
        $accounts = [];
        $created = [];
        $taxes = [];
        foreach ($draft->getLines() as $i => $line) {
            $taxes[$i] = $this->tax($book, $line['tax'], $draft->label);
            $number = Account::normalize($line['account']);
            if (null !== $line['party'] && $line['party']->getBook() !== $book) {
                throw new PostingException(sprintf('%s is a party of another book.', $line['party']->getName()));
            }
            if (isset($accounts[$number])) {
                continue;
            }
            $account = $this->accounts->findOneByNumber($book, $number);
            if (null === $account) {
                if (!$this->autoAccounts) {
                    throw new PostingException(sprintf('%s has no account %s (ledger.auto_accounts is off).', $book->getName(), $number));
                }
                $parent = $this->accounts->findParent($book, $number)
                    ?? throw new PostingException(sprintf('%s has no account %s, nor any account it could be a sub-account of.', $book->getName(), $number));
                $account = $created[] = new Account($book, $number, $line['party']?->getName() ?? $line['label'] ?? $parent->getLabel(), $parent->getType());
            }
            $accounts[$number] = $account;
        }

        $entry = new Entry($book, $journal, $draft->date, $draft->label, $draft->source, $draft->piece);
        $entry->setReversalOf($reversalOf);
        foreach ($draft->getLines() as $i => $line) {
            new EntryLine($entry, $accounts[Account::normalize($line['account'])], $line['debit'], $line['credit'], $line['party'], $line['label'], $taxes[$i], $line['taxBase'] ?? null, $line['dimensions'] ?? []);
        }

        foreach ($created as $account) {
            $this->entityManager->persist($account);
        }
        $this->entityManager->persist($entry);

        if (!$validate) {
            $this->entityManager->flush();

            return $entry;
        }

        $this->numbered($book, function () use ($entry, $book, $draft) {
            $entry->validate($this->nextNumber($book, $draft->date));
            $this->entityManager->flush();
        });

        return $entry;
    }

    /** @throws PostingException */
    private function check(EntryDraft $draft): void
    {
        $lines = $draft->getLines();
        if (\count($lines) < 2) {
            throw new PostingException(sprintf('"%s": an entry has at least two lines.', $draft->label));
        }
        if ('' === trim($draft->source)) {
            throw new PostingException(sprintf('"%s": an entry needs a source (what it records, once).', $draft->label));
        }
        foreach ($lines as $i => $line) {
            if ($line['debit'] < 0 || $line['credit'] < 0) {
                throw new PostingException(sprintf('"%s", line %d (%s): amounts are never negative - swap the sides instead.', $draft->label, $i + 1, $line['account']));
            }
            if ($line['debit'] > 0 && $line['credit'] > 0) {
                throw new PostingException(sprintf('"%s", line %d (%s): a line is a debit or a credit, not both.', $draft->label, $i + 1, $line['account']));
            }
            if (0 === $line['debit'] && 0 === $line['credit']) {
                throw new PostingException(sprintf('"%s", line %d (%s): the line has no amount.', $draft->label, $i + 1, $line['account']));
            }
            if (($line['taxBase'] ?? 0) < 0) {
                throw new PostingException(sprintf('"%s", line %d (%s): a tax base is never negative - it is on the line\'s side.', $draft->label, $i + 1, $line['account']));
            }
            if (null !== ($line['taxBase'] ?? null) && null === ($line['tax'] ?? null)) {
                throw new PostingException(sprintf('"%s", line %d (%s): a tax base without its tax.', $draft->label, $i + 1, $line['account']));
            }
            foreach ($line['dimensions'] ?? [] as $axis => $value) {
                try {
                    EntryLineDimension::axis((string) $axis);
                    EntryLineDimension::value((string) $value);
                } catch (\InvalidArgumentException $e) {
                    throw new PostingException(sprintf('"%s", line %d (%s): %s', $draft->label, $i + 1, $line['account'], $e->getMessage()));
                }
            }
        }
        if ($draft->getTotalDebit() !== $draft->getTotalCredit()) {
            throw new PostingException(sprintf('"%s" is not balanced: %d debit, %d credit.', $draft->label, $draft->getTotalDebit(), $draft->getTotalCredit()));
        }
    }

    /** The Tax a draft line names, by its code or itself: the book's, or a PostingException. */
    private function tax(Book $book, Tax|string|null $tax, string $label): ?Tax
    {
        if (null === $tax) {
            return null;
        }
        if (\is_string($tax)) {
            $tax = $this->taxes?->findOneByCode($book, $tax)
                ?? throw new PostingException(sprintf('"%s": %s has no tax %s.', $label, $book->getName(), Tax::normalize($tax)));
        }
        if ($tax->getBook() !== $book) {
            throw new PostingException(sprintf('"%s": tax %s is another book\'s.', $label, $tax->getCode()));
        }

        return $tax;
    }

    /** Numbering and the flush that saves the number, one process at a time per book when symfony/lock is there. */
    private function numbered(Book $book, callable $number): void
    {
        $lock = $this->locks?->createLock('ledger-number-'.($book->getId() ?? spl_object_id($book)));
        $lock?->acquire(true);
        try {
            $number();
        } finally {
            $lock?->release();
        }
    }
}
