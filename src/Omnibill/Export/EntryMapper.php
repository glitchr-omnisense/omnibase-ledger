<?php

namespace Base\Ledger\Omnibill\Export;

use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine as LedgerLine;
use Base\Ledger\Entity\Party;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Omnibill\Invoice\InvoiceProposer;
use Omnibill\Model\Contact;
use Omnibill\Model\ContactKind;
use Omnibill\Model\EntryLine;
use Omnibill\Model\JournalEntry;
use Omnibill\Model\Vat;
use Omnibill\Model\VatKind;

/**
 * The book's records as omnisense/omnibill's accounting contracts take
 * them, field by field (omnibill's docs/usage-accounting.md): an entry's
 * journal code, date, label, piece and source (the key), each line's
 * account number, amounts in cents, VAT (rate in basis points, kind, base,
 * code) and contact; a party as a customer or a supplier. And the
 * fingerprint of what is sent: a record whose fingerprint changed since it
 * went is flagged, not sent again.
 */
final class EntryMapper
{
    /** The contact a party is in the accountant's software; null for one that is neither customer nor supplier (an associate, an owner under management). */
    public function contactKind(Party $party): ?ContactKind
    {
        return match ($party->getKind()) {
            PartyKind::TENANT, PartyKind::CUSTOMER => ContactKind::CUSTOMER,
            PartyKind::SUPPLIER => ContactKind::SUPPLIER,
            default => null,
        };
    }

    /** The party's key in the software: its reference, else its id. */
    public function contactKey(Party $party): string
    {
        return $party->getReference() ?? 'party-'.$party->getId();
    }

    public function contact(Party $party): ?Contact
    {
        $kind = $this->contactKind($party);
        if (null === $kind) {
            return null;
        }
        $reference = (string) $party->getReference();
        $siren = str_starts_with($reference, InvoiceProposer::REFERENCE_PREFIX) ? substr($reference, \strlen(InvoiceProposer::REFERENCE_PREFIX)) : null;

        return new Contact(
            $kind,
            $party->getName(),
            $this->contactKey($party),
            $party->getAccountNumber(),
            siren: null !== $siren && 9 === \strlen($siren) ? $siren : null,
            iban: $party->getIban(),
            company: PartyKind::TENANT !== $party->getKind(),
        );
    }

    /**
     * @param bool $contacts whether lines name their contact (the gateway keeps contacts)
     */
    public function entry(Entry $entry, bool $contacts = true): JournalEntry
    {
        $lines = [];
        foreach ($entry->getLines() as $line) {
            $lines[] = $this->line($line, $contacts);
        }

        return new JournalEntry(
            $entry->getJournal()->getCode(),
            $entry->getDate(),
            $entry->getLabel(),
            $lines,
            $entry->getPiece(),
            $entry->getSource(),
            $entry->getBook()->getCurrency(),
        );
    }

    /** sha256 of what goes: the same record gives the same fingerprint. */
    public function fingerprint(JournalEntry|Contact $record): string
    {
        if ($record instanceof Contact) {
            $data = [$record->kind->value, $record->name, $record->key, $record->accountNumber, $record->siren, $record->iban, $record->company];
        } else {
            $data = [$record->journal, $record->date->format('Y-m-d'), $record->label, $record->piece, $record->key, $record->currency, array_map(
                static fn (EntryLine $l) => [$l->account, $l->debit, $l->credit, $l->label, $l->contact, null === $l->vat ? null : [$l->vat->rate, $l->vat->kind->value, $l->vat->base, $l->vat->code]],
                $record->lines,
            )];
        }

        return hash('sha256', json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    private function line(LedgerLine $line, bool $contacts): EntryLine
    {
        $party = $line->getParty();
        $tax = $line->getTax();

        return new EntryLine(
            $line->getAccount()->getNumber(),
            $line->getDebit(),
            $line->getCredit(),
            $line->getLabel(),
            $contacts && null !== $party && null !== $this->contactKind($party) ? $this->contactKey($party) : null,
            null === $tax ? null : new Vat($tax->getRate(), VatKind::from($tax->getKind()->value), $line->getTaxBase(), $tax->getCode()),
        );
    }
}
