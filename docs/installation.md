---
title: Installation
order: 10
---

# Installation

## Requirements

What `composer.json` requires: PHP 8.2 or later, `ext-sodium`, `doctrine/orm` 2.15 or 3,
`omnisense/omnibase` 3.0 or 4.0, `omnibridge/symfony` 1.x, `omnibase/admin`,
`omnisense/omnibank` 1.x, and `symfony/config`, `console`, `dependency-injection`,
`doctrine-bridge`, `http-kernel`, `security-bundle` and `validator` 6, 7 or 8.

What it suggests:

| Package | For |
|---|---|
| `omnibank/files` | statement files (CAMT.053, OFX, CSV), configured as the `files` gateway |
| `omnibank/qonto` | Qonto accounts synced through its API |
| `omnibank/powens` | bank aggregation through Powens (consent pages, webhooks) |
| `omnibank/bridge` | bank aggregation through Bridge (consent pages, webhooks) |
| `symfony/lock` | entry numbering one process at a time per book, on top of the unique key |

## The bundle

```bash
composer require omnibase/ledger:dev-main omnibank/files:1.x-dev
```

```php
// config/bundles.php
return [
    // ...
    Base\BaseBundle::class => ['all' => true],
    Base\Admin\AdminBundle::class => ['all' => true],
    Omnibank\Bridge\Symfony\OmnibankBundle::class => ['all' => true],
    Base\Ledger\LedgerBundle::class => ['all' => true],
];
```

`LedgerBundle` extends the core's `AbstractBaseBundle`: `getPath()` is the package's root
(its `templates/` is `@Ledger`), and `build()` aliases every `Base\Ledger\Entity\*` to
`App\Entity\Ledger\*` and every `Base\Ledger\Repository\*` to `App\Repository\Ledger\*`
unless the application declares a class of that name - which is how an application extends a
ledger entity (a `Book` tied to its own company) without touching the bundle.

## Omnibank's gateways

The bank feeds go through omnisense/omnibank's `Omnibank\Registry`, which its bundle
registers. Without the bundle the ledger, the lettering and the reports work; the bank
screens say Omnibank is missing and the bank services throw a `BankException`
(`Bank\Connections` is built with a `null` registry).

The ledger knows the gateways by their name in `omnibank.gateways`. `files` is the name the
statement upload asks for (`StatementUpload::GATEWAY`):

```yaml
# config/packages/omnibank.yaml
omnibank:
    gateways:
        files:  { factory: files }
        qonto:  { factory: qonto,  options: { ... } }
        powens: { factory: powens, options: { ... } }
        bridge: { factory: bridge, options: { ... } }
```

Each provider's options are in its own manual (omnisense/omnibank).

## The routes

```yaml
# config/routes.yaml
ledger_controller:
    resource: "@LedgerBundle/src/Controller"
    type: attribute
    prefix: /
```

This imports the back-office pages under `/admin/ledger` (reconciliation, bank, reports) and
the public webhook `/ledger/bank/{gateway}/webhook`. The CRUD screens' routes are generated
by omnibase/admin's `base_admin` loader. Every route is in the [Reference](reference.md#routes).

## Configuration

Every key is optional ([Configuration reference](configuration.md)):

```yaml
# config/packages/ledger.yaml
ledger:
    auto_accounts: false
    chart: sci
    admin_role: ROLE_ADMIN
    secret: '%kernel.secret%'
    reconciliation:
        auto_threshold: 0.9
        max_group_items: 12
    bank:
        sync_overlap_days: 5
```

## The schema

The bundle ships no migration; its tables are `ledger_*` (`ledger_book`, `ledger_account`,
`ledger_journal`, `ledger_fiscal_year`, `ledger_party`, `ledger_entry`, `ledger_entry_line`,
`ledger_bank_connection`, `ledger_bank_account`, `ledger_bank_line`,
`ledger_reconciliation_rule`):

```bash
bin/console make:migration
bin/console doctrine:migrations:migrate
```

## A first book

Create the book in the back office (Books > New), or in PHP (`new Book('SCI Les Tilleuls',
'123456789')`, persisted), then give it the chart's accounts and journals:

```bash
bin/console ledger:chart:seed "SCI Les Tilleuls"
```

The book is named by its id, its SIREN or its exact name. `config/charts/sci.php` holds the
PCG accounts an SCI uses (capital and reserves, loans and deposits, buildings and their
depreciation, the collective accounts 401, 411, 455, 467, the VAT accounts, the bank 512 and the card clearing
account 5112, the expenses and the rents) and the journals `BQ` (bank), `VT` (sales: rent
notices), `AC` (purchases), `OD` (miscellaneous) and `AN` (opening balances).

## Dashboard widgets

```php
// App\Controller\Admin\DashboardController::configureWidgetItems()
yield MenuItem::block('ledger_bank', 'Banque', 'fa-solid fa-building-columns');
yield MenuItem::block('ledger_books', 'Comptabilité', 'fa-solid fa-book');
```

What each shows is in [The back office](back-office.md#dashboard-widgets).

## A scheduled sync

```cron
0 */4 * * *  bin/console ledger:bank:sync
```

imports the API-fed accounts' new lines and reconciles what is sure; statement-file accounts
are skipped ([Bank feeds](usage-bank.md)).
