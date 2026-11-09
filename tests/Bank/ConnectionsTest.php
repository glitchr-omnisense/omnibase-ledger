<?php

namespace Tests\Base\Ledger\Bank;

use Base\Ledger\Bank\Connections;
use Base\Ledger\Entity\BankConnection;
use Base\Ledger\Entity\Book;
use Base\Ledger\Enum\ConsentState;
use Base\Ledger\Exception\BankException;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankConnectionRepository;
use Base\Ledger\Repository\JournalRepository;
use Base\Ledger\Security\StateCipher;
use Doctrine\ORM\EntityManagerInterface;
use Omnibank\Model\Connection;
use Omnibank\Model\Consent;
use PHPUnit\Framework\TestCase;

class ConnectionsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Connection::class)) {
            self::markTestSkipped('omnisense/omnibank is not installed.');
        }
    }

    private function connections(string $secret = 'kernel-secret', array $stored = []): Connections
    {
        $repository = $this->createStub(BankConnectionRepository::class);
        $repository->method('findBy')->willReturn($stored);

        return new Connections(
            $this->createStub(EntityManagerInterface::class),
            new StateCipher($secret),
            $this->createStub(AccountRepository::class),
            $this->createStub(JournalRepository::class),
            $this->createStub(BankAccountRepository::class),
            $repository,
        );
    }

    public function testTheStateIsSealedAndOpensBackTheSame(): void
    {
        $connection = new BankConnection(new Book('SCI'), 'powens');
        $omnibank = new Connection(['user_id' => 42, 'token' => 'secret-token-é', 'connections' => ['c-77']], Consent::active(new \DateTimeImmutable('2027-01-01T00:00:00+00:00')));

        $this->connections()->store($connection, $omnibank);

        self::assertStringStartsWith('v1:', $connection->getState());
        self::assertStringNotContainsString('secret-token', $connection->getState());
        self::assertSame(ConsentState::ACTIVE, $connection->getConsentStatus());
        self::assertEquals(new \DateTimeImmutable('2027-01-01T00:00:00+00:00'), $connection->getExpiresAt());

        $opened = $this->connections()->open($connection);
        self::assertSame($omnibank->state, $opened->state);
        self::assertSame($omnibank->consent->status, $opened->consent->status);
        self::assertEquals($omnibank->consent->expiresAt, $opened->consent->expiresAt);
    }

    public function testAnotherSecretOrAnAlteredStateDoesNotOpen(): void
    {
        $connection = new BankConnection(new Book('SCI'), 'qonto');
        $this->connections()->store($connection, new Connection(['api_key' => 'k']));

        try {
            $this->connections('another-secret')->open($connection);
            self::fail('Opened under another secret.');
        } catch (BankException) {
        }

        $sealed = base64_decode(substr($connection->getState(), 3));
        $sealed[30] = \chr(\ord($sealed[30]) ^ 1);
        $connection->setState('v1:'.base64_encode($sealed));
        $this->expectException(BankException::class);
        $this->connections()->open($connection);
    }

    public function testEachSealIsDifferent(): void
    {
        $cipher = new StateCipher('s');
        self::assertNotSame($cipher->seal(['a' => 1]), $cipher->seal(['a' => 1]));
        self::assertSame(['a' => 1], $cipher->open($cipher->seal(['a' => 1])));
    }

    public function testANotificationFindsItsConnectionByTheProvidersId(): void
    {
        $book = new Book('SCI');
        $one = new BankConnection($book, 'powens');
        $two = new BankConnection($book, 'powens');
        $connections = $this->connections(stored: [$one, $two]);
        $connections->store($one, new Connection(['connections' => [['id' => 'c-11']]]));
        $connections->store($two, new Connection(['connections' => [['id' => 'c-22']]]));

        self::assertSame($two, $connections->findByProviderId('powens', 'c-22'));
        self::assertNull($connections->findByProviderId('powens', 'c-99'));
    }

    public function testWithoutOmnibankTheGatewaysAreUnavailable(): void
    {
        self::assertFalse($this->connections()->isAvailable());
        self::assertSame([], $this->connections()->gateways());
        $this->expectException(BankException::class);
        $this->connections()->gateway('files');
    }
}
