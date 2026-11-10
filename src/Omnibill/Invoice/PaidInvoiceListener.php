<?php

namespace Base\Ledger\Omnibill\Invoice;

use Base\Ledger\Entity\Entry;
use Base\Ledger\Event\BankLineReconciled;
use Base\Ledger\Omnibill\Repository\SupplierInvoiceRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A bank line that settles a booked supplier's invoice - its entry's
 * supplier line among the open items the reconciliation lettered - makes
 * it paid, declared to the supplier's platform with its amount.
 */
#[AsEventListener(event: BankLineReconciled::class)]
final class PaidInvoiceListener
{
    public function __construct(
        private readonly SupplierInvoiceRepository $invoices,
        private readonly SupplierInvoices $supplierInvoices,
    ) {
    }

    public function __invoke(BankLineReconciled $event): void
    {
        $seen = [];
        foreach ($event->openItems as $line) {
            $entry = $line->getEntry();
            if (isset($seen[spl_object_id($entry)]) || !$line->isLettered()) {
                continue;
            }
            $seen[spl_object_id($entry)] = true;
            $invoice = $this->invoices->findOneByEntry($entry);
            if (null !== $invoice && $this->settled($entry)) {
                $this->supplierInvoices->paid($invoice, $event->line->getBookedOn());
            }
        }
    }

    /** Whether every line of the entry on a supplier's account is lettered: the whole invoice paid, not a part. */
    private function settled(Entry $entry): bool
    {
        foreach ($entry->getLines() as $line) {
            if (null !== $line->getParty() && !$line->isLettered()) {
                return false;
            }
        }

        return true;
    }
}
