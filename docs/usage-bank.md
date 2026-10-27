---
title: Bank feeds
order: 31
---

# Bank feeds

A book reaches its banks through omnisense/omnibank: each `BankConnection` names one gateway
of `omnibank.gateways`, keeps that gateway's `Connection` sealed, and holds the
`BankAccount`s it gives access to. Every bank service goes through
`Base\Ledger\Bank\Connections`, which holds the `Omnibank\Registry` (or `null` without
Omnibank's bundle: `isAvailable()` false, `gateways()` empty, `gateway()` throws a
`BankException`).

## Connecting a bank

```php
use Base\Ledger\Bank\Connections;

['connection' => $connection, 'url' => $url] = $connections->start(
    $book,
    'powens',
    fn ($connection) => $router->generate('ledger_admin_bank_return', ['connection' => $connection->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
    'Powens',
);
// $url: the bank's (or aggregator's) consent page; null when there is none.
```

`start(Book $book, string $gateway, string|callable $returnUrl, ?string $name = null)` saves a
new connection, then asks the gateway to `connect()`. The return URL may be a callable given
the saved connection. When the gateway answers with a page, the user is sent there; back on
the return URL, `complete(BankConnection $connection, string $returnUrl, array $query = [])`
hands the query the provider added to the gateway (state `return`) and asks `connect()` again
- either the next page, or `null` when the connection is done. Done, its accounts are
discovered.

What a gateway throws (other than a `BankException`) is recorded in the connection's
`lastError` and rethrown as a `BankException`. After each step the gateway's `Connection` is
sealed back into the entity with its consent status and expiry (`store()`).

`renew(BankConnection $connection, string $returnUrl): ?string` goes through the bank's page
again (an expired consent, a new password): the page to go to, or `null` when nothing is
needed.

### Accounts

`discover(BankConnection $connection): int` asks the gateway for the connection's accounts
and attaches those the book does not have yet. `attach()` gives each bank account its ledger
account and journal by rank in the book: the first posts on `5121` in `BQ`, the second on
`5122` in `BQ2`, and so on, each created (labelled `Banque - <name>`) when missing. An account
without a name is `Compte <masked IBAN>`, or `Compte bancaire`.

### Statement files

The `files` gateway has no consent: the back office's connect page asks the account's name
and IBAN, starts a `files` connection and attaches one account with a random external id
(`files-<12 hex>`) in the book's currency.

## Importing transactions

`Base\Ledger\Bank\Importer::import(BankAccount $account, iterable $transactions): int` turns
Omnibank `BankTransaction`s into `BankLine`s and returns how many are new. A transaction is
imported once (its id is unique per bank account; duplicates in one batch are skipped). A line
already there is updated while it is pending (unmatched or suggested) - banks correct pending
lines: date, amount, label, value date, counterparty, reference, raw data - and left alone once
reconciled or ignored.

## Syncing an account

`Base\Ledger\Bank\Sync::sync(BankAccount $account, ?\DateTimeImmutable $until = null): int`:

1. A connection that needs renewal (its status, or its expiry), or whose Omnibank consent is
   not active, is marked `NEEDS_RENEWAL` (or `REVOKED`, when the bank revoked it) with the
   message "The bank wants its consent given again." - and the sync stops with a
   `BankException`.
2. The transactions are asked since the last sync minus `ledger.bank.sync_overlap_days`
   days (5), from midnight; with no start date on the first sync. Lines already imported are matched
   on their id.
3. When the gateway supports `FetchBalances`, the last booked balance (any balance when none
   is booked) becomes the account's `bankBalance`.
4. `lastSyncedAt` is set and `lastError` cleared.

A failure is recorded in `lastError`; one whose message speaks of consent, expiry,
re-authentication, SCA, 401 or 403 marks the connection `NEEDS_RENEWAL`. The bank no longer
listing the account is a `BankException`.

`syncConnection(BankConnection $connection): int` syncs every account of a connection.

## Uploading a statement

`Base\Ledger\Bank\StatementUpload::upload(BankAccount $account, string $name, string $content): int`
reads a CAMT.053, OFX or CSV file through the `files` gateway and imports its lines:

- An empty file, or no `files` gateway, is a `BankException`.
- A CSV (by its `.csv` name, or content that starts neither with `<` nor `OFXHEADER`) says
  nothing of its account: it is given this account's id, IBAN, name and currency.
- The statement's account must be this one - same IBAN or same id; a single account without
  an IBAN on either side is taken - else "<file> is not a statement of <account>".
- The closing balance, when the statement has one, becomes the account's bank balance unless
  a later one is already kept.
- Overlapping statements import each line once. The file itself is not stored.

## The webhook

`POST /ledger/bank/{gateway}/webhook` (`ledger_bank_webhook`, public): the aggregators
(Powens, Bridge) say a connection changed.

| Case | Answer |
|---|---|
| the gateway is not configured | 404 |
| the gateway's `notify()` refuses the payload (`InvalidNotificationException`: a bad signature) | 400, with its message |
| no connection is found for it | 202 |
| handled | 204 |

The connection is the one of that gateway whose sealed state holds the provider's connection
id, or the only one of that gateway (`Connections::findByProviderId()`). A notification
carrying a consent updates the connection's status and expiry and the sealed state; then,
unless the connection needs renewal, each account is synced and reconciled automatically
(errors stay in `lastError`; the provider gets its 2xx).

## The sealed state

`Base\Ledger\Security\StateCipher` seals a connection's state (the provider's tokens, its
user) with sodium's secretbox (XSalsa20-Poly1305), under the SHA-256 of
`omnibase/ledger bank connection|<ledger.secret>`; the result is `v1:` and the base64 of the
nonce and the ciphertext. Each seal uses a new nonce. A state sealed under another secret, or
altered, does not open (`BankException`: "Connect the bank again."). An empty secret is an
`InvalidArgumentException` when the service is built.

> [!IMPORTANT]
> `ledger.secret` defaults to `%kernel.secret%`. Changing either makes every stored
> connection unreadable: each bank must be connected again.
