<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<ReconciliationRule> */
class ReconciliationRuleRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, ReconciliationRule::class);
    }

    /** @return list<ReconciliationRule> a book's enabled rules, highest priority first */
    public function forBook(Book $book): array
    {
        return $this->findBy(['book' => $book, 'enabled' => true], ['priority' => 'DESC', 'id' => 'ASC']);
    }
}
