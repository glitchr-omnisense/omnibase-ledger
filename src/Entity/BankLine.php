<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Enum\BankLineStatus;
use Base\Ledger\Repository\BankLineRepository;
use Doctrine\ORM\Mapping as ORM;
use Omnibank\Model\TransactionCategory;

/**
 * A transaction on a bank account's statement, as the bank booked it -
 * negative when money leaves. Imported once (its $externalId is unique per
 * bank account), then reconciled: posted in the bank journal ($entry) and
 * lettered with what it pays, or ignored.
 */
#[ORM\Entity(repositoryClass: BankLineRepository::class)]
#[ORM\Table(name: 'ledger_bank_line')]
#[ORM\UniqueConstraint(name: 'ledger_bank_line_external', fields: ['bankAccount', 'externalId'])]
#[ORM\Index(name: 'ledger_bank_line_status', fields: ['bankAccount', 'status'])]
#[ORM\Index(name: 'ledger_bank_line_booked', fields: ['bankAccount', 'bookedOn'])]
class BankLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BankAccount $bankAccount;

    #[ORM\Column(length: 191)]
    private string $externalId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $bookedOn;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $valueOn = null;

    /** Signed minor units: negative when money leaves the account. */
    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 512)]
    private string $label;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $counterpartyName = null;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $counterpartyIban = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: 'json')]
    private array $raw = [];

    /** What it was for: an Omnibank TransactionCategory's value, when the gateway categorised it. */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(length: 16, enumType: BankLineStatus::class)]
    private BankLineStatus $status = BankLineStatus::UNMATCHED;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entry $entry = null;

    /** How many times it was reconciled: an undone reconciliation's entry keeps its source, the next one gets "bank:<id>/2". */
    #[ORM\Column]
    private int $reconciliations = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(BankAccount $bankAccount, string $externalId, \DateTimeImmutable $bookedOn, int $amount, string $label, ?string $currency = null)
    {
        $this->bankAccount = $bankAccount;
        $this->externalId = $externalId;
        $this->bookedOn = $bookedOn->setTime(0, 0);
        $this->amount = $amount;
        $this->setLabel($label);
        $this->currency = strtoupper($currency ?? $bankAccount->getCurrency());
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s %s %s', $this->bookedOn->format('d/m/Y'), $this->label, number_format($this->amount / 100, 2, ',', ' '));
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getBook(): Book { return $this->bankAccount->getBook(); }
    public function getExternalId(): string { return $this->externalId; }
    public function getBookedOn(): \DateTimeImmutable { return $this->bookedOn; }
    public function setBookedOn(\DateTimeImmutable $bookedOn): self { $this->bookedOn = $bookedOn->setTime(0, 0); return $this; }
    public function getValueOn(): ?\DateTimeImmutable { return $this->valueOn; }
    public function setValueOn(?\DateTimeImmutable $valueOn): self { $this->valueOn = $valueOn?->setTime(0, 0); return $this; }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $amount): self { $this->amount = $amount; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = mb_substr(trim(preg_replace('/\s+/', ' ', $label)), 0, 512); return $this; }
    public function getCounterpartyName(): ?string { return $this->counterpartyName; }
    public function setCounterpartyName(?string $name): self { $this->counterpartyName = null === $name ? null : mb_substr($name, 0, 255); return $this; }
    public function getCounterpartyIban(): ?string { return $this->counterpartyIban; }
    public function setCounterpartyIban(?string $iban): self { $this->counterpartyIban = Party::normalizeIban($iban); return $this; }
    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): self { $this->reference = null === $reference ? null : mb_substr($reference, 0, 255); return $this; }
    public function getRaw(): array { return $this->raw; }
    public function setRaw(array $raw): self { $this->raw = $raw; return $this; }
    public function getCategory(): ?TransactionCategory { return TransactionCategory::of($this->category); }
    public function setCategory(?TransactionCategory $category): self { $this->category = $category?->value; return $this; }
    public function getStatus(): BankLineStatus { return $this->status; }
    public function setStatus(BankLineStatus $status): self { $this->status = $status; return $this; }
    public function getEntry(): ?Entry { return $this->entry; }
    public function setEntry(?Entry $entry): self { $this->entry = $entry; return $this; }
    public function getReconciliations(): int { return $this->reconciliations; }
    public function countReconciliation(): self { ++$this->reconciliations; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** The entry source of its next reconciliation: "bank:<id>", then "bank:<id>/2" after an undo. */
    public function getSource(): string
    {
        $source = 'bank:'.($this->id ?? $this->bankAccount->getId().':'.$this->externalId);

        return 0 === $this->reconciliations ? $source : $source.'/'.($this->reconciliations + 1);
    }
}
