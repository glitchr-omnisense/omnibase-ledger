<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Enum\ConsentState;
use Base\Ledger\Repository\BankConnectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Omnibank\Model\ConnectionState;

/**
 * A book's access to a bank through one of Omnibank's gateways ("files",
 * "qonto", "powens", "bridge"): the gateway's Connection state (tokens, the
 * provider's user) sealed with sodium's secretbox (Bank\Connections reads
 * and writes it, never in clear), the consent the bank gave and until when,
 * and the last error a sync met.
 */
#[ORM\Entity(repositoryClass: BankConnectionRepository::class)]
#[ORM\Table(name: 'ledger_bank_connection')]
class BankConnection
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    /** The Omnibank Registry's name for the gateway. */
    #[ORM\Column(length: 32)]
    private string $gateway;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name;

    /** Sealed: see Bank\Connections. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $state = null;

    #[ORM\Column(length: 16, enumType: ConsentState::class)]
    private ConsentState $consentStatus = ConsentState::NONE;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    /** How the access is doing, beside the consent: an Omnibank ConnectionState's value, from the last sync, the gateway's account or webhook. Pending until the first sync went through. */
    #[ORM\Column(length: 24, options: ['default' => 'pending'])]
    private string $connectionState = 'pending';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $connectionStateAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, BankAccount> */
    #[ORM\OneToMany(targetEntity: BankAccount::class, mappedBy: 'connection')]
    private Collection $accounts;

    public function __construct(Book $book, string $gateway, ?string $name = null)
    {
        $this->book = $book;
        $this->gateway = $gateway;
        $this->name = $name;
        $this->accounts = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name ?? $this->gateway;
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    public function getGateway(): string { return $this->gateway; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }
    public function getState(): ?string { return $this->state; }
    /** The sealed state, as Bank\Connections::store() writes it. */
    public function setState(?string $state): self { $this->state = $state; return $this; }
    public function getConsentStatus(): ConsentState { return $this->consentStatus; }
    public function setConsentStatus(ConsentState $consentStatus): self { $this->consentStatus = $consentStatus; return $this; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $lastError): self { $this->lastError = null === $lastError ? null : mb_substr($lastError, 0, 2000); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getConnectionState(): ConnectionState { return ConnectionState::of($this->connectionState); }
    public function getConnectionStateAt(): ?\DateTimeImmutable { return $this->connectionStateAt; }

    public function setConnectionState(ConnectionState $state, ?\DateTimeImmutable $at = null): self
    {
        $this->connectionState = $state->value;
        $this->connectionStateAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /** @return Collection<int, BankAccount> */
    public function getAccounts(): Collection { return $this->accounts; }

    public function addAccount(BankAccount $account): self
    {
        if (!$this->accounts->contains($account)) {
            $this->accounts->add($account);
        }

        return $this;
    }

    /** Whether the bank wants its consent given again (expired, or said so). */
    public function needsRenewal(?\DateTimeImmutable $now = null): bool
    {
        if (ConsentState::NEEDS_RENEWAL === $this->consentStatus || ConsentState::REVOKED === $this->consentStatus) {
            return true;
        }

        return null !== $this->expiresAt && $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
