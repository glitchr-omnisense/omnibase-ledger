<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Account> */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Account::class);
    }

    public function findOneByNumber(Book $book, string $number): ?Account
    {
        return $this->findOneBy(['book' => $book, 'number' => Account::normalize($number)]);
    }

    /** @return list<Account> the book's chart, by number */
    public function chart(Book $book): array
    {
        return $this->findBy(['book' => $book], ['number' => 'ASC']);
    }

    /**
     * The account $number belongs under: the book's account with the longest
     * number $number starts with (401 for 4011, 41 for 4199).
     */
    public function findParent(Book $book, string $number): ?Account
    {
        $number = Account::normalize($number);
        $prefixes = [];
        for ($length = \strlen($number) - 1; $length >= 1; --$length) {
            $prefixes[] = substr($number, 0, $length);
        }
        if ([] === $prefixes) {
            return null;
        }

        $accounts = $this->findBy(['book' => $book, 'number' => $prefixes]);
        usort($accounts, fn (Account $a, Account $b) => \strlen($b->getNumber()) <=> \strlen($a->getNumber()));

        return $accounts[0] ?? null;
    }
}
