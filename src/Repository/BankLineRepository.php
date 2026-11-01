<?php

namespace Base\Ledger\Repository;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Enum\BankLineStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BankLine> */
class BankLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankLine::class);
    }

    public function findOneByExternalId(BankAccount $account, string $externalId): ?BankLine
    {
        return $this->findOneBy(['bankAccount' => $account, 'externalId' => $externalId]);
    }

    /** @return list<BankLine> the lines still to reconcile (unmatched or suggested), oldest first */
    public function pending(BankAccount $account): array
    {
        return $this->findBy(['bankAccount' => $account, 'status' => [BankLineStatus::UNMATCHED, BankLineStatus::SUGGESTED]], ['bookedOn' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<BankLine> a bank account's lines, newest first */
    public function latest(?BankAccount $account = null, int $limit = 50, ?BankLineStatus $status = null): array
    {
        $criteria = array_filter(['bankAccount' => $account, 'status' => $status], fn ($value) => null !== $value);

        return $this->findBy($criteria, ['bookedOn' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /**
     * A bank account's lines as plain values, oldest first, for Bank\Aggregates (no entity hydrated).
     *
     * @return list<array{bookedOn: \DateTimeImmutable, amount: int, category: ?string, counterpartyName: ?string, counterpartyIban: ?string, label: string}>
     */
    public function movements(BankAccount $account): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.bookedOn AS bookedOn, l.amount AS amount, l.category AS category, l.counterpartyName AS counterpartyName, l.counterpartyIban AS counterpartyIban, l.label AS label')
            ->andWhere('l.bankAccount = :account')->setParameter('account', $account)
            ->orderBy('l.bookedOn', 'ASC')->addOrderBy('l.id', 'ASC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $row) => [
            'bookedOn' => $row['bookedOn'] instanceof \DateTimeImmutable ? $row['bookedOn'] : new \DateTimeImmutable((string) $row['bookedOn']),
            'amount' => (int) $row['amount'],
            'category' => $row['category'],
            'counterpartyName' => $row['counterpartyName'],
            'counterpartyIban' => $row['counterpartyIban'],
            'label' => (string) $row['label'],
        ], $rows);
    }

    /**
     * The largest movements of $accounts from $from to $to, by amount whatever its direction.
     *
     * @param list<BankAccount> $accounts
     *
     * @return list<BankLine>
     */
    public function largest(array $accounts, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 8): array
    {
        if ([] === $accounts) {
            return [];
        }

        return $this->createQueryBuilder('l')
            ->addSelect('ABS(l.amount) AS HIDDEN size')
            ->andWhere('l.bankAccount IN (:accounts)')->setParameter('accounts', $accounts)
            ->andWhere('l.bookedOn >= :from AND l.bookedOn <= :to')->setParameter('from', $from->setTime(0, 0))->setParameter('to', $to->setTime(0, 0))
            ->orderBy('size', 'DESC')->addOrderBy('l.bookedOn', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * The lines of $accounts the front office lists, newest first: by state
     * ("todo": unmatched or suggested, "reconciled", "ignored", "missing":
     * a receipt said missing, null: all), within a month ("2026-10"), found
     * by words of the label, the counterparty or the reference, or by an
     * amount (« 89,16 », either sign).
     *
     * @param list<BankAccount> $accounts
     *
     * @return list<BankLine>
     */
    public function review(array $accounts, ?string $state = null, ?string $month = null, ?string $search = null, int $offset = 0, int $limit = 100): array
    {
        if ([] === $accounts) {
            return [];
        }

        return $this->reviewQuery($accounts, $state, $month, $search)
            ->orderBy('l.bookedOn', 'DESC')->addOrderBy('l.id', 'DESC')
            ->setFirstResult($offset)->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * How many lines of $accounts each state counts, in the month and the
     * search given: todo, reconciled, ignored, missing, all.
     *
     * @param list<BankAccount> $accounts
     *
     * @return array{todo: int, reconciled: int, ignored: int, missing: int, all: int}
     */
    public function reviewCounts(array $accounts, ?string $month = null, ?string $search = null): array
    {
        $counts = ['todo' => 0, 'reconciled' => 0, 'ignored' => 0, 'missing' => 0, 'all' => 0];
        if ([] === $accounts) {
            return $counts;
        }
        $rows = $this->reviewQuery($accounts, null, $month, $search)
            ->select('l.status AS status, COUNT(l.id) AS n, SUM(CASE WHEN l.receiptMissing = true THEN 1 ELSE 0 END) AS missing')
            ->groupBy('l.status')
            ->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $status = $row['status'] instanceof BankLineStatus ? $row['status'] : BankLineStatus::from((string) $row['status']);
            $key = $status->isPending() ? 'todo' : $status->value;
            $counts[$key] += (int) $row['n'];
            $counts['missing'] += (int) $row['missing'];
            $counts['all'] += (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The first and the last day $accounts have lines on, for the months to choose from.
     *
     * @param list<BankAccount> $accounts
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    public function span(array $accounts): array
    {
        if ([] === $accounts) {
            return [null, null];
        }
        $row = $this->createQueryBuilder('l')->select('MIN(l.bookedOn) AS first, MAX(l.bookedOn) AS last')
            ->andWhere('l.bankAccount IN (:accounts)')->setParameter('accounts', $accounts)
            ->getQuery()->getSingleResult();
        $date = static fn ($value) => null === $value ? null : ($value instanceof \DateTimeImmutable ? $value : new \DateTimeImmutable((string) $value));

        return [$date($row['first'] ?? null), $date($row['last'] ?? null)];
    }

    /**
     * The ids of $account's lines: what a sync added is the difference.
     *
     * @return list<int>
     */
    public function ids(BankAccount $account): array
    {
        return array_map('intval', array_column($this->createQueryBuilder('l')->select('l.id')
            ->andWhere('l.bankAccount = :account')->setParameter('account', $account)
            ->getQuery()->getScalarResult(), 'id'));
    }

    /** @param list<BankAccount> $accounts */
    private function reviewQuery(array $accounts, ?string $state, ?string $month, ?string $search): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('l.bankAccount IN (:accounts)')->setParameter('accounts', $accounts);
        match ($state) {
            'todo' => $qb->andWhere('l.status IN (:states)')->setParameter('states', [BankLineStatus::UNMATCHED, BankLineStatus::SUGGESTED]),
            'reconciled' => $qb->andWhere('l.status = :state')->setParameter('state', BankLineStatus::RECONCILED),
            'ignored' => $qb->andWhere('l.status = :state')->setParameter('state', BankLineStatus::IGNORED),
            'missing' => $qb->andWhere('l.receiptMissing = true'),
            default => null,
        };
        if (null !== $month && preg_match('/^(\d{4})-(\d{2})$/', $month)) {
            $first = new \DateTimeImmutable($month.'-01');
            $qb->andWhere('l.bookedOn >= :from AND l.bookedOn <= :to')->setParameter('from', $first)->setParameter('to', $first->modify('last day of this month'));
        }
        $search = trim((string) $search);
        if ('' !== $search) {
            if (preg_match('/^-?\s*(\d{1,9})(?:[.,](\d{1,2}))?\s*€?$/u', $search, $m)) {
                $minor = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
                $qb->andWhere('l.amount = :plus OR l.amount = :minus')->setParameter('plus', $minor)->setParameter('minus', -$minor);
            } else {
                foreach (array_slice(preg_split('/\s+/', $search), 0, 5) as $i => $word) {
                    $qb->andWhere(sprintf('l.label LIKE :w%1$d OR l.counterpartyName LIKE :w%1$d OR l.reference LIKE :w%1$d', $i))
                        ->setParameter('w'.$i, '%'.addcslashes($word, '%_').'%');
                }
            }
        }

        return $qb;
    }

    /** @return array<string, int> status value => count, for one bank account */
    public function countByStatus(BankAccount $account): array
    {
        $rows = $this->createQueryBuilder('l')->select('l.status AS status, COUNT(l.id) AS n')
            ->andWhere('l.bankAccount = :account')->setParameter('account', $account)
            ->groupBy('l.status')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status'] instanceof BankLineStatus ? $row['status']->value : (string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }
}
