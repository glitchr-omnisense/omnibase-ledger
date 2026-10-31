<?php

namespace Base\Ledger\Report;

use Base\Ledger\Dimension\Dimensions;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Repository\EntryLineRepository;

/**
 * The grand livre: every line of every account over a period, with the
 * running balance and the account's totals - maybe only the lines carrying
 * some dimensions (['unit' => '12']), or per value of an axis.
 */
class GeneralLedger
{
    public function __construct(private readonly EntryLineRepository $lines, private readonly ?Dimensions $dimensions = null)
    {
    }

    /**
     * @param array<string, string|int> $dimensions only the lines carrying each axis => value
     *
     * @return list<array{number: string, label: string, lines: list<array{date: \DateTimeImmutable, journal: string, number: ?string, piece: ?string, label: string, party: ?string, debit: int, credit: int, balance: int, letter: ?string}>, debit: int, credit: int, balance: int}>
     */
    public function compute(Book $book, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, ?string $accountPrefix = null, array $dimensions = []): array
    {
        return self::fromLines($this->lines->forLedger($book, $from, $to, $accountPrefix, $dimensions));
    }

    /**
     * The grand livre per value of $axis: its accounts as compute() gives
     * them, the values named by the labelers, the lines without the axis
     * last (value null).
     *
     * @param array<string, string|int> $dimensions
     *
     * @return list<array{value: ?string, label: ?string, accounts: list<array>}>
     */
    public function byDimension(Book $book, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, string $axis, ?string $accountPrefix = null, array $dimensions = []): array
    {
        $byValue = [];
        foreach ($this->lines->forLedger($book, $from, $to, $accountPrefix, $dimensions, true) as $line) {
            $byValue[$line->getDimension($axis) ?? ''][] = $line;
        }
        $values = array_values(array_filter(array_map('strval', array_keys($byValue)), fn (string $value) => '' !== $value));
        $labels = $this->dimensions?->labels($axis, $values) ?? array_combine($values, $values);

        $groups = [];
        foreach ($byValue as $value => $lines) {
            $value = (string) $value;
            $groups[] = ['value' => '' === $value ? null : $value, 'label' => '' === $value ? null : ($labels[$value] ?? $value), 'accounts' => self::fromLines($lines)];
        }
        usort($groups, fn (array $a, array $b) => [null === $a['value'], (string) $a['label']] <=> [null === $b['value'], (string) $b['label']]);

        return $groups;
    }

    /** @param iterable<EntryLine> $lines by account then date */
    public static function fromLines(iterable $lines): array
    {
        $accounts = [];
        foreach ($lines as $line) {
            $account = $line->getAccount();
            $key = $account->getNumber();
            $accounts[$key] ??= ['number' => $key, 'label' => $account->getLabel(), 'lines' => [], 'debit' => 0, 'credit' => 0, 'balance' => 0];
            $accounts[$key]['debit'] += $line->getDebit();
            $accounts[$key]['credit'] += $line->getCredit();
            $accounts[$key]['balance'] += $line->getNet();
            $entry = $line->getEntry();
            $accounts[$key]['lines'][] = [
                'date' => $entry->getDate(),
                'journal' => $entry->getJournal()->getCode(),
                'number' => $entry->getNumber(),
                'piece' => $entry->getPiece(),
                'label' => $line->getLabel() ?? $entry->getLabel(),
                'party' => $line->getParty()?->getName(),
                'debit' => $line->getDebit(),
                'credit' => $line->getCredit(),
                'balance' => $accounts[$key]['balance'],
                'letter' => $line->getLetter(),
            ];
        }
        ksort($accounts, \SORT_STRING);

        return array_values($accounts);
    }
}
