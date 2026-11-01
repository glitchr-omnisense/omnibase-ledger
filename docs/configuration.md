---
title: Configuration reference
order: 40
---

# Configuration reference

## `ledger:`

`Base\Ledger\DependencyInjection\LedgerConfiguration`, dumped with Symfony's
`YamlReferenceDumper` in omnisense/omnibase's harness image:

```yaml
ledger:

    # Posting to an account number the book does not have creates it, as a sub-account of the longest existing account its number starts with (4011 under 401). Off: such an entry is refused.
    auto_accounts:        false

    # The chart Chart::seed() uses when none is named: a file of config/charts/ (sci: a French real-estate SCI).
    chart:                sci

    # Who views and manages every book (LEDGER_VIEW, LEDGER_MANAGE). Services implementing BookAccessInterface may grant more.
    admin_role:           ROLE_ADMIN

    # What the key sealing the bank connections' state (tokens) is derived from. Changing it makes the stored connections unreadable: they must be connected again.
    secret:               '%kernel.secret%'
    reconciliation:

        # A bank line is reconciled on its own only when exactly one suggestion scores at least this much.
        auto_threshold:       0.9

        # How many open items of one party a grouped payment is looked for among (subset sum, oldest first).
        max_group_items:      12

        # How many days before an open item's date a bank line may settle it (a rent paid ahead): a line older than that is never offered it.
        max_advance_days:     45
    receipts:

        # Where a bank line's receipt is filed in omnibase/drive when it is installed (the Partage): this folder, then the year and the month of the line.
        folder:               Comptabilité/Justificatifs

        # Who sees that folder in the drive when the ledger makes it (a drive audience's key): the team by default.
        audience:             staff

        # Where the receipts are kept without omnibase/drive: a directory never served directly.
        directory:            '%kernel.project_dir%/var/storage/ledger/receipts'

        # The largest receipt taken, in bytes.
        max_size:             15728640
    bank:

        # A sync asks again for the days before the last one: banks book late. Lines already imported are matched on their id.
        sync_overlap_days:    5
```

| Option | Type | Default | Read by | Effect |
|---|---|---|---|---|
| `auto_accounts` | bool | `false` | `Posting` | an unknown account number is created under its longest existing parent, instead of refused |
| `chart` | string | `sci` | `Chart` | the file of `config/charts/` seeded when none is named |
| `admin_role` | string | `ROLE_ADMIN` | `LedgerVoter` | the role that views and manages every book |
| `secret` | string | `%kernel.secret%` | `StateCipher` | what the bank state's key is derived from; must not be empty |
| `reconciliation.auto_threshold` | float, 0 to 1 | `0.9` | `Reconciler` | the score a lone suggestion needs to be applied on its own |
| `reconciliation.max_group_items` | int, 2 to 20 | `12` | `Matcher` | the open items a grouped payment is searched among |
| `reconciliation.max_advance_days` | int, 0 or more | `45` | `Matcher` | how long before an open item's date a bank line may settle it |
| `receipts.folder` | string | `Comptabilité/Justificatifs` | `Bank\Receipts` | the drive folder receipts are filed under (then year/month) |
| `receipts.audience` | string, null | `staff` | `Bank\Receipts` | the drive audience of that folder when the ledger makes it |
| `receipts.directory` | string | `%kernel.project_dir%/var/storage/ledger/receipts` | `Bank\Receipts` | where receipts go without omnibase/drive |
| `receipts.max_size` | int, bytes | `15728640` | `Bank\Receipts` | the largest receipt taken |
| `bank.sync_overlap_days` | int, 0 or more | `5` | `Sync` | the days asked again before the last sync |

## Parameters

`LedgerExtension` sets one parameter per key and per level (the core's
`AbstractBaseExtension::setConfiguration()`): `ledger.auto_accounts`, `ledger.chart`,
`ledger.admin_role`, `ledger.secret`, `ledger.reconciliation` (the array),
`ledger.reconciliation.auto_threshold`, `ledger.reconciliation.max_group_items`, `ledger.reconciliation.max_advance_days`,
`ledger.receipts` and its `folder`, `audience`, `directory`, `max_size`, `ledger.bank`,
`ledger.bank.sync_overlap_days`. The services read them with `#[Autowire('%ledger.…%')]`.

## Services

`config/services.php` autowires and autoconfigures every class of `src/` except
`DependencyInjection/`, `Entity/`, `Enum/`, `Model/`, `Event/`, `Exception/`,
`Reconciliation/Suggestion.php`, `Reconciliation/Part.php`, `Bank/Label.php` (value objects),
`Controller/Admin/`, `Admin/` and the bundle class. Every
service is private.

- `Controller/` but `Admin/` (the webhook, the front office's `Front\BankReviewController`) is
  tagged `controller.service_arguments`.
- When `Omnibank\Registry` exists, `Bank\Connections` gets `$registry` as
  `service('Omnibank\Registry')->nullOnInvalid()`: `null` when Omnibank's bundle is not
  registered.
- When omnibase/admin is installed: `Controller/Admin/` (tagged
  `controller.service_arguments`) and `Admin/` (the two widget types, autoconfigured as
  omnibase/admin widget types).

Autoconfiguration the bundle relies on:

| Class | How |
|---|---|
| `BookAccessInterface` | `#[AutoconfigureTag('ledger.book_access')]`, injected into `LedgerVoter` with `#[AutowireIterator]` |
| `ValidatedEntryGuard` | `#[AsDoctrineListener(event: Events::onFlush)]` |
| `LedgerExtension` (Twig) | `#[AsTwigFilter]` on its methods |
| the three commands | `#[AsCommand]` |
| `LedgerVoter` | a `Voter` |

Optional collaborators: `Symfony\Component\Lock\LockFactory` (`Posting`, numbering under a
lock), `EventDispatcherInterface` (`Reconciler`, the `BankLineReconciled` and `BankLineUnreconciled` events).

`ledger.bank.opening_account` (`890`) and `ledger.bank.opening_journal` (`AN`): the account and the
journal a bank account's opening entry is posted against and in ([Bank feeds](usage-bank.md#the-opening-entry)),
created when the book has none.

