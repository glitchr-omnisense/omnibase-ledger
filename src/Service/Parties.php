<?php

namespace Base\Ledger\Service;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Party;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\PartyRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The book's parties as the application knows them: partyFor() finds one by
 * the application's reference (a lease, a supplier code) or creates it,
 * with its auxiliary account under its kind's collective account - 411 +
 * the reference for a tenant (411U3), 401 for a supplier, 455 for an
 * associate, 467 otherwise.
 */
class Parties
{
    /** @var array<string, Party> created in this request, by book and reference: not yet findable before the flush */
    private array $created = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PartyRepository $parties,
        private readonly AccountRepository $accounts,
    ) {
    }

    /**
     * $accountSuffix names the auxiliary account in place of the reference
     * (401 + "EDF" for a supplier whose reference is its SIREN), made
     * unique the same way.
     */
    public function partyFor(Book $book, PartyKind|string $kind, string $reference, string $name, ?string $iban = null, ?string $accountSuffix = null): Party
    {
        $kind = $kind instanceof PartyKind ? $kind : PartyKind::from(strtolower($kind));
        $key = spl_object_id($book).'|'.$reference;

        $party = $this->created[$key] ?? $this->parties->findOneByReference($book, $reference);
        if (null !== $party) {
            // What the application says now wins: a renamed tenant, a new IBAN.
            $changed = false;
            if ($party->getName() !== $name) {
                $party->setName($name);
                $changed = true;
            }
            if (null !== $iban && Party::normalizeIban($iban) !== $party->getIban()) {
                $party->setIban($iban);
                $changed = true;
            }
            if ($changed) {
                $this->entityManager->flush();
            }

            return $party;
        }

        $number = $this->accountNumber($book, $kind, $accountSuffix ?? $reference);
        $account = $this->accounts->findOneByNumber($book, $number);
        if (null === $account) {
            $collective = $this->accounts->findOneByNumber($book, $kind->collective());
            $account = new Account($book, $number, $name, $collective?->getType());
            $this->entityManager->persist($account);
        }

        $party = new Party($book, $kind, $name, $number, $reference, $iban);
        $this->entityManager->persist($party);
        $this->entityManager->flush();

        return $this->created[$key] = $party;
    }

    /** 411 + the reference, upper-case and alphanumeric (411U3), made unique in the book. */
    public function accountNumber(Book $book, PartyKind $kind, string $reference): string
    {
        $suffix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $reference));
        $base = substr($kind->collective().('' === $suffix ? 'X' : $suffix), 0, 17);

        $number = $base;
        for ($n = 2; $this->parties->accountNumberTaken($book, $number); ++$n) {
            $number = $base.$n;
        }

        return $number;
    }
}
