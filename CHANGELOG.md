# Changelog

## [Unreleased]

### Added
- The owners' money a property manager holds, for omnibase/estate's `management` activity: a
  bank account may be the escrow account (`BankAccount::$escrow`, an `escrow` column, false by
  default; « Compte séquestre » on its screen; `BankAccountRepository::escrowFor()`), and a party
  may be an owner under management (`PartyKind::MANDATOR`, its account under 4671, guessed a
  liability: what the book owes them). `docs/concepts.md`.
- VAT. Each book has a regime (`Book::$vatRegime`, `VatRegime`: `none` by default - a property
  company letting homes is not liable -, `franchise`, `simplified` (CA12), `normal_monthly`,
  `normal_quarterly` (CA3)). VAT codes per book (`Tax`, `ledger_tax`: code, label, rate in basis
  points, `TaxKind` - collected, deductible, deductible on fixed assets, reverse charge, exempt -,
  account, a reverse charge's deductible account), the French set seeded with the chart (V20, V10,
  V55, V21, A20, A10, A55, A21, I20, EXO, AIC20; accounts 4452, 44562, 44567 added to the SCI
  chart), a back-office screen of their own. A VAT line knows its tax and its base
  (`EntryLine::$tax`, `$taxBase`). `EntryDraft::taxed()` posts an amount all taxes included or
  before tax as its base and its VAT, rounded to the cent half up, the base taking the remainder
  (`Tax::split()`); `line()` takes a tax code and a base. A reconciliation rule's split part may
  carry a tax code (`Reconciler::parts()`). `Report\VatReturn` gives the CA3's boxes (01, 03, 05,
  08, 9B, 09, 13, 16, 17, 19, 20, 23, 25, 28) and the CA12's totals, with its page
  (`ledger_admin_vat_return`, « TVA » among the reports) and a CSV. The FEC is unchanged.
  `docs/usage-vat.md`.
- Analytic axes. An entry line may carry dimensions (`unit=12`, `owner=5`), in a table of their
  own (`EntryLineDimension`, `ledger_entry_line_dimension`, indexed on axis and value):
  `EntryDraft::line(..., dimensions: [...])`, the taxed helper puts them on both lines, a reversal
  carries them, an axis may be added to a validated line (`EntryLine::addDimension()`), never
  changed. `IncomeStatement` and `GeneralLedger` filter on dimensions and group by an axis
  (`byDimension()`), their pages through `?dimension[axis]=value` and `?by=axis`, the statement
  per axis as a table and a CSV. `DimensionLabelerInterface` (tag `ledger.dimension_labeler`) names
  an axis's values, `Dimension\Dimensions` asks the labelers. `docs/usage-dimensions.md`.

### Changed
- The ledger screens choose a record's book in the list of books (`LedgerAdminTrait::bookField()`,
  Symfony's EntityType): the admin's AssociationType would embed the book's form, whose VAT
  regime is an enum.
- `Posting`, `Chart` and `Reconciler` take an optional `TaxRepository`; their other arguments are
  unchanged.
- Upgrading: a migration in the application adds `ledger_book.vatRegime` (default `none`),
  `ledger_entry_line.tax_id` and `taxBase` (nullable), `ledger_tax` and
  `ledger_entry_line_dimension`; `ledger:chart:seed <book>` adds the VAT codes to a book.

### Added
- The bank aggregators' webhooks registered by the application where the provider allows it:
  `ledger:bank:webhook [<gateway>] [--force]` (`Bank\Webhooks::ensure()`), through omnibank's
  optional `RegisterWebhook`, the secret sealed in omnibase's settings
  (`api.webhook.ledger_bank_webhook.<gateway>.secret`), read by the webhook (`Webhooks::notifier()`;
  a configured `webhook_secret` wins). Idempotent, exit 0 always, nothing asked on a host no
  provider reaches. No aggregator supports it today (Powens, Bridge: console only); the command
  says so. `docs/usage-bank.md`.

### Changed
- The bank cards, `ledger_bank` and `ledger_books` take omnibase/admin's dashboard card: the title above
  the white panel, tinted rows that alternate, labels in the text face instead of small capitals
  ("Crédit", "Net", "Ont le plus versé", "Aujourd'hui", "Prochaines échéances", "Reçus", "Émis").
  The balance, the transfers and the reconciliation are compact (two to a row on a phone).
  `ledger_books` gives the fiscal year as rows with their report a click away; `ledger_bank` its
  accounts as rows; their words are translated (`dashboard.bank.*`, `dashboard.books.*`), no longer
  written in French in the templates.

### Added
- The opening entry (`Bank\Opening`): the bank's balance less the lines imported, the day before the
  first line, on the bank account against 890 (Bilan d'ouverture) in AN, once per account
  (`bank:opening:<id>`), after the first sync or upload that brings a balance; `adjust()` replaces a
  stale one (reversal, then `/2`); `ledger:bank:opening [book] [--adjust] [--reclassify]` for the
  accounts already there. Configuration `ledger.bank.opening_account`, `opening_journal`. The SCI chart
  has 890; `AccountType::guess()` gives class 8 as equity and 519 as a liability.
- A loan or a consumer credit posts under 164 (1641...), a revolving credit under 519, the rest under
  512 (`Connections::ledgerPrefix()`, `ledgerAccount()`); `Connections::reclassify()` moves an account
  attached before its kind was known, its balance carried by an OD entry.
- A connection never finished (`BankConnection::isUnfinished()`, `isAbandoned()` after 24 hours): the
  connections card counts the abandoned no more, lists them apart and offers their removal
  (`ledger_admin_bank_remove`, POST with its token; `Connections::remove()`).
- The statistics' towering bars are clipped (`Chart::robustMax()`), marked and labelled, so that the
  other months stay readable.
- `ledger_number`.

### Changed
- Amounts, numbers, months and percentages print in the page's language (the translator's locale), not
  intl's default (`€7,704.99` on a French page); the bank accounts' list prints the bank balance so too.
- The accounts-by-kind legend no longer cuts a kind's name ("Compte courant"): the count and what
  they hold go on a line of their own.
- The recurring flows and the forecast leave out a transfer between two of the accounts shown, both
  ways (`Dashboard::recurring($accounts, true)`).
- The bank dashboard: twelve omnibase/admin palette cards (`Admin\Widget\Bank\*WidgetType`,
  `ledger_bank_kinds`, `_balance`, `_connections`, `_transfers`, `_statistics`, `_categories`,
  `_counterparties`, `_recurring`, `_forecast`, `_reconciliation`, `_freshness`, `_movements`), each
  placeable from the layout editor's palette or in code, with its `period` and `accounts`
  parameters set through the card's ⋮ panel. Charts drawn as markup (inline SVG and CSS on the
  admin's tokens, light and dark): a donut, stacked bars, bars with the balance on its own scale,
  periods and series switched in the card without a script, a tooltip per bucket, a table for
  screen readers. `ledger_bank` and `ledger_books` are palette cards too.
- Precomputation (`Bank\Aggregates`, `Bank\Aggregator`, `Bank\Recurrences`, `Bank\Counterparty`):
  per account, its days (in, out, counts, transfers, closing balance anchored on the bank's), its
  days by category, its months by counterparty and its recurring flows, in four new tables
  (`BankDay`, `BankDayCategory`, `BankCounterpartyMonth`, `BankRecurring`), written after each sync
  and statement upload and by `ledger:bank:aggregate`. `Bank\Dashboard` reads them for the cards.
- `BankAccount::getKind()` / `getProviderType()`, `BankConnection::getConnectionState()` /
  `getConnectionStateAt()`, `BankLine::getCategory()`: omnisense/omnibank's `AccountKind`,
  `ConnectionState` and `TransactionCategory`, stored by value, set by `Connections::attach()`, the
  sync, the statement upload, the webhook and the return from a provider's page.
- Twig: `ledger_compact`, `ledger_month`, `ledger_once()`.

### Fixed
- The dashboard's « Comptabilité » rows share the card's height when the card beside it is
  taller, instead of leaving it empty under « Trésorerie ».
- A bank line no longer settles an open item dated more than `reconciliation.max_advance_days`
  (new, 45 by default) after it: on meyers, a rent notice for November 2026 was auto-reconciled
  with a tenant's transfer of October 2025 of the same amount, still to reconcile in the history.
- `Reconciler::undo()` of a reconciled line dispatches `BankLineUnreconciled` (`line`, `entry`,
  `reversal`): what followed `BankLineReconciled` (omnibase/estate's rent call paid) can be undone
  - it stayed paid.
- The `ledger_bank` card no longer overflows a phone's width (its latest lines' table).
- The bank widget offers to connect a bank (each Omnibank gateway) and to import a statement under
  the accounts too, not only before the first one: a book with an account already (a statement
  imported, the demo's) had no way left to connect Powens, Bridge or Qonto.
- README: the requirements and the installation as composer.json states them: `omnisense/omnibank`
  (not `glitchr/omnibank`), `omnisense/omnibase` 3.0 or 4.0 (not 3.x); composer.json's
  description names omnisense/omnibank.
- The book's "Plan comptable" action asks to add the accounts and journals of the configured
  chart (`ledger.chart`), which ReportController::seed() seeds: it named "le plan SCI".
