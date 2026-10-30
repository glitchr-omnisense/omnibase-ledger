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
