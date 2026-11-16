<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\EntryLineDimension;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Entity\Party;
use Base\Database\Repository\Finder\Aggregate;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<EntryLine> */
class EntryLineRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, EntryLine::class);
    }

    /**
     * The unlettered lines - what is still owed, either way - of the
     * accounts under $accountPrefix (all when null), maybe one party's,
     * oldest first.
     *
     * @return list<EntryLine>
     */
    public function openItems(Book $book, ?Party $party = null, ?string $accountPrefix = null): array
    {
        $criteria = $this->lines($book) + [Group::of(Parser::SEPARATOR_AND, Criterion::of('letter', null, Parser::OPTION_NULL))];
        if (null !== $party) {
            $criteria['party'] = Key::of($party);
        }
        if (null !== $accountPrefix && '' !== $accountPrefix) {
            $criteria[] = $this->under($accountPrefix);
        }

        return $this->findBy($criteria, ['entry.date' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * How often each account was the other side of a bank line since $since:
     * the lines of the entries the reconciliation posted (source "bank:..."),
     * their undoing left out - what the front office offers first.
     *
     * @return array<string, int> account number => lines, most used first
     */
    public function bankUsage(Book $book, \DateTimeImmutable $since): array
    {
        $usage = [];
        foreach ($this->findValuesBy(['number' => 'account.number', 'n' => Aggregate::count()], array_merge($this->lines($book), [
            'entry.reversalOf' => null,
            Group::of(Parser::SEPARATOR_AND,
                Criterion::of('entry.source', 'bank:', Parser::OPTION_STARTING_WITH),
                Criterion::of('entry.source', ':undo', Parser::OPTION_NOT_ENDING_WITH),
                Criterion::of('entry.date', $since->setTime(0, 0), Parser::OPTION_GREATER_EQUAL),
            ),
        ]), ['n' => 'DESC']) as $row) {
            $usage[(string) $row['number']] = (int) $row['n'];
        }

        return $usage;
    }

    /**
     * The accounts the entries of $party went to - its usual expense (or
     * income) account: the other lines of the entries that have a line for
     * it, under $prefixes, the most used first; reversals left out.
     *
     * @param list<string> $prefixes
     *
     * @return array<string, int> account number => lines
     */
    public function accountsWith(Book $book, Party $party, array $prefixes = ['6', '2'], int $limit = 5): array
    {
        // The entries with a line for the party, then their other lines.
        $entries = array_values(array_filter(array_column($this->findValuesBy(['entry' => 'entry'], ['party' => $party], distinct: true), 'entry')));
        if ([] === $entries) {
            return [];
        }
        $criteria = array_merge($this->lines($book), ['entry' => Key::of($entries), 'entry.reversalOf' => null]);
        if ([] !== $prefixes) {
            $criteria[] = Group::of(Parser::SEPARATOR_OR, ...array_map(static fn (string $prefix) => Criterion::of('account.number', Account::normalize($prefix), Parser::OPTION_STARTING_WITH), array_values($prefixes)));
        }

        $accounts = [];
        foreach ($this->findValuesBy(['number' => 'account.number', 'n' => Aggregate::count()], $criteria, ['n' => 'DESC'], $limit) as $row) {
            $accounts[(string) $row['number']] = (int) $row['n'];
        }

        return $accounts;
    }

    /** @return list<EntryLine> */
    public function findByLetter(Book $book, string $letter): array
    {
        return $this->findBy($this->lines($book) + ['letter' => $letter], ['id' => 'ASC']);
    }

    /** The last letter given in the book: letters run AAA, AAB... ZZZ, AAAA. */
    public function lastLetter(Book $book): ?string
    {
        // The longest, then the greatest: an order on an expression (LENGTH), which no word of the language says.
        $rows = $this->sql('SELECT l.{EntryLine.letter} AS letter FROM {EntryLine} l JOIN {Entry} e ON e.{Entry.id} = l.{EntryLine.entry}'
            .' WHERE e.{Entry.book} = ? AND l.{EntryLine.letter} IS NOT NULL ORDER BY LENGTH(l.{EntryLine.letter}) DESC, l.{EntryLine.letter} DESC LIMIT 1', [$book]);

        return \is_array($rows) && isset($rows[0]['letter']) ? (string) $rows[0]['letter'] : null;
    }

    /**
     * Debit and credit totals per account over [$from, $to] (either open),
     * of the lines carrying every dimension of $dimensions (axis => value),
     * and per value of the $groupBy axis when asked ("dimension": null for
     * the lines without it).
     *
     * @param array<string, string|int> $dimensions
     *
     * @return list<array{number: string, label: string, type: mixed, debit: int, credit: int, dimension?: ?string}>
     */
    public function totalsByAccount(Book $book, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, bool $validatedOnly = false, array $dimensions = [], ?string $groupBy = null): array
    {
        $criteria = array_merge($this->lines($book), $this->period($from, $to, $validatedOnly), $this->carrying($dimensions));
        $values = ['number' => 'account.number', 'label' => 'account.label', 'type' => 'account.type', 'debit' => Aggregate::sum('debit'), 'credit' => Aggregate::sum('credit')];
        if (null === $groupBy) {
            $rows = $this->findValuesBy($values, $criteria, ['number' => 'ASC']);
        } else {
            // Per value of the axis: the lines without it (null), then those with it, read from their dimension.
            $axis = EntryLineDimension::axis($groupBy);
            $rows = array_map(static fn (array $row) => $row + ['dimension' => null], $this->findValuesBy($values, array_merge($criteria, [Criterion::notExists(EntryLineDimension::class, 'line', ['axis' => $axis])]), ['number' => 'ASC']));
            $lines = array_column($this->findValuesBy(['id' => 'id'], $criteria), 'id');
            if ([] !== $lines) {
                $rows = [...$rows, ...$this->dimensions()->findValuesBy(
                    ['number' => 'line.account.number', 'label' => 'line.account.label', 'type' => 'line.account.type', 'debit' => Aggregate::sum('line.debit'), 'credit' => Aggregate::sum('line.credit'), 'dimension' => 'value'],
                    ['axis' => $axis, 'line' => Key::of($lines)],
                    ['number' => 'ASC'],
                )];
            }
            usort($rows, static fn (array $a, array $b) => strcmp((string) $a['number'], (string) $b['number']));
        }

        return array_map(static function (array $row) use ($groupBy): array {
            $total = [
                'debit' => (int) $row['debit'], 'credit' => (int) $row['credit'],
                'number' => (string) $row['number'], 'label' => (string) $row['label'],
                'type' => $row['type'] instanceof AccountType ? $row['type'] : AccountType::from((string) $row['type']),
            ];

            return null === $groupBy ? $total : $total + ['dimension' => null === $row['dimension'] ? null : (string) $row['dimension']];
        }, $rows);
    }

    /**
     * The VAT return's figures over [$from, $to]: per tax and account, the
     * lines' debit and credit and their tax bases on each side.
     *
     * @return list<array{code: string, label: string, rate: int, kind: mixed, account: string, tax_account: ?string, deductible_account: ?string, debit: int, credit: int, base_debit: int, base_credit: int}>
     */
    public function taxTotals(Book $book, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $criteria = array_merge($this->lines($book), $this->period($from, $to, false), [Group::of(Parser::SEPARATOR_AND, Criterion::of('tax', Key::of(null), Parser::OPTION_NOT_EQUAL))]);
        $keys = ['code' => 'tax.code', 'label' => 'tax.label', 'rate' => 'tax.rate', 'kind' => 'tax.kind', 'account' => 'account.number', 'tax_account' => 'tax.account.number', 'deductible_account' => 'tax.deductibleAccount.number'];
        $order = ['code' => 'ASC', 'account' => 'ASC'];
        $key = static fn (array $row): string => implode("\0", array_map(static fn (string $name) => (string) $row[$name], array_keys($keys)));
        // The tax bases of the debit lines and of the credit lines: the same groups, read apart.
        $bases = [];
        foreach (['base_debit' => 'debit', 'base_credit' => 'credit'] as $name => $side) {
            foreach ($this->findValuesBy($keys + ['base' => Aggregate::sum('taxBase')], array_merge($criteria, [Group::of(Parser::SEPARATOR_AND, Criterion::of($side, 0, Parser::OPTION_GREATER))]), $order) as $row) {
                $bases[$key($row)][$name] = (int) $row['base'];
            }
        }

        return array_map(static fn (array $row): array => [
            'code' => (string) $row['code'], 'label' => (string) $row['label'], 'rate' => (int) $row['rate'],
            'kind' => $row['kind'] instanceof TaxKind ? $row['kind'] : TaxKind::from((string) $row['kind']),
            'account' => (string) $row['account'],
            'tax_account' => null === $row['tax_account'] ? null : (string) $row['tax_account'],
            'deductible_account' => null === $row['deductible_account'] ? null : (string) $row['deductible_account'],
            'debit' => (int) $row['debit'], 'credit' => (int) $row['credit'],
            'base_debit' => $bases[$key($row)]['base_debit'] ?? 0, 'base_credit' => $bases[$key($row)]['base_credit'] ?? 0,
        ], $this->findValuesBy($keys + ['debit' => Aggregate::sum('debit'), 'credit' => Aggregate::sum('credit')], $criteria, $order));
    }

    /**
     * The analytic axes the book's lines carry, and how many values each.
     *
     * @return array<string, int> axis => values
     */
    public function axes(Book $book): array
    {
        $axes = [];
        foreach ($this->dimensions()->findValuesBy(['axis' => 'axis', 'n' => Aggregate::countDistinct('value')], ['line.entry.book' => $book], ['axis' => 'ASC']) as $row) {
            $axes[(string) $row['axis']] = (int) $row['n'];
        }

        return $axes;
    }

    /**
     * The lines of the accounts under $accountPrefix over [$from, $to], by
     * account then date: the general ledger's rows - those carrying every
     * dimension of $dimensions, theirs loaded with them when asked.
     *
     * @param array<string, string|int> $dimensions
     *
     * @return list<EntryLine>
     */
    public function forLedger(Book $book, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, ?string $accountPrefix = null, array $dimensions = [], bool $withDimensions = false): array
    {
        $criteria = array_merge($this->lines($book), $this->period($from, $to, false), $this->carrying($dimensions));
        if (null !== $accountPrefix && '' !== $accountPrefix) {
            $criteria[] = $this->under($accountPrefix);
        }

        // $withDimensions: each line's dimensions load with its collection when read.
        return $this->findBy($criteria, ['account.number' => 'ASC', 'entry.date' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Debit and credit totals of the accounts under $accountPrefix, all
     * lines or only the unlettered ones.
     *
     * @return array{debit: int, credit: int}
     */
    public function sumUnder(Book $book, string $accountPrefix, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, bool $openOnly = false): array
    {
        $criteria = $this->lines($book) + $this->period($from, $to);
        $criteria[] = $this->under($accountPrefix);
        if ($openOnly) {
            $criteria[] = Group::of(Parser::SEPARATOR_AND, Criterion::of('letter', null, Parser::OPTION_NULL));
        }

        return ['debit' => (int) $this->sumOfDebitBy($criteria), 'credit' => (int) $this->sumOfCreditBy($criteria)];
    }

    /** An account's balance (debit minus credit), all dates or up to $to. */
    public function balance(Account $account, ?\DateTimeImmutable $to = null): int
    {
        $criteria = ['account' => Key::of($account)] + $this->period(null, $to);

        return (int) $this->sumOfDebitBy($criteria) - (int) $this->sumOfCreditBy($criteria);
    }

    /** @return array<int|string, mixed> the lines of a book's entries */
    private function lines(Book $book): array
    {
        return ['entry.book' => $book];
    }

    /** @return array<int|string, mixed> the lines of the entries dated in [$from, $to] (either open), validated ones only if asked */
    private function period(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, bool $validatedOnly = false): array
    {
        $criteria = [];
        if (null !== $from) {
            $criteria[] = Group::of(Parser::SEPARATOR_AND, Criterion::of('entry.date', $from->setTime(0, 0), Parser::OPTION_GREATER_EQUAL));
        }
        if (null !== $to) {
            $criteria[] = Group::of(Parser::SEPARATOR_AND, Criterion::of('entry.date', $to->setTime(0, 0), Parser::OPTION_LOWER_EQUAL));
        }
        if ($validatedOnly) {
            $criteria[] = Group::of(Parser::SEPARATOR_AND, Criterion::of('entry.validatedAt', null, Parser::OPTION_NOT_NULL));
        }

        return $criteria;
    }

    /**
     * @param array<string, string|int> $dimensions only the lines carrying each axis => value
     *
     * @return list<Criterion>
     */
    private function carrying(array $dimensions): array
    {
        $criteria = [];
        foreach ($dimensions as $axis => $value) {
            $criteria[] = Criterion::exists(EntryLineDimension::class, 'line', ['axis' => EntryLineDimension::axis((string) $axis), 'value' => EntryLineDimension::value((string) $value)]);
        }

        return $criteria;
    }

    /** The lines' dimensions, read by the same language (a repository of the class, as Repositories::of() gives one). */
    private function dimensions(): ServiceEntityRepository
    {
        return $this->dimensions ??= new ServiceEntityRepository($this->finders(), EntryLineDimension::class);
    }

    private ?ServiceEntityRepository $dimensions = null;

    /** The lines of the accounts under a prefix ("6", "411"). */
    private function under(string $accountPrefix): Group
    {
        return Group::of(Parser::SEPARATOR_AND, Criterion::of('account.number', Account::normalize($accountPrefix), Parser::OPTION_STARTING_WITH));
    }
}
