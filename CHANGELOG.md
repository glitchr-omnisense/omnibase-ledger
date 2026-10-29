# Changelog

## [Unreleased]

### Fixed
- README: the requirements and the installation as composer.json states them: `omnisense/omnibank`
  (not `glitchr/omnibank`), `omnisense/omnibase` 3.0 or 4.0 (not 3.x); composer.json's
  description names omnisense/omnibank.
- The book's "Plan comptable" action asks to add the accounts and journals of the configured
  chart (`ledger.chart`), which ReportController::seed() seeds: it named "le plan SCI".
