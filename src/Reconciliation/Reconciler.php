<?php

namespace Base\Ledger\Reconciliation;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Event\BankLineReconciled;
use Base\Ledger\Event\BankLineUnreconciled;
use Base\Ledger\Enum\BankLineStatus;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Exception\ReconciliationException;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Repository\BankLineRepository;
use Base\Ledger\Repository\TaxRepository;
use Base\Ledger\Service\Lettering;
use Base\Ledger\Service\Posting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Turns a bank line into the books: apply() posts it in its bank account's
 * journal - the bank account (512x) against what the suggestion says: the
 * open items' accounts, the rule's account(s), or the party's - and letters
 * it with the open items it settles. Its entry's source is "bank:<line id>",
 * so applying it twice posts it once.
 *
 * Stripe and the like go through the 5112 clearing account: the payment
 * (5112 / 411) and its fee (6278 / 5112) are posted by the application; the
 * payout on the statement (512 / 5112) settles the 5112 open items, by its
 * reference or by a rule on 5112.
 */
class Reconciler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Posting $posting,
        private readonly Lettering $lettering,
        private readonly Matcher $matcher,
        private readonly BankLineRepository $bankLines,
        #[Autowire('%ledger.reconciliation.auto_threshold%')] private readonly float $threshold = 0.9,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?TaxRepository $taxes = null,
    ) {
    }

    public function getThreshold(): float
    {
        return $this->threshold;
    }

    /** @throws ReconciliationException|LedgerException */
    public function apply(BankLine $line, Suggestion $suggestion): Entry
    {
        if (!$line->getStatus()->isPending()) {
            throw new ReconciliationException(sprintf('%s is %s already.', $line, $line->getStatus()->value));
        }
        $amount = $line->getAmount();
        if (0 === $amount) {
            throw new ReconciliationException(sprintf('%s has no amount: ignore it.', $line));
        }

        $bankAccount = $line->getBankAccount();
        $in = $amount > 0;
        $abs = abs($amount);
        $piece = null;
        foreach ($suggestion->openItems as $item) {
            $piece ??= $item->getEntry()->getPiece();
        }

        $draft = new EntryDraft($line->getBook(), $bankAccount->getJournal()->getCode(), $line->getBookedOn(), $line->getLabel(), $line->getSource(), $piece ?? $line->getReference());
        $draft->line($bankAccount->getAccount()->getNumber(), $in ? $abs : 0, $in ? 0 : $abs);

        /** @var list<array{account: Account, party: ?Party, items: list<EntryLine>}> $letterings */
        $letterings = [];
        if ($suggestion->settles($amount)) {
            foreach (self::byHolder($suggestion->openItems) as $holder) {
                $net = array_sum(array_map(fn (EntryLine $item) => $item->getNet(), $holder['items']));
                if (0 === $net) {
                    $letterings[] = $holder; // a debit and a credit of the same holder that cancel out among themselves
                    continue;
                }
                $draft->line($holder['account']->getNumber(), $net < 0 ? -$net : 0, $net > 0 ? $net : 0, $holder['party']);
                $letterings[] = $holder;
            }
        } elseif ([] !== $suggestion->openItems) {
            // Part of what is owed: on the first item's account, lettered when the rest arrives.
            $first = $suggestion->openItems[0];
            $draft->line($first->getAccount()->getNumber(), $in ? 0 : $abs, $in ? $abs : 0, $first->getParty());
        } elseif (null !== $suggestion->rule) {
            foreach ($this->parts($suggestion->rule, $abs) as [$number, $part, $label, $code]) {
                $party = Account::normalize($number) === $suggestion->rule->getAccount()->getNumber() ? ($suggestion->rule->getParty() ?? $suggestion->party) : null;
                if (null === $code) {
                    $draft->line($number, $in ? 0 : $part, $in ? $part : 0, $party, $label);
                    continue;
                }
                // A part with a tax code is an amount all taxes included: its base and its VAT.
                $tax = $this->taxes?->findOneByCode($line->getBook(), $code)
                    ?? throw new ReconciliationException(sprintf('%s: rule "%s" names tax %s, which %s does not have.', $line, $suggestion->rule->getName(), $code, $line->getBook()->getName()));
                $draft->taxed($number, $in ? 0 : $part, $in ? $part : 0, $tax, true, $party, $label);
            }
        } elseif (null !== $suggestion->party) {
            // A payment on account: the party's account, lettered later.
            $draft->line($suggestion->party->getAccountNumber(), $in ? 0 : $abs, $in ? $abs : 0, $suggestion->party);
        } else {
            throw new ReconciliationException(sprintf('%s: nothing to post it against - pick open items, a rule or a party.', $line));
        }

        $entry = $this->posting->post($draft);

        foreach ($letterings as $holder) {
            $lines = array_values(array_filter($holder['items'], fn (EntryLine $item) => !$item->isLettered()));
            foreach ($entry->getLines() as $mine) {
                if (!$mine->isLettered() && $mine->getAccount()->getNumber() === $holder['account']->getNumber() && $mine->getParty() === $holder['party']) {
                    $lines[] = $mine;
                    break;
                }
            }
            if (\count($lines) >= 2) {
                $this->lettering->letter($lines);
            }
        }

        $line->setEntry($entry)->setStatus(BankLineStatus::RECONCILED)->countReconciliation();
        $this->entityManager->flush();
        $this->dispatcher?->dispatch(new BankLineReconciled($line, $entry, $suggestion->settles($amount) ? $suggestion->openItems : []));

        return $entry;
    }

    /**
     * Matches the account's pending lines: a line with exactly one suggestion
     * at or above the threshold is applied; the others are left SUGGESTED
     * (or UNMATCHED, nothing found) for someone to decide. Returns how many
     * were applied.
     */
    public function autoReconcile(BankAccount $account): int
    {
        $items = $this->matcher->openItems($account->getBook());
        $applied = 0;

        foreach ($this->bankLines->pending($account) as $line) {
            $suggestions = $this->matcher->suggest($line, $items);
            $sure = array_values(array_filter($suggestions, fn (Suggestion $s) => $s->score >= $this->threshold));
            if (1 === \count($sure)) {
                try {
                    $this->apply($line, $sure[0]);
                    ++$applied;
                    $items = array_values(array_filter($items, fn (EntryLine $item) => !$item->isLettered()));
                    continue;
                } catch (LedgerException) {
                    // A locked period, an account missing: left for someone to look at.
                }
            }
            $line->setStatus([] === $suggestions ? BankLineStatus::UNMATCHED : BankLineStatus::SUGGESTED);
        }
        $this->entityManager->flush();

        return $applied;
    }

    /** Leaves a line out of the books (an internal transfer posted otherwise, a test). */
    public function ignore(BankLine $line): void
    {
        if (BankLineStatus::RECONCILED === $line->getStatus()) {
            throw new ReconciliationException(sprintf('%s is reconciled: undo it first.', $line));
        }
        $line->setStatus(BankLineStatus::IGNORED);
        $this->entityManager->flush();
    }

    /**
     * Back to unmatched: an ignored line simply, a reconciled one by
     * reversing its entry (dated as it was) and unlettering what it settled
     * (BankLineUnreconciled, for what followed the reconciliation) - refused
     * when that date is in the locked period.
     */
    public function undo(BankLine $line): void
    {
        if (BankLineStatus::IGNORED === $line->getStatus() || BankLineStatus::SUGGESTED === $line->getStatus()) {
            $line->setStatus(BankLineStatus::UNMATCHED);
            $this->entityManager->flush();

            return;
        }
        $entry = $line->getEntry();
        if (BankLineStatus::RECONCILED !== $line->getStatus() || null === $entry) {
            throw new ReconciliationException(sprintf('%s is not reconciled.', $line));
        }
        $book = $entry->getBook();
        if ($book->isLocked($entry->getDate())) {
            throw new ReconciliationException(sprintf('%s was posted on %s, in the locked period (until %s): it stays.', $line, $entry->getDate()->format('d/m/Y'), $book->getLockedUntil()->format('d/m/Y')));
        }
        $this->posting->assertOpen($book, $entry->getDate());

        $letters = [];
        foreach ($entry->getLines() as $mine) {
            if (null !== $mine->getLetter()) {
                $letters[$mine->getLetter()] = true;
            }
        }
        foreach (array_keys($letters) as $letter) {
            $this->lettering->unletter((string) $letter, $book);
        }

        $reversal = $this->posting->reverse($entry, $entry->getSource().':undo', $entry->getDate());

        // The entry and its reversal cancel out on the lettered accounts: lettered together, they are not open items.
        $used = [];
        foreach ($entry->getLines() as $mine) {
            if (!$mine->getAccount()->isUnder('4') && !$mine->getAccount()->isUnder('511')) {
                continue;
            }
            foreach ($reversal->getLines() as $mirror) {
                if (!isset($used[spl_object_id($mirror)]) && !$mirror->isLettered() && $mirror->getAccount() === $mine->getAccount() && $mirror->getParty() === $mine->getParty()
                    && $mirror->getDebit() === $mine->getCredit() && $mirror->getCredit() === $mine->getDebit()) {
                    $used[spl_object_id($mirror)] = true;
                    if (!$mine->isLettered()) {
                        $this->lettering->letter([$mine, $mirror]);
                    }
                    break;
                }
            }
        }

        $line->setEntry(null)->setStatus(BankLineStatus::UNMATCHED);
        $this->entityManager->flush();

        $this->dispatcher?->dispatch(new BankLineUnreconciled($line, $entry, $reversal));
    }

    /**
     * A rule's parts of $amount: [account number, minor units, label] -
     * fixed amounts first, then percentages, the rest to the part saying
     * "rest" or to the rule's own account.
     *
     * @return list<array{0: string, 1: int, 2: ?string}>
     */
    public function split(ReconciliationRule $rule, int $amount): array
    {
        return array_map(fn (array $part) => [$part[0], $part[1], $part[2]], $this->parts($rule, $amount));
    }

    /**
     * split()'s parts with the tax code each may carry ("tax": "A20"): the
     * part is then an amount all taxes included, posted as its base and its
     * VAT.
     *
     * @return list<array{0: string, 1: int, 2: ?string, 3: ?string}>
     */
    public function parts(ReconciliationRule $rule, int $amount): array
    {
        $split = $rule->getSplit();
        if (null === $split || [] === $split) {
            return [[$rule->getAccount()->getNumber(), $amount, null, null]];
        }

        $parts = [];
        $left = $amount;
        $restIndex = null;
        foreach (array_values($split) as $i => $part) {
            $number = (string) ($part['account'] ?? $rule->getAccount()->getNumber());
            $label = isset($part['label']) ? (string) $part['label'] : null;
            $code = isset($part['tax']) && '' !== trim((string) $part['tax']) ? (string) $part['tax'] : null;
            if (!empty($part['rest'])) {
                $restIndex = \count($parts);
                $parts[] = [$number, 0, $label, $code];
                continue;
            }
            $value = isset($part['amount']) ? (int) $part['amount'] : (int) round($amount * ((float) ($part['percent'] ?? 0)) / 100);
            $value = max(0, min($value, $left));
            $left -= $value;
            $parts[] = [$number, $value, $label, $code];
        }
        if ($left > 0) {
            if (null !== $restIndex) {
                $parts[$restIndex][1] += $left;
            } else {
                $parts[] = [$rule->getAccount()->getNumber(), $left, null, null];
            }
        }

        return array_values(array_filter($parts, fn (array $part) => $part[1] > 0));
    }

    /** @return list<array{account: Account, party: ?Party, items: list<EntryLine>}> */
    private static function byHolder(array $items): array
    {
        $holders = [];
        foreach ($items as $item) {
            $key = $item->getAccount()->getNumber().'|'.(null === $item->getParty() ? '' : spl_object_id($item->getParty()));
            $holders[$key] ??= ['account' => $item->getAccount(), 'party' => $item->getParty(), 'items' => []];
            $holders[$key]['items'][] = $item;
        }

        return array_values($holders);
    }
}
