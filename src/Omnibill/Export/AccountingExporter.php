<?php

namespace Base\Ledger\Omnibill\Export;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\Party;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Omnibill\Entity\AccountingExport;
use Base\Ledger\Omnibill\Entity\AccountingExportItem;
use Base\Ledger\Omnibill\Enum\ExportState;
use Base\Ledger\Omnibill\Repository\AccountingExportItemRepository;
use Base\Ledger\Omnibill\Repository\AccountingExportRepository;
use Base\Ledger\Repository\EntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Omnibill\ContactsInterface;
use Omnibill\EntryLookupInterface;
use Omnibill\Exception\InvalidConfigException;
use Omnibill\Exception\InvalidKeyException;
use Omnibill\Exception\NotSupportedException;
use Omnibill\Exception\OmnibillException;
use Omnibill\Exception\OutcomeUnknownException;
use Omnibill\Exception\ProviderException;
use Omnibill\Exception\UnreachableException;
use Omnibill\Exception\UsageLimitReachedException;
use Omnibill\GatewayInterface;
use Omnibill\JournalEntriesInterface;
use Omnibill\Model\Capabilities;
use Omnibill\Model\JournalEntry;
use Omnibill\Registry;
use Omnibill\Request\Accounting\FindEntry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * The book's validated entries of a period handed to the accountant's
 * software through omnisense/omnibill's accounting contracts (odoo,
 * pennylane): the contacts first - the customers and suppliers their lines
 * name -, then the entries. Each record sent is an AccountingExportItem,
 * keyed by the entry's source (the party's reference): a second export of
 * the same entry is a no-op, an entry changed in the book since it went is
 * flagged CHANGED and never sent again on its own.
 *
 * A run is an AccountingExport. The software refusing one record (an
 * account it has not) refuses that record only; the software unreachable,
 * a key refused or a usage limit reached stops the run, FAILED, and the
 * next export of the same period resumes it: what went is not sent again.
 * Nothing is ever sent twice by a retry: omnibill's gateways run without
 * the HTTP client's retries, and a record is sent again only by a run, for
 * a record the software did not take.
 *
 * A record whose answer was lost after it left (omnibill's
 * OutcomeUnknownException: a timeout, a server error) may be in the
 * software: it is UNKNOWN, and the run stops as for an unreachable one. It
 * is never sent again as it is: the next run, or a person's retry, asks the
 * software for the entry by its key first (EntryLookupInterface::findEntry(),
 * where the gateway supports it: odoo, pennylane) and sends it only when the
 * software has none. A contact, or an entry on a gateway that cannot be
 * asked, waits for a person, who checks in the software and sends it again
 * (retry()).
 */
class AccountingExporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EntryRepository $entries,
        private readonly AccountingExportRepository $runs,
        private readonly AccountingExportItemRepository $items,
        private readonly EntryMapper $mapper = new EntryMapper(),
        private readonly ?Registry $registry = null,
        #[Autowire('%ledger.accounting.gateway%')] private readonly ?string $gateway = null,
        private readonly ?LockFactory $locks = null,
    ) {
    }

    /** The gateway the books go to: ledger.accounting.gateway, else the first that keeps journal entries; null when none does. */
    public function accountingGateway(): ?string
    {
        if (null !== $this->gateway) {
            return $this->gateway;
        }
        foreach ($this->registry?->names() ?? [] as $name) {
            try {
                if ($this->registry->get($name)->capabilities()->keeps(Capabilities::JOURNAL_ENTRIES)) {
                    return $name;
                }
            } catch (OmnibillException) {
            }
        }

        return null;
    }

    /**
     * Exports the book's validated entries from $from to $to, resuming the
     * run of that period that did not finish.
     *
     * @throws LedgerException no gateway keeps journal entries, or another export of the book is running
     */
    public function export(Book $book, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $gateway = null, ?string $by = null): AccountingExport
    {
        $gateway ??= $this->accountingGateway() ?? throw new LedgerException('No gateway keeps journal entries: configure omnibill with odoo or pennylane.');
        [$books, $contacts] = $this->contracts($gateway);

        $lock = $this->locks?->createLock('ledger-accounting-export-'.($book->getId() ?? spl_object_id($book)));
        if (null !== $lock && !$lock->acquire()) {
            throw new LedgerException(sprintf('An export of %s is running.', $book->getName()));
        }
        try {
            $run = $this->runs->unfinished($book, $gateway, $from, $to);
            if (null !== $run) {
                $run->resume();
            } else {
                $run = new AccountingExport($book, $gateway, $from, $to, $by);
                $this->entityManager->persist($run);
            }
            $this->entityManager->flush();

            try {
                $period = $this->entries->forPeriod($book, $from, $to);
                if (null !== $contacts) {
                    foreach ($this->parties($period) as $party) {
                        $this->sendContact($run, $contacts, $party);
                    }
                }
                foreach ($period as $entry) {
                    $this->sendEntry($run, $books, $entry, null !== $contacts);
                }
                $run->finish();
            } catch (UnreachableException|InvalidKeyException|UsageLimitReachedException $e) {
                $run->fail($e->getMessage());
            }
            $this->entityManager->flush();
        } finally {
            $lock?->release();
        }

        return $run;
    }

    /**
     * A record a person looked at, sent again: a refused or failed one as
     * at first; one whose outcome was unknown once the person checked the
     * software (an entry still looked up first where the gateway can); a
     * changed contact updated in the software (its reference kept). A
     * changed entry is never sent again - the software has the first one:
     * correct it there, then acknowledge() it.
     *
     * @throws LedgerException
     */
    public function retry(AccountingExportItem $item, ?string $by = null): AccountingExportItem
    {
        if (ExportState::CHANGED === $item->getState() && AccountingExportItem::ENTRY === $item->getKind()) {
            throw new LedgerException('A changed entry is not sent twice: correct it in the accounting software, then mark it as handled.');
        }
        if (ExportState::SENT === $item->getState()) {
            return $item;
        }
        [$books, $contacts] = $this->contracts($item->getGateway());
        $run = $item->getRun() ?? new AccountingExport($item->getBook(), $item->getGateway(), new \DateTimeImmutable('today'), new \DateTimeImmutable('today'), $by);
        if (null === $run->getId()) {
            $this->entityManager->persist($run);
        }
        try {
            if (AccountingExportItem::CONTACT === $item->getKind()) {
                $party = $item->getParty() ?? throw new LedgerException('The party of this contact is gone.');
                if (null === $contacts) {
                    throw new LedgerException(sprintf('The "%s" gateway keeps no contacts.', $item->getGateway()));
                }
                $this->sendContact($run, $contacts, $party, true);
            } else {
                $entry = $item->getEntry() ?? throw new LedgerException('The entry is gone.');
                $this->sendEntry($run, $books, $entry, null !== $contacts, true);
            }
        } catch (UnreachableException|InvalidKeyException|UsageLimitReachedException $e) {
            throw new LedgerException($e->getMessage(), 0, $e);
        } finally {
            $this->entityManager->flush();
        }

        return $item;
    }

    /** A changed record a person handled in the software: what the book has now is what the software has. */
    public function acknowledge(AccountingExportItem $item): void
    {
        if (ExportState::CHANGED !== $item->getState() || null === $item->getReference()) {
            return;
        }
        $record = match (true) {
            null !== $item->getEntry() => $this->mapper->entry($item->getEntry()),
            null !== $item->getParty() => $this->mapper->contact($item->getParty()),
            default => null,
        };
        if (null === $record || null === ($run = $item->getRun())) {
            return;
        }
        $item->sent($run, $item->getReference(), $item->getNumber(), $this->mapper->fingerprint($record));
        $this->entityManager->flush();
    }

    /** @return array{JournalEntriesInterface, ?ContactsInterface} */
    private function contracts(string $gateway): array
    {
        if (null === $this->registry) {
            throw new LedgerException('omnisense/omnibill is not configured.');
        }
        try {
            $books = $this->registry->journalEntries($gateway);
        } catch (NotSupportedException|InvalidConfigException $e) {
            throw new LedgerException($e->getMessage(), 0, $e);
        }
        try {
            $contacts = $this->registry->contacts($gateway);
        } catch (NotSupportedException) {
            $contacts = null;
        }

        return [$books, $contacts];
    }

    /**
     * The customers and suppliers the period's lines name, once each.
     *
     * @param list<Entry> $entries
     *
     * @return list<Party>
     */
    private function parties(array $entries): array
    {
        $parties = [];
        foreach ($entries as $entry) {
            foreach ($entry->getLines() as $line) {
                $party = $line->getParty();
                if (null !== $party && null !== $this->mapper->contactKind($party)) {
                    $parties[spl_object_id($party)] = $party;
                }
            }
        }

        return array_values($parties);
    }

    private function sendContact(AccountingExport $run, ContactsInterface $contacts, Party $party, bool $retry = false): void
    {
        $contact = $this->mapper->contact($party);
        if (null === $contact) {
            return;
        }
        $fingerprint = $this->mapper->fingerprint($contact);
        $item = $this->item($run, AccountingExportItem::CONTACT, (string) $contact->key, $fingerprint, null, $party);
        if ($item->isSent() && !($retry && ExportState::CHANGED === $item->getState())) {
            $this->unchangedOrFlagged($run, $item, $fingerprint);

            return;
        }
        if (ExportState::UNKNOWN === $item->getState() && !$retry) {
            // No lookup of a contact in omnibill: a person checks the software first.
            return;
        }
        try {
            $saved = $contacts->saveContact(null === $item->getReference() ? $contact : $contact->with(reference: $item->getReference()));
            $item->sent($run, $saved->reference(), null, $fingerprint);
            $run->count('contact');
        } catch (OutcomeUnknownException $e) {
            $item->unknown($run, $e->getMessage(), $fingerprint);
            $this->entityManager->flush();
            throw $e;
        } catch (UnreachableException|InvalidKeyException|UsageLimitReachedException $e) {
            $item->failed($run, $e->getMessage(), $fingerprint);
            $this->entityManager->flush();
            throw $e;
        } catch (ProviderException|InvalidConfigException|NotSupportedException|\LogicException $e) {
            $item->refused($run, $e->getMessage(), $fingerprint);
            $run->count('refused');
        }
        $this->entityManager->flush();
    }

    private function sendEntry(AccountingExport $run, JournalEntriesInterface $books, Entry $entry, bool $contacts, bool $retry = false): void
    {
        $record = $this->mapper->entry($entry, $contacts);
        $fingerprint = $this->mapper->fingerprint($record);
        $item = $this->item($run, AccountingExportItem::ENTRY, $entry->getSource(), $fingerprint, $entry);
        if ($item->isSent()) {
            $this->unchangedOrFlagged($run, $item, $fingerprint);

            return;
        }
        if (ExportState::UNKNOWN === $item->getState() && !$this->absent($run, $books, $record, $item, $retry)) {
            return;
        }
        try {
            $posted = $books->postEntry($record);
            $item->sent($run, $posted->reference(), $posted->number, $fingerprint);
            $run->count('entry');
        } catch (OutcomeUnknownException $e) {
            $item->unknown($run, $e->getMessage(), $fingerprint);
            $this->entityManager->flush();
            throw $e;
        } catch (UnreachableException|InvalidKeyException|UsageLimitReachedException $e) {
            $item->failed($run, $e->getMessage(), $fingerprint);
            $this->entityManager->flush();
            throw $e;
        } catch (ProviderException|InvalidConfigException|NotSupportedException|\LogicException $e) {
            $item->refused($run, $e->getMessage(), $fingerprint);
            $run->count('refused');
        }
        $this->entityManager->flush();
    }

    /**
     * An entry whose outcome was unknown, asked of the software by its key
     * before it is posted again: whether it is to be posted. Found, it is
     * SENT with the software's reference (counted as already there); asked
     * in vain, it is to be posted. A gateway that cannot be asked leaves it
     * to a person: posted only on their retry.
     *
     * @throws UnreachableException|InvalidKeyException|UsageLimitReachedException the software could not be asked: it stays UNKNOWN
     */
    private function absent(AccountingExport $run, JournalEntriesInterface $books, JournalEntry $record, AccountingExportItem $item, bool $retry): bool
    {
        if (!$books instanceof EntryLookupInterface || ($books instanceof GatewayInterface && !$books->supports(FindEntry::class))) {
            return $retry;
        }
        try {
            $found = $books->findEntry($record);
        } catch (UnreachableException|InvalidKeyException|UsageLimitReachedException $e) {
            throw $e;
        } catch (ProviderException|InvalidConfigException|NotSupportedException $e) {
            // Asked, and no answer to rely on: a person checks.
            $item->unknown($run, $e->getMessage(), $item->getFingerprint());
            $this->entityManager->flush();

            return $retry;
        }
        if (null === $found) {
            return true;
        }
        // The software took it: what it took is what was sent then, the fingerprint kept (a change since is flagged next time).
        $item->sent($run, $found->reference ?? $item->getRecordKey(), $found->number, $item->getFingerprint());
        $run->count('skipped');
        $this->entityManager->flush();

        return false;
    }

    /** A record the software has: left alone when the book still says the same, flagged when it does not. */
    private function unchangedOrFlagged(AccountingExport $run, AccountingExportItem $item, string $fingerprint): void
    {
        if ($item->getFingerprint() === $fingerprint && ExportState::CHANGED !== $item->getState()) {
            $run->count('skipped');

            return;
        }
        if (ExportState::CHANGED !== $item->getState()) {
            $item->changed($run);
        }
        $run->count('changed');
        $this->entityManager->flush();
    }

    private function item(AccountingExport $run, string $kind, string $key, string $fingerprint, ?Entry $entry = null, ?Party $party = null): AccountingExportItem
    {
        $item = $this->items->findOneByKey($run->getBook(), $run->getGateway(), $kind, $key);
        if (null === $item) {
            $item = new AccountingExportItem($run->getBook(), $run->getGateway(), $kind, $key, $fingerprint, $entry, $party);
            $this->entityManager->persist($item);
        }

        return $item;
    }
}
