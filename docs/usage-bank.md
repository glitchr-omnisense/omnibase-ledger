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
reconciled or ignored. Each line keeps the transaction's category (`BankLine::getCategory()`, an
Omnibank `TransactionCategory`, stored by its value), when the gateway gives one.

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
4. `lastSyncedAt` is set and `lastError` cleared; the account's kind and the gateway's word
   for it (`BankAccount::getKind()`, an Omnibank `AccountKind`, and `getProviderType()`) are
   taken from the gateway's account, and the connection's state
   (`BankConnection::getConnectionState()`, an Omnibank `ConnectionState`) becomes the one the
   gateway gives with the account (Powens' `error`: data read, but the user must act), else OK.
5. The account's precomputed rows are written again (Aggregates, below).

A failure is recorded in `lastError` and as the connection's state
(`ConnectionState::ofFailure()`: the state a provider's refusal carries, BANK_UNAVAILABLE for
an outage, else BUG); one whose message speaks of consent, expiry, re-authentication, SCA, 401
or 403, or whose state wants the user, marks the connection `NEEDS_RENEWAL`. The bank no longer
listing the account is a `BankException`. A webhook's state (`Notification::$state`) is
recorded on its connection before the sync it starts; a connection opened through a provider's
page is PENDING until its accounts are found.

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
- A statement that says what the account is (CAMT.053's `Acct/Tp`, OFX's `ACCTTYPE`) sets its
  kind; the connection's state becomes OK and the precomputed rows are written again.

## The opening entry

A connected account brings only its recent lines: what came before them is missing from the books.
`Base\Ledger\Bank\Opening::open(BankAccount)` writes it once - the bank's balance less the lines
imported up to the balance's date, dated the day before the first line (the balance's own day
when there is none), on the bank account's ledger account against `ledger.bank.opening_account`
(`890`, Bilan d'ouverture) in `ledger.bank.opening_journal` (`AN`, the à-nouveaux), validated,
source `bank:opening:<id>`. Both are created when the book lacks them. A balance positive is a
debit on the bank account, a negative one (a loan) a credit. Once its lines are reconciled, the
account's ledger balance is the bank's; until then the difference is exactly the lines still to
reconcile.

It is written after the first sync or upload that brings a balance (a locked period leaves it to
the command). `adjust()` replaces an opening the bank's balance no longer agrees with (older lines
imported since, a corrected balance): reversed on its own date, posted again as
`bank:opening:<id>/2`. For the accounts already there:

```sh
bin/console ledger:bank:opening [book] [--adjust] [--reclassify]
```

`890` should come back to zero once the book's own à-nouveaux agree with the banks: a balance left
on it is the difference the accountant has to look at (a bank balance already carried in the
à-nouveaux on 512, for instance).

## Which ledger account

`Connections::ledgerPrefix(AccountKind)`: a loan or a consumer credit posts under `164`
(Emprunts auprès des établissements de crédit: `1641`, `1642`...), a revolving credit or overdraft
line under `519` (Concours bancaires courants), every other account under `512` (`5121`...), each
the first number of its prefix no bank account of the book uses yet, created when the chart does
not have it. `Connections::reclassify(BankAccount, Posting)` moves an account attached before its
kind was known (a loan on `512x`) onto its prefix: the former account's balance carried by an OD
entry (`bank:reclass:<id>:<number>`), then the bank account posts on the new one
(`ledger:bank:opening --reclassify` does it for every account).

## A connection never finished

A connection opened through a provider's page and left there has no consent and no account
(`BankConnection::isUnfinished()`); after 24 hours it is abandoned (`isAbandoned()`): the connections
card counts it no more, lists it apart, and offers to remove it - `POST
/admin/ledger/bank/connection/{id}/remove` (`ledger_admin_bank_remove`, `LEDGER_MANAGE`, the
token `ledger_bank_remove_<id>`), which `Connections::remove()` refuses for a connection that holds
an account or a consent.

## What the dashboard reads

The bank cards never read every line on a page load. `Base\Ledger\Bank\Aggregates::rebuild(BankAccount)`
folds an account's lines (one query, plain values, no entity) through the pure
`Bank\Aggregator` into four tables, deleted and written again:

| Entity (table) | One row per | Holds |
|---|---|---|
| `BankDay` (`ledger_bank_day`) | account and day with a movement | in, out, how many each way, the transfers among them (lines categorised `transfer` or carrying the counterparty's IBAN), the closing balance |
| `BankDayCategory` (`ledger_bank_day_category`) | account, day and category (`none` when uncategorised) | in, out, how many each way |
| `BankCounterpartyMonth` (`ledger_bank_counterparty_month`) | account, month and counterparty | in, out, how many each way, the name to show |
| `BankRecurring` (`ledger_bank_recurring`) | recurring flow found | the usual amount, the rhythm, occurrences, the last and next date, the category |

The closing balances are anchored on the bank's last balance (the lines booked after its date
added), else on the lines alone from zero. A counterparty is the bank's name for it, else the
IBAN, else the label stripped of the bank's words (`Bank\Counterparty`). A recurring flow
(`Bank\Recurrences`) is one counterparty's lines in one direction, split by amount (a bank's
instalment and its fees are two flows), at least three, whose median interval is a week, a
month, a quarter or a year with three quarters of the intervals near it, whose amounts stay
within a quarter of their median, and whose next date is not past by more than the rhythm's
tolerance (2, 6, 12, 20 days).

When: after each sync (`Sync`) and statement upload (`StatementUpload`), and for every account
with `bin/console ledger:bank:aggregate [book]` - once a day, to move the recurring flows' next
dates on and to catch up after lines changed by other means:

```cron
30 4 * * *  bin/console ledger:bank:aggregate
```

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

**Registered by the application, where the provider allows it.** `ledger:bank:webhook [<gateway>]
[--force]` (`Bank\Webhooks::ensure()`), run at each deploy, registers each configured gateway's
webhook on the address the router builds for `ledger_bank_webhook` outside a request, through
omnibank's optional `RegisterWebhook`, and keeps the secret the provider answers in omnibase's
settings, sealed by its `#[Vault]`: `api.webhook.ledger_bank_webhook.<gateway>.secret` (with
`.id`, `.url`). The webhook checks a call with it (`Webhooks::notifier()`: `Registry::create($gateway,
['webhook_secret' => ...])`); a configured `webhook_secret` wins. The secret needs the
environment's vault key pair (`php bin/console secrets:generate-keys --env=prod`, or
`SYMFONY_DECRYPTION_SECRET` on the server); two runs at once take turns over the site's lock store
(`LOCK_DSN`). Idempotent, exit 0 always; on an
address no provider reaches (`localhost`, `*.local`, `*.test`, a private IP, HTTP) it registers
nothing and says that `ledger:bank:sync` covers it. **No aggregator supports it today**: Powens
documents no API for it (its configuration routes take a console-only token) and Bridge sets
webhooks in its dashboard, so the command says so for each - their webhook stays optional, set in
their console if wanted.

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
