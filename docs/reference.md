---
title: Reference
order: 45
---

# Reference

## Routes

| Route | Path | Methods | Access |
|---|---|---|---|
| `ledger_admin_reconcile` | `/admin/ledger/reconcile/{bankAccount}` | GET | view |
| `ledger_admin_reconcile_apply` | `/admin/ledger/reconcile/line/{line}/apply` | POST | manage, token |
| `ledger_admin_reconcile_ignore` | `/admin/ledger/reconcile/line/{line}/ignore` | POST | manage, token |
| `ledger_admin_reconcile_undo` | `/admin/ledger/reconcile/line/{line}/undo` | POST | manage, token |
| `ledger_admin_reconcile_auto` | `/admin/ledger/reconcile/{bankAccount}/auto` | POST | manage, token |
| `ledger_admin_bank_upload` | `/admin/ledger/bank/{bankAccount}/upload` | GET, POST | manage |
| `ledger_admin_bank_connect` | `/admin/ledger/bank/connect/{gateway}` (`?book=`) | GET, POST | manage, token |
| `ledger_admin_bank_return` | `/admin/ledger/bank/return/{connection}` | GET | manage |
| `ledger_admin_bank_renew` | `/admin/ledger/bank/connection/{connection}/renew` | GET | manage, link token |
| `ledger_admin_bank_sync` | `/admin/ledger/bank/{bankAccount}/sync` | GET | manage, link token |
| `ledger_admin_trial_balance` | `/admin/ledger/book/{book}/trial-balance` | GET | view |
| `ledger_admin_general_ledger` | `/admin/ledger/book/{book}/general-ledger` | GET | view |
| `ledger_admin_open_items` | `/admin/ledger/book/{book}/open-items` | GET | view |
| `ledger_admin_income_statement` | `/admin/ledger/book/{book}/income-statement` | GET | view |
| `ledger_admin_balance_sheet` | `/admin/ledger/book/{book}/balance-sheet` | GET | view |
| `ledger_admin_fec` | `/admin/ledger/book/{book}/fec/{year}` | GET | view |
| `ledger_admin_book_seed` | `/admin/ledger/book/{book}/seed` | GET | manage, link token |
| `ledger_admin_entry_validate` | `/admin/ledger/entry/{entry}/validate` | GET | manage, link token |
| `ledger_admin_entry_reverse` | `/admin/ledger/entry/{entry}/reverse` | GET | manage, link token |
| `ledger_bank_webhook` | `/ledger/bank/{gateway}/webhook` | POST | public (the gateway checks the signature) |

Ids are digits; `{gateway}` is `[a-z0-9_-]+`; `{year}` four digits. "View" and "manage" are
`LEDGER_VIEW` and `LEDGER_MANAGE` on the record (see [The back office](back-office.md)).

## Commands

| Command | Arguments and options | What it does |
|---|---|---|
| `ledger:chart:seed` | `book` (id, SIREN or name); `--chart=` (a file of `config/charts/`, default `ledger.chart`) | adds the chart's missing accounts and journals |
| `ledger:bank:sync` | `[book]`; `--no-reconcile` | syncs every API-fed bank account (all books, or one) and reconciles what is sure; `files` accounts are skipped; fails (exit 1) when an account failed, each reported as a warning |
| `ledger:bank:opening` | `[book]`; `--adjust`; `--reclassify` | posts the opening entry of every bank account that has none (so the books hold the bank's balance); `--adjust` replaces one the bank no longer agrees with; `--reclassify` first moves each account onto the ledger account its kind belongs under (a loan on 164x) |
| `ledger:bank:aggregate` | `[book]` | writes every bank account's precomputed rows again (all books, or one), one line per account: how many days, category rows, counterparty rows and recurring flows |
| `ledger:fec:export` | `book`, `year` (the year the fiscal year closes in); `--dir=` / `-d` (`.`); `--stdout` | writes `<SIREN>FEC<YYYYMMDD>.txt`, or prints it; a year that is not digits is exit 2 |

An unknown book is an error and exit 1. `ledger:bank:sync` prints one line per account:
`<book> / <account>: <n> new line(s), <m> reconciled.`

## Twig filters

`Base\Ledger\Twig\LedgerExtension`:

| Filter | Example | Result |
|---|---|---|
| `ledger_money(currency = 'EUR', locale = null)` | `{{ 123456\|ledger_money }}` | `1 234,56 €` in a French locale (`NumberFormatter`, the default locale); `1 234,56 EUR` without intl |
| `ledger_amount` | `{{ 123456\|ledger_amount }}` | `1 234,56`; empty for `0` and `null` (a report's column) |
| `ledger_iban` | `{{ iban\|ledger_iban }}` | `FR76 •••• 4321` |
| `ledger_compact(currency = 'EUR', locale = null)` | `{{ 1250000\|ledger_compact }}` | `12,5 k€` (a chart's axis, a tile) |
| `ledger_month(locale = null)` | `{{ day\|ledger_month }}` | `oct.` |
| `ledger_number(decimals = 0, locale = null)` | `{{ -5.7\|ledger_number(1) }}` | `-5,7` |

Every filter takes the page's language (the translator's locale) when it is given none.

The function `ledger_once(key)` is true the first time it is asked in a request only (the bank
cards' shared style, written once).

## Event

`Base\Ledger\Event\BankLineReconciled` (dispatched by its class name, after
`Reconciler::apply()`): `line`, `entry`, `openItems`
([Reconciliation](usage-reconciliation.md#following-a-reconciliation)).
`Base\Ledger\Event\BankLineUnreconciled` (after `Reconciler::undo()` of a reconciled line):
`line`, `entry`, `reversal`.

## Templates

`@Ledger/admin/`: `reconcile`, `upload`, `connect_files`, `trial_balance`, `general_ledger`,
`open_items`, `income_statement`, `balance_sheet` (`.html.twig`), the partials `_period`,
`_report_nav`, `_statement`, `_style`, and `widget/bank`, `widget/books`, `widget/bank/` (`_layout`,
`_style` and one per bank card). An application
overrides one in `templates/bundles/LedgerBundle/admin/...`.

## Translations

The domain `ledger` (ICU), in English and French (`translations/ledger+intl-icu.{en,fr}.yaml`):
`status.*` (the bank lines' statuses), `reconcile.*`, `bank.*`, `report.*`, `dashboard.*` (the
bank cards: periods, series, kinds, categories, states, rhythms). The domain `admin`
(`translations/admin.{en,fr}.yaml`): `ledger_widget.*`, the cards' names in the palette. The CRUDs' labels
and the flash messages are written in French in the code.
