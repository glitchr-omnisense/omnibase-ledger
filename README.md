# omnibase/ledger

Double-entry accounting for [omnibase](https://github.com/glitchr-omnisense/omnibase) applications, with bank feeds through [glitchr/omnibank](https://github.com/glitchr-omnisense/omnibank) and bank **reconciliation**. It was written for French real-estate companies (SCI): rent notices, tenants' payments, supplier invoices, a loan, card payments through Stripe.

- **Books.** One per legal entity. Each has its chart of accounts (PCG), its journals (BQ, VT, AC, OD, AN), its fiscal years, and a lock date after which nothing changes.
- **Posting.**
  - Entries come in through `Posting::post()`, which refuses an unbalanced entry, a negative or two-sided line, an unknown account or journal, and a date in the locked period.
  - Posting the same `source` twice returns the first entry.
  - A validated entry is numbered per fiscal year (`2026-000042`) and frozen. A Doctrine listener refuses any change to it except lettering. A wrong entry is corrected with `Posting::reverse()`.
- **Parties and lettering.**
  - Tenants, suppliers and associates each get an auxiliary account (`411U3`, `401EDF`, `455M1`).
  - Lettering ties lines that cancel out, such as a rent notice and its payment. Open items are the lines that are not lettered yet.
- **Bank feeds.**
  - Omnibank's gateways import transactions: statement files (`files`: CAMT.053, OFX, CSV), Qonto, Powens and Bridge.
  - Each transaction is imported once.
  - A connection's state (tokens) is sealed with sodium's secretbox, under a key derived from the kernel secret.
- **Reconciliation.** `Matcher` ranks what a bank line may settle:
  1. a reference found in the label
  2. the counterparty's IBAN
  3. a party's name
  4. several open items of one party that add up to the amount
  5. a reconciliation rule

  `Reconciler` then posts the line in the bank journal, letters it and marks it reconciled. A line is applied automatically only when exactly one suggestion is sure (score ≥ 0.9).
- **Reports.** Trial balance, general ledger, open items, the **FEC** (fichier des écritures comptables), and the two statements an SCI's associates and accountant read:
  - the **compte de résultat** over a period: rents, recovered charges, other income; rental charges, repairs, insurance, fees, taxes (taxe foncière), loan interest, depreciation, bank fees, other expenses - each account under the longest PCG prefix it matches, the rest in "Autres". With N-1 beside it when asked.
  - the **bilan** at a date: net fixed assets, receivables, other receivables, cash; equity (with the year's result, and earlier results not yet closed), loans, tenants' deposits, suppliers, associates' current accounts, other debts. Class 4 and 5 accounts go by their balance (a tenant in credit is a debt). Actif equals passif.

  Every report counts draft entries as well as validated ones: they say what the books say today. The statements print without the admin's chrome and download as CSV (`?format=csv`).
- **Back office** (omnibase/admin):
  - CRUD screens for every entity. A record is created from its "new" form (books, journals, accounts, fiscal years, parties, reconciliation rules); its book - and a journal's code, an account's number - are chosen then and only shown on "edit". What the database would refuse is said by the form: a second journal of the same code in a book, a second account of the same number, a fiscal year that ends before it starts, a party's reference already taken. A party's auxiliary account left empty is numbered from its kind and reference (411…, 401…)
  - the reconciliation page, statement upload, bank connection
  - the reports
  - two dashboard widgets

## Requirements

- PHP 8.2+, `ext-sodium`
- `omnisense/omnibase` 3.x, `omnibase/admin`
- `glitchr/omnibank` 1.x with the gateways you use (`omnibank/files`, `omnibank/qonto`, `omnibank/powens`, `omnibank/bridge`). Without it, the ledger, lettering and reports still work, and the bank screens say Omnibank is missing.

## Installation

```bash
composer require omnibase/ledger:dev-main glitchr/omnibank:1.x-dev omnibank/files:1.x-dev
```

```php
// config/bundles.php
Omnibank\Bridge\Symfony\OmnibankBundle::class => ['all' => true],
Base\Ledger\LedgerBundle::class => ['all' => true],
```

```yaml
# config/routes.yaml
ledger_controller:
    resource: "@LedgerBundle/src/Controller"
    type: attribute
    prefix: /
```

```yaml
# config/packages/ledger.yaml: every key is optional
ledger:
    auto_accounts: false        # posting to an unknown 7061 creates it under 706
    chart: sci                  # config/charts/sci.php, what Chart::seed() uses
    admin_role: ROLE_ADMIN      # views and manages every book (LEDGER_VIEW, LEDGER_MANAGE)
    secret: '%kernel.secret%'   # seals the bank connections; changing it means connecting again
    reconciliation:
        auto_threshold: 0.9     # one suggestion at least this sure: applied without asking
        max_group_items: 12     # a grouped payment is searched among this many open items
    bank:
        sync_overlap_days: 5    # each sync asks again for the days before the last one
```

```yaml
# config/packages/omnibank.yaml: the gateway names the ledger knows
omnibank:
    gateways:
        files:  { factory: files }                 # statement upload (required for it)
        qonto:  { factory: qonto,  options: { ... } }   # see each package's README
        powens: { factory: powens, options: { ... } }
        bridge: { factory: bridge, options: { ... } }
```

Then generate the migration. The bundle ships none: its tables are `ledger_*`.

```bash
bin/console make:migration && bin/console doctrine:migrations:migrate
bin/console ledger:chart:seed "<book id, SIREN or name>"
```

### Dashboard widgets

```php
// App\Controller\Admin\DashboardController::configureWidgetItems()
yield MenuItem::block('ledger_bank', 'Banque', 'fa-solid fa-building-columns');
yield MenuItem::block('ledger_books', 'Comptabilité', 'fa-solid fa-book');
```

- `ledger_bank` shows:
  - each bank account with its balance in the books (512x) and at the bank
  - its last sync and its consent, with a "Renouveler" link when the bank asks
  - its lines to reconcile, linking to the reconciliation page
  - the six latest lines
  - with no account yet: "Connecter une banque" for each configured gateway, and "Importer un relevé".
- `ledger_books` shows each book's current fiscal year to date: income (7), expenses (6), result (the compte de résultat's totals), open receivables (411), open payables (401) and cash (512). Each tile opens its report, and the book links to its compte de résultat, bilan and balance.

### Who sees a book

`ledger.admin_role` sees and manages every book. To grant more, implement `Base\Ledger\Security\BookAccessInterface`. Such services are autoconfigured: any of them saying yes is enough.

```php
final class AssociateBookAccess implements BookAccessInterface
{
    public function canView(UserInterface $user, Book $book): bool { /* an associé of its company */ }
    public function canManage(UserInterface $user, Book $book): bool { return false; }
}
```

## Use

```php
// A rent notice: the tenant owes 850 €.
$tenant = $parties->partyFor($book, 'tenant', 'U3', 'Jean Dupont', 'FR76…');
$posting->post((new EntryDraft($book, 'VT', $date, 'Loyer octobre U3', 'notice:2026-10-U3', 'AVIS-2026-10-U3'))
    ->line($tenant->getAccountNumber(), 85000, 0, $tenant)
    ->line('706', 0, 85000));

// The bank feed: import, then reconcile what is sure.
$sync->sync($bankAccount);              // or $upload->upload($bankAccount, $name, $content)
$reconciler->autoReconcile($bankAccount);
```

The transfer carrying "AVIS-2026-10-U3", or coming from Jean Dupont's IBAN, is then posted as 512 / 411U3 in BQ and lettered with the notice.

**Stripe** goes through the 5112 clearing account:

1. The application posts each payment (5112 / 411, its piece the payment id) and its fee (6278 / 5112).
2. The payout on the statement settles the 5112 open items, either by its reference or by a rule on 5112 ("STRIPE", money in).
3. The payout is then posted 512 / 5112 and the clearing account is lettered.

A **rule** may split the amount: `[{"account": "164", "amount": 85000}, {"account": "6611", "rest": true}]` splits a loan instalment between capital and interest.

## Public API

| Class | Methods |
|---|---|
| `Model\EntryDraft` | `__construct(Book, string $journal, \DateTimeImmutable, string $label, string $source, ?string $piece)`, `line(string $account, int $debit, int $credit, ?Party, ?string $label): self` |
| `Service\Posting` | `post(EntryDraft, bool $validate = true): Entry`, `reverse(Entry, string $source, ?\DateTimeImmutable): Entry`, `validate(Entry): Entry`, `assertOpen(Book, \DateTimeInterface)` |
| `Service\Chart` | `seed(Book, ?string $chart = null)` |
| `Service\Parties` | `partyFor(Book, PartyKind\|string $kind, string $reference, string $name, ?string $iban): Party` |
| `Service\Lettering` | `letter(iterable $lines): string`, `unletter(string, Book): int`, `openItems(Book, ?Party, ?string $prefix = '411'): array` |
| `Bank\Importer` | `import(BankAccount, iterable<BankTransaction>): int` |
| `Bank\Sync` | `sync(BankAccount): int`, `syncConnection(BankConnection): int` |
| `Bank\StatementUpload` | `upload(BankAccount, string $name, string $content): int` |
| `Bank\Connections` | `start()`, `complete()`, `renew()`, `discover()`, `attach()`, `open()`, `store()`, `gateways()` |
| `Reconciliation\Matcher` | `suggest(BankLine, ?array $openItems): list<Suggestion>`, `openItems(Book)` |
| `Reconciliation\Reconciler` | `apply(BankLine, Suggestion): Entry`, `autoReconcile(BankAccount): int`, `ignore(BankLine)`, `undo(BankLine)` |
| `Report\TrialBalance`, `GeneralLedger`, `OpenItems` | `compute(Book, …): array` |
| `Report\IncomeStatement` | `compute(Book, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $withPrevious = false): array`, `fromTotals(array $totals, ?array $previous)`, `yearBefore(\DateTimeImmutable)` |
| `Report\BalanceSheet` | `compute(Book, \DateTimeImmutable $at, bool $withPrevious = false): array`, `fromTotals(array $totals, ?array $yearTotals, ?array $previous, ?array $previousYear)`, `place(string $number, int $balance)` |
| `Export\FecWriter` | `write(Book, FiscalYear): string`, `filename(Book, FiscalYear)` |

Every refusal is a `Base\Ledger\Exception\LedgerException`. The subclasses are `PostingException`, `LockedPeriodException`, `LockedEntryException`, `LetteringException`, `ReconciliationException` and `BankException`.

## Commands

| Command | What it does | Suggested cron |
|---|---|---|
| `ledger:chart:seed <book> [--chart=sci]` | add a chart's missing accounts and journals | once |
| `ledger:bank:sync [book] [--no-reconcile]` | import the API-fed accounts' new lines, reconcile what is sure | every few hours |
| `ledger:fec:export <book> <year> [--dir=.] [--stdout]` | write `<SIREN>FEC<YYYYMMDD>.txt` | when the accountant asks |

## Routes

| Path | Name |
|---|---|
| `/admin/ledger/reconcile/{bankAccount}` | `ledger_admin_reconcile`, plus `_apply`, `_ignore`, `_undo`, `_auto` (POST, CSRF) |
| `/admin/ledger/bank/{bankAccount}/upload` | `ledger_admin_bank_upload` |
| `/admin/ledger/bank/connect/{gateway}?book=` | `ledger_admin_bank_connect`; back on `ledger_admin_bank_return` |
| `/admin/ledger/bank/connection/{id}/renew`, `/admin/ledger/bank/{id}/sync` | `ledger_admin_bank_renew`, `ledger_admin_bank_sync` |
| `/admin/ledger/book/{book}/trial-balance`, `/general-ledger`, `/open-items` | `ledger_admin_trial_balance`, `ledger_admin_general_ledger`, `ledger_admin_open_items` |
| `/admin/ledger/book/{book}/income-statement?from=&to=&n1=0\|1&format=csv` | `ledger_admin_income_statement` |
| `/admin/ledger/book/{book}/balance-sheet?at=&n1=0\|1&format=csv` | `ledger_admin_balance_sheet` |
| `/admin/ledger/book/{book}/fec/{year}` | `ledger_admin_fec` |
| `/ledger/bank/{gateway}/webhook` | `ledger_bank_webhook` (public; the gateway checks the signature) |

## Tests

The tests need no database. Entities are built in memory and repositories are stubs. The mapping is checked against a MySQL schema. The statement uploads go through Omnibank's real `files` gateway.

```bash
vendor/bin/phpunit   # standalone, or from a host: vendor/bin/phpunit -c vendor/omnibase/ledger
```

## Licence

MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
