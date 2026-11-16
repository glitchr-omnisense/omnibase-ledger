<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Journal;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Journal> */
class JournalRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Journal::class);
    }

    public function findOneByCode(Book $book, string $code): ?Journal
    {
        return $this->findOneBy(['book' => $book, 'code' => strtoupper($code)]);
    }
}
