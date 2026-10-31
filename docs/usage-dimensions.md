---
title: Analytic axes
order: 34
---

# Analytic axes

An entry line may carry dimensions - an axis and a value: `unit=12`, `owner=5`,
`ownership=3`. The reports filter on them and group by them: one home's result, the result
per home, per owner.

## Storage

`ledger_entry_line_dimension` (`EntryLineDimension`): the line, the axis, the value - a table
of its own, not JSON, indexed on `(axis, value)` and unique on `(line, axis)`, so the reports
filter and group in SQL whatever the database. An axis is a lower-case key (letters, digits,
underscores, up to 32); a value up to 64 characters. `EntryLine::getDimensions()` (axis =>
value), `getDimension($axis)`.

A validated entry stays frozen, but an axis may be added to a line that has not got it
(`EntryLine::addDimension()`: the analytic allocation completed after the fact, the amounts
untouched); a dimension is never changed nor removed (`ValidatedEntryGuard`).

## Posting

```php
$draft->line('706', 0, 85000, dimensions: ['unit' => '12']);
$draft->taxed('615', 12000, 0, $a20, dimensions: ['unit' => '12']);   // on the base and the VAT line
```

`Posting` refuses an axis or a value that is not one (`PostingException`); a reversal carries
the dimensions.

## Reports

- `IncomeStatement::compute($book, $from, $to, $withPrevious, ['unit' => '12'])`: only the
  lines carrying every dimension asked.
- `IncomeStatement::byDimension($book, $from, $to, 'unit', $filter = [])`: `{axis, groups,
  totals}`, a group per value - `{value, label, report}`, `report` as `compute()` gives it -,
  named and sorted by label, the lines without the axis last (`value` null), and the whole.
- `GeneralLedger::compute(..., $accountPrefix, $filter)` and `byDimension($book, $from, $to,
  $axis, $accountPrefix, $filter)`: a group per value with its accounts.
- `EntryLineRepository::totalsByAccount(..., $dimensions, $groupBy)`, `forLedger(...,
  $dimensions, $withDimensions)` and `axes($book)` (the axes a book's lines carry, with how many
  values each) are what they read.

The back office: the income statement and the general ledger take `?dimension[unit]=12` (shown
as a filter that can be removed) and `?by=unit` (an « Par axe » choice among the book's axes);
the income statement per axis is a table of the values, each opening its own statement, and
downloads as CSV.

## Labels

The ledger does not know what a unit is. A service implementing
`Base\Ledger\Dimension\DimensionLabelerInterface` (autoconfigured, tag
`ledger.dimension_labeler`) names an axis's values:

```php
final class UnitLabeler implements DimensionLabelerInterface
{
    public function supports(string $axis): bool { return 'unit' === $axis; }

    public function labels(string $axis, array $values): array
    {
        // value => label, for the values it knows
    }
}
```

`Dimension\Dimensions::labels($axis, $values)` asks them; a value nobody names is its own
label. An axis's own name is the translation `dimension.<axis>` of the `ledger` domain (unit,
owner, ownership are there), else its key. omnibase/estate names `unit`.
