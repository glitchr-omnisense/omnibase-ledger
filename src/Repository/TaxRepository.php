<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Tax;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Tax> */
class TaxRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Tax::class);
    }

    public function findOneByCode(Book $book, string $code): ?Tax
    {
        return $this->findOneBy(['book' => $book, 'code' => Tax::normalize($code)]);
    }

    /** @return list<Tax> */
    public function forBook(Book $book): array
    {
        return $this->findBy(['book' => $book], ['kind' => 'ASC', 'rate' => 'DESC', 'code' => 'ASC']);
    }
}
