<?php

namespace Tests\Base\Ledger\Bank;

use Base\Ledger\Bank\Connections;
use Base\Ledger\Bank\Importer;
use Base\Ledger\Bank\StatementUpload;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankConnection;
use Base\Ledger\Exception\BankException;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankConnectionRepository;
use Base\Ledger\Security\StateCipher;
use Omnibank\Files\FilesGatewayFactory;
use Omnibank\Registry;
use Tests\Base\Ledger\InMemoryLedger;

/** Statement files through Omnibank's own "files" gateway, its fixtures as the bank's exports. */
class StatementUploadTest extends InMemoryLedger
{
    private string $fixtures;

    protected function setUp(): void
    {
        if (!class_exists(FilesGatewayFactory::class)) {
            self::markTestSkipped('omnibank/files is not installed.');
        }
        parent::setUp();
        $this->fixtures = \dirname((new \ReflectionClass(FilesGatewayFactory::class))->getFileName()).'/Tests/Fixtures';
        if (!is_dir($this->fixtures)) {
            self::markTestSkipped('omnibank/files comes without its fixtures.');
        }
    }

    private function upload(): StatementUpload
    {
        $connections = new Connections($this->unitOfWork(), new StateCipher('s'), $this->accountRepository(), $this->journalRepository(),
            $this->createStub(BankAccountRepository::class), $this->createStub(BankConnectionRepository::class),
            new Registry([new FilesGatewayFactory()], ['files' => ['factory' => 'files']]));

        return new StatementUpload($this->unitOfWork(), $connections, new Importer($this->unitOfWork(), $this->bankLineRepository()));
    }

    private function fileAccount(string $iban): BankAccount
    {
        return $this->persisted(new BankAccount(new BankConnection($this->book, 'files'), 'files-1', 'Compte courant', $this->accounts['5121'], $this->journals['BQ'], $iban));
    }

    public function testOverlappingCamtStatementsImportEachLineOnce(): void
    {
        $account = $this->fileAccount('FR76 3000 6000 0112 3456 7890 189');
        $upload = $this->upload();

        $first = $upload->upload($account, 'camt053-2026-09-a.xml', file_get_contents($this->fixtures.'/camt053-2026-09-a.xml'));
        self::assertGreaterThan(0, $first);
        self::assertCount($first, $this->bankLines);
        self::assertSame(592660, $account->getBankBalance());
        self::assertNotNull($account->getLastSyncedAt());

        $second = $upload->upload($account, 'camt053-2026-09-b.xml', file_get_contents($this->fixtures.'/camt053-2026-09-b.xml'));
        self::assertCount($first + $second, $this->bankLines);
        self::assertSame(0, $upload->upload($account, 'camt053-2026-09-a.xml', file_get_contents($this->fixtures.'/camt053-2026-09-a.xml')));
        self::assertSame(array_unique(array_map(fn ($l) => $l->getExternalId(), $this->bankLines)), array_map(fn ($l) => $l->getExternalId(), $this->bankLines));
    }

    public function testAStatementOfAnotherAccountIsRefused(): void
    {
        $this->expectException(BankException::class);
        $this->upload()->upload($this->fileAccount('FR76 1111 2222 3333 4444 5555 666'), 'camt.xml', file_get_contents($this->fixtures.'/camt053-2026-09-a.xml'));
    }

    public function testACsvIsThisAccounts(): void
    {
        $account = $this->fileAccount('FR76 1111 2222 3333 4444 5555 666');
        $created = $this->upload()->upload($account, 'bank-export.csv', file_get_contents($this->fixtures.'/bank-export.csv'));

        self::assertGreaterThan(0, $created);
        self::assertSame(130000, $this->bankLines[0]->getAmount());
        self::assertSame('SARL Le Comptoir', $this->bankLines[0]->getCounterpartyName());
    }
}
