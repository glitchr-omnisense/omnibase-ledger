<?php

namespace Base\Ledger\Reconciliation;

use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\ReconciliationRule;

/**
 * What a bank line may be (Matcher), or what someone in the back office
 * says it is: the open items it pays - lettered with it when they add up to
 * its amount -, else the rule it falls under, else the party it comes from
 * (a payment on account). $score runs from 0 to 1; Reconciler applies a
 * suggestion on its own only when it is the single one at or above
 * ledger.reconciliation.auto_threshold.
 *
 * $parts, given by hand (the front office's category or split), say where
 * each share of the line goes and prevail over the rest.
 */
final readonly class Suggestion
{
    /**
     * @param list<EntryLine> $openItems
     * @param list<Part>      $parts
     */
    public function __construct(
        public float $score,
        public string $reason,
        public array $openItems = [],
        public ?ReconciliationRule $rule = null,
        public ?Party $party = null,
        public array $parts = [],
    ) {
    }

    /** A suggestion made of parts given by hand (a category for the whole line, a split). */
    public static function ofParts(string $reason, Part ...$parts): self
    {
        return new self(1.0, $reason, [], null, null, array_values($parts));
    }

    /** What the open items add up to, debit minus credit: the bank amount they settle exactly. */
    public function total(): int
    {
        return array_sum(array_map(fn (EntryLine $line) => $line->getNet(), $this->openItems));
    }

    /** Whether it settles $amount exactly: its open items are then lettered with the bank entry. */
    public function settles(int $amount): bool
    {
        return [] !== $this->openItems && $this->total() === $amount;
    }

    /** Identity of what it proposes, whatever its score: two ways to the same items are one suggestion. */
    public function key(): string
    {
        $ids = array_map(fn (EntryLine $line) => $line->getId() ?? 'o'.spl_object_id($line), $this->openItems);
        sort($ids);

        return implode(',', $ids).'|'.($this->rule ? ($this->rule->getId() ?? 'o'.spl_object_id($this->rule)) : '').'|'.($this->party ? ($this->party->getId() ?? 'o'.spl_object_id($this->party)) : '');
    }

    public function withScore(float $score, ?string $reason = null): self
    {
        return new self($score, $reason ?? $this->reason, $this->openItems, $this->rule, $this->party, $this->parts);
    }
}
