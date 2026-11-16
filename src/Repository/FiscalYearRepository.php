<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\FiscalYear;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<FiscalYear> */
class FiscalYearRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, FiscalYear::class);
    }

    /** The fiscal year of $book that $date falls in, if the book has one. */
    public function containing(Book $book, \DateTimeInterface $date): ?FiscalYear
    {
        $day = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        return $this->findOneBy(['book' => Key::of($book), Group::of(Parser::SEPARATOR_AND,
            Criterion::of('start', $day, Parser::OPTION_LOWER_EQUAL),
            Criterion::of('end', $day, Parser::OPTION_GREATER_EQUAL),
        )]);
    }

    /** The fiscal year closing in $year, else the calendar year $year (unsaved). */
    public function forYear(Book $book, int $year): FiscalYear
    {
        $years = $this->findBy(['book' => Key::of($book)], ['end' => 'DESC']);
        foreach ($years as $fiscalYear) {
            if ((int) $fiscalYear->getEnd()->format('Y') === $year) {
                return $fiscalYear;
            }
        }

        return FiscalYear::calendar($book, $year);
    }

    /** The fiscal year $date falls in, or its calendar year. */
    public function current(Book $book, ?\DateTimeImmutable $date = null): FiscalYear
    {
        $date ??= new \DateTimeImmutable('today');

        return $this->containing($book, $date) ?? FiscalYear::calendar($book, (int) $date->format('Y'));
    }
}
