<?php

namespace Base\Ledger\Omnibill\Entity;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\Party;
use Base\Ledger\Omnibill\Enum\SupplierInvoiceStatus;
use Base\Ledger\Omnibill\Repository\SupplierInvoiceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A supplier's invoice received by the book - through an omnisense/omnibill
 * gateway (an approved platform's flow) or dropped by a person (the PDF a
 * supplier mailed) -, what its file says (read by horstoeko/zugferd), and
 * what the book proposes to do with it: the supplier it matched, the
 * expense account its history suggests. Nothing is posted until a person
 * books it ($entry); refused, the reason goes back to the supplier's
 * platform; paid once a bank line settles the entry's supplier line.
 *
 * $dedupe ("<supplier>|<number>") is unique per book and set on the first
 * one only: the same invoice again is a DUPLICATE pointing to it, kept for
 * the record and never proposed.
 *
 * Mapped only when omnisense/omnibill is installed (LedgerExtension): a
 * site without it has no ledger_supplier_invoice table, and needs none.
 */
#[ORM\Entity(repositoryClass: SupplierInvoiceRepository::class)]
#[ORM\Table(name: 'ledger_supplier_invoice')]
#[ORM\UniqueConstraint(name: 'ledger_supplier_invoice_dedupe', fields: ['book', 'dedupe'])]
#[ORM\UniqueConstraint(name: 'ledger_supplier_invoice_flow', fields: ['gateway', 'flowReference'])]
#[ORM\Index(name: 'ledger_supplier_invoice_status', fields: ['book', 'status'])]
#[ORM\Index(name: 'ledger_supplier_invoice_sha', fields: ['book', 'sha256'])]
class SupplierInvoice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    /** The omnibill gateway it came through; null when a person dropped the file. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $gateway;

    /** The gateway's flow: what its statuses are declared against. */
    #[ORM\Column(length: 191, nullable: true)]
    private ?string $flowReference;

    /** Factur-X, UBL, CII: omnibill's Syntax. */
    #[ORM\Column(length: 16)]
    private string $syntax;

    #[ORM\Column(length: 255)]
    private string $filename;

    /** Where the file is kept (Bank\Receipts: "drive:<path>" or "local:<path>"). */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $file = null;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $issuedOn = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueOn = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $supplierName = null;

    #[ORM\Column(length: 9, nullable: true)]
    private ?string $supplierSiren = null;

    #[ORM\Column(length: 14, nullable: true)]
    private ?string $supplierSiret = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $supplierVat = null;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $supplierIban = null;

    /** Minor units, as the invoice states them. */
    #[ORM\Column(nullable: true)]
    private ?int $totalExcl = null;

    #[ORM\Column(nullable: true)]
    private ?int $totalVat = null;

    #[ORM\Column(nullable: true)]
    private ?int $totalIncl = null;

    /** @var list<array{rate: int, base: int, vat: int, category: string}> the VAT breakdown: rate in basis points, amounts in minor units, the EN 16931 category (S, E, AE, K, Z, O...) */
    #[ORM\Column(type: 'json')]
    private array $vat = [];

    /** A credit note (type 381): the entry the other way round. */
    #[ORM\Column(options: ['default' => false])]
    private bool $creditNote = false;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $dedupe = null;

    #[ORM\ManyToOne(targetEntity: SupplierInvoice::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SupplierInvoice $duplicateOf = null;

    /** The supplier as the book knows it, when it matched one. */
    #[ORM\ManyToOne(targetEntity: Party::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Party $party = null;

    /** The expense account proposed: the supplier's usual one. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $account = null;

    #[ORM\Column(length: 16, enumType: SupplierInvoiceStatus::class)]
    private SupplierInvoiceStatus $status = SupplierInvoiceStatus::PROPOSED;

    /** Why it was refused, or why it could not be read. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entry $entry = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $decidedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /** @var list<array{status: string, at: string, sent: bool, error?: ?string, flow?: ?string, reason?: ?string, amount?: ?string}> the lifecycle statuses declared, oldest first: sent to the gateway, or waiting to be */
    #[ORM\Column(type: 'json')]
    private array $statuses = [];

    public function __construct(Book $book, string $syntax, string $filename, string $sha256, ?string $gateway = null, ?string $flowReference = null)
    {
        $this->book = $book;
        $this->syntax = $syntax;
        $this->filename = mb_substr($filename, 0, 255);
        $this->sha256 = $sha256;
        $this->gateway = $gateway;
        $this->flowReference = $flowReference;
        $this->currency = $book->getCurrency();
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return trim(($this->supplierName ?? $this->filename).' '.($this->number ?? ''));
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    public function getGateway(): ?string { return $this->gateway; }
    public function getFlowReference(): ?string { return $this->flowReference; }
    public function getSyntax(): string { return $this->syntax; }
    public function getFilename(): string { return $this->filename; }
    public function getFile(): ?string { return $this->file; }
    public function setFile(?string $file): self { $this->file = $file; return $this; }
    public function getSha256(): string { return $this->sha256; }
    public function getNumber(): ?string { return $this->number; }
    public function getIssuedOn(): ?\DateTimeImmutable { return $this->issuedOn; }
    public function getDueOn(): ?\DateTimeImmutable { return $this->dueOn; }
    public function getCurrency(): string { return $this->currency; }
    public function getSupplierName(): ?string { return $this->supplierName; }
    public function getSupplierSiren(): ?string { return $this->supplierSiren; }
    public function getSupplierSiret(): ?string { return $this->supplierSiret; }
    public function getSupplierVat(): ?string { return $this->supplierVat; }
    public function getSupplierIban(): ?string { return $this->supplierIban; }
    public function getTotalExcl(): ?int { return $this->totalExcl; }
    public function getTotalVat(): ?int { return $this->totalVat; }
    public function getTotalIncl(): ?int { return $this->totalIncl; }
    /** @return list<array{rate: int, base: int, vat: int, category: string}> */
    public function getVat(): array { return $this->vat; }
    public function isCreditNote(): bool { return $this->creditNote; }
    public function getDedupe(): ?string { return $this->dedupe; }
    public function getDuplicateOf(): ?SupplierInvoice { return $this->duplicateOf; }
    public function getParty(): ?Party { return $this->party; }
    public function setParty(?Party $party): self { $this->party = $party; return $this; }
    public function getAccount(): ?string { return $this->account; }
    public function setAccount(?string $account): self { $this->account = null === $account || '' === trim($account) ? null : strtoupper(preg_replace('/\s+/', '', $account)); return $this; }
    public function getStatus(): SupplierInvoiceStatus { return $this->status; }
    public function getReason(): ?string { return $this->reason; }
    public function getEntry(): ?Entry { return $this->entry; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function getDecidedBy(): ?string { return $this->decidedBy; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }

    /** What the file says, as read (InvoiceReader). */
    public function describe(?string $number, ?\DateTimeImmutable $issuedOn, ?\DateTimeImmutable $dueOn, ?string $currency, ?string $supplierName, ?string $siren, ?string $siret, ?string $vatNumber, ?string $iban, ?int $totalExcl, ?int $totalVat, ?int $totalIncl, array $vat, bool $creditNote = false): self
    {
        $this->creditNote = $creditNote;
        $this->number = null === $number ? null : mb_substr(trim($number), 0, 64);
        $this->issuedOn = $issuedOn?->setTime(0, 0);
        $this->dueOn = $dueOn?->setTime(0, 0);
        $this->currency = strtoupper($currency ?: $this->currency);
        $this->supplierName = null === $supplierName ? null : mb_substr(trim($supplierName), 0, 255);
        $this->supplierSiren = $siren;
        $this->supplierSiret = $siret;
        $this->supplierVat = $vatNumber;
        $this->supplierIban = Party::normalizeIban($iban);
        $this->totalExcl = $totalExcl;
        $this->totalVat = $totalVat;
        $this->totalIncl = $totalIncl;
        $this->vat = array_values($vat);

        return $this;
    }

    /** The supplier as it identifies itself: its SIREN, else its VAT number, else its name. */
    public function supplierKey(): ?string
    {
        if (null !== $this->supplierSiren) {
            return 'siren:'.$this->supplierSiren;
        }
        if (null !== $this->supplierVat) {
            return 'vat:'.strtoupper(preg_replace('/\W/', '', $this->supplierVat));
        }
        $name = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $this->supplierName)));

        return '' === $name ? null : 'name:'.$name;
    }

    /** "<supplier>|<number>": what makes the same invoice the same; null when the file did not say. */
    public function dedupeKey(): ?string
    {
        $supplier = $this->supplierKey();
        if (null === $supplier || null === $this->number || '' === $this->number) {
            return null;
        }

        return mb_substr($supplier.'|'.strtoupper($this->number), 0, 191);
    }

    /** The first of its kind: it takes the key. */
    public function markOriginal(): self
    {
        $this->dedupe = $this->dedupeKey();

        return $this;
    }

    public function markDuplicateOf(SupplierInvoice $original): self
    {
        $this->dedupe = null;
        $this->duplicateOf = $original;
        $this->status = SupplierInvoiceStatus::DUPLICATE;

        return $this;
    }

    public function markUnreadable(string $reason): self
    {
        $this->status = SupplierInvoiceStatus::UNREADABLE;
        $this->reason = mb_substr($reason, 0, 255);

        return $this;
    }

    public function book(Entry $entry, ?string $by): self
    {
        $this->entry = $entry;
        $this->status = SupplierInvoiceStatus::BOOKED;
        $this->reason = null;
        $this->decidedAt = new \DateTimeImmutable();
        $this->decidedBy = $by;

        return $this;
    }

    public function refuse(string $reason, ?string $by): self
    {
        $this->status = SupplierInvoiceStatus::REFUSED;
        $this->reason = mb_substr($reason, 0, 255);
        $this->decidedAt = new \DateTimeImmutable();
        $this->decidedBy = $by;

        return $this;
    }

    public function markPaid(\DateTimeImmutable $on): self
    {
        $this->status = SupplierInvoiceStatus::PAID;
        $this->paidAt = $on;

        return $this;
    }

    /** @return list<array{status: string, at: string, sent: bool, error?: ?string, flow?: ?string, reason?: ?string, amount?: ?string}> */
    public function getStatuses(): array { return $this->statuses; }

    /** A lifecycle status to declare: kept, sent or not. Returns its index. */
    public function declare(string $status, ?string $reason = null, ?string $amount = null): int
    {
        $this->statuses[] = ['status' => $status, 'at' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'sent' => false, 'reason' => $reason, 'amount' => $amount];

        return \count($this->statuses) - 1;
    }

    public function statusSent(int $index, ?string $flow): self
    {
        $this->statuses[$index] = ['sent' => true, 'flow' => $flow, 'error' => null] + $this->statuses[$index];

        return $this;
    }

    public function statusFailed(int $index, string $error): self
    {
        $this->statuses[$index]['error'] = mb_substr($error, 0, 255);

        return $this;
    }

    /** @return array<int, array{status: string, at: string, sent: bool, error?: ?string, flow?: ?string, reason?: ?string, amount?: ?string}> by index, oldest first */
    public function unsentStatuses(): array
    {
        return array_filter($this->statuses, static fn (array $s) => !$s['sent']);
    }

    /** Whether a lifecycle status was declared already (sent or not). */
    public function hasDeclared(string $status): bool
    {
        return [] !== array_filter($this->statuses, static fn (array $s) => $s['status'] === $status);
    }
}
