<?php

namespace Base\Ledger\Bank;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Exception\BankException;
use Doctrine\ORM\EntityManagerInterface;
use Omnibank\Model\Account as BankAccountModel;
use Omnibank\Model\AccountKind;
use Omnibank\Model\Connection;
use Omnibank\Model\ConnectionState;
use Omnibank\Model\Consent;
use Omnibank\Request\FetchBalances;

/**
 * A statement file (CAMT.053, OFX, CSV) typed into a bank account through
 * Omnibank's "files" gateway: its lines imported (each once, however many
 * overlapping statements are uploaded) and the statement's closing balance
 * kept. The file itself is not stored.
 */
class StatementUpload
{
    public const GATEWAY = 'files';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connections $connections,
        private readonly Importer $importer,
        private readonly ?Aggregates $aggregates = null,
        private readonly ?Opening $opening = null,
    ) {
    }

    /** @return int how many new lines */
    public function upload(BankAccount $account, string $name, string $content): int
    {
        if ('' === trim($content)) {
            throw new BankException(sprintf('%s is empty.', $name));
        }
        $gateway = $this->connections->gateway(self::GATEWAY);

        $file = ['name' => $name, 'content' => $content];
        // A CSV says nothing of its account: it is this one.
        if (self::isCsv($name, $content)) {
            $file['account'] = array_filter(['id' => $account->getExternalId(), 'iban' => $account->getIban(), 'name' => $account->getName(), 'currency' => $account->getCurrency()]);
        }
        $omnibank = new Connection(['statements' => [$file]], Consent::active());

        try {
            $model = $this->pick($account, $gateway->accounts($omnibank), $name);
            // A statement that says what the account is (CAMT.053's Acct/Tp, OFX's ACCTTYPE) sets its kind; one that does not leaves it as set.
            if (AccountKind::UNKNOWN !== $model->kind) {
                $account->setKind($model->kind)->setProviderType($model->type);
            }
            $created = $this->importer->import($account, $gateway->transactions($omnibank, $model));
            if ($gateway->supports(FetchBalances::class)) {
                $balances = $gateway->balances($omnibank, $model);
                if ([] !== $balances) {
                    usort($balances, fn ($a, $b) => $a->at <=> $b->at);
                    $latest = end($balances);
                    if (null === $account->getBankBalanceAt() || $latest->at >= $account->getBankBalanceAt()) {
                        $account->setBankBalance($latest->amount->amount, $latest->at);
                    }
                }
            }
        } catch (BankException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BankException(sprintf('%s: %s', $name, $e->getMessage()), 0, $e);
        }
        $account->setLastSyncedAt(new \DateTimeImmutable());
        $account->getConnection()->setConnectionState(ConnectionState::OK);
        $this->entityManager->flush();
        try {
            $this->opening?->open($account);
        } catch (\Base\Ledger\Exception\LedgerException) {
            // A period the book locked: ledger:bank:opening writes it once opened.
        }
        $this->aggregates?->rebuild($account);

        return $created;
    }

    /** @param list<BankAccountModel> $accounts */
    private function pick(BankAccount $account, array $accounts, string $name): BankAccountModel
    {
        foreach ($accounts as $candidate) {
            if ((null !== $account->getIban() && $account->getIban() === preg_replace('/\s+/', '', strtoupper((string) $candidate->iban))) || $candidate->id === $account->getExternalId()) {
                return $candidate;
            }
        }
        if (1 === \count($accounts) && (null === $accounts[0]->iban || null === $account->getIban())) {
            return $accounts[0];
        }

        throw new BankException(sprintf('%s is not a statement of %s%s.', $name, $account->getName(), null !== $account->getIban() ? ' ('.$account->getMaskedIban().')' : ''));
    }

    private static function isCsv(string $name, string $content): bool
    {
        if (str_ends_with(strtolower($name), '.csv')) {
            return true;
        }
        $head = ltrim(substr($content, 0, 200), "\xEF\xBB\xBF \t\r\n");

        return !str_starts_with($head, '<') && !str_starts_with($head, 'OFXHEADER');
    }
}
