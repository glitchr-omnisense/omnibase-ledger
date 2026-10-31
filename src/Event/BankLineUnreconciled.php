<?php

namespace Base\Ledger\Event;

use Base\Ledger\Entity\BankLine;
use Base\Ledger\Entity\Entry;

/**
 * A reconciled bank line taken back (Reconciler::undo): its entry reversed,
 * what it settled open again. What followed BankLineReconciled - a rent call
 * paid, an invoice settled - is undone from the line.
 */
final class BankLineUnreconciled
{
    public function __construct(
        public readonly BankLine $line,
        public readonly Entry $entry,
        public readonly Entry $reversal,
    ) {
    }
}
