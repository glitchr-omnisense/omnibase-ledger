<?php

namespace Base\Ledger\Omnibill\Invoice;

use Base\Ledger\Omnibill\Entity\SupplierInvoice;

/**
 * Writes the lifecycle message (CDAR) an approved platform carries a
 * status as: omnibill's afnor gateway sends the file it is given and writes
 * none (the format is not the family's), and the ledger writes none either.
 * An application that has a writer registers it under this interface; the
 * e-mail gateway needs none. Without one, a status for an approved
 * platform stays unsent in the book, its error saying why.
 */
interface LifecycleMessageInterface
{
    /**
     * @param string      $status omnibill's LifecycleStatus value: taken_over, approved, refused, paid
     * @param string|null $amount for paid, a decimal string
     *
     * @return array{content: string, filename: string}|null null: no message for this one
     */
    public function write(SupplierInvoice $invoice, string $status, ?string $reason = null, ?string $amount = null): ?array;
}
