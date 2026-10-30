<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Repository\BankCounterpartyMonthRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What one counterparty paid into or took from one bank account in one
 * month, precomputed from its lines (Bank\Aggregates). $counterparty is the
 * key the lines are gathered under (Bank\Counterparty::key(): the IBAN, else
 * the name, else the label stripped of the bank's words), $name what to show.
 */
#[ORM\Entity(repositoryClass: BankCounterpartyMonthRepository::class)]
#[ORM\Table(name: 'ledger_bank_counterparty_month')]
#[ORM\UniqueConstraint(name: 'ledger_bank_counterparty_month_unique', fields: ['bankAccount', 'month', 'counterparty'])]
class BankCounterpartyMonth
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BankAccount $bankAccount;

    /** The month's first day. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $month;

    #[ORM\Column(length: 191)]
    private string $counterparty;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'bigint')]
    private int|string $inflow;

    #[ORM\Column(type: 'bigint')]
    private int|string $outflow;

    #[ORM\Column]
    private int $inflowCount;

    #[ORM\Column]
    private int $outflowCount;

    public function __construct(BankAccount $bankAccount, \DateTimeImmutable $month, string $counterparty, string $name, int $inflow, int $outflow, int $inflowCount, int $outflowCount)
    {
        $this->bankAccount = $bankAccount;
        $this->month = $month->modify('first day of this month')->setTime(0, 0);
        $this->counterparty = mb_substr($counterparty, 0, 191);
        $this->name = mb_substr($name, 0, 255);
        $this->inflow = $inflow;
        $this->outflow = $outflow;
        $this->inflowCount = $inflowCount;
        $this->outflowCount = $outflowCount;
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getMonth(): \DateTimeImmutable { return $this->month; }
    public function getCounterparty(): string { return $this->counterparty; }
    public function getName(): string { return $this->name; }
    public function getInflow(): int { return (int) $this->inflow; }
    public function getOutflow(): int { return (int) $this->outflow; }
    public function getInflowCount(): int { return $this->inflowCount; }
    public function getOutflowCount(): int { return $this->outflowCount; }
}
