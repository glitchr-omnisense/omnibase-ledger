---
title: Reports and the FEC
order: 33
---

# Reports and the FEC

Every report is a service with a `compute()` that reads the book, and a static part
(`fromTotals()`, `fromLines()`) that works on plain arrays - what the tests and an
application's own exports use. Amounts are minor units. Draft entries count in every report
(they say what the books say today); only the FEC keeps validated entries alone.

## Trial balance

`Base\Ledger\Report\TrialBalance::compute(Book $book, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null)`
- the balance générale:

| Key | Content |
|---|---|
| `rows` | per account, by number: `number`, `label`, `type` (value), `debit`, `credit`, `balance` (debit minus credit), `debitBalance`, `creditBalance` |
| `classes` | per PCG class (the number's first character): `debit`, `credit`, `balance` |
| `totals` | `debit`, `credit`, `debitBalance`, `creditBalance` |
| `result` | income minus expenses (accounts of type income and expense): a profit is positive |
| `balanced` | total debits equal total credits |

`TrialBalance::typeOf(array $row)` reads a row's type: the enum, its value, or
`AccountType::guess()` from the number.

## General ledger

`GeneralLedger::compute(Book $book, ?$from = null, ?$to = null, ?string $accountPrefix = null)`
- the grand livre: per account (by number) its `lines` - `date`, `journal`, `number`,
`piece`, `label` (the line's, else the entry's), `party`, `debit`, `credit`, the running
`balance`, `letter` - and its `debit`, `credit` and `balance` totals.

## Open items

`OpenItems::compute(Book $book, ?Party $party = null, ?string $accountPrefix = '411', ?\DateTimeImmutable $at = null)`
- what is still owed, by account and party: `parties` (each with `party`, `name`, `account`,
`items`, `balance`, `oldest` date), largest balance first, and the `total`. `$at` leaves out
the lines dated after it. `401` gives what is still to pay to suppliers.

## Compte de résultat

`IncomeStatement::compute(Book $book, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $withPrevious = false)`.
The headings, and the account prefixes each takes:

| Side | Key | Heading | Prefixes |
|---|---|---|---|
| income | `rents` | Loyers | `706`, `7083` |
| income | `recovered_charges` | Charges récupérées | `708` |
| income | `other_income` | Autres produits | `7088`, and every income account no prefix matches |
| expenses | `rental_charges` | Charges locatives et de copropriété | `614`, `6061` |
| expenses | `repairs` | Entretien et réparations | `615`, `6063` |
| expenses | `insurance` | Assurances | `616` |
| expenses | `fees` | Honoraires | `622`, `611` |
| expenses | `taxes` | Impôts et taxes | `63` |
| expenses | `interest` | Intérêts des emprunts et dettes | `661` |
| expenses | `depreciation` | Dotations aux amortissements | `681` |
| expenses | `bank_fees` | Frais bancaires | `627` |
| expenses | `other_expenses` | Autres charges | every expense account no prefix matches |

An account goes to the group of the longest prefix it starts with (`7083` to the rents, `708`
to the recovered charges); its side is its type. Each side is a map of groups (`key`,
`label`, `lines` - `number`, `label`, `amount`, `previous` -, `total`, `previous`); both
sides are positive when normal (a refund larger than the charge makes it negative).
`totals` is `income`, `expenses`, `result`; `previous` the same for N-1, or `null`.

With `$withPrevious`, the N-1 column is the same period a year earlier:
`IncomeStatement::yearBefore()` keeps a month's last day its last day (`2028-02-29` gives
`2027-02-28`), so the N-1 of a twelve-month fiscal year is the fiscal year before.

## Bilan

`BalanceSheet::compute(Book $book, \DateTimeImmutable $at, bool $withPrevious = false)` -
every account's balance from the first entry to `$at`.

| Side | Key | Heading |
|---|---|---|
| assets | `fixed_assets` | Immobilisations nettes |
| assets | `receivables` | Créances locataires et clients |
| assets | `other_assets` | Autres créances |
| assets | `cash` | Disponibilités |
| liabilities | `equity` | Capitaux propres |
| liabilities | `loans` | Emprunts |
| liabilities | `deposits` | Dépôts de garantie reçus |
| liabilities | `suppliers` | Dettes fournisseurs |
| liabilities | `associates` | Comptes courants d'associés |
| liabilities | `other_liabilities` | Autres dettes |

Where an account goes (`BalanceSheet::place(string $number, int $balance)`):

- `165` deposits, `16` loans, `10`-`14` equity, the rest of class 1 other liabilities - on the
  passif whatever their side (negative when on the wrong one);
- class 2 fixed assets, net (depreciation `28` shows negative), class 3 other assets;
- the others by their balance: in debit, `41` receivables, `51` and `53` cash, the rest other
  assets; in credit, `40` suppliers, `455` associates, the rest other liabilities - a tenant
  who overpaid is a debt, an overdrawn bank a debt.

The income and expense accounts are not closed in the books: their balance is the result,
in the capitaux propres - "Résultat de l'exercice" for the fiscal year containing `$at`, and
"Résultats antérieurs non affectés" for what earlier years left (no closing entry to
`120`/`129`). `totals` is `assets`, `liabilities`, `result`; `balanced` says whether actif and
passif agree (in both columns with N-1).

```php
use Base\Ledger\Report\BalanceSheet;
use Base\Ledger\Report\IncomeStatement;
use Base\Ledger\Report\TrialBalance;

// The totals of a period, as EntryLineRepository::totalsByAccount() returns them.
$totals = [
    ['number' => '411U3', 'label' => 'Jean Dupont', 'type' => 'asset', 'debit' => 170000, 'credit' => 85000],
    ['number' => '5121', 'label' => 'Banque', 'type' => 'asset', 'debit' => 85000, 'credit' => 850],
    ['number' => '627', 'label' => 'Services bancaires', 'type' => 'expense', 'debit' => 850, 'credit' => 0],
    ['number' => '706', 'label' => 'Loyers', 'type' => 'income', 'debit' => 0, 'credit' => 170000],
];
$trial = TrialBalance::fromTotals($totals);
echo 'balanced: ', var_export($trial['balanced'], true), ', result: ', $trial['result'], "\n";

$statement = IncomeStatement::fromTotals($totals);
echo 'rents: ', $statement['income']['rents']['total'], ', bank fees: ', $statement['expenses']['bank_fees']['total'], ', result: ', $statement['totals']['result'], "\n";

$sheet = BalanceSheet::fromTotals($totals);
echo 'assets: ', $sheet['totals']['assets'], ', liabilities: ', $sheet['totals']['liabilities'], ', balanced: ', var_export($sheet['balanced'], true), "\n";
echo implode('/', BalanceSheet::place('411U3', -5000)), ' ', implode('/', BalanceSheet::place('5121', 1000)), "\n";
echo IncomeStatement::yearBefore(new DateTimeImmutable('2028-02-29'))->format('Y-m-d'), "\n";
```

Run in omnisense/omnibase's harness image:

```
balanced: true, result: 169150
rents: 170000, bank fees: 850, result: 169150
assets: 169150, liabilities: 169150, balanced: true
liabilities/other_liabilities assets/cash
2027-02-28
```

## CSV downloads

The back office's compte de résultat and bilan download with `?format=csv`: a UTF-8 BOM,
semicolons, the columns Section, Rubrique, Compte, Libellé, N, N-1 (N-1 left out with
`?n1=0`), amounts with a decimal comma and no thousands separator; one row per account, one
total row per group, then the result (compte de résultat) or each side's total (bilan). Files:
`compte-de-resultat-<from>-<to>.csv`, `bilan-<at>.csv`.

## The FEC

`Base\Ledger\Export\FecWriter::write(Book $book, FiscalYear $year): string` - the Fichier des
Écritures Comptables (article A47 A-1 of the LPF) of a fiscal year:

- one row per line of every validated entry of the period, by date then number;
- the 18 columns in their order: `JournalCode`, `JournalLib`, `EcritureNum`, `EcritureDate`,
  `CompteNum`, `CompteLib`, `CompAuxNum`, `CompAuxLib`, `PieceRef`, `PieceDate`,
  `EcritureLib`, `Debit`, `Credit`, `EcritureLet`, `DateLet`, `ValidDate`, `Montantdevise`,
  `Idevise`;
- tab-separated, CRLF line ends, dates `YYYYMMDD`, amounts with a decimal comma and no
  thousands separator (`FecWriter::amount(123456)` is `1234,56`);
- a line on a party, or on an auxiliary account (`411U3`), is filed under its general account
  (`411`, with that account's label) and carries the auxiliary account and the party's name in
  `CompAuxNum` and `CompAuxLib`;
- `PieceRef` is the entry's piece, or its number; `PieceDate` the entry's date;
- `Montantdevise` and `Idevise` are empty for a book in euros, the signed amount and the
  currency otherwise;
- tabs, pipes and line breaks inside a field become a space (`clean()`).

`FecWriter::filename()` is `<SIREN>FEC<closing date>.txt` (`000000000` without a SIREN). The
fiscal year is `FiscalYearRepository::forYear($book, 2026)`: the one ending in 2026, else the
calendar year.

```bash
bin/console ledger:fec:export "SCI Les Tilleuls" 2026 --dir=var/fec
bin/console ledger:fec:export 1 2026 --stdout > fec.txt
```
