<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Repository\FiscalYearRepository;
use Base\Mapping as ORM;
use Base\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An accounting period of a book, usually twelve months. Entry numbers run
 * per fiscal year; once closed, nothing is posted in it any more. A book
 * without fiscal years works on calendar years.
 */
#[ORM\Entity(repositoryClass: FiscalYearRepository::class)]
#[ORM\Table(name: 'ledger_fiscal_year')]
#[ORM\UniqueConstraint(name: 'ledger_fiscal_year_start', fields: ['book', 'start'])]
#[UniqueEntity(fields: ['book', 'start'], errorPath: 'start', message: 'Ce livre a déjà un exercice qui commence ce jour-là.')]
class FiscalYear
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\Column(name: 'starts_on', type: 'date_immutable')]
    private \DateTimeImmutable $start;

    #[ORM\Column(name: 'ends_on', type: 'date_immutable')]
    private \DateTimeImmutable $end;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(Book $book, \DateTimeImmutable $start, \DateTimeImmutable $end)
    {
        if ($end < $start) {
            throw new \InvalidArgumentException('A fiscal year ends after it starts.');
        }
        $this->book = $book;
        $this->start = $start->setTime(0, 0);
        $this->end = $end->setTime(0, 0);
    }

    /** The calendar year $year of a book, unsaved: what a book without fiscal years works on. */
    public static function calendar(Book $book, int $year): self
    {
        return new self($book, new \DateTimeImmutable(sprintf('%04d-01-01', $year)), new \DateTimeImmutable(sprintf('%04d-12-31', $year)));
    }

    public function __toString(): string
    {
        return $this->start->format('Y') === $this->end->format('Y')
            ? $this->end->format('Y')
            : $this->start->format('Y').'-'.$this->end->format('Y');
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    public function setBook(Book $book): self { $this->book = $book; return $this; }
    public function getStart(): \DateTimeImmutable { return $this->start; }
    public function setStart(\DateTimeInterface $start): self { $this->start = \DateTimeImmutable::createFromInterface($start)->setTime(0, 0); return $this; }
    public function getEnd(): \DateTimeImmutable { return $this->end; }
    public function setEnd(\DateTimeInterface $end): self { $this->end = \DateTimeImmutable::createFromInterface($end)->setTime(0, 0); return $this; }

    /** What the constructor refuses, said to a form instead of thrown. */
    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        if ($this->end < $this->start) {
            $context->buildViolation('Un exercice se termine après son début.')->atPath('end')->addViolation();
        }
    }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function setClosedAt(?\DateTimeImmutable $closedAt): self { $this->closedAt = $closedAt; return $this; }
    public function isClosed(): bool { return null !== $this->closedAt; }

    public function contains(\DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->start->format('Y-m-d') && $day <= $this->end->format('Y-m-d');
    }
}
