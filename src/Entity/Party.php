<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Repository\PartyRepository;
use Base\Mapping as ORM;
use Base\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Someone the book owes or is owed by - a tenant, a supplier, an associate -
 * with their own auxiliary account (411U3 under 411). $reference is the
 * application's handle on them (a lease, a supplier's code), unique per book;
 * $iban is how their transfers are recognised on the bank statement.
 */
#[ORM\Entity(repositoryClass: PartyRepository::class)]
#[ORM\Table(name: 'ledger_party')]
#[ORM\UniqueConstraint(name: 'ledger_party_reference', fields: ['book', 'reference'])]
#[UniqueEntity(fields: ['book', 'reference'], errorPath: 'reference', ignoreNull: true, message: 'Ce livre a déjà un tiers de cette référence.')]
#[ORM\Index(name: 'ledger_party_iban', fields: ['iban'])]
class Party
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\Column(length: 16, enumType: PartyKind::class)]
    private PartyKind $kind;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $reference;

    /** The auxiliary account's number (411U3): an Account of the book with that number exists. */
    #[ORM\Column(length: 20)]
    private string $accountNumber;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Book $book, PartyKind $kind, string $name, string $accountNumber, ?string $reference = null, ?string $iban = null)
    {
        $this->book = $book;
        $this->kind = $kind;
        $this->name = $name;
        $this->accountNumber = Account::normalize($accountNumber);
        $this->reference = $reference;
        $this->setIban($iban);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function normalizeIban(?string $iban): ?string
    {
        $iban = null === $iban ? '' : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban));

        return '' === $iban ? null : $iban;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    public function setBook(Book $book): self { $this->book = $book; return $this; }
    public function getKind(): PartyKind { return $this->kind; }
    public function setKind(PartyKind $kind): self { $this->kind = $kind; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = (string) $name; return $this; }
    public function getIban(): ?string { return $this->iban; }
    public function setIban(?string $iban): self { $this->iban = self::normalizeIban($iban); return $this; }
    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): self { $this->reference = '' === trim((string) $reference) ? null : trim($reference); return $this; }
    public function getAccountNumber(): string { return $this->accountNumber; }
    /** Before its first entry only: lines already posted stay on the old account. */
    /** Left empty (a form gives null), the back office numbers it from the party's kind and reference when it is saved. */
    public function setAccountNumber(?string $accountNumber): self { $this->accountNumber = Account::normalize((string) $accountNumber); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
