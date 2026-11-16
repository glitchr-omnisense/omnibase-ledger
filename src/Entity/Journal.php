<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Repository\JournalRepository;
use Base\Mapping as ORM;
use Base\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/** A book's journal: BQ (bank), VT (sales: rent notices), AC (purchases), OD (miscellaneous), AN (opening balances). */
#[ORM\Entity(repositoryClass: JournalRepository::class)]
#[ORM\Table(name: 'ledger_journal')]
#[ORM\UniqueConstraint(name: 'ledger_journal_code', fields: ['book', 'code'])]
#[UniqueEntity(fields: ['book', 'code'], errorPath: 'code', message: 'Ce livre a déjà un journal de ce code.')]
class Journal
{
    public const BANK = 'BQ';
    public const SALES = 'VT';
    public const PURCHASES = 'AC';
    public const MISCELLANEOUS = 'OD';
    public const OPENING = 'AN';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\Column(length: 8)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 8)]
    private string $code;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label;

    public function __construct(Book $book, string $code, string $label)
    {
        $this->book = $book;
        $this->code = strtoupper($code);
        $this->label = $label;
    }

    public function __toString(): string
    {
        return $this->code.' '.$this->label;
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    /** A journal is given its book and its code when it is created: the back office's "new" form writes them, its "edit" form shows them. */
    public function setBook(Book $book): self { $this->book = $book; return $this; }
    public function getCode(): string { return $this->code; }
    public function setCode(?string $code): self { $this->code = strtoupper(trim((string) $code)); return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = (string) $label; return $this; }
}
