<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\EntryLineDimension;
use Base\Ledger\Entity\Party;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EntryLine> */
class EntryLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryLine::class);
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
        $qb = $this->lines($book)
            ->andWhere('l.letter IS NULL')
            ->orderBy('e.date', 'ASC')->addOrderBy('l.id', 'ASC');
        if (null !== $party) {
            $qb->andWhere('l.party = :party')->setParameter('party', $party);
        }
        if (null !== $accountPrefix && '' !== $accountPrefix) {
            $qb->andWhere('a.number LIKE :prefix')->setParameter('prefix', addcslashes(Account::normalize($accountPrefix), '%_').'%');
        }

        return $qb->getQuery()->getResult();
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
        $rows = $this->lines($book)
            ->select('a.number AS number, COUNT(l.id) AS n')
            ->andWhere('e.source LIKE :bank AND e.source NOT LIKE :undo AND e.reversalOf IS NULL')->setParameter('bank', 'bank:%')->setParameter('undo', '%:undo')
            ->andWhere('e.date >= :since')->setParameter('since', $since->setTime(0, 0))
            ->groupBy('a.number')
            ->orderBy('n', 'DESC')
            ->getQuery()->getArrayResult();

        $usage = [];
        foreach ($rows as $row) {
            $usage[(string) $row['number']] = (int) $row['n'];
        }

        return $usage;
    }

    /** @return list<EntryLine> */
    public function findByLetter(Book $book, string $letter): array
    {
        return $this->lines($book)->andWhere('l.letter = :letter')->setParameter('letter', $letter)
            ->orderBy('l.id', 'ASC')->getQuery()->getResult();
    }

    /** The last letter given in the book: letters run AAA, AAB... ZZZ, AAAA. */
    public function lastLetter(Book $book): ?string
    {
        $row = $this->lines($book)->select('l.letter, LENGTH(l.letter) AS HIDDEN size')
            ->andWhere('l.letter IS NOT NULL')
            ->orderBy('size', 'DESC')->addOrderBy('l.letter', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $row['letter'] ?? null;
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
        $qb = $this->dimensions($this->period($this->lines($book), $from, $to, $validatedOnly), $dimensions)
            ->select('a.number AS number, a.label AS label, a.type AS type, SUM(l.debit) AS debit, SUM(l.credit) AS credit')
            ->groupBy('a.id, a.number, a.label, a.type')
            ->orderBy('a.number', 'ASC');
        if (null !== $groupBy) {
            $qb->leftJoin('l.dimensions', 'g', 'WITH', 'g.axis = :group')->setParameter('group', EntryLineDimension::axis($groupBy))
                ->addSelect('g.value AS dimension')->addGroupBy('g.value');
        }

        return array_map(fn (array $row) => ['debit' => (int) $row['debit'], 'credit' => (int) $row['credit']] + $row, $qb->getQuery()->getArrayResult());
    }

    /**
     * The VAT return's figures over [$from, $to]: per tax and account, the
     * lines' debit and credit and their tax bases on each side.
     *
     * @return list<array{code: string, label: string, rate: int, kind: mixed, account: string, tax_account: ?string, deductible_account: ?string, debit: int, credit: int, base_debit: int, base_credit: int}>
     */
    public function taxTotals(Book $book, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->period($this->lines($book), $from, $to, false)
            ->join('l.tax', 't')
            ->leftJoin('t.account', 'ta')
            ->leftJoin('t.deductibleAccount', 'tda')
            ->select('t.code AS code, t.label AS label, t.rate AS rate, t.kind AS kind, a.number AS account, ta.number AS tax_account, tda.number AS deductible_account')
            ->addSelect('SUM(l.debit) AS debit, SUM(l.credit) AS credit')
            ->addSelect('SUM(CASE WHEN l.debit > 0 THEN COALESCE(l.taxBase, 0) ELSE 0 END) AS base_debit')
            ->addSelect('SUM(CASE WHEN l.credit > 0 THEN COALESCE(l.taxBase, 0) ELSE 0 END) AS base_credit')
            ->groupBy('t.id, t.code, t.label, t.rate, t.kind, a.number, ta.number, tda.number')
            ->orderBy('t.code', 'ASC')->addOrderBy('a.number', 'ASC');

        return array_map(fn (array $row) => array_merge($row, [
            'rate' => (int) $row['rate'], 'debit' => (int) $row['debit'], 'credit' => (int) $row['credit'],
            'base_debit' => (int) $row['base_debit'], 'base_credit' => (int) $row['base_credit'],
        ]), $qb->getQuery()->getArrayResult());
    }

    /**
     * The analytic axes the book's lines carry, and how many values each.
     *
     * @return array<string, int> axis => values
     */
    public function axes(Book $book): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('d.axis AS axis, COUNT(DISTINCT d.value) AS n')
            ->from(EntryLineDimension::class, 'd')
            ->join('d.line', 'l')->join('l.entry', 'e')
            ->andWhere('e.book = :book')->setParameter('book', $book)
            ->groupBy('d.axis')->orderBy('d.axis', 'ASC')
            ->getQuery()->getArrayResult();

        return array_combine(array_column($rows, 'axis'), array_map('intval', array_column($rows, 'n'))) ?: [];
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
        $qb = $this->dimensions($this->period($this->lines($book), $from, $to, false), $dimensions)
            ->orderBy('a.number', 'ASC')->addOrderBy('e.date', 'ASC')->addOrderBy('l.id', 'ASC');
        if ($withDimensions) {
            $qb->leftJoin('l.dimensions', 'dims')->addSelect('dims');
        }
        if (null !== $accountPrefix && '' !== $accountPrefix) {
            $qb->andWhere('a.number LIKE :prefix')->setParameter('prefix', addcslashes(Account::normalize($accountPrefix), '%_').'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Debit and credit totals of the accounts under $accountPrefix, all
     * lines or only the unlettered ones.
     *
     * @return array{debit: int, credit: int}
     */
    public function sumUnder(Book $book, string $accountPrefix, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, bool $openOnly = false): array
    {
        $qb = $this->period($this->lines($book), $from, $to, false)
            ->select('COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit')
            ->andWhere('a.number LIKE :prefix')->setParameter('prefix', addcslashes(Account::normalize($accountPrefix), '%_').'%');
        if ($openOnly) {
            $qb->andWhere('l.letter IS NULL');
        }
        $row = $qb->getQuery()->getSingleResult();

        return ['debit' => (int) $row['debit'], 'credit' => (int) $row['credit']];
    }

    /** An account's balance (debit minus credit), all dates or up to $to. */
    public function balance(Account $account, ?\DateTimeImmutable $to = null): int
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit')
            ->join('l.entry', 'e')
            ->andWhere('l.account = :account')->setParameter('account', $account);
        if (null !== $to) {
            $qb->andWhere('e.date <= :to')->setParameter('to', $to->setTime(0, 0), 'date_immutable');
        }
        // DQL has no arithmetic between aggregates: the difference is made here.
        $row = $qb->getQuery()->getSingleResult();

        return (int) $row['debit'] - (int) $row['credit'];
    }

    private function lines(Book $book): QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->join('l.entry', 'e')->addSelect('e')
            ->join('l.account', 'a')->addSelect('a')
            ->andWhere('e.book = :book')->setParameter('book', $book);
    }

    /** Only the lines carrying each axis => value of $dimensions. */
    private function dimensions(QueryBuilder $qb, array $dimensions): QueryBuilder
    {
        $i = 0;
        foreach ($dimensions as $axis => $value) {
            $qb->join('l.dimensions', 'd'.$i, 'WITH', sprintf('d%1$d.axis = :axis%1$d AND d%1$d.value = :value%1$d', $i))
                ->setParameter('axis'.$i, EntryLineDimension::axis((string) $axis))
                ->setParameter('value'.$i, EntryLineDimension::value((string) $value));
            ++$i;
        }

        return $qb;
    }

    private function period(QueryBuilder $qb, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, bool $validatedOnly): QueryBuilder
    {
        if (null !== $from) {
            $qb->andWhere('e.date >= :from')->setParameter('from', $from->setTime(0, 0), 'date_immutable');
        }
        if (null !== $to) {
            $qb->andWhere('e.date <= :to')->setParameter('to', $to->setTime(0, 0), 'date_immutable');
        }
        if ($validatedOnly) {
            $qb->andWhere('e.validatedAt IS NOT NULL');
        }

        return $qb;
    }
}
