<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Repository\EntryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Base\Mapping as ORM;

/**
 * A journal entry: balanced lines (debits = credits) on one date, in one
 * journal. $source says where it comes from ("bank:42", "notice:2026-10-U3")
 * and is unique per book, so posting the same thing twice gives the same
 * entry back. Validated, it gets its number (sequential per book and fiscal
 * year) and becomes immutable: a mistake is undone by a reversal entry
 * ($reversalOf), never by an edit.
 */
#[ORM\Entity(repositoryClass: EntryRepository::class)]
#[ORM\Table(name: 'ledger_entry')]
#[ORM\UniqueConstraint(name: 'ledger_entry_source', fields: ['book', 'source'])]
#[ORM\UniqueConstraint(name: 'ledger_entry_number', fields: ['book', 'number'])]
#[ORM\Index(name: 'ledger_entry_date', fields: ['book', 'date'])]
class Entry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\ManyToOne(targetEntity: Journal::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Journal $journal;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 255)]
    private string $label;

    /** The supporting document's reference: an invoice number, a rent notice (AVIS-2026-10-U3). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $piece;

    #[ORM\Column(length: 191)]
    private string $source;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entry $reversalOf = null;

    /** @var Collection<int, EntryLine> */
    #[ORM\OneToMany(targetEntity: EntryLine::class, mappedBy: 'entry', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Book $book, Journal $journal, \DateTimeImmutable $date, string $label, string $source, ?string $piece = null)
    {
        $this->book = $book;
        $this->journal = $journal;
        $this->date = $date->setTime(0, 0);
        $this->label = mb_substr($label, 0, 255);
        $this->source = $source;
        $this->piece = $piece;
        $this->lines = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return ($this->number ?? '#'.($this->id ?? '?')).' '.$this->label;
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    public function getJournal(): Journal { return $this->journal; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getLabel(): string { return $this->label; }
    public function getPiece(): ?string { return $this->piece; }
    public function getSource(): string { return $this->source; }
    public function getNumber(): ?string { return $this->number; }
    public function getValidatedAt(): ?\DateTimeImmutable { return $this->validatedAt; }
    public function isValidated(): bool { return null !== $this->validatedAt; }
    public function getReversalOf(): ?Entry { return $this->reversalOf; }
    public function setReversalOf(?Entry $reversalOf): self { $this->reversalOf = $reversalOf; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Numbered and frozen - by Posting, which picks the number. */
    public function validate(string $number, ?\DateTimeImmutable $at = null): self
    {
        if ($this->isValidated()) {
            throw new \LogicException(sprintf('Entry %s is already validated.', $this->number));
        }
        $this->number = $number;
        $this->validatedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /** @return Collection<int, EntryLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(EntryLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }

        return $this;
    }

    public function getTotalDebit(): int
    {
        return array_sum($this->lines->map(fn (EntryLine $line) => $line->getDebit())->toArray());
    }

    public function getTotalCredit(): int
    {
        return array_sum($this->lines->map(fn (EntryLine $line) => $line->getCredit())->toArray());
    }

    public function isBalanced(): bool
    {
        return $this->getTotalDebit() === $this->getTotalCredit();
    }
}
