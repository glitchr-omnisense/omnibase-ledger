<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\BankConnection;
use Base\Ledger\Entity\Book;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<BankConnection> */
class BankConnectionRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, BankConnection::class);
    }

    /** @return list<BankConnection> */
    public function forBook(?Book $book = null): array
    {
        return null === $book ? $this->findBy([], ['id' => 'ASC']) : $this->findBy(['book' => $book], ['id' => 'ASC']);
    }
}
