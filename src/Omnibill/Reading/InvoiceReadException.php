<?php

namespace Base\Ledger\Omnibill\Reading;

use Base\Ledger\Exception\LedgerException;

/** A supplier's invoice file the book could not read: it arrives UNREADABLE, for a person. */
class InvoiceReadException extends LedgerException
{
}
