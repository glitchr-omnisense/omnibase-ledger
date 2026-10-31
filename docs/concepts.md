---
title: Books, accounts and entries
order: 20
---

# Books, accounts and entries

Every entity hangs off a `Book`; every relation to a book is `onDelete: CASCADE` at the
database. Amounts are integers in minor units. Dates are `date_immutable` columns set to
midnight.

## `Book`

One legal entity's accounts (`ledger_book`).

| Property | Column | Meaning |
|---|---|---|
| `name` | `string(255)`, not blank | the entity's name |
| `siren` | `string(9)`, nullable | the SIREN; `setSiren()` keeps the digits only, an empty value is `null`. The FEC's file name starts with it |
| `currency` | `string(3)` | `EUR` by default, upper-cased |
| `lockedUntil` | `date`, nullable | nothing dated on or before it can be posted, changed or reversed |
| `vatRegime` | `VatRegime`, `string(20)`, default `none` | how its VAT is declared ([VAT](usage-vat.md)) |
| `createdAt` | `datetime` | |

`isLocked(\DateTimeInterface $date)` compares days. `getBook()` returns the book itself, so
the voter treats a book like anything of a book.

## `Account`

An account of a book's chart, by its PCG number (`ledger_account`, unique on book and number).

| Property | Column | Meaning |
|---|---|---|
| `number` | `string(20)` | upper-cased, without spaces (`Account::normalize()`: `"411 u3"` is `411U3`) |
| `label` | `string(255)` | |
| `type` | `AccountType` | given, or guessed from the number (`AccountType::guess()`) |

`getGeneralNumber()` is the leading digits (`411` for `411U3`): the general account the FEC
files an auxiliary account under. `isUnder(string $prefix)` tells whether the number starts
with the prefix.

## `Journal`

`ledger_journal`, unique on book and code. `code` (`string(8)`, upper-cased) and `label`.
The constants name the usual codes: `Journal::BANK` (`BQ`), `SALES` (`VT`), `PURCHASES`
(`AC`), `MISCELLANEOUS` (`OD`), `OPENING` (`AN`).

## `FiscalYear`

An accounting period (`ledger_fiscal_year`, unique on book and start; columns `starts_on`,
`ends_on`, `closedAt`). The constructor refuses an end before the start; the back office's
form says so instead (`validatePeriod()`). `FiscalYear::calendar($book, 2026)` is the unsaved
calendar year a book without fiscal years works on. `contains($date)`, `isClosed()`.
`__toString()` is `2026`, or `2025-2026` for a year across two calendar years.

A closed fiscal year takes no entry. Entry numbers run per fiscal year, prefixed with the year
it ends in.

## `Party`

Someone the book owes or is owed by (`ledger_party`, unique on book and reference, indexed on
IBAN).

| Property | Column | Meaning |
|---|---|---|
| `kind` | `PartyKind` | tenant, supplier, associate, other, mandator (an owner whose homes the company manages) |
| `name` | `string(255)` | |
| `iban` | `string(34)`, nullable | normalised (upper-case, alphanumeric): how the party's transfers are recognised |
| `reference` | `string(64)`, nullable | the application's handle on them (a lease, a supplier code), unique in the book |
| `accountNumber` | `string(20)` | its auxiliary account (`411U3`); an `Account` of that number exists in the book |

## `Entry` and `EntryLine`

An `Entry` (`ledger_entry`) is balanced lines on one date in one journal.

| Property | Column | Meaning |
|---|---|---|
| `journal` | relation | |
| `date` | `date` | |
| `label` | `string(255)` | cut to 255 characters |
| `piece` | `string(64)`, nullable | the supporting document's reference (an invoice number, `AVIS-2026-10-U3`): the matcher looks for it in bank labels |
| `source` | `string(191)` | what it records, unique per book (`notice:2026-10-U3`, `bank:42`) |
| `number` | `string(32)`, nullable | given at validation (`2026-000042`), unique per book |
| `validatedAt` | `datetime`, nullable | set at validation |
| `reversalOf` | relation, nullable | the entry it reverses |
| `lines` | one-to-many, cascade persist, by id | |

`isValidated()`, `getTotalDebit()`, `getTotalCredit()`, `isBalanced()`. `validate(string
$number)` is called by `Posting` only; a second call is a `LogicException`.

An `EntryLine` (`ledger_entry_line`, indexed on `letter`):

| Property | Column | Meaning |
|---|---|---|
| `account` | relation | |
| `party` | relation, nullable, `SET NULL` on delete | |
| `debit`, `credit` | `integer` | never negative; one of them is zero |
| `label` | `string(255)`, nullable | the line's own label, else the entry's |
| `letter` | `string(8)`, nullable | the lettering code (`AAA`) |
| `letteredOn` | `date`, nullable | |
| `tax` | relation to `Tax`, nullable | a VAT line's code (an exempt base line's too) |
| `taxBase` | `integer`, nullable | what the VAT was computed on, minor units, on the line's side |
| `dimensions` | one-to-many `EntryLineDimension`, cascade persist, by axis | its analytic axes ([Analytic axes](usage-dimensions.md)) |

`getDimensions()` (axis => value), `getDimension($axis)`, `addDimension($axis, $value)` (an
axis the line has not got, even once validated).

`getNet()` is debit minus credit: positive when the line is owed to the book (a rent notice
on 411). An open item is an unlettered line.

## Bank entities

`BankConnection` (`ledger_bank_connection`): a book's access to a bank through one gateway.

| Property | Column | Meaning |
|---|---|---|
| `gateway` | `string(32)` | the gateway's name in `omnibank.gateways` |
| `name` | `string(255)`, nullable | |
| `state` | `text`, nullable | the gateway's `Connection`, sealed ([Bank feeds](usage-bank.md#the-sealed-state)) |
| `consentStatus` | `ConsentState` | `NONE` at first |
| `expiresAt` | `datetime`, nullable | when the consent ends |
| `lastError` | `text`, nullable | the last error a sync met, cut to 2000 characters |
| `accounts` | one-to-many `BankAccount` | |

`needsRenewal(?$now)` is true when the consent is `NEEDS_RENEWAL` or `REVOKED`, or
`expiresAt` is past.

`BankAccount` (`ledger_bank_account`, unique on connection and external id): one account at
the bank - `externalId` (the gateway's id), `iban` (normalised), `name`, `currency`, its
ledger `account` (`512x`) and `journal` (`BQ`, `BQ2`...), `lastSyncedAt`, and the bank's own
`bankBalance` (`bigint`, minor units) with `bankBalanceAt`. `BankAccount::mask($iban)` shows
`FR76 •••• 4321`.

A bank account may hold money for third parties, not the company's own: `escrow`
(`isEscrow()`, « Compte séquestre » on its screen) marks a property manager's escrow account
(compte séquestre, loi Hoguet) - the owners' rents arrive there, their payouts leave from it.
`BankAccountRepository::escrowFor($book)` gives the book's (omnibase/estate's `management`
activity posts its owners' money to it).

`BankLine` (`ledger_bank_line`, unique on bank account and external id, indexed on account
and status): a transaction as the bank booked it.

| Property | Column | Meaning |
|---|---|---|
| `externalId` | `string(191)` | the transaction's id at the gateway |
| `bookedOn`, `valueOn` | `date` | |
| `amount` | `integer` | signed: negative when money leaves |
| `currency` | `string(3)` | |
| `label` | `string(512)` | white space collapsed |
| `counterpartyName`, `counterpartyIban`, `reference` | nullable | what the bank gives |
| `raw` | `json` | the gateway's raw transaction |
| `status` | `BankLineStatus` | `UNMATCHED` at first |
| `entry` | relation, nullable | its bank entry once reconciled |
| `reconciliations` | `integer` | how many times it was reconciled |

`getSource()` is the source of its next bank entry: `bank:<id>`, then `bank:<id>/2` after an
undo, and so on.

## `Tax`

A VAT code of a book (`ledger_tax`, unique on book and code): `code` (`string(16)`,
upper-cased, no spaces), `label`, `rate` (basis points: 2000 for 20 %), `kind` (`TaxKind`),
`account` (nullable: none for an exempt code), `deductibleAccount` (a reverse charge's 44566).
`split(int $amount, bool $taxIncluded = true)` gives `[base, VAT]`, rounded to the cent half up,
the base taking the remainder. See [VAT](usage-vat.md).

## `EntryLineDimension`

An analytic axis of a line and its value (`ledger_entry_line_dimension`, unique on line and
axis, indexed on axis and value). `axis()` and `value()` check and normalise them.

## `ReconciliationRule`

What to post a recurring bank line against when nothing open matches it
(`ledger_reconciliation_rule`).

| Property | Column | Meaning |
|---|---|---|
| `name` | `string(255)` | |
| `pattern` | `string(255)`, nullable | words that must all appear in the label (accents and punctuation ignored), or a `/regular expression/` (flags `iu` added when it ends with `/`) |
| `iban` | `string(34)`, nullable | the counterparty's IBAN, when set it must match |
| `sign` | `RuleSign` | `ANY`, `IN` (money in), `OUT` (money out) |
| `account` | relation | the account the line is posted against |
| `party` | relation, nullable | |
| `priority` | `integer` | the highest first |
| `split` | `json`, nullable | the amount spread over several accounts ([Reconciliation](usage-reconciliation.md#rules-and-splits)) |
| `enabled` | `boolean` | `true` |

`matches(string $label, ?string $iban, int $amount)`: enabled, the sign accepts the amount,
the IBAN agrees when the rule has one; an empty pattern then matches only when the rule has
an IBAN.

## Enums

| Enum | Cases | Methods |
|---|---|---|
| `AccountType` | `ASSET` `asset`, `LIABILITY` `liability`, `EQUITY` `equity`, `INCOME` `income`, `EXPENSE` `expense` | `isDebitNormal()` (asset, expense), `isResult()` (income, expense), `guess(string $number)` |
| `BankLineStatus` | `UNMATCHED`, `SUGGESTED`, `RECONCILED`, `IGNORED` | `isPending()` (unmatched or suggested) |
| `ConsentState` | `ACTIVE`, `NEEDS_RENEWAL`, `REVOKED`, `NONE` | `of()`: from Omnibank's `ConsentStatus` (or a string), by value or name, `NONE` otherwise |
| `PartyKind` | `TENANT`, `SUPPLIER`, `ASSOCIATE`, `OTHER`, `MANDATOR` | `collective()`: `411`, `401`, `455`, `467`, `4671` (the owners' money a property manager holds) |
| `RuleSign` | `ANY`, `IN`, `OUT` | `accepts(int $amount)` (`ANY` refuses `0`) |
| `TaxKind` | `COLLECTED`, `DEDUCTIBLE`, `DEDUCTIBLE_ASSETS`, `REVERSE_CHARGE`, `EXEMPT` | `label()`, `isCreditNormal()` |
| `VatRegime` | `NONE`, `FRANCHISE`, `SIMPLIFIED`, `NORMAL_MONTHLY`, `NORMAL_QUARTERLY` | `isLiable()`, `form()`, `label()`, `lastPeriod()` |

`AccountType::guess()` follows the PCG's classes: `10`-`14` equity, the rest of class 1
liability; classes 2, 3, 5 asset; 6 expense; 7 income; class 4 by its longest prefix (`40`
liability but `409` asset, `41` asset but `419` liability, `42`, `43` liability, `44`
liability but `441`, `4456`, `4458` asset, `45` liability but `456` asset, `46`, `47`, `48`,
`49` asset, `487` liability).

## `EntryDraft`

`Base\Ledger\Model\EntryDraft` is an entry as the application describes it, with account
numbers and a journal code rather than entities:

```php
new EntryDraft(Book $book, string $journal, \DateTimeImmutable $date, string $label, string $source, ?string $piece = null)
```

`line(string $account, int $debit, int $credit, ?Party $party = null, ?string $label = null, array $dimensions = [], Tax|string|null $tax = null, ?int $taxBase = null): self`
adds a line; `taxed(string $account, int $debit, int $credit, Tax $tax, bool $taxIncluded = true, ?Party $party = null, ?string $label = null, array $dimensions = [])`
adds a taxed amount as its base and its VAT ([VAT](usage-vat.md)); `getLines()`,
`getTotalDebit()`, `getTotalCredit()`. Existing calls are unchanged.

## `Text`

How bank labels, names and references are compared (`Base\Ledger\Model\Text`): `fold()`
upper-cases, removes accents and turns every other character into a space;
`squash()` removes the spaces too, so references survive the bank's spacing.

```
Text::fold("Loyer d'Octobre - Dupont")   // LOYER D OCTOBRE DUPONT
Text::squash('AVIS-2026-10/U3')          // AVIS202610U3
```
