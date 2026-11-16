<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\Book;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Book> */
class BookRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Book::class);
    }

    /** A book by its id, SIREN or exact name: what the commands accept. */
    public function resolve(string $handle): ?Book
    {
        if (ctype_digit($handle) && null !== ($book = $this->find((int) $handle))) {
            return $book;
        }

        $siren = preg_replace('/\D/', '', $handle);
        if (9 === \strlen($siren) && null !== ($book = $this->findOneBy(['siren' => $siren]))) {
            return $book;
        }

        return $this->findOneBy(['name' => $handle]);
    }
}
