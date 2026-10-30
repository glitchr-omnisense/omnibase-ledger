<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Repository\BankAccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Omnibank\Model\AccountKind;

/**
 * One account at the bank, as its connection's gateway sees it ($externalId),
 * tied to the book's ledger account for it (a 512) and the journal its
 * movements are posted in (BQ).
 */
#[ORM\Entity(repositoryClass: BankAccountRepository::class)]
#[ORM\Table(name: 'ledger_bank_account')]
#[ORM\UniqueConstraint(name: 'ledger_bank_account_external', fields: ['connection', 'externalId'])]
class BankAccount
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankConnection::class, inversedBy: 'accounts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BankConnection $connection;

    #[ORM\Column(length: 191)]
    private string $externalId;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** Its ledger account: 512, 5121... */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: Journal::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Journal $journal;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    /** The balance the bank last gave, in minor units, and when: shown next to the ledger's own. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private int|string|null $bankBalance = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $bankBalanceAt = null;

    /** What the account is: an Omnibank AccountKind's value, the gateway's mapping of its own word ($providerType). Stored as text, so a kind added later reads as unknown, never as an error. */
    #[ORM\Column(length: 24, options: ['default' => 'unknown'])]
    private string $kind = 'unknown';

    /** The gateway's own word for the account's type, as it gave it ("livret_a", "CACC"...). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $providerType = null;

    public function __construct(BankConnection $connection, string $externalId, string $name, Account $account, Journal $journal, ?string $iban = null, ?string $currency = null)
    {
        $this->connection = $connection;
        $this->externalId = $externalId;
        $this->name = $name;
        $this->account = $account;
        $this->journal = $journal;
        $this->iban = Party::normalizeIban($iban);
        $this->currency = strtoupper($currency ?? $connection->getBook()->getCurrency());
        $connection->addAccount($this);
    }

    public function __toString(): string
    {
        return $this->name.(null !== $this->iban ? ' ('.self::mask($this->iban).')' : '');
    }

    /** FR76 •••• 4321: what a screen shows of an IBAN. */
    public static function mask(?string $iban): string
    {
        if (null === $iban || \strlen($iban) < 8) {
            return (string) $iban;
        }

        return substr($iban, 0, 4).' •••• '.substr($iban, -4);
    }

    public function getId(): ?int { return $this->id; }
    public function getConnection(): BankConnection { return $this->connection; }
    public function getBook(): Book { return $this->connection->getBook(); }
    public function getExternalId(): string { return $this->externalId; }
    public function getIban(): ?string { return $this->iban; }
    public function setIban(?string $iban): self { $this->iban = Party::normalizeIban($iban); return $this; }
    public function getMaskedIban(): string { return self::mask($this->iban); }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function getAccount(): Account { return $this->account; }
    public function setAccount(Account $account): self { $this->account = $account; return $this; }
    public function getJournal(): Journal { return $this->journal; }
    public function setJournal(Journal $journal): self { $this->journal = $journal; return $this; }
    public function getLastSyncedAt(): ?\DateTimeImmutable { return $this->lastSyncedAt; }
    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): self { $this->lastSyncedAt = $lastSyncedAt; return $this; }
    public function getBankBalance(): ?int { return null === $this->bankBalance ? null : (int) $this->bankBalance; }
    public function getBankBalanceAt(): ?\DateTimeImmutable { return $this->bankBalanceAt; }

    public function getKind(): AccountKind { return AccountKind::of($this->kind); }
    public function setKind(AccountKind $kind): self { $this->kind = $kind->value; return $this; }
    public function getProviderType(): ?string { return $this->providerType; }
    public function setProviderType(?string $type): self { $this->providerType = null === $type ? null : mb_substr($type, 0, 64); return $this; }

    /** The balance to show: the bank's last one, else what its lines add up to ($fallback). */
    public function getCurrentBalance(?int $fallback = null): ?int
    {
        return $this->getBankBalance() ?? $fallback;
    }

    public function setBankBalance(?int $balance, ?\DateTimeImmutable $at = null): self
    {
        $this->bankBalance = $balance;
        $this->bankBalanceAt = null === $balance ? null : ($at ?? new \DateTimeImmutable());

        return $this;
    }
}
