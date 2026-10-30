<?php

namespace Base\Ledger\Bank;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Repository\BankLineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Omnibank\Model\BankTransaction;

/**
 * Omnibank's transactions into a bank account's lines, each once: a
 * transaction already imported (same id on the same account) is updated
 * while it waits for reconciliation - banks correct pending lines - and
 * left alone once reconciled or ignored.
 */
class Importer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BankLineRepository $lines,
    ) {
    }

    /**
     * @param iterable<BankTransaction> $transactions
     *
     * @return int how many new lines
     */
    public function import(BankAccount $account, iterable $transactions): int
    {
        $seen = [];
        $created = 0;
        foreach ($transactions as $transaction) {
            if (isset($seen[$transaction->id])) {
                continue;
            }
            $line = $this->lines->findOneByExternalId($account, $transaction->id);
            if (null === $line) {
                $line = new BankLine($account, $transaction->id, $transaction->bookedOn, $transaction->amount->amount, $transaction->label, $transaction->amount->currency);
                $this->entityManager->persist($line);
                ++$created;
            } elseif (!$line->getStatus()->isPending()) {
                $seen[$transaction->id] = true;
                continue;
            } else {
                $line->setBookedOn($transaction->bookedOn)->setAmount($transaction->amount->amount)->setLabel($transaction->label);
            }

            $line->setValueOn($transaction->valueOn)
                ->setCounterpartyName($transaction->counterpartyName)
                ->setCounterpartyIban($transaction->counterpartyIban)
                ->setReference($transaction->reference)
                ->setRaw($transaction->raw)
                ->setCategory($transaction->category ?? $line->getCategory());
            $seen[$transaction->id] = true;
        }
        $this->entityManager->flush();

        return $created;
    }
}
