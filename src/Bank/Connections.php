<?php

namespace Base\Ledger\Bank;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankConnection;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Journal;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Enum\ConsentState;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Repository\EntryLineRepository;
use Base\Ledger\Service\Posting;
use Base\Ledger\Exception\BankException;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankConnectionRepository;
use Base\Ledger\Repository\JournalRepository;
use Base\Ledger\Security\StateCipher;
use Doctrine\ORM\EntityManagerInterface;
use Omnibank\GatewayInterface;
use Omnibank\Model\Account as BankAccountModel;
use Omnibank\Model\AccountKind;
use Omnibank\Model\Connection;
use Omnibank\Model\ConnectionState;
use Omnibank\Registry;

/**
 * A book's bank connections and Omnibank: the gateway behind each, its
 * Connection read from and written back to the sealed state, the bank's
 * pages to go through to give consent (start(), complete()), and the bank
 * accounts it gives access to, each with its ledger account (5121, 5122...)
 * and bank journal (BQ, BQ2...).
 */
class Connections
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StateCipher $cipher,
        private readonly AccountRepository $accounts,
        private readonly JournalRepository $journals,
        private readonly BankAccountRepository $bankAccounts,
        private readonly BankConnectionRepository $connections,
        private readonly ?Registry $registry = null,
        private readonly ?EntryLineRepository $lines = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->registry;
    }

    /** @return list<string> the gateways the application configured (omnibank.gateways) */
    public function gateways(): array
    {
        return null === $this->registry ? [] : $this->registry->names();
    }

    public function gateway(string $name): GatewayInterface
    {
        if (null === $this->registry) {
            throw new BankException('Bank feeds need glitchr/omnibank: install it and register its bundle.');
        }
        if (!$this->registry->has($name)) {
            throw new BankException(sprintf('No "%s" bank gateway is configured (omnibank.gateways): %s.', $name, implode(', ', $this->registry->names()) ?: 'none'));
        }

        return $this->registry->get($name);
    }

    /** The connection's Omnibank Connection, unsealed. */
    public function open(BankConnection $connection): Connection
    {
        $state = $connection->getState();
        if (null === $state || '' === $state) {
            return new Connection();
        }

        return Connection::fromArray($this->cipher->open($state));
    }

    /** Seals $omnibank into the connection, with its consent. Flushed by the caller. */
    public function store(BankConnection $connection, Connection $omnibank): void
    {
        $connection->setState($this->cipher->seal($omnibank->toArray()));
        $connection->setConsentStatus(ConsentState::of($omnibank->consent->status));
        $connection->setExpiresAt($omnibank->consent->expiresAt);
    }

    /**
     * Opens a new connection of $book through $gateway: the connection,
     * saved, and the page the user gives consent on (null when there is
     * none, as for statement files - its accounts are then discovered).
     * $returnUrl may be a callable given the saved connection: the return
     * route usually names it.
     *
     * @param string|callable(BankConnection): string $returnUrl
     *
     * @return array{connection: BankConnection, url: ?string}
     */
    public function start(Book $book, string $gateway, string|callable $returnUrl, ?string $name = null): array
    {
        $this->gateway($gateway);
        $connection = new BankConnection($book, $gateway, $name);
        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        $url = $this->complete($connection, \is_callable($returnUrl) ? $returnUrl($connection) : $returnUrl);

        return ['connection' => $connection, 'url' => $url];
    }

    /** Through the bank's page again: an expired consent, a new password. Null when nothing is needed. */
    public function renew(BankConnection $connection, string $returnUrl): ?string
    {
        return $this->complete($connection, $returnUrl);
    }

    /**
     * The user came back from the bank's page: what the provider put in the
     * return URL's query is handed to the gateway (state "return") and
     * connect() asked again. Null when nothing more is needed - the accounts
     * are then discovered -, else the next page to go through.
     *
     * @param array<string, mixed> $query
     */
    public function complete(BankConnection $connection, string $returnUrl, array $query = []): ?string
    {
        $omnibank = $this->open($connection);
        if ([] !== $query) {
            $omnibank = $omnibank->withState(['return' => $query] + $omnibank->state);
        }

        try {
            $result = $this->gateway($connection->getGateway())->connect($omnibank, $returnUrl);
        } catch (BankException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $connection->setLastError($e->getMessage());
            $this->entityManager->flush();

            throw new BankException($e->getMessage(), 0, $e);
        }

        $state = $result->connection->state;
        unset($state['return']);
        $this->store($connection, $result->connection->withState($state));
        $connection->setLastError(null);
        $this->entityManager->flush();

        if (null !== $result->url) {
            return $result->url;
        }
        // Through: the accounts found, the access works; none yet, the provider is still reading the bank.
        $connection->setConnectionState($this->discover($connection) > 0 || !$connection->getAccounts()->isEmpty() ? ConnectionState::OK : ConnectionState::PENDING);
        $this->entityManager->flush();

        return null;
    }

    /**
     * Removes a connection left unfinished (BankConnection::isUnfinished():
     * no consent, no account), sealed state included. Flushed.
     *
     * @throws BankException for a connection that holds accounts or a consent
     */
    public function remove(BankConnection $connection): void
    {
        if (!$connection->isUnfinished()) {
            throw new BankException(sprintf('%s holds accounts or a consent: it is not removed.', $connection));
        }
        $this->entityManager->remove($connection);
        $this->entityManager->flush();
    }

    /** Asks the bank for the connection's accounts and adds those the book does not have yet; returns how many. */
    public function discover(BankConnection $connection): int
    {
        $known = [];
        foreach ($connection->getAccounts() as $account) {
            $known[$account->getExternalId()] = true;
        }

        $added = 0;
        foreach ($this->gateway($connection->getGateway())->accounts($this->open($connection)) as $account) {
            if (!isset($known[$account->id])) {
                $this->attach($connection, $account);
                $known[$account->id] = true;
                ++$added;
            }
        }
        $this->entityManager->flush();

        return $added;
    }

    /**
     * The ledger account a bank account of $kind posts on, by its prefix and
     * label: a credit is what the book owes - a loan or a consumer credit
     * under 164 (Emprunts auprès des établissements de crédit), a revolving
     * credit or overdraft line under 519 (Concours bancaires courants) -,
     * every other account under 512 (Banques).
     *
     * @return array{0: string, 1: string}
     */
    public static function ledgerPrefix(AccountKind $kind): array
    {
        return match ($kind) {
            AccountKind::LOAN, AccountKind::CONSUMER_CREDIT => ['164', 'Emprunt'],
            AccountKind::REVOLVING => ['519', 'Concours bancaire'],
            default => ['512', 'Banque'],
        };
    }

    /**
     * A bank account of the connection, with its ledger account and journal:
     * the book's first bank account posts on 5121 in BQ, the second on 5122
     * in BQ2...; a loan on 1641, 1642... (ledgerPrefix()). Persisted, not
     * flushed.
     */
    public function attach(BankConnection $connection, BankAccountModel|string $account, ?string $name = null, ?string $iban = null, ?string $currency = null): BankAccount
    {
        $book = $connection->getBook();
        $kind = AccountKind::UNKNOWN;
        $type = null;
        if ($account instanceof BankAccountModel) {
            [$externalId, $name, $iban, $currency, $kind, $type] = [$account->id, $name ?? $account->name, $iban ?? $account->iban, $currency ?? $account->currency, $account->kind, $account->type];
        } else {
            $externalId = $account;
        }
        $name = $name ?: ($iban ? 'Compte '.BankAccount::mask($iban) : 'Compte bancaire');

        $rank = $this->bankAccounts->countForBook($book) + 1;
        foreach ($connection->getAccounts() as $existing) {
            if (null === $existing->getId()) {
                ++$rank; // attached in this request, not counted by the query yet
            }
        }

        $ledgerAccount = $this->ledgerAccount($book, $kind, $name);

        $code = 1 === $rank ? Journal::BANK : Journal::BANK.$rank;
        $journal = $this->journals->findOneByCode($book, $code);
        if (null === $journal) {
            $journal = new Journal($book, $code, 'Banque - '.$name);
            $this->entityManager->persist($journal);
        }

        $bankAccount = new BankAccount($connection, $externalId, $name, $ledgerAccount, $journal, $iban, $currency);
        $bankAccount->setKind($kind)->setProviderType($type);
        $this->entityManager->persist($bankAccount);

        return $bankAccount;
    }

    /**
     * The next free ledger account under $kind's prefix (ledgerPrefix()):
     * the first of 5121, 5122... (1641, 1642...) no bank account of the book
     * posts on yet - created when the chart does not have it.
     */
    public function ledgerAccount(Book $book, AccountKind $kind, string $name): Account
    {
        [$prefix, $label] = self::ledgerPrefix($kind);
        $taken = [];
        foreach ($this->bankAccounts->forBook($book) as $existing) {
            $taken[$existing->getAccount()->getNumber()] = true;
        }
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof BankAccount) {
                $taken[$pending->getAccount()->getNumber()] = true;
            }
        }
        for ($n = 1; isset($taken[$prefix.$n]); ++$n) {
        }
        $number = $prefix.$n;

        $account = $this->accounts->findOneByNumber($book, $number);
        if (null === $account) {
            $account = new Account($book, $number, $label.' - '.$name, '512' === $prefix ? AccountType::ASSET : AccountType::LIABILITY);
            $this->entityManager->persist($account);
        }

        return $account;
    }

    /**
     * Moves a bank account onto the ledger account its kind belongs under
     * (a loan attached before its kind was known, on 512x, to 164x): what
     * the former account holds is carried over by an entry in OD
     * ("Reclassement"), then the bank account posts on the new one. Null
     * when it is already where it belongs. Flushed.
     */
    public function reclassify(BankAccount $bankAccount, Posting $posting): ?Account
    {
        [$prefix] = self::ledgerPrefix($bankAccount->getKind());
        $former = $bankAccount->getAccount();
        if (str_starts_with($former->getNumber(), $prefix)) {
            return null;
        }
        $book = $bankAccount->getBook();
        $target = $this->ledgerAccount($book, $bankAccount->getKind(), $bankAccount->getName());
        $this->entityManager->flush();

        $balance = $this->lines?->balance($former) ?? 0;
        if (0 !== $balance) {
            $draft = new EntryDraft($book, Journal::MISCELLANEOUS, new \DateTimeImmutable('today'), sprintf('Reclassement %s vers %s', $former->getNumber(), $target->getNumber()), sprintf('bank:reclass:%d:%s', $bankAccount->getId(), $target->getNumber()));
            // The former account's balance (debit when positive) moves to the new one, on the same side.
            $posting->post($balance > 0
                ? $draft->line($target->getNumber(), $balance, 0)->line($former->getNumber(), 0, $balance)
                : $draft->line($former->getNumber(), -$balance, 0)->line($target->getNumber(), 0, -$balance));
        }
        $bankAccount->setAccount($target);
        $this->entityManager->flush();

        return $target;
    }

    /**
     * The connection a provider's notification is about: the one of that
     * gateway whose state holds the provider's connection id, or the only
     * one of that gateway.
     */
    public function findByProviderId(string $gateway, ?string $providerId): ?BankConnection
    {
        $candidates = array_values(array_filter($this->connections->findBy(['gateway' => $gateway]), fn (BankConnection $c) => null !== $c->getState()));
        if (null !== $providerId && '' !== $providerId) {
            foreach ($candidates as $connection) {
                try {
                    if (self::holds($this->open($connection)->state, $providerId)) {
                        return $connection;
                    }
                } catch (BankException) {
                    continue;
                }
            }
        }

        return 1 === \count($candidates) ? $candidates[0] : null;
    }

    private static function holds(array $state, string $value): bool
    {
        foreach ($state as $item) {
            if (\is_array($item) ? self::holds($item, $value) : (\is_scalar($item) && (string) $item === $value)) {
                return true;
            }
        }

        return false;
    }
}
