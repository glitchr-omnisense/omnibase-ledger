# Changelog

## [Unreleased]

### Fixed
- The bank widget offers to connect a bank (each Omnibank gateway) and to import a statement under
  the accounts too, not only before the first one: a book with an account already (a statement
  imported, the demo's) had no way left to connect Powens, Bridge or Qonto.
- README: the requirements and the installation as composer.json states them: `omnisense/omnibank`
  (not `glitchr/omnibank`), `omnisense/omnibase` 3.0 or 4.0 (not 3.x); composer.json's
  description names omnisense/omnibank.
- The book's "Plan comptable" action asks to add the accounts and journals of the configured
  chart (`ledger.chart`), which ReportController::seed() seeds: it named "le plan SCI".
