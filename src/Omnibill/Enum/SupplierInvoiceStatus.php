<?php

namespace Base\Ledger\Omnibill\Enum;

/**
 * Where a supplier's invoice stands in the book: proposed as an entry until
 * a person books it or refuses it, then paid once a bank line settles it.
 * A duplicate (the same supplier and number as one already received) is
 * kept for the record and never proposed.
 */
enum SupplierInvoiceStatus: string
{
    /** Read and proposed: waiting for a person. */
    case PROPOSED = 'proposed';
    /** Read, but not as an invoice the book can propose (no file reader, a file it cannot read): a person types it in or refuses it. */
    case UNREADABLE = 'unreadable';
    /** Booked: its entry posted and validated by a person. */
    case BOOKED = 'booked';
    case REFUSED = 'refused';
    /** Settled by a bank line (the entry's supplier line lettered). */
    case PAID = 'paid';
    /** The same supplier and number as an invoice already received: never proposed. */
    case DUPLICATE = 'duplicate';

    /** Whether it waits for a person. */
    public function isOpen(): bool
    {
        return self::PROPOSED === $this || self::UNREADABLE === $this;
    }
}
