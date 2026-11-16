<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Entry> */
class EntryRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Entry::class);
    }

    public function findOneBySource(Book $book, string $source): ?Entry
    {
        return $this->findOneBy(['book' => $book, 'source' => $source]);
    }

    /** The highest number given with this prefix ("2026-"): numbers are zero-padded, so the greatest string is the last. */
    public function lastNumber(Book $book, string $prefix): ?string
    {
        $number = $this->maxOfNumberBy([
            'book' => Key::of($book),
            Group::of(Parser::SEPARATOR_AND, Criterion::of('number', $prefix, Parser::OPTION_STARTING_WITH)),
        ]);

        return null === $number ? null : (string) $number;
    }

    /** The entry reversing $entry, if one does. */
    public function findReversal(Entry $entry): ?Entry
    {
        return $this->findOneBy(['reversalOf' => $entry]);
    }

    /**
     * The book's entries dated in [$from, $to], in the order the FEC lists
     * them (date, then number); their lines load with them, by id (Entry::$lines' order).
     *
     * @return list<Entry>
     */
    public function forPeriod(Book $book, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $validatedOnly = true): array
    {
        $criteria = [
            'book' => Key::of($book),
            Group::of(Parser::SEPARATOR_AND,
                Criterion::of('date', $from->setTime(0, 0), Parser::OPTION_GREATER_EQUAL),
                Criterion::of('date', $to->setTime(0, 0), Parser::OPTION_LOWER_EQUAL),
            ),
        ];
        if ($validatedOnly) {
            $criteria[] = Group::of(Parser::SEPARATOR_AND, Criterion::of('validatedAt', null, Parser::OPTION_NOT_NULL));
        }

        return $this->findBy($criteria, ['date' => 'ASC', 'number' => 'ASC', 'id' => 'ASC']);
    }
}
