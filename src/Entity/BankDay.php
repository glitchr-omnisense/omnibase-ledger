<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Repository\BankDayRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One bank account's day, precomputed from its lines (Bank\Aggregates): what
 * came in and went out, how many lines each way, and the balance at the end
 * of the day. Only days with a movement have a row; the balance carries over
 * the days between. The dashboard's widgets read these, never the lines.
 */
#[ORM\Entity(repositoryClass: BankDayRepository::class)]
#[ORM\Table(name: 'ledger_bank_day')]
#[ORM\UniqueConstraint(name: 'ledger_bank_day_unique', fields: ['bankAccount', 'day'])]
class BankDay
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BankAccount $bankAccount;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    /** Minor units in, positive. */
    #[ORM\Column(type: 'bigint')]
    private int|string $inflow;

    /** Minor units out, positive. */
    #[ORM\Column(type: 'bigint')]
    private int|string $outflow;

    #[ORM\Column]
    private int $inflowCount;

    #[ORM\Column]
    private int $outflowCount;

    /** Of what came in and went out, the transfers (lines categorised so, or carrying the counterparty's IBAN): how much and how many each way. */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int|string $transferIn = 0;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int|string $transferOut = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $transferInCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $transferOutCount = 0;

    /** The balance once the day's lines are booked: anchored on the bank's last balance when it gave one, else on the lines alone (from zero). */
    #[ORM\Column(type: 'bigint')]
    private int|string $closingBalance;

    public function __construct(BankAccount $bankAccount, \DateTimeImmutable $day, int $inflow, int $outflow, int $inflowCount, int $outflowCount, int $closingBalance, int $transferIn = 0, int $transferOut = 0, int $transferInCount = 0, int $transferOutCount = 0)
    {
        $this->transferIn = $transferIn;
        $this->transferOut = $transferOut;
        $this->transferInCount = $transferInCount;
        $this->transferOutCount = $transferOutCount;
        $this->bankAccount = $bankAccount;
        $this->day = $day->setTime(0, 0);
        $this->inflow = $inflow;
        $this->outflow = $outflow;
        $this->inflowCount = $inflowCount;
        $this->outflowCount = $outflowCount;
        $this->closingBalance = $closingBalance;
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getDay(): \DateTimeImmutable { return $this->day; }
    public function getInflow(): int { return (int) $this->inflow; }
    public function getOutflow(): int { return (int) $this->outflow; }
    public function getInflowCount(): int { return $this->inflowCount; }
    public function getOutflowCount(): int { return $this->outflowCount; }
    public function getNet(): int { return $this->getInflow() - $this->getOutflow(); }
    public function getTransferIn(): int { return (int) $this->transferIn; }
    public function getTransferOut(): int { return (int) $this->transferOut; }
    public function getTransferInCount(): int { return $this->transferInCount; }
    public function getTransferOutCount(): int { return $this->transferOutCount; }
    public function getClosingBalance(): int { return (int) $this->closingBalance; }
    public function getOpeningBalance(): int { return $this->getClosingBalance() - $this->getNet(); }
}
