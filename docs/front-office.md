---
title: The front office's bank screen
order: 34
---

# The front office's bank screen

The day-to-day reconciliation, for whoever keeps the books, has a screen of its own outside the
back office: `/comptabilite` (`Controller\Front\BankReviewController`, French paths as
omnibase/estate's spaces have). The back office's reconciliation page
(`ledger_admin_reconcile`) stays, for monitoring and the technical side. Both go through the
same service, `Reconciliation\Desk`, and from there through the `Reconciler`: one
implementation, the same events.

## Who reaches it

Anyone signed in reaches the address; the screen then needs `LEDGER_VIEW` on the book (to read)
and `LEDGER_MANAGE` (to act): `ledger.admin_role` by default, or what an application's
`BookAccessInterface` grants. Who may not even read the book gets a 404 - what exists is not
told -, who reads it but may not change it a 403 on each action. The book is `?book=`, else the
first one the user may see; several books give a switcher in the heading.

The application links it from where its team starts (meyers: « La comptabilité » on « Mon
accès », shown when `is_granted('LEDGER_VIEW')`).

## What it shows

One list of compact rows, newest first, a hundred at a time (the next ones load as the list
scrolls, `ledger_front_review_rows`):

- the date, the label as a person reads it (`Bank\Label::clean()`: « CB DEBIT DIFFERE
  MONOPRIX » is « Monoprix », a transfer the name of who sent it; the bank's own label on hover),
  the account, the amount (signed, money in coloured);
- for a line to handle, its best suggestion as a chip (« Léa Muller · AVIS-2026-10-U3 »), its
  trust in words - « sûr » at or above `reconciliation.auto_threshold`, « probable » from 0.75,
  « possible » under -, the others under « autres »; without one, « Choisir une catégorie »;
- for a line placed, what it was placed on (the category in plain words, the party) and its
  entry's number; « Ignorée » for an ignored one;
- its receipt as a thumbnail (a photo) or « PDF », or « manquant ».

Above the list: the states « À traiter » (the default), « Rapprochées », « Ignorées »,
« Justificatif manquant », « Toutes », each with its count; the account, the month and a search
(words of the label, the counterparty or the reference, or an amount: « 89,16 »); the month's
progress (« 41 / 63 traitées ») with its bar; « Synchroniser » and « Rapprocher ce qui est sûr ».

## The keyboard

| Key | Does |
|---|---|
| `j` / `↓`, `k` / `↑` | the next / previous line |
| `Enter` | accepts the line's suggestion (the picker when it has none) |
| `c` | the category picker: type to filter, `↑` `↓`, `Enter`, `Esc` |
| `s` | the split |
| `i` | ignores the line |
| `u` | undoes the last action (else puts the current line back) |
| `r` | makes the rule the toast offers (« Toujours classer “Monoprix” ainsi ») |
| `a` | attaches a receipt |
| `m` | marks the receipt missing, or not |
| `/` | the search |

A key does nothing while one types in a field. After a line is handled the cursor goes to the
next line still to handle; the row is answered again in place (an XHR), the counters and the
progress with it, and a toast says what was done with « Annuler ». Nothing takes the focus on
its own. At a phone's width the rows stack (label and amount, the chip below) and the keys' hint
is hidden.

## The actions

Every action is a POST with the token `ledger_review` (`_token`, or the `X-CSRF-Token` header),
answered in JSON: `ok`, `id`, `row` (the row's HTML), `message`, `undo` (`{url, data}`),
`counts`, `progress` (`done`, `total`, `text`). Each goes through `Desk`:

| Action | `Desk` | What it posts |
|---|---|---|
| accept a suggestion (`suggestion` = its index), or open items / a rule / a party picked | `accept()`, `manual()` | as the back office does |
| a category (`account`, `party`, `tax`) | `categorize()` | the whole line on that account; with a tax code, its base and its VAT |
| a split (`parts[i][amount|account|items|party|tax|label]`) | `split()` | each part on its account or against its open items (lettered when it is exactly their total) - the parts add up to the line |
| ignore, undo | `ignore()`, `undo()` | the Reconciler's |
| « Toujours classer “X” ainsi » | `ruleFrom()`, then `undoRule()` | a `ReconciliationRule` (the cleaned label's words, the line's direction, the account, the party, a tax code as a one-part split), applied at once to the book's other lines to handle that it matches |
| « Rapprocher ce qui est sûr » | `autoReconcile()`, `undoLines()` | the Reconciler's `autoReconcile()` for the accounts shown, the lines it placed listed to be undone |
| « Synchroniser » | `sync()`, `undoSync()` | what `ledger:bank:sync` does for the accounts shown (statement-file accounts left out); undone, the lines it added that nobody placed are taken out and each account's last sync put back |
| a receipt, « manquant » | `Bank\Receipts` | below |

The categories (`Reconciliation\Categories`) are the book's chart in plain words: charges,
income, fixed assets, loans and deposits, the associates' current accounts, internal transfers
and the suspense account (the third parties' auxiliary accounts, the bank, VAT and result
accounts are not offered). Each account's plain words are the translation
`review.category.<key>` of its longest prefix in `Categories::PLAIN` (627 « Frais bancaires »,
616 « Assurance »), its own label otherwise. First come the accounts the book's bank lines went
to most over the last year, then `Categories::FREQUENT` (6257, 6251, 6063, 627, 616, 6061, 615,
626, 455, 580) as far as the chart has them. A book liable to VAT gets a tax code beside a charge
(the deductible codes) or an income (the collected ones).

The rules have their page, `/comptabilite/regles` (`ledger_front_rules`): each rule's name,
words, direction, category, party, tax code, priority and whether it is active, changed or
deleted in place. A rule split over several parts is changed in the back office.

## Receipts

`Bank\Receipts` files a line's receipt - a photo (JPEG, PNG, WebP, HEIC, GIF) or a PDF, up to
`receipts.max_size` - dropped on its row or chosen (`a`):

- with omnibase/drive installed, in the drive (the Partage), under `receipts.folder`
  (« Comptabilité/Justificatifs ») then the line's year and month, named after the line
  (« 2026-10-31 Monoprix -89,16.jpg »), beside a file of that name rather than over it; the top
  folder made for `receipts.audience` (the team) when the ledger creates it;
- otherwise in `receipts.directory`, never served directly.

The line keeps where its receipt is (`BankLine::$receipt`, `$receiptName`, `$receiptType`);
`ledger_front_review_receipt_file` streams it to who may view the book. Taken off the line, the
file stays where it was: the company's papers are kept (the drive's retention lock would refuse
deleting them anyway). A line may be marked « justificatif manquant »
(`BankLine::$receiptMissing`, cleared when a receipt comes); the state « Justificatif
manquant » lists them.

## Its look

The bundle writes `ledger-front-*`, `ledger-review-*`, `ledger-row-*` markup
(`templates/front/`: `layout`, `review`, `rules`, `_row`, `_suggestion`) with a structural style
(`public/css/review.css`) on custom properties: `--ledger-ink`, `--ledger-soft`,
`--ledger-paper`, `--ledger-mist`, `--ledger-line`, `--ledger-accent`, `--ledger-accent-ink`,
`--ledger-in`, `--ledger-sure`, `--ledger-likely`, `--ledger-possible`, `--ledger-warn`,
`--ledger-radius`, `--ledger-current`. A site sets them (and its fonts, its dark mode) in its own
style, or overrides a template in `templates/bundles/LedgerBundle/front/`. The layout extends
`layout/layout1.html.twig`, as omnibase/estate's spaces do. `public/js/review.js` (no
dependency, every listener on the document, so a page swapped in by transparent.js or Turbo
works) makes it the keyboard screen; without it the states and the filters are plain links and
a form. `assets:install` publishes both under `bundles/ledger/`.
