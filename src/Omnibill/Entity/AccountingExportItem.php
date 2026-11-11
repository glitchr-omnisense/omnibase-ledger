<?php

namespace Base\Ledger\Omnibill\Entity;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\Party;
use Base\Ledger\Omnibill\Enum\ExportState;
use Base\Ledger\Omnibill\Repository\AccountingExportItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One record of the book as the accountant's software has it: an entry
 * (its key the entry's source) or a contact (its key the party's
 * reference), the software's reference for it, and the fingerprint of what
 * was sent. Unique per book, gateway, kind and key: what makes a second
 * export of the same entry a no-op. A record whose fingerprint no longer
 * matches the book is CHANGED: flagged for a person, not sent again.
 */
#[ORM\Entity(repositoryClass: AccountingExportItemRepository::class)]
#[ORM\Table(name: 'ledger_accounting_export_item')]
#[ORM\UniqueConstraint(name: 'ledger_accounting_export_item_key', fields: ['book', 'gateway', 'kind', 'recordKey'])]
#[ORM\Index(name: 'ledger_accounting_export_item_state', fields: ['book', 'gateway', 'state'])]
class AccountingExportItem
{
    public const ENTRY = 'entry';
    public const CONTACT = 'contact';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\Column(length: 64)]
    private string $gateway;

    /** entry or contact */
    #[ORM\Column(length: 8)]
    private string $kind;

    /** The entry's source, the party's reference: the application's key, sent as such. */
    #[ORM\Column(length: 191)]
    private string $recordKey;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entry $entry = null;

    #[ORM\ManyToOne(targetEntity: Party::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Party $party = null;

    /** The last run that touched it. */
    #[ORM\ManyToOne(targetEntity: AccountingExport::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AccountingExport $run = null;

    #[ORM\Column(length: 16, enumType: ExportState::class)]
    private ExportState $state = ExportState::PENDING;

    /** The software's id for it, once accepted. */
    #[ORM\Column(length: 191, nullable: true)]
    private ?string $reference = null;

    /** The software's number for it (an entry it validated). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $number = null;

    /** sha256 of what was sent (EntryMapper::fingerprint()). */
    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Book $book, string $gateway, string $kind, string $recordKey, string $fingerprint, ?Entry $entry = null, ?Party $party = null)
    {
        $this->book = $book;
        $this->gateway = $gateway;
        $this->kind = $kind;
        $this->recordKey = mb_substr($recordKey, 0, 191);
        $this->fingerprint = $fingerprint;
        $this->entry = $entry;
        $this->party = $party;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    public function getGateway(): string { return $this->gateway; }
    public function getKind(): string { return $this->kind; }
    public function getRecordKey(): string { return $this->recordKey; }
    public function getEntry(): ?Entry { return $this->entry; }
    public function getParty(): ?Party { return $this->party; }
    public function getRun(): ?AccountingExport { return $this->run; }
    public function getState(): ExportState { return $this->state; }
    public function getReference(): ?string { return $this->reference; }
    public function getNumber(): ?string { return $this->number; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getError(): ?string { return $this->error; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Whether the software has it. */
    public function isSent(): bool
    {
        return null !== $this->reference;
    }

    public function sent(AccountingExport $run, string $reference, ?string $number, string $fingerprint): self
    {
        $this->run = $run;
        $this->state = ExportState::SENT;
        $this->reference = $reference;
        $this->number = $number;
        $this->fingerprint = $fingerprint;
        $this->error = null;
        $this->sentAt = $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function refused(AccountingExport $run, string $error, string $fingerprint): self
    {
        return $this->stopped($run, ExportState::REFUSED, $error, $fingerprint);
    }

    public function failed(AccountingExport $run, string $error, string $fingerprint): self
    {
        return $this->stopped($run, ExportState::FAILED, $error, $fingerprint);
    }

    /** Sent, its answer lost: the software may have it (ExportState::UNKNOWN). */
    public function unknown(AccountingExport $run, string $error, string $fingerprint): self
    {
        return $this->stopped($run, ExportState::UNKNOWN, $error, $fingerprint);
    }

    /** The book's record no longer matches what the software has: flagged, the reference kept. */
    public function changed(AccountingExport $run): self
    {
        $this->run = $run;
        $this->state = ExportState::CHANGED;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    private function stopped(AccountingExport $run, ExportState $state, string $error, string $fingerprint): self
    {
        $this->run = $run;
        $this->state = $state;
        $this->error = mb_substr($error, 0, 255);
        $this->fingerprint = $fingerprint;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
