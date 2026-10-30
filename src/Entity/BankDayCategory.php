<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Repository\BankDayCategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Omnibank\Model\TransactionCategory;

/**
 * One bank account's day for one category (an Omnibank TransactionCategory's
 * value, or "none" for the lines the gateway did not categorise), precomputed
 * from its lines (Bank\Aggregates): in, out and how many lines each way.
 */
#[ORM\Entity(repositoryClass: BankDayCategoryRepository::class)]
#[ORM\Table(name: 'ledger_bank_day_category')]
#[ORM\UniqueConstraint(name: 'ledger_bank_day_category_unique', fields: ['bankAccount', 'day', 'category'])]
class BankDayCategory
{
    public const NONE = 'none';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BankAccount $bankAccount;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column(length: 24)]
    private string $category;

    #[ORM\Column(type: 'bigint')]
    private int|string $inflow;

    #[ORM\Column(type: 'bigint')]
    private int|string $outflow;

    #[ORM\Column]
    private int $inflowCount;

    #[ORM\Column]
    private int $outflowCount;

    public function __construct(BankAccount $bankAccount, \DateTimeImmutable $day, string $category, int $inflow, int $outflow, int $inflowCount, int $outflowCount)
    {
        $this->bankAccount = $bankAccount;
        $this->day = $day->setTime(0, 0);
        $this->category = $category;
        $this->inflow = $inflow;
        $this->outflow = $outflow;
        $this->inflowCount = $inflowCount;
        $this->outflowCount = $outflowCount;
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getDay(): \DateTimeImmutable { return $this->day; }
    /** The category's value, or "none". */
    public function getCategory(): string { return $this->category; }
    public function getTransactionCategory(): ?TransactionCategory { return self::NONE === $this->category ? null : TransactionCategory::of($this->category); }
    public function getInflow(): int { return (int) $this->inflow; }
    public function getOutflow(): int { return (int) $this->outflow; }
    public function getInflowCount(): int { return $this->inflowCount; }
    public function getOutflowCount(): int { return $this->outflowCount; }
    public function getCount(): int { return $this->inflowCount + $this->outflowCount; }
}
