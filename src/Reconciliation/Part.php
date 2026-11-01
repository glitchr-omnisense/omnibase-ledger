<?php

namespace Base\Ledger\Reconciliation;

use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Party;

/**
 * A share of a bank line someone allocates by hand (a split, or one
 * category for the whole line): $amount, in minor units and positive,
 * posted on an account of the book - a tax code making it an amount all
 * taxes included, posted as its base and its VAT -, or settling open items
 * (lettered with them when it is exactly what they add up to, else on the
 * first one's account, a payment on account). The parts of a line add up to
 * its amount.
 */
final readonly class Part
{
    /** @param list<EntryLine> $openItems */
    public function __construct(
        public int $amount,
        public ?string $account = null,
        public array $openItems = [],
        public ?Party $party = null,
        public ?string $tax = null,
        public ?string $label = null,
    ) {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('A part of a bank line is a positive amount.');
        }
        if (null === $account && [] === $openItems && null === $party) {
            throw new \InvalidArgumentException('A part of a bank line goes to an account, open items or a party.');
        }
    }

    /** What its open items add up to, debit minus credit. */
    public function total(): int
    {
        return array_sum(array_map(fn (EntryLine $line) => $line->getNet(), $this->openItems));
    }
}
