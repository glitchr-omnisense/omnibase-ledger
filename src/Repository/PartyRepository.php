<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Party;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Party> */
class PartyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Party::class);
    }

    public function findOneByReference(Book $book, string $reference): ?Party
    {
        return $this->findOneBy(['book' => $book, 'reference' => $reference]);
    }

    /** @return list<Party> the book's parties paying from (or paid to) this IBAN */
    public function findByIban(Book $book, ?string $iban): array
    {
        $iban = Party::normalizeIban($iban);

        return null === $iban ? [] : $this->findBy(['book' => $book, 'iban' => $iban]);
    }

    public function accountNumberTaken(Book $book, string $number): bool
    {
        return null !== $this->findOneBy(['book' => $book, 'accountNumber' => $number]);
    }

    /** @return list<Party> the book's parties of this kind, by name */
    public function ofKind(Book $book, \Base\Ledger\Enum\PartyKind $kind): array
    {
        return $this->findBy(['book' => $book, 'kind' => $kind], ['name' => 'ASC']);
    }
}
