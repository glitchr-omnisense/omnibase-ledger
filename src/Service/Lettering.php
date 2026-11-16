<?php

namespace Base\Ledger\Service;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Party;
use Base\Ledger\Exception\LetteringException;
use Base\Ledger\Repository\EntryLineRepository;
use Base\Contract\UnitOfWork;

/**
 * Lettering: lines of one account and one party whose debits and credits
 * cancel out - a rent notice and the transfer paying it - share a letter
 * (AAA, AAB...) and stop being open items. Partial payments stay open until
 * the rest arrives.
 */
class Lettering
{
    /** @var array<int, string> the last letter given per book in this request, ahead of the flush */
    private array $last = [];

    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly EntryLineRepository $lines,
    ) {
    }

    /**
     * Letters $lines together and returns their letter.
     *
     * @param iterable<EntryLine> $lines
     *
     * @throws LetteringException unless they balance, on one account and one party, none lettered yet
     */
    public function letter(iterable $lines, ?\DateTimeImmutable $on = null): string
    {
        $unique = [];
        foreach ($lines as $line) {
            if (!$line instanceof EntryLine) {
                throw new LetteringException('Only entry lines are lettered.');
            }
            $unique[spl_object_id($line)] = $line;
        }
        $lines = array_values($unique);
        if (\count($lines) < 2) {
            throw new LetteringException('Lettering ties at least two lines.');
        }

        $first = $lines[0];
        $book = $first->getBook();
        $debit = $credit = 0;
        foreach ($lines as $line) {
            if ($line->isLettered()) {
                throw new LetteringException(sprintf('%s is already lettered %s.', $line, $line->getLetter()));
            }
            if ($line->getBook() !== $book || $line->getAccount() !== $first->getAccount()) {
                throw new LetteringException(sprintf('Lettered lines share one account: %s and %s.', $first->getAccount()->getNumber(), $line->getAccount()->getNumber()));
            }
            if ($line->getParty() !== $first->getParty()) {
                throw new LetteringException(sprintf('Lettered lines share one party: %s and %s.', $first->getParty() ?? '-', $line->getParty() ?? '-'));
            }
            $debit += $line->getDebit();
            $credit += $line->getCredit();
        }
        if ($debit !== $credit) {
            throw new LetteringException(sprintf('Lettered lines balance: %d debit against %d credit.', $debit, $credit));
        }

        $letter = $this->nextLetter($book);
        foreach ($lines as $line) {
            $line->setLetter($letter, $on);
        }
        $this->unitOfWork->flush();

        return $letter;
    }

    /** Takes a letter off the lines carrying it in $book; returns how many. */
    public function unletter(string $letter, Book $book): int
    {
        $lines = $this->lines->findByLetter($book, $letter);
        foreach ($lines as $line) {
            $line->setLetter(null);
        }
        $this->unitOfWork->flush();

        return \count($lines);
    }

    /**
     * The unlettered lines of the accounts under $accountPrefix (411:
     * what tenants still owe, and their payments not matched yet), maybe one
     * party's, oldest first.
     *
     * @return list<EntryLine>
     */
    public function openItems(Book $book, ?Party $party = null, ?string $accountPrefix = '411'): array
    {
        return $this->lines->openItems($book, $party, $accountPrefix);
    }

    /** The letter after the book's last one: AAA, AAB... AAZ, ABA... ZZZ, AAAA. */
    public function nextLetter(Book $book): string
    {
        $key = spl_object_id($book);
        $last = $this->last[$key] ?? $this->lines->lastLetter($book);

        return $this->last[$key] = null === $last ? 'AAA' : self::increment($last);
    }

    public static function increment(string $letter): string
    {
        $letter = strtoupper($letter);
        for ($i = \strlen($letter) - 1; $i >= 0; --$i) {
            if ('Z' !== $letter[$i]) {
                $letter[$i] = \chr(\ord($letter[$i]) + 1);

                return $letter;
            }
            $letter[$i] = 'A';
        }

        return 'A'.$letter;
    }
}
