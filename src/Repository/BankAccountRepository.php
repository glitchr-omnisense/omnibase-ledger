<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\Book;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<BankAccount> */
class BankAccountRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, BankAccount::class);
    }

    /** @return list<BankAccount> every bank account, or a book's */
    public function forBook(?Book $book = null): array
    {
        return $this->findBy(null === $book ? [] : ['connection.book' => $book], ['id' => 'ASC']);
    }

    /** The book's escrow account (BankAccount::isEscrow(): the third parties' money), the first one if several. */
    public function escrowFor(Book $book): ?BankAccount
    {
        foreach ($this->forBook($book) as $account) {
            if ($account->isEscrow()) {
                return $account;
            }
        }

        return null;
    }

    public function countForBook(Book $book): int
    {
        return $this->count(['connection.book' => $book]);
    }
}
