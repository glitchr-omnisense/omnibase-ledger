---
title: Posting, parties and lettering
order: 30
---

# Posting, parties and lettering

## Posting an entry

`Base\Ledger\Service\Posting` is the one way entries get into a book.

```php
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Service\Parties;
use Base\Ledger\Service\Posting;

// A rent notice: the tenant owes 850 €.
$tenant = $parties->partyFor($book, 'tenant', 'U3', 'Jean Dupont', 'FR76 3000 6000 0112 3456 7890 189');

$entry = $posting->post(
    (new EntryDraft($book, 'VT', new \DateTimeImmutable('2026-10-01'), 'Loyer octobre U3', 'notice:2026-10-U3', 'AVIS-2026-10-U3'))
        ->line($tenant->getAccountNumber(), 85000, 0, $tenant)
        ->line('706', 0, 85000)
);

$entry->getNumber(); // "2026-000001": validated, numbered and frozen
```

`post(EntryDraft $draft, bool $validate = true): Entry`:

1. An entry of the same book and `source` already there is returned as it is: posting is
   idempotent.
2. The draft is checked: two lines at least, a non-blank source, no negative amount, no line
   both debit and credit, no line without an amount, debits equal to credits - else a
   `PostingException` naming the line.
3. The date is checked (`assertOpen()`): on or before the book's `lockedUntil`, or in a
   closed fiscal year, is a `LockedPeriodException`.
4. The journal must exist in the book (by code); a party must be of the same book.
5. Every account is resolved by its normalised number. An unknown number is a
   `PostingException` unless `ledger.auto_accounts` is on: the account is then created
   under the longest existing account its number starts with (`4011` under `401`), with
   that parent's type and the line's party name, label, or the parent's label - and a
   `PostingException` when there is no such parent.
6. The entry is persisted; with `$validate`, it is numbered and flushed under a lock.

Nothing is written when a check fails.

### Numbering

`nextNumber(Book $book, \DateTimeInterface $date)` gives `<year>-<six digits>`: the year the
fiscal year containing the date ends in (the calendar year without one), then the last number
with that prefix plus one. With symfony/lock installed, numbering and its flush run under the
lock `ledger-number-<book id>`, one process at a time per book; the unique key
(`ledger_entry_number`) stands behind it either way.

### Drafts and validation

`post($draft, false)` leaves the entry a draft: no number, editable in the back office (its
date, label and piece), deletable. `validate(Entry $entry)` numbers and freezes it - refused
(`PostingException`) when it is unbalanced or has fewer than two lines, and in a locked
period. A validated entry is returned unchanged.

### Reversing

`reverse(Entry $entry, string $source, ?\DateTimeImmutable $date = null): Entry` posts the
mirror image (debits and credits swapped, the same accounts, parties and labels) in the same
journal, labelled `Extourne <number> <label>`, dated `$date` or the entry's own date, with
`reversalOf` set, validated. The same `$source` twice returns the reversal already posted. A
draft is not reversed (change or delete it); an entry already reversed is refused.

### The guard

`Base\Ledger\EventListener\ValidatedEntryGuard` (a Doctrine `onFlush` listener) refuses, with
a `LockedEntryException`, any flush that:

- changes a validated entry, or deletes it;
- changes a line of a validated entry other than its `letter` and `letteredOn`, deletes one,
  or adds one.

An entry inserted or validated in the same flush is not yet frozen. The guard applies to
every writer - the back office, a fixture, a service.

## Parties

`Base\Ledger\Service\Parties::partyFor(Book $book, PartyKind|string $kind, string $reference, string $name, ?string $iban = null): Party`
finds a party by the application's reference, or creates it:

- Found, its name and IBAN are updated to what the application says now (and flushed when
  they changed).
- Created, its auxiliary account is the kind's collective account followed by the reference,
  upper-cased and alphanumeric (`411U3`; `X` for an empty reference), cut to 17 characters
  and made unique in the book with a suffix (`411U32`). The `Account` is created when the book
  has none of that number, with the collective account's type.

A party created in the request is found again before the flush (kept in memory by book and
reference).

## Lettering

`Base\Ledger\Service\Lettering` ties lines that cancel out:

```php
$letter = $lettering->letter([$noticeLine, $paymentLine]); // "AAA"
```

`letter(iterable $lines, ?\DateTimeImmutable $on = null): string` refuses
(`LetteringException`) fewer than two lines, a line already lettered, lines of different
books, accounts or parties, and lines whose debits and credits differ. The letter is the
book's next: `AAA`, `AAB`... `AAZ`, `ABA`... `ZZZ`, `AAAA` (`Lettering::increment()`); the
lines' `letteredOn` is `$on` or today.

`unletter(string $letter, Book $book): int` takes a letter off every line carrying it and
returns how many. `openItems(Book $book, ?Party $party = null, ?string $accountPrefix = '411')`
returns the unlettered lines under the prefix (all accounts with `null`), oldest first.

A partial payment stays open beside its notice until the rest arrives: the lines are
lettered together once their debits and credits balance.

## The chart

`Base\Ledger\Service\Chart::seed(Book $book, ?string $chart = null)` adds to the book the
accounts and journals of `config/charts/<chart>.php` that it does not have, and leaves the
others alone - it can run again after the chart grows. The chart is `ledger.chart` (`sci`)
when none is named. `definition(string $chart)` returns the file's array; a name outside
`[a-z0-9_-]` or a missing file is an `InvalidArgumentException`.

A chart file returns:

```php
use Base\Ledger\Enum\AccountType as T;

return [
    'accounts' => [
        '101' => ['Capital social', T::EQUITY],
        '411' => ['Locataires', T::ASSET],
        // number => [label, type]
    ],
    'journals' => [
        'BQ' => 'Banque',
        // code => label
    ],
];
```

`Chart` reads the bundle's `config/charts/` unless built with another `$chartsDir`
(a service definition argument).

## In memory

The entities and the reports' static parts need no database. Run in omnisense/omnibase's
harness image, with this checkout's `src/` on the autoloader:

```php
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Enum\RuleSign;
use Base\Ledger\Export\FecWriter;
use Base\Ledger\Model\Text;
use Base\Ledger\Service\Lettering;

$book = new Book('SCI Les Tilleuls', '123 456 789');
echo $book->getSiren(), ' ', AccountType::guess('411U3')->value, ' ', AccountType::guess('401EDF')->value, "\n";

$rule = new ReconciliationRule($book, 'Frais bancaires', 'FRAIS TENUE COMPTE', new Account($book, '627', 'Services bancaires'), RuleSign::OUT);
var_dump($rule->matches('Frais de tenue de compte - octobre', null, -850));
var_dump($rule->matches('FRAIS TENUE COMPTE', null, 850));

echo FecWriter::amount(123456), ' ', FecWriter::amount(-5), "\n";
echo Lettering::increment('AAZ'), ' ', Lettering::increment('ZZZ'), "\n";
echo Text::fold("Loyer d'Octobre - Dupont"), ' | ', Text::squash('AVIS-2026-10/U3'), "\n";
```

```
123456789 asset liability
bool(true)
bool(false)
1234,56 -0,05
ABA AAAA
LOYER D OCTOBRE DUPONT | AVIS202610U3
```
