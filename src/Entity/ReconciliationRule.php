<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Enum\RuleSign;
use Base\Ledger\Model\Text;
use Base\Ledger\Repository\ReconciliationRuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What to post a recurring bank line against when nothing open matches it:
 * bank fees to 627, the loan instalment split between 164 (capital) and
 * 6611 (interest), a Stripe payout to the 5112 clearing account. A rule
 * matches on the label ($pattern: words that must all appear, or a
 * /regular expression/), the counterparty's IBAN, and the direction; the
 * highest priority wins.
 *
 * $split, when set, spreads the amount over several accounts:
 * [{"account": "164", "amount": 85000}, {"account": "6611", "rest": true}]
 * - "amount" a fixed part in minor units, "percent" a share, "rest" what
 * remains (the rule's own account takes the rest when no part says so).
 * A part may carry a tax code ("tax": "A20"): it is then an amount all
 * taxes included, posted as its base and its VAT - a recurring supplier's
 * bank line posts its VAT: [{"account": "6061", "rest": true, "tax": "A20"}].
 */
#[ORM\Entity(repositoryClass: ReconciliationRuleRepository::class)]
#[ORM\Table(name: 'ledger_reconciliation_rule')]
class ReconciliationRule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pattern;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 8, enumType: RuleSign::class)]
    private RuleSign $sign = RuleSign::ANY;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: Party::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Party $party = null;

    #[ORM\Column]
    private int $priority = 0;

    /** @var list<array{account: string, amount?: int, percent?: float, rest?: bool, label?: string, tax?: string}>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $split = null;

    #[ORM\Column]
    private bool $enabled = true;

    public function __construct(Book $book, string $name, ?string $pattern, Account $account, RuleSign $sign = RuleSign::ANY)
    {
        $this->book = $book;
        $this->name = $name;
        $this->pattern = $pattern;
        $this->account = $account;
        $this->sign = $sign;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    public function setBook(Book $book): self { $this->book = $book; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = (string) $name; return $this; }
    public function getPattern(): ?string { return $this->pattern; }
    public function setPattern(?string $pattern): self { $this->pattern = $pattern; return $this; }
    public function getIban(): ?string { return $this->iban; }
    public function setIban(?string $iban): self { $this->iban = Party::normalizeIban($iban); return $this; }
    public function getSign(): RuleSign { return $this->sign; }
    public function setSign(RuleSign $sign): self { $this->sign = $sign; return $this; }
    public function getAccount(): Account { return $this->account; }
    public function setAccount(Account $account): self { $this->account = $account; return $this; }
    public function getParty(): ?Party { return $this->party; }
    public function setParty(?Party $party): self { $this->party = $party; return $this; }
    public function getPriority(): int { return $this->priority; }
    public function setPriority(int $priority): self { $this->priority = $priority; return $this; }
    public function getSplit(): ?array { return $this->split; }
    public function setSplit(?array $split): self { $this->split = [] === $split ? null : $split; return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }

    /** Whether a bank line of this label, counterparty IBAN and signed amount is one of this rule's. */
    public function matches(string $label, ?string $iban, int $amount): bool
    {
        if (!$this->enabled || !$this->sign->accepts($amount)) {
            return false;
        }
        if (null !== $this->iban && $this->iban !== Party::normalizeIban($iban)) {
            return false;
        }
        $pattern = trim((string) $this->pattern);
        if ('' === $pattern) {
            return null !== $this->iban;
        }
        if (\strlen($pattern) > 2 && '/' === $pattern[0] && false !== strrpos($pattern, '/', 1)) {
            return 1 === @preg_match($pattern.(str_ends_with($pattern, '/') ? 'iu' : ''), $label);
        }

        $haystack = ' '.Text::fold($label).' ';
        foreach (preg_split('/\s+/', Text::fold($pattern)) as $word) {
            if ('' !== $word && !str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }
}
