---
title: Exporting to the accountant's software
order: 38
---

# Exporting to the accountant's software

The books stay the ledger's; the accountant works in their own software. The ledger hands them
the validated entries of a period through [omnisense/omnibill](https://github.com/glitchr-omnisense/omnibill)'s
accounting contracts, `JournalEntriesInterface` and `ContactsInterface` - `omnibill/odoo` (Odoo
19 and later) and `omnibill/pennylane` today.

Optional: without `omnisense/omnibill` none of it is registered and none of its tables exists.

```bash
composer require omnisense/omnibill omnibill/pennylane
bin/console make:migration && bin/console doctrine:migrations:migrate   # ledger_accounting_export, ledger_accounting_export_item
```

```yaml
# config/packages/omnibill.yaml
omnibill:
    gateways:
        accountant:
            factory: pennylane
            options: { token: '%env(PENNYLANE_TOKEN)%' }

# config/packages/ledger.yaml
ledger:
    accounting:
        gateway: accountant        # null: the first configured gateway that keeps journal entries
```

## What goes

`Omnibill\Export\AccountingExporter::export($book, $from, $to)`, the page
`/admin/ledger/accounting`, or `ledger:accounting:export <book> [--from=] [--to=]` (the last
month by default):

1. **the contacts first**: the customers and suppliers the period's lines name (a tenant or a
   customer as a customer, a supplier as a supplier; an associate or an owner under management is
   neither, and goes as its auxiliary account only) - their name, their key (the party's
   reference), their auxiliary account, their SIREN (a reference `siren:<SIREN>`) and IBAN;
2. **then the entries**: every validated entry dated in the period (drafts never), with its
   journal's code, date, label, piece, its `source` as the key, each line's account number,
   amounts in cents, VAT (rate in basis points, kind, base, code) and contact. The mapping is
   `Omnibill\Export\EntryMapper`, field by field as omnibill's `docs/usage-accounting.md` gives
   it.

## What is never sent twice

Each record sent is an `AccountingExportItem` (`ledger_accounting_export_item`), unique per
book, gateway, kind and key (the entry's source, the party's reference), with the software's
reference and the fingerprint of what went:

- **sent**: an export of the same period again leaves it alone (counted « déjà présents »);
- **refused** by the software (an account it has not, a journal unknown, a customer without the
  billing address Pennylane asks for): that record only; the run goes on. The next export tries it
  again; « Renvoyer » does it now;
- **failed**: the software could not be reached (nothing left), a key was refused, a usage limit
  was reached (Pennylane: 25 requests every 5 seconds). The run stops, `FAILED`, and the next
  export of the same period **resumes it** - the same `AccountingExport`, its count of
  resumptions up by one: what went is not sent again; what the software never took is;
- **unknown**: sent, and the answer lost after it left (omnibill's `OutcomeUnknownException`: a
  timeout, a server error) - the software may have it. The run stops as for a failure, and the
  record is **never sent again as it is**: the next export, or « Vérifier et renvoyer », first asks
  the software for the entry by its key (omnibill's `EntryLookupInterface::findEntry()`: Odoo by
  the key it keeps, Pennylane by the date, journal, label and piece) - found, it is sent with the
  software's reference (counted « déjà présents »); absent, it is posted. A contact likewise, by
  its key (omnibill 1.3's `ContactLookupInterface::findContact()`: Pennylane by `external_reference`
  on the kind's own list, Odoo by `ref`): found, its reference is kept and it is not created
  twice; absent, it is created. Odoo's `ref` is not unique: a partner of the other kind under the
  same key (a customer where a supplier is looked for, told by its `supplier_rank` /
  `customer_rank`) is neither taken for it nor created beside it on its own - it waits for a
  person. A record on a gateway that cannot be asked (omnibill 1.2, a gateway without
  `FindEntry` / `FindContact`), a contact without a key, or a lookup refused, waits for a person, who
  checks the software and sends it again with « Vérifier et renvoyer » (still looked up first);
- **changed** since it went (its fingerprint no longer the book's): flagged, never sent again on
  its own. A contact is updated in the software by « Mettre à jour » (its reference kept); an
  entry is corrected in the software by hand, then marked « Corrigé à la main »
  (`AccountingExporter::acknowledge()`).

Nothing is replayed by a retry underneath: omnibill hands its gateways an HTTP client without
retries, and a record goes again only through a run, for a record the software did not take.

A run (`AccountingExport`, `ledger_accounting_export`) keeps when, by whom, to which gateway, the
period, and its counts: contacts and entries sent, already there, refused, changed.

## Not carried

The analytic axes (they stay in the books), the lettering, the attachments (the invoice files).
