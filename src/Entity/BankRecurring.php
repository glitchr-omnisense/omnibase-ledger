<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Repository\BankRecurringRepository;
use Doctrine\ORM\Mapping as ORM;
use Omnibank\Model\TransactionCategory;

/**
 * A flow that comes back on a bank account at a steady rhythm - a rent, a
 * loan's instalment, a subscription, a salary -, found in its lines by
 * Bank\Recurrences: the same counterparty, the same direction, an amount
 * that hardly moves, at least three times at a weekly, monthly, quarterly or
 * yearly interval, the last one recent enough for the next to be due.
 */
#[ORM\Entity(repositoryClass: BankRecurringRepository::class)]
#[ORM\Table(name: 'ledger_bank_recurring')]
class BankRecurring
{
    public const WEEKLY = 'weekly';
    public const MONTHLY = 'monthly';
    public const QUARTERLY = 'quarterly';
    public const YEARLY = 'yearly';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BankAccount $bankAccount;

    #[ORM\Column(length: 191)]
    private string $counterparty;

    #[ORM\Column(length: 255)]
    private string $label;

    /** The usual amount, signed (negative going out), in minor units: the median of the occurrences. */
    #[ORM\Column(type: 'bigint')]
    private int|string $amount;

    #[ORM\Column(length: 16)]
    private string $rhythm;

    #[ORM\Column]
    private int $occurrences;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $lastOn;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $nextOn;

    #[ORM\Column(length: 24, nullable: true)]
    private ?string $category;

    public function __construct(BankAccount $bankAccount, string $counterparty, string $label, int $amount, string $rhythm, int $occurrences, \DateTimeImmutable $lastOn, \DateTimeImmutable $nextOn, ?TransactionCategory $category = null)
    {
        $this->bankAccount = $bankAccount;
        $this->counterparty = mb_substr($counterparty, 0, 191);
        $this->label = mb_substr($label, 0, 255);
        $this->amount = $amount;
        $this->rhythm = $rhythm;
        $this->occurrences = $occurrences;
        $this->lastOn = $lastOn->setTime(0, 0);
        $this->nextOn = $nextOn->setTime(0, 0);
        $this->category = $category?->value;
    }

    public function getId(): ?int { return $this->id; }
    public function getBankAccount(): BankAccount { return $this->bankAccount; }
    public function getCounterparty(): string { return $this->counterparty; }
    public function getLabel(): string { return $this->label; }
    public function getAmount(): int { return (int) $this->amount; }
    public function getRhythm(): string { return $this->rhythm; }
    public function getOccurrences(): int { return $this->occurrences; }
    public function getLastOn(): \DateTimeImmutable { return $this->lastOn; }
    public function getNextOn(): \DateTimeImmutable { return $this->nextOn; }
    public function getCategory(): ?TransactionCategory { return TransactionCategory::of($this->category); }
    public function isIncoming(): bool { return $this->getAmount() > 0; }

    /** The dates it falls on after $after, up to $until included. @return list<\DateTimeImmutable> */
    public function occurrencesBetween(\DateTimeImmutable $after, \DateTimeImmutable $until): array
    {
        $dates = [];
        $next = $this->nextOn;
        for ($i = 0; $next <= $until && $i < 400; ++$i) {
            if ($next > $after) {
                $dates[] = $next;
            }
            $next = self::step($next, $this->rhythm, $this->lastOn);
        }

        return $dates;
    }

    /** The date one rhythm after $from; a monthly date keeps $anchor's day of the month where the month has it. */
    public static function step(\DateTimeImmutable $from, string $rhythm, ?\DateTimeImmutable $anchor = null): \DateTimeImmutable
    {
        $months = match ($rhythm) {
            self::MONTHLY => 1,
            self::QUARTERLY => 3,
            self::YEARLY => 12,
            default => 0,
        };
        if (0 === $months) {
            return $from->modify('+7 days');
        }
        $day = (int) ($anchor ?? $from)->format('j');
        $first = $from->modify('first day of this month')->modify(sprintf('+%d months', $months));

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, (int) $first->format('t')));
    }
}
