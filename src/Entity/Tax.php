<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Repository\TaxRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A VAT code of a book: its rate in basis points (2000 = 20 %), what it is
 * (TaxKind) and the account its VAT goes to - 44571 collected, 44566
 * deductible, 44562 deductible on fixed assets, 4452 due on an intra-EU
 * acquisition, whose deductible half goes to $deductibleAccount. An exempt
 * code has no account: only its base is reported. Chart::seed() gives a book
 * the French set; a VAT line of an entry points to its code and keeps its
 * base (EntryLine::$tax, $taxBase).
 */
#[ORM\Entity(repositoryClass: TaxRepository::class)]
#[ORM\Table(name: 'ledger_tax')]
#[ORM\UniqueConstraint(name: 'ledger_tax_code', fields: ['book', 'code'])]
class Tax
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\Column(length: 16)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 16)]
    private string $code;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label;

    /** Basis points: 2000 for 20 %, 550 for 5.5 %. */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 10000)]
    private int $rate;

    #[ORM\Column(length: 24, enumType: TaxKind::class)]
    private TaxKind $kind;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Account $account;

    /** A reverse charge's deductible side (44566). */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Account $deductibleAccount;

    public function __construct(Book $book, string $code, string $label, int $rate, TaxKind $kind, ?Account $account = null, ?Account $deductibleAccount = null)
    {
        $this->book = $book;
        $this->setCode($code);
        $this->label = $label;
        $this->rate = $rate;
        $this->kind = $kind;
        $this->account = $account;
        $this->deductibleAccount = $deductibleAccount;
    }

    public function __toString(): string
    {
        return $this->code.' - '.$this->label;
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    public function setBook(Book $book): self { $this->book = $book; return $this; }
    public function getCode(): string { return $this->code; }
    public function setCode(?string $code): self { $this->code = self::normalize((string) $code); return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = (string) $label; return $this; }
    public function getRate(): int { return $this->rate; }
    public function setRate(?int $rate): self { $this->rate = (int) $rate; return $this; }
    public function getKind(): TaxKind { return $this->kind; }
    public function setKind(TaxKind $kind): self { $this->kind = $kind; return $this; }
    public function getAccount(): ?Account { return $this->account; }
    public function setAccount(?Account $account): self { $this->account = $account; return $this; }
    public function getDeductibleAccount(): ?Account { return $this->deductibleAccount; }
    public function setDeductibleAccount(?Account $deductibleAccount): self { $this->deductibleAccount = $deductibleAccount; return $this; }

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', $code));
    }

    /**
     * $amount (minor units) split into its base and its VAT, rounded to the
     * cent half up as an invoice is: all taxes included, the VAT is taken
     * out of it and the base takes what the rounding leaves; before tax, the
     * VAT is added to it. A reverse charge's amount is its base (the
     * supplier invoices no VAT); an exempt code's VAT is zero.
     *
     * @return array{0: int, 1: int} [base, VAT]
     */
    public function split(int $amount, bool $taxIncluded = true): array
    {
        if ($amount < 0) {
            [$base, $vat] = $this->split(-$amount, $taxIncluded);

            return [-$base, -$vat];
        }
        if (TaxKind::EXEMPT === $this->kind || 0 === $this->rate) {
            return [$amount, 0];
        }
        if (!$taxIncluded || TaxKind::REVERSE_CHARGE === $this->kind) {
            return [$amount, self::roundedShare($amount, $this->rate, 10000)];
        }
        $vat = self::roundedShare($amount, $this->rate, 10000 + $this->rate);

        return [$amount - $vat, $vat];
    }

    /** round($amount * $numerator / $denominator), half up, in integers. */
    private static function roundedShare(int $amount, int $numerator, int $denominator): int
    {
        return intdiv(2 * $amount * $numerator + $denominator, 2 * $denominator);
    }
}
