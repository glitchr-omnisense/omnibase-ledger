<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\Book;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BankAccount> */
class BankAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankAccount::class);
    }

    /** @return list<BankAccount> every bank account, or a book's */
    public function forBook(?Book $book = null): array
    {
        $qb = $this->createQueryBuilder('a')->join('a.connection', 'c')->addSelect('c')->orderBy('a.id', 'ASC');
        if (null !== $book) {
            $qb->andWhere('c.book = :book')->setParameter('book', $book);
        }

        return $qb->getQuery()->getResult();
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
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')->join('a.connection', 'c')
            ->andWhere('c.book = :book')->setParameter('book', $book)
            ->getQuery()->getSingleScalarResult();
    }
}
