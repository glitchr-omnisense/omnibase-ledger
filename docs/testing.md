---
title: Testing
order: 60
---

# Testing

## The suite

```bash
vendor/bin/phpunit                                   # checked out on its own
vendor/bin/phpunit -c vendor/omnibase/ledger         # from an application that requires it
```

The tests need no database: entities are built in memory and repositories are stubs
(`tests/InMemoryLedger.php`); the mapping is checked against a MySQL schema
(`Entity/MappingTest`). The statement uploads go through omnisense/omnibank's real `files`
gateway. `tests/bootstrap.php` uses whichever autoloader exists (`LEDGER_AUTOLOAD`, this
checkout's, the host's, `/srv/app/vendor`) and registers omnisense/omnibank's `core` and
`files` from `OMNIBANK_PATH`, a checkout beside this one, or `/srv/omnibank`, when the host
does not have them - without them the bank tests skip.

In omnisense/omnibase's harness, with this checkout and the omnibank workspace mounted:

```bash
docker run --rm --network none \
    -v "$PWD":/srv/demo/vendor/omnibase/ledger:ro \
    -v ~/Sites/omnibank:/srv/omnibank:ro \
    omnisense/omnibase-harness test ledger
```

```
...............................................................  63 / 111 ( 56%)
................................................                111 / 111 (100%)

OK (111 tests, 666 assertions)
```

Without the omnibank mount, 12 bank tests skip (111 tests, 12 skipped).

## What it checks

| File | What |
|---|---|
| `Service/PostingTest` | the checks of a draft, idempotent sources, numbering per fiscal year, auto accounts, reversals, locked periods |
| `Service/PartiesTest` | a party created once with its auxiliary account, each kind under its collective account, numbers kept unique |
| `Service/LetteringTest` | letters in sequence, the refusals, unlettering, open items |
| `Service/ChartTest` | the SCI chart has what a real-estate company posts to, the French VAT codes with it; seeding again keeps what the book has |
| `Service/TaxedPostingTest` | a taxed purchase splits and rounds (all taxes included or before tax), a sale on the credit side, a reverse charge, a code by its name and an unknown one, an invalid axis, a reversal carrying tax and dimensions, a rule's part with a tax code |
| `EventListener/ValidatedEntryGuardTest` | what the guard refuses and the lettering it lets through |
| `Reconciliation/MatcherTest` | a reference in the label, a reference with another amount, the counterparty's IBAN, the oldest of equal items, a party's name, one transfer for several items, all of a party's items, a supplier, ambiguous amounts and IBANs, a rule, a Stripe payout through its rule |
| `Reconciliation/ReconcilerTest` | a payment posted and lettered with its notice, a supplier payment, a loan instalment split, a Stripe payout, a partial payment, nothing to post against, automatic reconciliation, undo (and in the locked period), ignored lines, splits adding up |
| `Reconciliation/DeskTest` | the front office's actions through `Desk`: a suggestion accepted pays its notice (`BankLineReconciled` with it), a category (and with a tax code), an account outside the categories refused, a split over open items, an associate and a category (and one not adding up), ignore and undo, a rule from a line filing the other matching lines then undone, the sure ones applied and undone, another book's party refused |
| `Bank/LabelTest`, `Bank/ReceiptsTest` | bank labels as a person reads them; a receipt filed under the line's month and read back, a second beside it, kept when taken off the line, only a photo or a PDF, « manquant » |
| `Bank/ConnectionsTest` | the sealed state, other secrets, the webhook's connection, no Omnibank |
| `Bank/WebhooksTest` | a gateway that can registers its webhook once, its secret sealed, kept, replaced; the others left alone; nothing on a local host or over a configured secret; the webhook checked with the secret registered |
| `Bank/ImporterTest`, `Bank/SyncTest`, `Bank/StatementUploadTest` | each transaction once, the overlap, consent to renew, statements of another account, CSV files |
| `Report/ReportsTest`, `Report/StatementsTest` | trial balance, general ledger, open items, compte de résultat and bilan with N-1 |
| `Report/VatReturnTest` | the CA3's boxes on a month of sales, a credit note, purchases, a fixed asset and an intra-EU acquisition; the CA12 totals; a book outside VAT; each regime's last period |
| `Report/DimensionsTest` | a filter keeps one home's lines, the result per home named by a labeler, a value nobody names |
| `Export/FecWriterTest` | the 18 columns, auxiliary accounts, amounts, the file name |
| `Security/LedgerVoterTest` | the admin role views and manages everything; an application grants a book through `BookAccessInterface` |
| `DependencyInjection/LedgerExtensionTest` | the defaults and the services; configured values |
| `Entity/MappingTest` | the mapping is valid; the tables and their unique keys |
| `Templates/TemplatesTest` | the reconciliation page, the reports, the VAT return, the statement per axis and its filter, the upload and connect pages, the widgets render |
| `Controller/CrudRequiredFieldsTest`, `Controller/Admin/CrudFormsTest`, `Controller/Admin/NewFormsSubmitTest` | the CRUDs' required fields; enums chosen through `EnumType` and related records picked in a list, not embedded; each "new" form writes every field it shows, a fiscal year ending before it starts is a form error, book and code fixed once created |

## In an application's tests

Post through `Posting` as production does, and assert on the entries: a rent call posts once
whatever the retries (same `source`), and a payment reconciled settles it
(`BankLineReconciled`, the lines lettered). `Lettering::openItems($book, $party)` says what a
tenant still owes. Feed bank lines with `Importer::import()` and Omnibank's `BankTransaction`
models, or with a CAMT.053 file through `StatementUpload::upload()` and the `files` gateway -
no bank account needed.
