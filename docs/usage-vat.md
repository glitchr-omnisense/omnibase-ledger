---
title: VAT
order: 34
---

# VAT

A book says how its VAT is declared, its VAT codes say what each rate does, a VAT line knows
its code and the base it was computed on, and `Report\VatReturn` reads those lines back as the
French return's boxes.

## The book's regime

`Book::$vatRegime` (`Base\Ledger\Enum\VatRegime`), set on the book's screen:

| Case | Value | Return |
|---|---|---|
| `NONE` | `none` (the default) | none: not liable - a property company (SCI) letting homes, unless it opts in |
| `FRANCHISE` | `franchise` | none: franchise en base, no VAT invoiced nor deducted |
| `SIMPLIFIED` | `simplified` | CA12, once a year |
| `NORMAL_MONTHLY` | `normal_monthly` | CA3, each month |
| `NORMAL_QUARTERLY` | `normal_quarterly` | CA3, each quarter |

`isLiable()`, `form()` (`CA3`, `CA12` or null), `label()`, and `lastPeriod($today)`: the
period a return is due for (the month or the quarter before, the calendar year before for the
CA12; null when nothing is filed).

## The codes: `Tax`

`ledger_tax`, one row per book and code (unique): `code`, `label`, `rate` in basis points
(2000 for 20 %), `kind` (`Base\Ledger\Enum\TaxKind`), `account`, and for a reverse charge its
`deductibleAccount`.

| Kind | Value | Its VAT goes to |
|---|---|---|
| `COLLECTED` | `collected` | 44571, on the credit side |
| `DEDUCTIBLE` | `deductible` | 44566 (goods and services), debit |
| `DEDUCTIBLE_ASSETS` | `deductible_assets` | 44562 (fixed assets), debit |
| `REVERSE_CHARGE` | `reverse_charge` | due on 4452 (credit) and deducted on 44566 (debit) at once |
| `EXEMPT` | `exempt` | no VAT; the base line carries the code, its base is reported |

`Chart::seed()` (and `ledger:chart:seed`) gives a book the French set with the accounts they
need (4452, 44562, 44566, 44567, 44571): `V20`, `V10`, `V55`, `V21` collected; `A20`, `A10`,
`A55`, `A21` deductible; `I20` on fixed assets; `EXO` exempt; `AIC20` an intra-EU acquisition
at 20 %, reverse-charged. Seeding again adds the codes the book lacks and leaves the others.
The codes are a back-office screen of their own (`VatCodeCrudController`, « Codes de TVA »); a code
a line was posted with is not deleted.

## Posting a taxed amount

`EntryDraft::taxed($account, $debit, $credit, Tax $tax, $taxIncluded = true, $party, $label, $dimensions)`
splits an amount into its base line and its VAT line:

```php
$a20 = $taxes->findOneByCode($book, 'A20');

$posting->post((new EntryDraft($book, 'AC', $date, 'Facture EDF octobre', 'invoice:EDF-10', 'EDF-10'))
    ->taxed('6061', 9999, 0, $a20)                 // 6061 D 83,32 and 44566 D 16,67
    ->line('401EDF', 0, 9999, $supplier));
```

- All taxes included (what an invoice's total says), the VAT is taken out of the amount -
  `round(amount × rate / (1 + rate))` to the cent, half up - and the base takes what the
  rounding leaves. Before tax (`$taxIncluded = false`), the VAT is added:
  `round(amount × rate)`, and the counterpart is `getTotalDebit()` / `getTotalCredit()`.
  `Tax::split($amount, $taxIncluded)` gives `[base, VAT]` without posting.
- The VAT line points to its tax and keeps its base: `EntryLine::$tax`, `EntryLine::$taxBase`
  (minor units, never negative, on the line's side - a credit note's VAT line is on the other
  side, and counts negatively in the return).
- A reverse charge's amount is its base (the supplier invoices no VAT): the base line, the VAT
  deducted on the base's side and the VAT due on the other, which cancel out.
- An exempt code adds no VAT line: the base line carries the code and its base.
- The dimensions go on every line.

`line()` takes a tax too, by its code or the `Tax`, with its base:
`->line('44566', 2000, 0, tax: 'A20', taxBase: 10000)`. `Posting` refuses a code the book does
not have, another book's `Tax`, a negative base and a base without its tax (a
`PostingException`). A reversal carries the tax and the base.

Posting itself does not know the book's regime: a book outside VAT simply has no taxed lines.

## Bank lines: a rule's part with a tax code

A part of a reconciliation rule's split may carry a tax code; the part is then an amount all
taxes included, posted as its base and its VAT - a recurring supplier's bank line posts its VAT:

```json
[{"account": "6061", "rest": true, "tax": "A20"}]
```

`Reconciler::parts($rule, $amount)` gives the parts with their code (`split()` is unchanged);
a code the book does not have is a `ReconciliationException`.

## The return: `Report\VatReturn`

`compute(Book $book, \DateTimeImmutable $from, \DateTimeImmutable $to)` reads the taxed lines
of the period (`EntryLineRepository::taxTotals()`, one SQL query grouped by tax and account;
drafts count, as in the other reports) and gives, in minor units:

- `regime`, `liable`, `form`;
- `operations`: `sales` (box 01, the collected codes' bases), `acquisitions` (03, the reverse
  charges'), `exempt` (05);
- `rates`: per rate, its box (08 for 20 %, 9B for 10 %, 09 for 5.5 %, 13 for 2.1 %), the base
  and the VAT due - the sales and the intra-EU acquisitions together;
- `gross` (16), `reverse_charge` (`vat` is box 17), `deductible` (`fixed_assets` 19,
  `goods_services` 20 with the reverse charges' deducted half, `total` 23), `credit` (25) or
  `net_due` (28);
- `boxes`: the same as a list of `{box, label, base, amount}`, what the page and the CSV show;
- `ca12`: the yearly totals of the simplified regime (VAT due, deductible on fixed assets and
  on the rest, total, credit or net due, before the instalments paid);
- `taxes`: per code, its base and its VAT.

`VatReturn::fromTotals($rows, $regime)` does the same from rows, without a database. The credit
carried over from the previous return (box 22) and the instalments are not known here.

The page is `ledger_admin_vat_return` (`/admin/ledger/book/{book}/vat-return`, « TVA » beside
the other reports and on the book's screen): `?from=&to=`, else the last period the regime
declares, else the current fiscal year; `?format=csv`. A book outside VAT says so above the
boxes.

The FEC is unchanged: VAT lines are ordinary lines.
