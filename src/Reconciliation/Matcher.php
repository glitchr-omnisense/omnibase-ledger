<?php

namespace Base\Ledger\Reconciliation;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Model\Text;
use Base\Ledger\Repository\EntryLineRepository;
use Base\Ledger\Repository\PartyRepository;
use Base\Ledger\Repository\ReconciliationRuleRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What a bank line may settle, ranked. In order of trust:
 *
 * 1. a reference of an open item found in the line's label or reference
 *    (the piece of a rent notice, "AVIS-2026-10-U3"; a Stripe payout's id)
 *    - 0.99 when the amounts agree;
 * 2. the counterparty's IBAN, a party's, with an open item of exactly the
 *    amount (0.95), several adding up to it (0.92-0.94), or none (0.5: a
 *    payment on account);
 * 3. a party's name in the label, with an open item of exactly the amount
 *    (0.9), several adding up to it (0.85-0.89);
 * 4. nobody recognised, but open items of one party adding up to the amount
 *    (0.7), or one open item of exactly the amount (0.75);
 * 5. a reconciliation rule (0.9), with the open items of its account when
 *    they add up (0.92: a Stripe payout against the 5112 clearing account).
 *
 * Only the open items a line can have paid are looked at: none dated more
 * than max_advance_days (45) after the line - an old transfer of the same
 * amount never settles a notice issued a year later.
 *
 * The same amount owed by two parties, nothing else to tell them apart,
 * scores 0.5 each; two parties recognised by the same IBAN or name, 0.6.
 * Under the auto threshold (0.9), someone decides.
 */
class Matcher
{
    public const REFERENCE = 0.99;
    public const REFERENCE_PARTIAL = 0.6;
    public const IBAN_EXACT = 0.95;
    public const IBAN_ALL = 0.94;
    public const IBAN_OLDEST = 0.93;
    public const IBAN_GROUP = 0.92;
    public const IBAN_ONLY = 0.5;
    public const NAME_EXACT = 0.9;
    public const NAME_ALL = 0.89;
    public const NAME_OLDEST = 0.88;
    public const NAME_GROUP = 0.85;
    public const AMOUNT_ONLY = 0.75;
    public const GROUP_ONLY = 0.7;
    public const RULE_ITEMS = 0.92;
    public const RULE = 0.9;
    public const AMBIGUOUS_PARTY = 0.6;
    public const AMBIGUOUS = 0.5;

    /** How far the subset search goes before giving up. */
    private const SUBSET_BUDGET = 20000;

    public function __construct(
        private readonly EntryLineRepository $lines,
        private readonly PartyRepository $parties,
        private readonly ReconciliationRuleRepository $rules,
        #[Autowire('%ledger.reconciliation.max_group_items%')] private readonly int $maxGroupItems = 12,
        #[Autowire('%ledger.reconciliation.max_advance_days%')] private readonly int $maxAdvanceDays = 45,
    ) {
    }

    /**
     * What a bank line can settle in $book: the open lines of the third-party
     * accounts (class 4) and of the clearing accounts (511).
     *
     * @return list<EntryLine>
     */
    public function openItems(Book $book): array
    {
        return array_merge($this->lines->openItems($book, null, '4'), $this->lines->openItems($book, null, '511'));
    }

    /**
     * @param list<EntryLine>|null $openItems the book's open items, when the caller has them (autoReconcile)
     *
     * @return list<Suggestion> best first
     */
    public function suggest(BankLine $line, ?array $openItems = null): array
    {
        $amount = $line->getAmount();
        if (0 === $amount) {
            return [];
        }
        $book = $line->getBook();
        $latest = $line->getBookedOn()->modify(sprintf('+%d days', $this->maxAdvanceDays));
        $items = array_values(array_filter($openItems ?? $this->openItems($book), fn (EntryLine $item) => !$item->isLettered() && $item->getEntry()->getDate() <= $latest));
        $text = Text::fold($line->getLabel().' '.$line->getReference().' '.$line->getCounterpartyName());
        $squashed = str_replace(' ', '', $text);

        $suggestions = [];
        $add = function (Suggestion $suggestion) use (&$suggestions): void {
            $key = $suggestion->key();
            if (!isset($suggestions[$key]) || $suggestions[$key]->score < $suggestion->score) {
                $suggestions[$key] = $suggestion;
            }
        };

        // Holders: the items of one account and one party, what is lettered together.
        $holders = [];
        foreach ($items as $item) {
            $holders[self::holderKey($item->getAccount(), $item->getParty())][] = $item;
        }

        // 1. A reference.
        foreach ($this->byReference($items, $squashed) as $piece => $group) {
            $settling = $this->settling($group, $amount);
            if (null !== $settling) {
                $add(new Suggestion(self::REFERENCE, sprintf('Référence %s', $piece), $settling, null, $settling[0]->getParty()));
                continue;
            }
            $partial = $this->sameSign($group, $amount);
            if ([] !== $partial) {
                $add(new Suggestion(self::REFERENCE_PARTIAL, sprintf('Référence %s, montant différent', $piece), [], null, $partial[0]->getParty() ?? null));
            }
        }

        // 2. The counterparty's IBAN.
        $recognised = [];
        $byIban = [];
        foreach ($this->partiesByIban($book, $line->getCounterpartyIban(), $items) as $party) {
            $recognised[spl_object_id($party)] = true;
            $found = $this->forParty($party, $holders, $amount, [self::IBAN_EXACT, self::IBAN_OLDEST, self::IBAN_ALL, self::IBAN_GROUP], 'IBAN de %s');
            $byIban[] = $found ?? new Suggestion(self::IBAN_ONLY, sprintf('IBAN de %s, sans pièce du même montant', $party->getName()), [], null, $party);
        }
        foreach ($this->disambiguate($byIban) as $suggestion) {
            $add($suggestion);
        }

        // 3. A party's name in the label.
        $byName = [];
        foreach ($this->partiesOf($items) as $party) {
            if (isset($recognised[spl_object_id($party)]) || !self::names($party, $text)) {
                continue;
            }
            $recognised[spl_object_id($party)] = true;
            $found = $this->forParty($party, $holders, $amount, [self::NAME_EXACT, self::NAME_OLDEST, self::NAME_ALL, self::NAME_GROUP], '%s dans le libellé');
            if (null !== $found) {
                $byName[] = $found;
            }
        }
        foreach ($this->disambiguate($byName) as $suggestion) {
            $add($suggestion);
        }

        // 4. Nobody recognised: an amount, or a group of one party's items adding up to it.
        $exact = [];
        $groups = [];
        foreach ($holders as $holder) {
            $party = $holder[0]->getParty();
            if (null === $party || isset($recognised[spl_object_id($party)])) {
                continue;
            }
            foreach ($this->sameSign($holder, $amount) as $item) {
                if ($item->getNet() === $amount) {
                    $exact[] = $item;
                }
            }
            $subset = $this->subset($this->sameSign($holder, $amount), $amount);
            if (null !== $subset) {
                $groups[] = new Suggestion(self::GROUP_ONLY, sprintf('%d pièces de %s pour ce montant', \count($subset), $party->getName()), $subset, null, $party);
            }
        }
        foreach ($exact as $item) {
            $add(new Suggestion(1 === \count($exact) ? self::AMOUNT_ONLY : self::AMBIGUOUS, 1 === \count($exact) ? 'Même montant' : sprintf('Même montant, %d pièces possibles', \count($exact)), [$item], null, $item->getParty()));
        }
        foreach ($groups as $suggestion) {
            $add(1 === \count($groups) ? $suggestion : $suggestion->withScore(self::AMBIGUOUS));
        }

        // 5. A rule: the first matching one, by priority.
        $first = true;
        foreach ($this->rules->forBook($book) as $rule) {
            if (!$rule->matches($line->getLabel().' '.$line->getCounterpartyName().' '.$line->getReference(), $line->getCounterpartyIban(), $amount)) {
                continue;
            }
            $add($this->forRule($rule, $holders, $amount, $first));
            $first = false;
        }

        $suggestions = array_values($suggestions);
        usort($suggestions, fn (Suggestion $a, Suggestion $b) => $b->score <=> $a->score);

        return $suggestions;
    }

    /** @return array<string, list<EntryLine>> the open items by the piece found in the text, longest pieces only */
    private function byReference(array $items, string $squashed): array
    {
        $byPiece = [];
        foreach ($items as $item) {
            $piece = Text::squash($item->getEntry()->getPiece());
            if (\strlen($piece) >= 4 && str_contains($squashed, $piece)) {
                $byPiece[$piece][] = $item;
            }
        }
        foreach (array_keys($byPiece) as $shorter) {
            foreach (array_keys($byPiece) as $longer) {
                if ($shorter !== $longer && str_contains($longer, $shorter)) {
                    unset($byPiece[$shorter]);
                    break;
                }
            }
        }

        return $byPiece;
    }

    /**
     * The lines of a reference's group the amount settles: one holder's,
     * whose lines add up to it (a notice on 411; the payments and the fee of
     * a payout on 5112), else the whole group.
     *
     * @param list<EntryLine> $group
     *
     * @return list<EntryLine>|null
     */
    private function settling(array $group, int $amount): ?array
    {
        $byHolder = [];
        foreach ($group as $item) {
            $byHolder[self::holderKey($item->getAccount(), $item->getParty())][] = $item;
        }
        foreach ($byHolder as $lines) {
            if (self::total($lines) === $amount) {
                return $lines;
            }
            foreach ($lines as $line) {
                if ($line->getNet() === $amount) {
                    return [$line];
                }
            }
        }

        return 1 === \count($byHolder) || self::total($group) !== $amount ? null : $group;
    }

    /**
     * One recognised party's best match: an item of exactly the amount (the
     * oldest when several), all its items, or some of them.
     *
     * @param array<string, list<EntryLine>> $holders
     * @param array{0: float, 1: float, 2: float, 3: float} $scores exact, oldest, all, group
     */
    private function forParty(Party $party, array $holders, int $amount, array $scores, string $reason): ?Suggestion
    {
        $name = $party->getName();
        foreach ($holders as $holder) {
            if ($holder[0]->getParty() !== $party) {
                continue;
            }
            $candidates = $this->sameSign($holder, $amount);
            $exact = array_values(array_filter($candidates, fn (EntryLine $item) => $item->getNet() === $amount));
            if (1 === \count($exact)) {
                return new Suggestion($scores[0], sprintf($reason, $name).', montant exact', $exact, null, $party);
            }
            if (\count($exact) > 1) {
                return new Suggestion($scores[1], sprintf($reason, $name).sprintf(', la plus ancienne de %d pièces du même montant', \count($exact)), [$exact[0]], null, $party);
            }
            if (\count($holder) > 1 && self::total($holder) === $amount) {
                return new Suggestion($scores[2], sprintf($reason, $name).sprintf(', solde de %d pièces', \count($holder)), $holder, null, $party);
            }
            $subset = $this->subset($candidates, $amount);
            if (null !== $subset) {
                return new Suggestion($scores[3], sprintf($reason, $name).sprintf(', %d pièces pour ce montant', \count($subset)), $subset, null, $party);
            }
        }

        return null;
    }

    /** @param array<string, list<EntryLine>> $holders */
    private function forRule(ReconciliationRule $rule, array $holders, int $amount, bool $first): Suggestion
    {
        $reason = sprintf('Règle « %s »', $rule->getName());
        if (!$first) {
            return new Suggestion(self::AMBIGUOUS, $reason.' (une règle prioritaire s\'applique aussi)', [], $rule, $rule->getParty());
        }

        // A rule posting to a lettered account (5112, 411...): its open items there, when they add up.
        $holder = $holders[self::holderKey($rule->getAccount(), $rule->getParty())] ?? [];
        if (null === $rule->getSplit() && [] !== $holder) {
            if (self::total($holder) === $amount) {
                return new Suggestion(self::RULE_ITEMS, $reason.sprintf(', solde de %d pièces', \count($holder)), $holder, $rule, $rule->getParty());
            }
            $subset = $this->subset($this->sameSign($holder, $amount), $amount);
            if (null !== $subset) {
                return new Suggestion(self::RULE_ITEMS, $reason.sprintf(', %d pièces pour ce montant', \count($subset)), $subset, $rule, $rule->getParty());
            }
        }

        return new Suggestion(self::RULE, $reason, [], $rule, $rule->getParty());
    }

    /**
     * Two parties recognised the same way, both with something to settle:
     * nothing says which, so neither is sure.
     *
     * @param list<Suggestion> $suggestions
     *
     * @return list<Suggestion>
     */
    private function disambiguate(array $suggestions): array
    {
        $withItems = array_filter($suggestions, fn (Suggestion $s) => [] !== $s->openItems);
        if (\count($withItems) < 2) {
            return $suggestions;
        }

        return array_map(fn (Suggestion $s) => $s->withScore(min($s->score, self::AMBIGUOUS_PARTY), $s->reason.' (plusieurs tiers possibles)'), $suggestions);
    }

    /** @return list<Party> the parties paying from this IBAN: the book's, and those of the open items */
    private function partiesByIban(Book $book, ?string $iban, array $items): array
    {
        $iban = Party::normalizeIban($iban);
        if (null === $iban) {
            return [];
        }
        $parties = [];
        foreach ($this->parties->findByIban($book, $iban) as $party) {
            $parties[spl_object_id($party)] = $party;
        }
        foreach ($this->partiesOf($items) as $party) {
            if ($party->getIban() === $iban) {
                $parties[spl_object_id($party)] = $party;
            }
        }

        return array_values($parties);
    }

    /** @return list<Party> */
    private function partiesOf(array $items): array
    {
        $parties = [];
        foreach ($items as $item) {
            if (null !== ($party = $item->getParty())) {
                $parties[spl_object_id($party)] = $party;
            }
        }

        return array_values($parties);
    }

    /** Whether the folded text names the party: each word of its name (3 letters or more) there, one of 4 or more at least. */
    private static function names(Party $party, string $text): bool
    {
        $words = array_filter(explode(' ', Text::fold($party->getName())), fn (string $word) => \strlen($word) >= 3);
        if ([] === $words || max(array_map('strlen', $words)) < 4) {
            return false;
        }
        $text = ' '.$text.' ';
        foreach ($words as $word) {
            if (!str_contains($text, ' '.$word.' ') && !str_contains(str_replace(' ', '', $text), $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Two or more items, oldest first among the first max_group_items, adding
     * up to the amount exactly - or null.
     *
     * @param list<EntryLine> $items
     *
     * @return list<EntryLine>|null
     */
    private function subset(array $items, int $amount): ?array
    {
        $items = \array_slice($items, 0, $this->maxGroupItems);
        if (\count($items) < 2) {
            return null;
        }
        $values = array_map(fn (EntryLine $item) => abs($item->getNet()), $items);
        $target = abs($amount);
        $budget = self::SUBSET_BUDGET;
        $count = \count($values);

        // Suffix sums: a branch that cannot reach the target any more is cut.
        $rest = array_fill(0, $count + 1, 0);
        for ($i = $count - 1; $i >= 0; --$i) {
            $rest[$i] = $rest[$i + 1] + $values[$i];
        }

        $search = function (int $from, int $sum, array $picked) use (&$search, &$budget, $values, $target, $count, $rest): ?array {
            if ($sum === $target && \count($picked) >= 2) {
                return $picked;
            }
            if ($sum >= $target || --$budget <= 0 || $sum + $rest[$from] < $target) {
                return null;
            }
            for ($i = $from; $i < $count; ++$i) {
                if ($sum + $values[$i] <= $target && null !== ($found = $search($i + 1, $sum + $values[$i], [...$picked, $i]))) {
                    return $found;
                }
            }

            return null;
        };

        $picked = $search(0, 0, []);

        return null === $picked ? null : array_map(fn (int $i) => $items[$i], $picked);
    }

    /** @return list<EntryLine> the items on the amount's side: owed to the book for money coming in, owed by it for money going out */
    private function sameSign(array $items, int $amount): array
    {
        return array_values(array_filter($items, fn (EntryLine $item) => $amount > 0 ? $item->getNet() > 0 : $item->getNet() < 0));
    }

    private static function total(array $items): int
    {
        return array_sum(array_map(fn (EntryLine $item) => $item->getNet(), $items));
    }

    private static function holderKey(Account $account, ?Party $party): string
    {
        return $account->getNumber().'|'.(null === $party ? '' : spl_object_id($party));
    }
}
