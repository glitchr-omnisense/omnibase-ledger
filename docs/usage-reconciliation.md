---
title: Reconciliation
order: 32
---

# Reconciliation

A bank line is reconciled when it is posted in its bank account's journal and lettered with
what it settles. `Base\Ledger\Reconciliation\Matcher` says what a line may settle, ranked;
`Base\Ledger\Reconciliation\Reconciler` posts it.

## The open items

`Matcher::openItems(Book $book)` is what a bank line can settle: the unlettered lines of the
third-party accounts (class 4) and of the clearing accounts (`511`).

## Suggestions

`Matcher::suggest(BankLine $line, ?array $openItems = null): list<Suggestion>` returns the
suggestions best first (a line of amount 0 has none). The line's label, reference and
counterparty name are folded (`Text::fold()`) and compared with the open items, by rank of
trust:

| Rank | What is found | Score |
|---|---|---|
| 1 | an open item's piece (4 characters at least, squashed) in the label or reference, the amounts agreeing | `0.99` |
| 1 | the piece found, a different amount | `0.6`, with no items |
| 2 | the counterparty's IBAN is a party's, one open item of exactly the amount | `0.95` |
| 2 | the same, several items of that amount: the oldest | `0.93` |
| 2 | the same, all the party's items adding up to it | `0.94` |
| 2 | the same, some of them adding up to it | `0.92` |
| 2 | the IBAN is a party's, nothing of that amount (a payment on account) | `0.5` |
| 3 | a party's name in the label, one item of exactly the amount | `0.9` |
| 3 | the same, several of that amount: the oldest | `0.88` |
| 3 | the same, all its items | `0.89` |
| 3 | the same, some of them | `0.85` |
| 4 | nobody recognised, one open item of exactly the amount | `0.75` |
| 4 | nobody recognised, one party's items adding up to it | `0.7` |
| 5 | the first matching rule by priority, with its account's open items adding up to it | `0.92` |
| 5 | the first matching rule | `0.9` |
| 5 | another matching rule | `0.5` |

Ambiguity lowers the scores: the same amount owed by several parties with nothing else to
tell them apart gives `0.5` each, several groups `0.5` each, two parties recognised the same
way (IBAN or name) both with items `0.6` at most. A party's name is recognised when every
word of it of 3 letters or more is in the label, one of them 4 letters or more. Only items on
the amount's side are considered: owed to the book for money coming in, owed by it for money
going out.

"Adding up" is a subset search among a holder's first `ledger.reconciliation.max_group_items`
open items (12), oldest first, at least two of them, with a search budget of 20,000 steps.
Two ways to the same items are one suggestion, the best score kept.

### `Suggestion`

`Base\Ledger\Reconciliation\Suggestion` (readonly): `score` (0 to 1), `reason` (French, shown
in the back office), `openItems`, `rule`, `party`. `total()` is what the open items add up to
(debit minus credit); `settles(int $amount)` whether they settle the amount exactly;
`withScore()` a copy with another score.

## Applying a suggestion

`Reconciler::apply(BankLine $line, Suggestion $suggestion): Entry` posts the line in its bank
account's journal, dated as the bank booked it, with the line's label, the source
`$line->getSource()` (`bank:<id>`), and the first open item's piece (or the line's reference)
as piece. The bank account's line (`512x`) is debited for money in, credited for money out;
against it:

- **The items settle the amount**: one line per account and party, for what their items add
  up to; each is then lettered with its items. A holder whose items cancel out among
  themselves is lettered without a line.
- **Items that do not settle it** (a partial payment): one line on the first item's account
  and party, not lettered - it will be when the rest arrives.
- **A rule**: its split ([below](#rules-and-splits)), the rule's party on the lines of the
  rule's own account.
- **A party**: its auxiliary account (a payment on account).
- Nothing of these: a `ReconciliationException`.

The line becomes `RECONCILED` with its entry, its reconciliation count goes up, and
`Base\Ledger\Event\BankLineReconciled` is dispatched (with the settled items, or none). A line
that is not pending, or of amount 0, is refused.

## Automatic reconciliation

`autoReconcile(BankAccount $account): int` goes through the account's pending lines, oldest
first: a line with exactly one suggestion at or above `ledger.reconciliation.auto_threshold`
(0.9) is applied; the others become `SUGGESTED` (suggestions exist) or `UNMATCHED` (none). A
line that fails to post (a locked period, an account missing) is left for someone. It returns
how many were applied. It runs after each sync, each statement upload, each webhook, and from
the reconciliation page's button.

## Ignoring and undoing

- `ignore(BankLine $line)` leaves a line out of the books (an internal transfer posted
  otherwise, a test): `IGNORED`. A reconciled line is refused: undo it first.
- `undo(BankLine $line)`: an ignored or suggested line goes back to `UNMATCHED`. A reconciled
  one is unlettered, its entry reversed (dated as it was, source `<source>:undo`), the entry
  and its reversal lettered together on the class 4 and 511 accounts so that neither is an
  open item, and the line is `UNMATCHED` again. Refused when the entry's date is in the
  locked period or a closed fiscal year. The next reconciliation posts with the source
  `bank:<id>/2`.

## Rules and splits

A `ReconciliationRule` places recurring lines nothing open matches: bank fees to `627`, a loan
instalment split between `164` (capital) and `6611` (interest), a Stripe payout to `5112`.

`Reconciler::split(ReconciliationRule $rule, int $amount)` returns the parts
`[account, minor units, label]`:

- no split: the whole amount on the rule's account;
- otherwise, in the split's order: `amount` a fixed part in minor units, `percent` a share
  (rounded), each capped at what is left; a part with `rest: true` takes what remains; with no
  such part, the rule's own account takes it. A part without `account` is the rule's account;
  `label` is the line's label. Parts of 0 are dropped.

```json
[{"account": "164", "amount": 85000}, {"account": "6611", "rest": true}]
```

> [!NOTE]
> In the back office, the split is typed as JSON. A text that is not a JSON array saves the
> rule with no split (`ReconciliationRuleCrudController`'s setter), without an error.

## The Stripe pattern

Card payments go through the `5112` clearing account:

1. The application posts each payment (`5112` / `411`, its piece the payment id) and its fee
   (`6278` / `5112`).
2. The payout on the statement settles the `5112` open items, by its reference (rank 1) or by
   a rule on `5112` ("STRIPE", money in: rank 5 with its items, `0.92`).
3. The payout is posted `512` / `5112` and the clearing account's lines are lettered.

## Following a reconciliation

```php
use Base\Ledger\Event\BankLineReconciled;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class RentPaid
{
    public function __invoke(BankLineReconciled $event): void
    {
        foreach ($event->openItems as $item) {
            // $item->getEntry()->getSource(): "notice:2026-10-U3" - the rent call is paid.
        }
    }
}
```

`$event->line`, `$event->entry` (the bank entry) and `$event->openItems` (the items it settled;
empty for a partial payment, a rule or a payment on account).
