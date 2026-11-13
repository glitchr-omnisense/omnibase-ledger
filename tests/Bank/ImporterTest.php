<?php

namespace Tests\Base\Ledger\Bank;

use Base\Ledger\Bank\Importer;
use Base\Ledger\Enum\BankLineStatus;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\Money;
use Tests\Base\Ledger\InMemoryLedger;

class ImporterTest extends InMemoryLedger
{
    protected function setUp(): void
    {
        if (!class_exists(BankTransaction::class)) {
            self::markTestSkipped('omnisense/omnibank is not installed.');
        }
        parent::setUp();
    }

    private static function transaction(string $id, int $amount, string $label = 'VIR SEPA', string $date = '2026-10-05'): BankTransaction
    {
        return new BankTransaction($id, new \DateTimeImmutable($date), null, new Money($amount, 'EUR'), $label, 'M DUPONT', 'FR76 3000 6000 0198 7654 3210 173', 'AVIS-U3', ['bank' => 'raw']);
    }

    public function testEachTransactionBecomesOneLineOnce(): void
    {
        $importer = new Importer($this->entityManager(), $this->bankLineRepository());
        $account = $this->bankAccount();

        self::assertSame(2, $importer->import($account, [self::transaction('t1', 85000), self::transaction('t2', -1250, 'FRAIS'), self::transaction('t1', 85000)]));
        self::assertCount(2, $this->bankLines);
        $line = $this->bankLines[0];
        self::assertSame([85000, 'EUR', 'VIR SEPA', 'M DUPONT', 'FR7630006000019876543210173', 'AVIS-U3'], [$line->getAmount(), $line->getCurrency(), $line->getLabel(), $line->getCounterpartyName(), $line->getCounterpartyIban(), $line->getReference()]);
        self::assertSame(BankLineStatus::UNMATCHED, $line->getStatus());

        // Again, corrected by the bank: updated while pending, untouched once reconciled.
        $this->bankLines[1]->setStatus(BankLineStatus::RECONCILED);
        self::assertSame(1, $importer->import($account, [self::transaction('t1', 85000, 'VIR SEPA LOYER'), self::transaction('t2', -9999), self::transaction('t3', 100)]));
        self::assertSame('VIR SEPA LOYER', $this->bankLines[0]->getLabel());
        self::assertSame(-1250, $this->bankLines[1]->getAmount());
        self::assertCount(3, $this->bankLines);
    }
}
