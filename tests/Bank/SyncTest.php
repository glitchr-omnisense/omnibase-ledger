<?php

namespace Tests\Base\Ledger\Bank;

use Base\Ledger\Bank\Connections;
use Base\Ledger\Bank\Importer;
use Base\Ledger\Bank\Sync;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankConnection;
use Base\Ledger\Enum\ConsentState;
use Base\Ledger\Exception\BankException;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankConnectionRepository;
use Base\Ledger\Security\StateCipher;
use Omnibank\GatewayFactoryInterface;
use Omnibank\GatewayInterface;
use Omnibank\Model\Account;
use Omnibank\Model\AccountKind;
use Omnibank\Model\Balance;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\Connection;
use Omnibank\Model\ConnectionState;
use Omnibank\Model\Consent;
use Omnibank\Model\Money;
use Omnibank\Model\TransactionCategory;
use Omnibank\Exception\ProviderException;
use Omnibank\Registry;
use Omnibank\Request\FetchBalances;
use Tests\Base\Ledger\InMemoryLedger;

class SyncTest extends InMemoryLedger
{
    private ?\DateTimeInterface $since = null;
    private ?\Throwable $failure = null;
    private ?ConnectionState $accountState = null;

    protected function setUp(): void
    {
        if (!class_exists(Registry::class)) {
            self::markTestSkipped('glitchr/omnibank is not installed.');
        }
        parent::setUp();
    }

    private function connections(): Connections
    {
        $gateway = $this->createStub(GatewayInterface::class);
        $gateway->method('accounts')->willReturnCallback(fn () => [new Account('acc-1', 'FR7630006000011234567890189', null, 'Compte courant', 'EUR', 'checking', [], AccountKind::CHECKING, $this->accountState)]);
        $gateway->method('transactions')->willReturnCallback(function (Connection $connection, Account $account, ?\DateTimeInterface $since) {
            if (null !== $this->failure) {
                throw $this->failure;
            }
            $this->since = $since;

            return [
                new BankTransaction('t1', new \DateTimeImmutable('2026-10-01'), null, new Money(85000, 'EUR'), 'VIR SEPA LOYER', 'M DUPONT', null, null),
                new BankTransaction('t2', new \DateTimeImmutable('2026-10-02'), null, new Money(-1250, 'EUR'), 'FRAIS', null, null, null, [], TransactionCategory::BANK_FEES),
            ];
        });
        $gateway->method('supports')->willReturnCallback(fn (string $request) => FetchBalances::class === $request);
        $gateway->method('balances')->willReturn([new Balance(new Money(83750, 'EUR'), new \DateTimeImmutable('2026-10-02'))]);
        $factory = $this->createStub(GatewayFactoryInterface::class);
        $factory->method('getName')->willReturn('powens');
        $factory->method('create')->willReturn($gateway);

        return new Connections($this->entityManager(), new StateCipher('s'), $this->accountRepository(), $this->journalRepository(),
            $this->createStub(BankAccountRepository::class), $this->createStub(BankConnectionRepository::class),
            new Registry([$factory], ['powens' => ['factory' => 'powens']]));
    }

    private function syncedAccount(Connections $connections, Consent $consent): BankAccount
    {
        $connection = new BankConnection($this->book, 'powens');
        $connections->store($connection, new Connection(['user' => 'u-1'], $consent));

        return new BankAccount($connection, 'acc-1', 'Compte courant', $this->accounts['5121'], $this->journals['BQ']);
    }

    public function testNewLinesSinceTheLastSyncMinusTheOverlap(): void
    {
        $connections = $this->connections();
        $account = $this->syncedAccount($connections, Consent::active());
        $account->setLastSyncedAt(new \DateTimeImmutable('2026-09-20 14:00'));
        $sync = new Sync($this->entityManager(), $connections, new Importer($this->entityManager(), $this->bankLineRepository()));

        self::assertSame(2, $sync->sync($account));
        self::assertSame('2026-09-15 00:00', $this->since->format('Y-m-d H:i'));
        self::assertSame(83750, $account->getBankBalance());
        self::assertGreaterThan(new \DateTimeImmutable('2026-09-20 14:00'), $account->getLastSyncedAt());

        self::assertSame(0, $sync->sync($account));
        self::assertCount(2, $this->bankLines);
    }

    public function testAConsentToRenewStopsTheSync(): void
    {
        $connections = $this->connections();
        $account = $this->syncedAccount($connections, Consent::needsRenewal());
        $sync = new Sync($this->entityManager(), $connections, new Importer($this->entityManager(), $this->bankLineRepository()));

        try {
            $sync->sync($account);
            self::fail('Synced without consent.');
        } catch (BankException) {
        }
        self::assertSame(ConsentState::NEEDS_RENEWAL, $account->getConnection()->getConsentStatus());
        self::assertTrue($account->getConnection()->needsRenewal());
        self::assertSame([], $this->bankLines);
    }

    public function testAProviderRefusingForConsentMarksTheConnection(): void
    {
        $connections = $this->connections();
        $account = $this->syncedAccount($connections, Consent::active());
        $this->failure = new \RuntimeException('HTTP 401: SCA required');
        $sync = new Sync($this->entityManager(), $connections, new Importer($this->entityManager(), $this->bankLineRepository()));

        try {
            $sync->sync($account);
            self::fail('The failure went unnoticed.');
        } catch (BankException $e) {
            self::assertStringContainsString('SCA required', $e->getMessage());
        }
        self::assertSame(ConsentState::NEEDS_RENEWAL, $account->getConnection()->getConsentStatus());
        self::assertSame('HTTP 401: SCA required', $account->getConnection()->getLastError());
    }

    public function testASyncRecordsTheKindTheCategoriesAndTheConnectionsState(): void
    {
        $connections = $this->connections();
        $account = $this->syncedAccount($connections, Consent::active());
        $sync = new Sync($this->entityManager(), $connections, new Importer($this->entityManager(), $this->bankLineRepository()));

        $sync->sync($account);
        self::assertSame(AccountKind::CHECKING, $account->getKind());
        self::assertSame('checking', $account->getProviderType());
        self::assertSame(TransactionCategory::BANK_FEES, $this->bankLines[1]->getCategory());
        self::assertNull($this->bankLines[0]->getCategory(), 'none given, none kept');
        self::assertSame(ConnectionState::OK, $account->getConnection()->getConnectionState());

        // Read with the account, the provider's own word for the access stands: the data came, stale.
        $this->accountState = ConnectionState::ACTION_NEEDED;
        $sync->sync($account);
        self::assertSame(ConnectionState::ACTION_NEEDED, $account->getConnection()->getConnectionState());

        // A refusal says what it means for the access.
        $this->failure = new ProviderException('powens', 'Wrong password', 'wrongpass', null, ConnectionState::WRONG_CREDENTIALS);
        try {
            $sync->sync($account);
            self::fail('refused');
        } catch (BankException) {
        }
        self::assertSame(ConnectionState::WRONG_CREDENTIALS, $account->getConnection()->getConnectionState());
        self::assertSame(ConsentState::NEEDS_RENEWAL, $account->getConnection()->getConsentStatus(), 'the user must go through the bank\'s page again');

        $this->failure = new \Omnibank\Exception\UnavailableException('powens', 'HTTP 503');
        $account->getConnection()->setConsentStatus(ConsentState::ACTIVE);
        $connections->store($account->getConnection(), new Connection(['user' => 'u-1'], Consent::active()));
        try {
            $sync->sync($account);
        } catch (BankException) {
        }
        self::assertSame(ConnectionState::BANK_UNAVAILABLE, $account->getConnection()->getConnectionState());
    }
}
