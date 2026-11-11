---
title: Suppliers' invoices
order: 37
---

# Suppliers' invoices

Since 2026-09-01 every French company liable to VAT must be able to **receive** its suppliers'
invoices as electronic invoices through an approved platform (plateforme agréée). The ledger
takes them in through [omnisense/omnibill](https://github.com/glitchr-omnisense/omnibill):
each invoice arrives, is read, and is **proposed** as an entry. Nothing is posted until a person
books it.

Optional: without `omnisense/omnibill` none of this is registered, none of its tables exists, and
the ledger works as before. The files are read by `horstoeko/zugferd` (Factur-X, CII; UBL
through `horstoeko/zugferdublbridge`); without it an invoice arrives `UNREADABLE`.

```bash
composer require omnisense/omnibill omnibill/afnor horstoeko/zugferd
bin/console make:migration && bin/console doctrine:migrations:migrate   # ledger_supplier_invoice, ledger_invoice_inbox
```

```yaml
# config/packages/omnibill.yaml
omnibill:
    gateways:
        platform:
            factory: afnor
            options: { flow_url: '%env(PA_FLOW_URL)%', directory_url: '%env(PA_DIRECTORY_URL)%', token_url: '%env(PA_TOKEN_URL)%', client_id: '%env(PA_CLIENT_ID)%', client_secret: '%env(PA_CLIENT_SECRET)%' }

# config/packages/ledger.yaml
ledger:
    invoices:
        gateway: platform          # null: the first configured gateway that receives
        default_account: ~         # proposed for a supplier without history (606, 615...); null: a person chooses
```

## How they arrive

- **Through the approved platform** (`omnibill/afnor`): `ledger:invoices:receive`, from a
  scheduler, or « Recevoir maintenant » on the back office's page. `SupplierInvoices::receive()`
  asks the gateway for what arrived since the cursor it kept (`ledger_invoice_inbox`), page after
  page, asking for the invoices only (omnibill's `Receive` with `[FlowKind::INVOICE]`: the
  platform's search filtered by `flowType`): the statuses about the application's own invoices
  stay for the bundle that sent them (omnibase/marketplace), read with a cursor of its own.
- **By hand**: a supplier who still mails its invoices (the `omnibill/email` route carries no
  incoming flow) - the file dropped on the page, or `ledger:invoices:receive --file=FA-77.pdf`.

Either way `SupplierInvoices::import()`:

1. reads the file (`Omnibill\Reading\InvoiceReader`): number, dates, the seller's name, SIREN,
   SIRET, VAT number and IBAN, the buyer's SIREN (which book it goes to), the totals and the VAT
   breakdown per rate and category, a credit note (type 381);
2. checks it against what the book received: the same supplier (its SIREN, else its VAT number,
   else its name) and number is a **duplicate** - kept for the record, pointing to the first one,
   never proposed. The same file dropped twice by hand gives back the invoice already there;
3. files it with the receipts (`Bank\Receipts::file()`: omnibase/drive's
   « Comptabilité/Justificatifs/<year>/<month> » when installed, `ledger.receipts.directory`
   otherwise), named « 2026-11-03 EDF Entreprises FA-2026-118.pdf »;
4. proposes (`Omnibill\Invoice\InvoiceProposer`): the supplier matched by its SIREN (the
   reference `siren:<SIREN>` of a party), its IBAN, then its name; the expense account the
   supplier's earlier entries went to most, else `ledger.invoices.default_account`.

## The entry proposed

In the purchases journal (AC), dated the invoice's date, labelled « Facture EDF Entreprises
FA-2026-118 », its piece the invoice's number:

| VAT row (category) | Book liable to VAT | Book outside VAT |
|---|---|---|
| standard (S) | the base on the account; the VAT on the book's deductible code of that rate (A20 → 44566), with its base | base + VAT on the account |
| reverse charge (AE, K) | the base on the account, the VAT due and deducted at once through the book's reverse-charge code (AIC20: 44566 / 4452) | the base |
| exempt, zero, outside scope | the base | the base |

The supplier is credited with the total. A rate the book has no code for books its VAT on the
expense, and says so; a breakdown that does not add up to the invoice's total says so too. A
credit note is the same entry the other way round.

## A person decides

On `/admin/ledger/invoices/{id}`: the invoice as read, the entry proposed, and

- **Comptabiliser** (`SupplierInvoices::book()`): the account (the one proposed, or another), the
  supplier (the one matched, another, or created from the invoice: reference `siren:<SIREN>`,
  auxiliary account 401 + its name's letters, 401EDF), the date. The entry is posted and
  validated; its source is `supplier-invoice:<id>`.
- **Refuser** (`refuse()`), with a reason.

An invoice `UNREADABLE` is refused, or its entry posted by hand.

## Paid

When the reconciliation letters the entry's supplier line with a bank line
(`BankLineReconciled`), `PaidInvoiceListener` marks the invoice paid.

## The statuses sent back

Each step is declared to the supplier's platform through the gateway the invoice came by, as
omnibill's `LifecycleStatus`: `taken_over` (204) on arrival, `approved` (205) once booked,
`refused` (210) with its reason, `paid` (212) with its amount and its amount at each VAT rate
(`StatusChange::$amounts`, from the invoice's VAT breakdown: `[2000 => '120.00']`, the rate in
basis points, base and VAT together). A duplicate declares nothing; an invoice dropped by hand
has no platform to tell.

An approved platform carries a status as a lifecycle message (CDAR), and neither omnibill nor the
ledger writes one: the application registers a service implementing omnibill's
`Omnibill\Writer\LifecycleMessageWriterInterface` (`write(StatusChange $change, ?Flow $flow):
StatusChange`, the change `withFile()`), written or validated by a person against the DGFiP's
annexes; the flow it is handed is the invoice's (its `reference` the flow the invoice came by,
`SupplierInvoiceRepository::findOneByFlow()`), the change carries the number, the status, the
reason, the amount and the amounts by rate. Without one - or when the platform could not be
reached - the status waits in the invoice (`statuses`, its error said on the page) and
`ledger:invoices:receive` sends it again.

A status whose answer was lost after it left (omnibill's `OutcomeUnknownException`: a timeout, a
server error) may be on the platform already, and a platform cannot be asked for a status
message it took: it is marked « issue inconnue » on the invoice's page and never sent again on its
own. A person looks on the platform, then answers « Reçu par la plateforme » (marked sent) or
« Absent : renvoyer » (sent again now): `SupplierInvoices::confirmStatus()`,
`ledger_admin_invoice_status`.
