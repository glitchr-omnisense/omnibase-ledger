<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Tax;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Tax> */
class TaxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tax::class);
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
