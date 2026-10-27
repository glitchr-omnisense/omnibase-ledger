---
title: The back office
order: 35
---

# The back office

The screens are loaded when omnibase/admin is installed (`config/services.php` checks for
`Base\Admin\Controller\AbstractCrudController`). Their labels are in French, as the books
are.

## Who sees a book

`Base\Ledger\Security\Voter\LedgerVoter` decides `LEDGER_VIEW` and `LEDGER_MANAGE`, on a
`Book` or on anything with a `getBook()` (an entry, an account, a bank line...):

- `ledger.admin_role` (`ROLE_ADMIN`) views and manages every book;
- otherwise the application's `BookAccessInterface` services are asked: any `canManage()`
  saying yes grants both, any `canView()` grants `LEDGER_VIEW`;
- without a subject (the ledger's screens as a whole), only the admin role is granted.

```php
use Base\Ledger\Entity\Book;
use Base\Ledger\Security\BookAccessInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AssociateBookAccess implements BookAccessInterface
{
    public function canView(UserInterface $user, Book $book): bool
    {
        return /* the user is an associate of the book's company */ false;
    }

    public function canManage(UserInterface $user, Book $book): bool
    {
        return false;
    }
}
```

`BookAccessInterface` is autoconfigured (tag `ledger.book_access`): implementing it is
enough. omnibase/estate's `Ledger\AssociateBookAccess` is one.

Every ledger CRUD uses `LedgerAdminTrait`: reading a record takes `LEDGER_VIEW`
(`setEntityPermission`), the actions that write (`NEW`, `EDIT`, `DELETE`, `BATCH_DELETE`, the
three saves) `LEDGER_MANAGE` - not omnibase/admin's default super-administrator. The pages
below are `#[IsGranted('LEDGER_VIEW')]` as a whole, and check `LEDGER_MANAGE` on the record
for what changes something.

## The screens

| CRUD | Slug | What it allows |
|---|---|---|
| `BookCrudController` | `books` | the books; actions Balance (trial balance), Impayés (open items), Plan comptable (seed the chart, asked to confirm); a book with entries cannot be deleted |
| `AccountCrudController` | `accounts` | the chart, by number, 50 a page, searched by number and label, filtered by type; book and number chosen when created, shown on edit; an account with lines cannot be deleted |
| `JournalCrudController` | `journals` | book and code chosen when created; a journal with entries cannot be deleted |
| `FiscalYearCrudController` | `fiscal-years` | a new one is this calendar year of the first book; closing it is setting `closedAt` |
| `PartyCrudController` | `parties` | a party typed here gets its auxiliary account when saved: left empty, the kind's collective account and the reference (else the name), made unique; the `Account` created when missing; a party with lines cannot be deleted |
| `EntryCrudController` | `entries` | no "new" (entries are posted, not typed); a draft's date, piece and label can be corrected, then Valider; a validated entry is read-only, Extourner posts its reversal; the detail page shows the lines; only drafts can be deleted |
| `BankConnectionCrudController` | `bank-connections` | no "new" (opened from the connect page); Renouveler, except for `files`; the record's note says when the bank asks for renewal, else the last error; the state is never shown |
| `BankAccountCrudController` | `bank-accounts` | no "new"; Rapprocher, Importer un relevé, Synchroniser (not for `files`); the ledger account and journal chosen in a list grouped by book |
| `BankLineCrudController` | `bank-lines` | read-only, newest first, 50 a page, filtered by status; Rapprocher opens the account's reconciliation |
| `ReconciliationRuleCrudController` | `reconciliation-rules` | name, priority, enabled, pattern, counterparty IBAN, direction, account, party, split (JSON); a new rule points to `627` (or the first account) of the first book |

A new record belongs to the first book until its form says otherwise; with no book, "Create a
book first". The CRUDs' routes are `admin_crud_<slug>_*` (`admin_crud_bank_lines_index`...).

The record links that act (Plan comptable, Valider, Extourner, Renouveler, Synchroniser) carry
a CSRF token in their query, `<action>_<id>`: a link from elsewhere, or a prefetch, does
nothing.

## The pages

They render in the admin's layout with its menus (`AbstractLedgerPageController::page()`).
After an action they go back to the back-office page the button was on, and only to one under
`/admin` on the same host - never to the site page under a nested back office.

**Reconciliation** (`/admin/ledger/reconcile/{bankAccount}`): the pending lines, each with
its five best suggestions and the open items on its side to pick from by hand; the lines
placed lately (40), which can be undone; the account's rules and the book's parties; the
threshold. Accept a suggestion, or pick items, a rule or a party (a hand-made suggestion of
score 1.0); ignore a line; undo; reconcile automatically. Every change is a POST with the
token `ledger_line_<id>` (`ledger_reconcile_auto_<id>` for the button).

**Upload** (`/admin/ledger/bank/{bankAccount}/upload`): the file field `statement` (CAMT.053,
OFX or CSV), token `ledger_upload_<id>`; after the import, automatic reconciliation and the
reconciliation page.

**Connect** (`/admin/ledger/bank/connect/{gateway}?book=`): for `files`, a form (name, IBAN;
token `ledger_bank_connect`), then the upload page; for another gateway (token
`ledger_bank_connect` in the form or the query), off to its consent page and back on
`/admin/ledger/bank/return/{connection}`. A gateway that is not configured is a flash
message. The book is `?book=`, else the first.

**Reports** (`/admin/ledger/book/{book}/...`): trial balance, general ledger (`?account=` a
prefix), open items (`?account=`, `411` by default), compte de résultat and bilan, printed
without the admin's chrome, `?n1=0` without N-1, `?format=csv` downloaded; the period is
`?from=&to=` (Y-m-d), or `?year=` (the fiscal year ending in it), else the current fiscal
year; the bilan is `?at=`, else the period's end or today, whichever is earlier. The FEC of a
year downloads from `/fec/{year}`.

## Dashboard widgets

`ledger_bank` (`BankWidgetType`, `@Ledger/admin/widget/bank.html.twig`), for the books the
user may view:

- each bank account with its balance in the books (its `512x`) and at the bank, its last sync
  and consent - "Renouveler" when the bank asks (not for `files`) - and its lines to reconcile,
  linking to the reconciliation page;
- the six latest lines across accounts;
- with no account yet: "Connecter une banque" for each configured gateway but `files`
  (Powens, Bridge, Qonto by name), and "Importer un relevé" when `files` is configured.

`ledger_books` (`BooksWidgetType`, `@Ledger/admin/widget/books.html.twig`), for up to four
books the user may view: the current fiscal year to date - income, expenses and result (the
compte de résultat's totals), open receivables (open debits on `411`), open payables (open
credits on `401`) and cash (`512` to today). Each tile opens its report; the book links to its
compte de résultat, bilan and balance.
