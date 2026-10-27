---
title: Errors
order: 50
---

# Errors

Every refusal of the ledger is a `Base\Ledger\Exception\LedgerException`
(a `\RuntimeException`): nothing was written. Catch it where an application posts on behalf
of a user; the back office turns each into a flash message.

| Exception | Parent | Thrown when |
|---|---|---|
| `LedgerException` | `\RuntimeException` | the base class |
| `PostingException` | `LedgerException` | `Posting`: fewer than two lines, no source, a negative or two-sided line, a line without amount, an unbalanced entry, no such journal, a party of another book, an unknown account (`auto_accounts` off) or one with no parent (on); validating an unbalanced entry; reversing a draft or an entry already reversed |
| `LockedPeriodException` | `PostingException` | a date on or before the book's `lockedUntil`, or in a closed fiscal year (`Posting::assertOpen()`, called by post, validate and undo) |
| `LockedEntryException` | `LedgerException` | `ValidatedEntryGuard` at flush: a validated entry changed or deleted, a line of one changed (beyond lettering), added or deleted |
| `LetteringException` | `LedgerException` | `Lettering::letter()`: not entry lines, fewer than two, one already lettered, different accounts or parties, debits and credits that differ |
| `ReconciliationException` | `LedgerException` | `Reconciler`: a line not pending, of amount 0, with nothing to post against; ignoring a reconciled line; undoing a line that is not reconciled, or in the locked period |
| `BankException` | `LedgerException` | Omnibank missing, a gateway not configured, a gateway's failure (wrapped), a consent to renew, an account the bank no longer lists, an empty statement or one of another account, a sealed state that does not open |

Other exceptions:

- `\InvalidArgumentException` - `Chart::definition()` for a chart name outside `[a-z0-9_-]` or
  a missing file; `new FiscalYear()` ending before it starts; `StateCipher` built with an empty
  secret.
- `\LogicException` - `Entry::validate()` on an entry already validated (`Posting` checks
  first).
- `Omnibank\Exception\InvalidNotificationException` - caught by the webhook, answered 400.

A gateway's own exception is kept as the `BankException`'s previous exception, and its message
in the connection's `lastError`.
