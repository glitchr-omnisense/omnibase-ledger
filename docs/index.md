# omnibase/ledger

Double-entry accounting for the sites built on
[omnisense/omnibase](https://github.com/glitchr-omnisense/omnibase): books, a French chart of
accounts (PCG), journals, fiscal years, parties and lettering, bank feeds through
[omnisense/omnibank](https://github.com/glitchr-omnisense/omnibank), the reconciliation of
bank lines with ranked suggestions, and the reports a French accountant reads - trial
balance, general ledger, open items, compte de résultat, bilan, the VAT return (CA3, CA12),
FEC - and analytic axes on entry lines (per unit, per owner). It is a Symfony bundle
(`Base\Ledger\LedgerBundle`) on the core 3 or 4, with its back office on omnibase/admin.

It was written for French real-estate companies (SCI): rent notices, tenants' payments,
supplier invoices, a loan, card payments through Stripe. omnibase/estate posts its rent
calls, payments and work invoices to it.

The rules it keeps:

- **One way in.** Entries are posted through `Posting::post()`, which refuses an unbalanced
  entry, a negative or two-sided line, an unknown account or journal, and a date in the
  locked period - before writing anything. An entry carries a `source` (what it records);
  posting the same source twice returns the first entry.
- **Validated is final.** A validated entry is numbered per fiscal year (`2026-000042`) and
  frozen: a Doctrine listener refuses any change but its lines' lettering. A mistake is
  corrected by a reversal entry.
- **Amounts are integers.** Every amount is in minor units (cents); a bank line is signed
  (negative when money leaves), an entry line has a debit or a credit, never negative.
- **Reports read the books as they are.** Draft entries count in every report but the FEC,
  which lists validated entries only.

Use it when an application must keep real books for one or several legal entities and
match its bank statements against them. It is not an invoicing tool: invoices, rent calls or
orders stay the application's, which posts what they mean.

## How this manual is organised

- [Installation](installation.md): requirements, the bundle, Omnibank's gateways, the
  routes, the schema, the chart.
- [Books, accounts and entries](concepts.md): every entity, its fields and invariants, the
  enums, `EntryDraft`.
- [Posting, parties and lettering](usage-posting.md): `Posting`, numbering, reversals, the
  guard, `Parties`, `Lettering`, `Chart`.
- [Bank feeds](usage-bank.md): connections and consent, syncs, statement files, the importer,
  the webhook, the sealed state.
- [Reconciliation](usage-reconciliation.md): the matcher's ranks and scores, applying a
  suggestion, automatic reconciliation, undoing, rules and splits, the Stripe pattern.
- [Reports and the FEC](usage-reports.md): every report's shape, the CSV downloads, the FEC.
- [VAT](usage-vat.md): the book's regime, the codes, taxed postings, rules with a tax code,
  the VAT return (CA3, CA12).
- [Analytic axes](usage-dimensions.md): dimensions on entry lines, the reports filtered and
  grouped by them, the labelers.
- [The front office's bank screen](front-office.md): the team's day-to-day reconciliation at
  `/comptabilite` - compact rows, the keyboard, categories, splits, rules, receipts, sync.
- [The back office](back-office.md): the screens, the pages, the dashboard widgets, who sees
  a book.
- [Configuration reference](configuration.md): `ledger:`, the parameters, the services.
- [Reference](reference.md): the routes, the commands, the Twig filters, the event, the
  translations.
- [Errors](errors.md): the exceptions and when each is thrown.
- [Testing](testing.md): the suite, what it checks, testing an application that posts.
