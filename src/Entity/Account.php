<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Enum\AccountType;
use Base\Ledger\Repository\AccountRepository;
use Base\Mapping as ORM;
use Base\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An account of a book's chart, by its PCG number: 512 (bank), 706
 * (rents), or an auxiliary account under a collective one (411U3 for a
 * tenant, under 411).
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'ledger_account')]
#[ORM\UniqueConstraint(name: 'ledger_account_number', fields: ['book', 'number'])]
#[UniqueEntity(fields: ['book', 'number'], errorPath: 'number', message: 'Ce livre a déjà un compte de ce numéro.')]
class Account
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $number;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label;

    #[ORM\Column(length: 16, enumType: AccountType::class)]
    private AccountType $type;

    public function __construct(Book $book, string $number, string $label, ?AccountType $type = null)
    {
        $this->book = $book;
        $this->number = self::normalize($number);
        $this->label = $label;
        $this->type = $type ?? AccountType::guess($this->number);
    }

    /** Account numbers are kept upper-case, without spaces: "411 u3" is 411U3. */
    public static function normalize(string $number): string
    {
        return strtoupper(preg_replace('/\s+/', '', $number));
    }

    public function __toString(): string
    {
        return $this->number.' '.$this->label;
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    /** An account is given its book and its number when it is created: the back office's "new" form writes them, its "edit" form shows them. */
    public function setBook(Book $book): self { $this->book = $book; return $this; }
    public function getNumber(): string { return $this->number; }
    public function setNumber(?string $number): self { $this->number = self::normalize((string) $number); return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = (string) $label; return $this; }
    public function getType(): AccountType { return $this->type; }
    public function setType(AccountType $type): self { $this->type = $type; return $this; }

    /** The general account this one sits under in the FEC: its leading digits (411 for 411U3). */
    public function getGeneralNumber(): string
    {
        return preg_match('/^\d+/', $this->number, $m) ? $m[0] : $this->number;
    }

    public function isUnder(string $prefix): bool
    {
        return str_starts_with($this->number, self::normalize($prefix));
    }
}
