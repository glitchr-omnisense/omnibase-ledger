# Changelog

## [Unreleased]

### Added
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
- The `ledger_bank` card no longer overflows a phone's width (its latest lines' table).
- The bank widget offers to connect a bank (each Omnibank gateway) and to import a statement under
  the accounts too, not only before the first one: a book with an account already (a statement
  imported, the demo's) had no way left to connect Powens, Bridge or Qonto.
- README: the requirements and the installation as composer.json states them: `omnisense/omnibank`
  (not `glitchr/omnibank`), `omnisense/omnibase` 3.0 or 4.0 (not 3.x); composer.json's
  description names omnisense/omnibank.
- The book's "Plan comptable" action asks to add the accounts and journals of the configured
  chart (`ledger.chart`), which ReportController::seed() seeds: it named "le plan SCI".
