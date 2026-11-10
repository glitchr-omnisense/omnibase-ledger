<?php

namespace Base\Ledger\Omnibill\Entity;

use Base\Ledger\Entity\Book;
use Base\Ledger\Omnibill\Enum\ExportState;
use Base\Ledger\Omnibill\Repository\AccountingExportRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One export of a book's period to the accountant's software (an
 * omnisense/omnibill gateway keeping journal entries: odoo, pennylane):
 * when, by whom, to which gateway, and what came of it. What each record
 * became is in AccountingExportItem. A run that stopped (the software
 * unreachable, a key refused) stays FAILED and is resumed by the next
 * export of the same period: what it sent is not sent again.
 */
#[ORM\Entity(repositoryClass: AccountingExportRepository::class)]
#[ORM\Table(name: 'ledger_accounting_export')]
#[ORM\Index(name: 'ledger_accounting_export_period', fields: ['book', 'gateway', 'fromDate', 'toDate'])]
class AccountingExport
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\Column(length: 64)]
    private string $gateway;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $fromDate;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $toDate;

    #[ORM\Column(length: 16, enumType: ExportState::class)]
    private ExportState $state = ExportState::PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $startedBy;

    /** How many times it was resumed after stopping. */
    #[ORM\Column]
    private int $resumed = 0;

    #[ORM\Column]
    private int $contacts = 0;

    #[ORM\Column]
    private int $entries = 0;

    /** Already sent by an earlier run: left alone. */
    #[ORM\Column]
    private int $skipped = 0;

    #[ORM\Column]
    private int $refused = 0;

    #[ORM\Column]
    private int $changed = 0;

    /** Why the run stopped. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    public function __construct(Book $book, string $gateway, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $startedBy = null)
    {
        $this->book = $book;
        $this->gateway = $gateway;
        $this->fromDate = $from->setTime(0, 0);
        $this->toDate = $to->setTime(0, 0);
        $this->startedBy = $startedBy;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    public function getGateway(): string { return $this->gateway; }
    public function getFromDate(): \DateTimeImmutable { return $this->fromDate; }
    public function getToDate(): \DateTimeImmutable { return $this->toDate; }
    public function getState(): ExportState { return $this->state; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getStartedBy(): ?string { return $this->startedBy; }
    public function getResumed(): int { return $this->resumed; }
    public function getContacts(): int { return $this->contacts; }
    public function getEntries(): int { return $this->entries; }
    public function getSkipped(): int { return $this->skipped; }
    public function getRefused(): int { return $this->refused; }
    public function getChanged(): int { return $this->changed; }
    public function getError(): ?string { return $this->error; }
    public function isFinished(): bool { return ExportState::DONE === $this->state; }

    /** Taken up again where it stopped. */
    public function resume(): self
    {
        ++$this->resumed;
        $this->state = ExportState::PENDING;
        $this->error = null;
        $this->finishedAt = null;

        return $this;
    }

    public function count(string $what): self
    {
        match ($what) {
            'contact' => ++$this->contacts,
            'entry' => ++$this->entries,
            'skipped' => ++$this->skipped,
            'refused' => ++$this->refused,
            'changed' => ++$this->changed,
        };

        return $this;
    }

    public function finish(): self
    {
        $this->state = ExportState::DONE;
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }

    public function fail(string $error): self
    {
        $this->state = ExportState::FAILED;
        $this->error = mb_substr($error, 0, 255);
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }
}
