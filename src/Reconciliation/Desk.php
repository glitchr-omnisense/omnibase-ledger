<?php

namespace Base\Ledger\Reconciliation;

use Base\Ledger\Bank\Aggregates;
use Base\Ledger\Bank\Label;
use Base\Ledger\Bank\StatementUpload;
use Base\Ledger\Bank\Sync;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\BankLineStatus;
use Base\Ledger\Enum\RuleSign;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Exception\ReconciliationException;
use Base\Ledger\Model\Text;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankLineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The day-to-day reconciliation, whoever does it - the back office's page,
 * the front office's bank screen: what a line may be (the Matcher's
 * suggestions), and what someone says it is - a suggestion accepted, open
 * items, a rule or a party picked, a category, a split -, a line ignored or
 * put back, a rule made from a line and applied to the others, the sure
 * suggestions applied, the bank synced. Everything goes through the
 * Reconciler (so BankLineReconciled and BankLineUnreconciled are told, and
 * what listens to them follows), and every batch says what it did, so that
 * it can be undone.
 */
class Desk
{
    /** @var array<int, list<EntryLine>> a book's open items, once per request */
    private array $openItems = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Matcher $matcher,
        private readonly Reconciler $reconciler,
        private readonly BankLineRepository $bankLines,
        private readonly AccountRepository $accounts,
        private readonly BankAccountRepository $bankAccounts,
        private readonly ?Sync $sync = null,
        private readonly ?Aggregates $aggregates = null,
    ) {
    }

    public function threshold(): float
    {
        return $this->reconciler->getThreshold();
    }

    /** @return list<EntryLine> what the book's lines may settle (cached for the request) */
    public function openItems(Book $book): array
    {
        return $this->openItems[spl_object_id($book)] ??= $this->matcher->openItems($book);
    }

    /** @return list<Suggestion> best first, at most $limit */
    public function suggestions(BankLine $line, int $limit = 5): array
    {
        if (!$line->getStatus()->isPending()) {
            return [];
        }

        return \array_slice($this->matcher->suggest($line, $this->openItems($line->getBook())), 0, $limit);
    }

    /** @return list<EntryLine> the open items on the line's side (owed to the book for money in, by it for money out) */
    public function candidates(BankLine $line): array
    {
        $in = $line->getAmount() > 0;

        return array_values(array_filter($this->openItems($line->getBook()), fn (EntryLine $item) => !$item->isLettered() && ($in ? $item->getNet() > 0 : $item->getNet() < 0)));
    }

    /** « Monoprix » for « CB DEBIT DIFFERE MONOPRIX ». */
    public function label(BankLine $line): string
    {
        return Label::clean($line->getLabel(), $line->getCounterpartyName());
    }

    /**
     * What someone picked by hand, as a suggestion: open items (by id), a
     * rule, a party - each of the line's book, or refused.
     *
     * @param list<int|string> $items
     */
    public function manual(BankLine $line, array $items = [], ?int $rule = null, ?int $party = null): Suggestion
    {
        $book = $line->getBook();
        $picked = [];
        foreach ($items as $id) {
            $item = $this->entityManager->find(EntryLine::class, (int) $id);
            if (null !== $item && $item->getBook() === $book) {
                $picked[] = $item;
            }
        }
        $rule = null !== $rule && $rule > 0 ? $this->entityManager->find(ReconciliationRule::class, $rule) : null;
        $party = null !== $party && $party > 0 ? $this->entityManager->find(Party::class, $party) : null;
        if ((null !== $rule && $rule->getBook() !== $book) || (null !== $party && $party->getBook() !== $book)) {
            throw new AccessDeniedException('Not of this book.');
        }

        return new Suggestion(1.0, 'Choisi à la main', $picked, $rule, $party ?? $rule?->getParty());
    }

    /** @throws LedgerException */
    public function accept(BankLine $line, Suggestion $suggestion): Entry
    {
        return $this->reconciler->apply($line, $suggestion);
    }

    /**
     * The whole line on one account of the book (a category: 627 for bank
     * fees, 455 for an associate's expense), maybe a party's and with a tax
     * code (an amount all taxes included: its base and its VAT).
     *
     * @throws LedgerException
     */
    public function categorize(BankLine $line, string $account, ?Party $party = null, ?string $tax = null): Entry
    {
        $this->assertAccount($line->getBook(), $account);

        return $this->reconciler->apply($line, Suggestion::ofParts('Catégorie choisie', new Part(abs($line->getAmount()), $account, [], $party, self::code($tax))));
    }

    /**
     * The line spread over several accounts or open items: each part
     * ["amount" => minor units, "account" => number | "items" => [ids],
     * "party" => id, "tax" => code, "label" => text]. The parts add up to
     * the line's amount.
     *
     * @param list<array{amount: int|string, account?: ?string, items?: list<int|string>, party?: int|string|null, tax?: ?string, label?: ?string}> $parts
     *
     * @throws LedgerException|\InvalidArgumentException
     */
    public function split(BankLine $line, array $parts): Entry
    {
        $book = $line->getBook();
        $built = [];
        foreach ($parts as $part) {
            $amount = (int) ($part['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $party = !empty($part['party']) ? $this->entityManager->find(Party::class, (int) $part['party']) : null;
            if (null !== $party && $party->getBook() !== $book) {
                throw new AccessDeniedException('Not of this book.');
            }
            $items = [];
            foreach ($part['items'] ?? [] as $id) {
                $item = $this->entityManager->find(EntryLine::class, (int) $id);
                if (null !== $item && $item->getBook() === $book && !$item->isLettered()) {
                    $items[] = $item;
                }
            }
            $account = '' === trim((string) ($part['account'] ?? '')) ? null : (string) $part['account'];
            if (null !== $account && [] === $items) {
                $this->assertAccount($book, $account);
            }
            $built[] = new Part($amount, [] === $items ? $account : null, $items, $party, [] === $items ? self::code($part['tax'] ?? null) : null, ('' === trim((string) ($part['label'] ?? ''))) ? null : mb_substr((string) $part['label'], 0, 255));
        }
        if (\count($built) < 1) {
            throw new ReconciliationException(sprintf('%s: a split needs at least one part.', $line));
        }

        return $this->reconciler->apply($line, Suggestion::ofParts('Ventilation', ...$built));
    }

    /** @throws LedgerException */
    public function ignore(BankLine $line): void
    {
        $this->reconciler->ignore($line);
    }

    /** @throws LedgerException */
    public function undo(BankLine $line): void
    {
        $this->reconciler->undo($line);
    }

    /**
     * A rule made from a line someone just placed on $account (« Toujours
     * classer “Monoprix” ainsi »): the line's cleaned label as its words, its
     * direction, the account, the party, the tax code - then applied to the
     * book's other lines still to place that it matches. Returns the rule and
     * the lines it placed, to undo them with undoRule().
     *
     * @return array{rule: ReconciliationRule, applied: list<BankLine>}
     *
     * @throws LedgerException
     */
    public function ruleFrom(BankLine $line, string $account, ?Party $party = null, ?string $tax = null, ?string $pattern = null, ?string $name = null): array
    {
        $book = $line->getBook();
        $target = $this->assertAccount($book, $account);
        $pattern = Text::fold($pattern ?? '') ?: Label::pattern($line->getLabel(), $line->getCounterpartyName());
        if ('' === $pattern) {
            throw new ReconciliationException(sprintf('%s: its label says nothing a rule could recognise.', $line));
        }
        $rule = new ReconciliationRule($book, $name ?? mb_substr($this->label($line) ?: $pattern, 0, 255), $pattern, $target, $line->getAmount() < 0 ? RuleSign::OUT : RuleSign::IN);
        $rule->setParty($party);
        if (null !== ($code = self::code($tax))) {
            $rule->setSplit([['account' => $target->getNumber(), 'rest' => true, 'tax' => $code]]);
        }
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return ['rule' => $rule, 'applied' => $this->applyRule($rule)];
    }

    /**
     * Applies $rule to the book's lines still to place that it matches, each
     * through the Reconciler; a line that cannot be posted (a locked period)
     * is left as it is.
     *
     * @return list<BankLine> the lines it placed
     */
    public function applyRule(ReconciliationRule $rule): array
    {
        $applied = [];
        foreach ($this->pending($rule->getBook()) as $line) {
            if (!$rule->matches($line->getLabel().' '.$line->getCounterpartyName().' '.$line->getReference(), $line->getCounterpartyIban(), $line->getAmount())) {
                continue;
            }
            try {
                $this->reconciler->apply($line, new Suggestion(1.0, sprintf('Règle « %s »', $rule->getName()), [], $rule, $rule->getParty()));
                $applied[] = $line;
            } catch (LedgerException) {
            }
        }

        return $applied;
    }

    /**
     * Undoes what a rule placed (the lines given, those still reconciled
     * by it) and, when asked, removes the rule.
     *
     * @param list<int> $lines
     *
     * @return int how many lines were put back
     */
    public function undoRule(ReconciliationRule $rule, array $lines, bool $remove = true): int
    {
        $undone = $this->undoLines($rule->getBook(), $lines);
        if ($remove) {
            $this->entityManager->remove($rule);
            $this->entityManager->flush();
        }

        return $undone;
    }

    /**
     * The sure suggestions of $accounts applied (Reconciler::autoReconcile()).
     *
     * @param list<BankAccount> $accounts
     *
     * @return list<BankLine> the lines it placed
     */
    public function autoReconcile(array $accounts): array
    {
        $applied = [];
        foreach ($accounts as $account) {
            $before = array_map(fn (BankLine $line) => $line->getId(), $this->bankLines->pending($account));
            $this->reconciler->autoReconcile($account);
            foreach ($before as $id) {
                $line = $this->entityManager->find(BankLine::class, $id);
                if (null !== $line && BankLineStatus::RECONCILED === $line->getStatus()) {
                    $applied[] = $line;
                }
            }
        }

        return $applied;
    }

    /**
     * Puts back the lines given (reconciled or ignored), those of $book only.
     *
     * @param list<int> $ids
     *
     * @return int how many
     */
    public function undoLines(Book $book, array $ids): int
    {
        $undone = 0;
        foreach (array_reverse($ids) as $id) {
            $line = $this->entityManager->find(BankLine::class, (int) $id);
            if (null === $line || $line->getBook() !== $book || $line->getStatus()->isPending()) {
                continue;
            }
            try {
                $this->reconciler->undo($line);
                ++$undone;
            } catch (LedgerException) {
            }
        }

        return $undone;
    }

    /**
     * What ledger:bank:sync does, for $accounts: their new lines brought in
     * through their connection (the accounts fed by statement files left
     * out: they are uploaded). Returns the lines added, when each account had
     * last synced (to undo it) and what failed.
     *
     * @param list<BankAccount> $accounts
     *
     * @return array{added: list<int>, synced: array<int, ?string>, failed: array<string, string>, skipped: list<string>}
     */
    public function sync(array $accounts): array
    {
        $report = ['added' => [], 'synced' => [], 'failed' => [], 'skipped' => []];
        foreach ($accounts as $account) {
            if (null === $this->sync || StatementUpload::GATEWAY === $account->getConnection()->getGateway()) {
                $report['skipped'][] = $account->getName();
                continue;
            }
            $before = $this->bankLines->ids($account);
            $report['synced'][$account->getId()] = $account->getLastSyncedAt()?->format(\DATE_ATOM);
            try {
                $this->sync->sync($account);
            } catch (LedgerException $e) {
                $report['failed'][$account->getName()] = $e->getMessage();
                continue;
            }
            array_push($report['added'], ...array_values(array_diff($this->bankLines->ids($account), $before)));
        }

        return $report;
    }

    /**
     * Undoes a sync: the lines it added that nobody placed since are taken
     * out, each account's last sync put back to what it was (so that the
     * next sync asks for them again).
     *
     * @param list<int>          $added
     * @param array<int, ?string> $synced bank account id => its last sync before (ATOM), as sync() said
     *
     * @return int how many lines were taken out
     */
    public function undoSync(Book $book, array $added, array $synced): int
    {
        $removed = 0;
        $touched = [];
        foreach ($added as $id) {
            $line = $this->entityManager->find(BankLine::class, (int) $id);
            if (null === $line || $line->getBook() !== $book || !$line->getStatus()->isPending()) {
                continue;
            }
            $touched[$line->getBankAccount()->getId()] = $line->getBankAccount();
            $this->entityManager->remove($line);
            ++$removed;
        }
        foreach ($synced as $id => $at) {
            $account = $this->entityManager->find(BankAccount::class, (int) $id);
            if (null !== $account && $account->getBook() === $book) {
                $account->setLastSyncedAt(null === $at || '' === $at ? null : new \DateTimeImmutable($at));
                $touched[$account->getId()] = $account;
            }
        }
        $this->entityManager->flush();
        foreach ($touched as $account) {
            $this->aggregates?->rebuild($account);
        }

        return $removed;
    }

    /** @return list<BankLine> the book's lines still to place, oldest first */
    private function pending(Book $book): array
    {
        $lines = [];
        foreach ($this->bankAccounts->forBook($book) as $account) {
            array_push($lines, ...$this->bankLines->pending($account));
        }
        usort($lines, fn (BankLine $a, BankLine $b) => [$a->getBookedOn(), $a->getId()] <=> [$b->getBookedOn(), $b->getId()]);

        return $lines;
    }

    private function assertAccount(Book $book, string $number): \Base\Ledger\Entity\Account
    {
        $account = $this->accounts->findOneByNumber($book, $number);
        if (null === $account || !Categories::offered($account)) {
            throw new ReconciliationException(sprintf('%s has no account %s to place a bank line on.', $book->getName(), $number));
        }

        return $account;
    }

    private static function code(mixed $tax): ?string
    {
        $tax = trim((string) $tax);

        return '' === $tax ? null : Tax::normalize($tax);
    }
}
