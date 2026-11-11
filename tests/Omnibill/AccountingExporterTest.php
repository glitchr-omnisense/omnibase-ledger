<?php

namespace Tests\Base\Ledger\Omnibill;

use Base\Ledger\Entity\Party;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\VatRegime;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Omnibill\Entity\AccountingExportItem;
use Base\Ledger\Omnibill\Enum\ExportState;
use Omnibill\Pennylane\PennylaneGatewayFactory;
use Omnibill\Registry;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The book's validated entries handed to the accountant's software through
 * omnibill's pennylane gateway (its Company API v2, answered by a
 * MockHttpClient that records each request): contacts first, then entries;
 * a second export a no-op, a refusal kept per record, a run that stopped
 * resumed without sending anything twice, a record whose answer was lost
 * looked up before it is sent again, a record changed since it went
 * flagged and never sent again on its own.
 */
class AccountingExporterTest extends InMemoryOmnibill
{
    /** @var list<array{string, string, mixed}> method, path, JSON body */
    private array $calls = [];
    /**
     * What befalls the nth POST /ledger_entries (1 for the first): "refused" the connection
     * (it never left), "down" (a 503, nothing taken), "lost" (taken, then a 503).
     *
     * @var array<int, string>
     */
    private array $onEntry = [];
    private int $entriesPosted = 0;
    /** What befalls the next POST /suppliers: "down" (a 503). */
    private ?string $onSupplier = null;
    /** @var list<array<string, mixed>> the entries the software has, as it lists them */
    private array $software = [];
    private Party $edf;
    private Party $dupont;

    protected function setUp(): void
    {
        parent::setUp();
        $this->book->setVatRegime(VatRegime::NORMAL_MONTHLY);
        $this->frenchTaxes();
        $this->account('6061', 'Fournitures non stockables');
        $this->account('6288', 'Autres services extérieurs divers');
        $this->edf = $this->party('EDF', PartyKind::SUPPLIER, 'siren:552081317', 'FR7630004000031234567890143');
        $this->dupont = $this->party('Jean Dupont', PartyKind::TENANT, 'U3');
        $this->posting()->post((new EntryDraft($this->book, 'AC', new \DateTimeImmutable('2026-10-03'), 'Facture EDF octobre', 'supplier-invoice:7', 'FA-2026-101'))
            ->line('6061', 10000, 0)->line('44566', 2000, 0, tax: 'A20', taxBase: 10000)->line($this->edf->getAccountNumber(), 0, 12000, $this->edf));
        $this->notice($this->dupont, 85000, 'AVIS-2026-10-U3', '2026-10-01');
        $this->posting()->post((new EntryDraft($this->book, 'OD', new \DateTimeImmutable('2026-10-15'), 'Frais divers', 'misc:1'))
            ->line('6288', 500, 0)->line('512', 0, 500));
        // A draft and an entry of another month: not exported.
        $this->posting()->post((new EntryDraft($this->book, 'OD', new \DateTimeImmutable('2026-10-20'), 'Brouillon', 'misc:draft'))->line('6061', 100, 0)->line('512', 0, 100), false);
        $this->posting()->post((new EntryDraft($this->book, 'OD', new \DateTimeImmutable('2026-11-02'), 'Novembre', 'misc:nov'))->line('6061', 100, 0)->line('512', 0, 100));
    }

    private function registry(): Registry
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = substr((string) parse_url($url, \PHP_URL_PATH), \strlen('/api/external/v2'));
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $body = isset($options['body']) && \is_string($options['body']) ? json_decode($options['body'], true) : null;
            $this->calls[] = [$method, $path, $body];
            $json = static fn (array $data, int $status = 200) => new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);

            if ('GET' === $method && '/journals' === $path) {
                return $json(['items' => [['id' => 8001, 'code' => 'VT'], ['id' => 8002, 'code' => 'AC'], ['id' => 8003, 'code' => 'BQ'], ['id' => 8004, 'code' => 'OD']], 'has_more' => false, 'next_cursor' => null]);
            }
            if ('GET' === $method && '/ledger_accounts' === $path) {
                $number = (string) (json_decode((string) ($query['filter'] ?? '[]'), true)[0]['value'] ?? '');

                return $json(['items' => '6288' === $number ? [] : [['id' => crc32($number) % 100000, 'number' => $number, 'vat_rate' => null, 'enabled' => true]], 'has_more' => false, 'next_cursor' => null]);
            }
            if ('GET' === $method && '/ledger_entries' === $path) {
                $where = array_column(json_decode((string) ($query['filter'] ?? '[]'), true), 'value', 'field');

                return $json(['items' => array_values(array_filter($this->software, static fn (array $e) => $e['date'] === $where['date'] && $e['journal_id'] === $where['journal_id'])), 'has_more' => false, 'next_cursor' => null]);
            }
            if ('POST' === $method && '/ledger_entries' === $path) {
                $fate = $this->onEntry[++$this->entriesPosted] ?? null;
                if ('refused' === $fate) {
                    return new MockResponse('', ['error' => 'Failed to connect to app.pennylane.com port 443: Connection refused']);
                }
                if ('down' === $fate) {
                    return new MockResponse('', ['http_code' => 503]);
                }
                $entry = ['id' => 610000 + \count($this->calls), 'label' => $body['label'] ?? '', 'piece_number' => $body['piece_number'] ?? null, 'date' => $body['date'] ?? null, 'journal_id' => $body['journal_id'] ?? null, 'status' => 'complete', 'ledger_entry_lines' => []];
                $this->software[] = $entry;

                return 'lost' === $fate ? new MockResponse('', ['http_code' => 503]) : $json($entry, 201);
            }
            if ('POST' === $method && '/suppliers' === $path && 'down' === $this->onSupplier) {
                $this->onSupplier = null;

                return new MockResponse('', ['http_code' => 503]);
            }
            if (\in_array($method, ['POST', 'PUT'], true) && str_starts_with($path, '/suppliers')) {
                return $json(['id' => 'PUT' === $method ? (int) substr($path, \strlen('/suppliers/')) : 3301, 'name' => $body['name'] ?? '', 'external_reference' => $body['external_reference'] ?? null, 'ledger_account' => ['id' => 4011, 'number' => $body['ledger_account']['number'] ?? null]], 'POST' === $method ? 201 : 200);
            }

            return $json(['error' => 'unexpected '.$method.' '.$path], 404);
        });

        return new Registry([new PennylaneGatewayFactory($http)], ['accountant' => ['factory' => 'pennylane', 'options' => ['token' => 'pl-company-token']]]);
    }

    /** @return list<array{string, string, mixed}> the writes: what reached the software */
    private function writes(): array
    {
        return array_values(array_filter($this->calls, static fn (array $c) => 'GET' !== $c[0]));
    }

    private function item(string $kind, string $key): ?AccountingExportItem
    {
        return array_values(array_filter($this->items, static fn (AccountingExportItem $i) => $i->getKind() === $kind && $i->getRecordKey() === $key))[0] ?? null;
    }

    public function testContactsFirstThenTheEntriesAndASecondExportSendsNothingAgain(): void
    {
        $exporter = $this->exporter($this->registry());
        self::assertSame('accountant', $exporter->accountingGateway(), 'the first gateway keeping journal entries');

        $run = $exporter->export($this->book, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), null, 'gestion');

        self::assertSame([ExportState::DONE, 1, 2, 0, 2], [$run->getState(), $run->getContacts(), $run->getEntries(), $run->getSkipped(), $run->getRefused()]);
        $writes = $this->writes();
        // The supplier first, with its SIREN and auxiliary account; the tenant refused (Pennylane wants a customer's billing address).
        self::assertSame(['POST', '/suppliers'], [$writes[0][0], $writes[0][1]]);
        self::assertSame(['name' => 'EDF', 'reg_no' => '552081317', 'iban' => 'FR7630004000031234567890143', 'external_reference' => 'siren:552081317', 'ledger_account' => ['number' => $this->edf->getAccountNumber()]], $writes[0][2]);
        $tenant = $this->item(AccountingExportItem::CONTACT, 'U3');
        self::assertSame(ExportState::REFUSED, $tenant?->getState());
        self::assertStringContainsString('billing address', (string) $tenant?->getError());

        // Then the entries: the purchase with its VAT line, the rent notice; the one on an account Pennylane has not, refused.
        self::assertSame(['/ledger_entries', '2026-10-03', 'Facture EDF octobre', 'FA-2026-101', 8002, ['100.00', '20.00', '0.00']], [$writes[1][1], $writes[1][2]['date'], $writes[1][2]['label'], $writes[1][2]['piece_number'], $writes[1][2]['journal_id'], array_column($writes[1][2]['ledger_entry_lines'], 'debit')]);
        self::assertSame(['/ledger_entries', 'Loyer Jean Dupont', 8001], [$writes[2][1], $writes[2][2]['label'], $writes[2][2]['journal_id']]);
        self::assertCount(3, $writes, 'nothing else written: the draft and November left out, 6288 refused before any write');
        $misc = $this->item(AccountingExportItem::ENTRY, 'misc:1');
        self::assertSame(ExportState::REFUSED, $misc?->getState());
        self::assertStringContainsString('6288', (string) $misc?->getError());
        $purchase = $this->item(AccountingExportItem::ENTRY, 'supplier-invoice:7');
        self::assertSame([ExportState::SENT, (string) (610000 + 6), 'FA-2026-101'], [$purchase?->getState(), $purchase?->getReference(), $purchase?->getNumber()]);
        self::assertNull($this->item(AccountingExportItem::ENTRY, 'misc:draft'));

        // Exported again: what went is left alone, the refused are tried again (and refused again).
        $this->calls = [];
        $again = $exporter->export($this->book, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
        self::assertNotSame($run, $again);
        self::assertSame([ExportState::DONE, 0, 0, 3, 2], [$again->getState(), $again->getContacts(), $again->getEntries(), $again->getSkipped(), $again->getRefused()]);
        self::assertSame([], $this->writes(), 'no second write of anything');
    }

    public function testARunThatStoppedIsResumedWithoutSendingTwice(): void
    {
        $exporter = $this->exporter($this->registry(), 'accountant');
        // The second entry never leaves: the connection refused.
        $this->onEntry = [2 => 'refused'];
        $run = $exporter->export($this->book, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));

        self::assertSame(ExportState::FAILED, $run->getState());
        self::assertStringContainsString('pennylane', (string) $run->getError());
        self::assertSame(ExportState::SENT, $this->item(AccountingExportItem::ENTRY, 'supplier-invoice:7')?->getState());
        self::assertSame(ExportState::FAILED, $this->item(AccountingExportItem::ENTRY, 'notice:AVIS-2026-10-U3')?->getState());
        self::assertNull($this->item(AccountingExportItem::ENTRY, 'misc:1'), 'not reached');

        $resumed = $exporter->export($this->book, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
        self::assertSame($run, $resumed, 'the same run, taken up again');
        self::assertSame([ExportState::DONE, 1, 2], [$resumed->getState(), $resumed->getResumed(), $resumed->getEntries()]);
        self::assertSame(ExportState::SENT, $this->item(AccountingExportItem::ENTRY, 'notice:AVIS-2026-10-U3')?->getState());
        self::assertCount(1, $this->runs);
        $posted = array_values(array_filter($this->writes(), static fn (array $c) => '/ledger_entries' === $c[1]));
        self::assertSame(['Facture EDF octobre', 'Loyer Jean Dupont', 'Loyer Jean Dupont'], array_map(static fn (array $c) => $c[2]['label'], $posted), 'the purchase posted once; the notice again only because it never reached the software');
    }

    public function testAnEntryWhoseAnswerWasLostIsLookedUpBeforeItIsSentAgain(): void
    {
        $exporter = $this->exporter($this->registry(), 'accountant');
        $period = [new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')];
        $posts = fn () => array_map(static fn (array $c) => $c[2]['label'], array_values(array_filter($this->writes(), static fn (array $c) => '/ledger_entries' === $c[1])));

        // The purchase taken by Pennylane, its answer lost (a 503 after it was created): the outcome is unknown, the run stops.
        $this->onEntry = [1 => 'lost'];
        $run = $exporter->export($this->book, ...$period);
        $purchase = $this->item(AccountingExportItem::ENTRY, 'supplier-invoice:7');
        self::assertSame([ExportState::FAILED, ExportState::UNKNOWN], [$run->getState(), $purchase?->getState()]);
        self::assertStringContainsString('may have been taken', (string) $purchase?->getError());
        self::assertCount(1, $this->software);

        // Resumed: Pennylane is asked for it by its date, journal, label and piece, and has it - not posted again.
        $this->calls = [];
        $resumed = $exporter->export($this->book, ...$period);
        self::assertSame(ExportState::DONE, $resumed->getState());
        self::assertSame([ExportState::SENT, (string) $this->software[0]['id']], [$purchase->getState(), $purchase->getReference()]);
        self::assertSame(['Loyer Jean Dupont'], $posts(), 'the purchase found, the notice posted');
        self::assertContains(['GET', '/ledger_entries', null], $this->calls);
        self::assertCount(2, $this->software, 'one purchase in the software');

    }

    public function testAnEntryTheSoftwareDoesNotHaveIsSentAgainOnlyOnceItWasAsked(): void
    {
        $exporter = $this->exporter($this->registry(), 'accountant');
        $period = [new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')];
        $posts = fn () => array_map(static fn (array $c) => $c[2]['label'], array_values(array_filter($this->writes(), static fn (array $c) => '/ledger_entries' === $c[1])));

        // A 503 to the purchase, which Pennylane did not take: unknown all the same, looked up, then posted once.
        $this->onEntry = [1 => 'down'];
        $exporter->export($this->book, ...$period);
        self::assertSame(ExportState::UNKNOWN, $this->item(AccountingExportItem::ENTRY, 'supplier-invoice:7')?->getState());
        $exporter->export($this->book, ...$period);
        self::assertSame(ExportState::SENT, $this->item(AccountingExportItem::ENTRY, 'supplier-invoice:7')?->getState());
        self::assertSame(['Facture EDF octobre', 'Facture EDF octobre', 'Loyer Jean Dupont'], $posts(), 'the purchase sent again only after Pennylane said it had none');
        self::assertCount(2, $this->software);
    }

    public function testAContactWhoseAnswerWasLostWaitsForAPerson(): void
    {
        $exporter = $this->exporter($this->registry(), 'accountant');
        $period = [new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31')];
        $suppliers = fn () => \count(array_filter($this->writes(), static fn (array $c) => 'POST' === $c[0] && '/suppliers' === $c[1]));
        $this->onSupplier = 'down';

        $run = $exporter->export($this->book, ...$period);
        $edf = $this->item(AccountingExportItem::CONTACT, 'siren:552081317');
        self::assertSame([ExportState::FAILED, ExportState::UNKNOWN], [$run->getState(), $edf?->getState()]);

        // omnibill cannot look a contact up: the next run leaves it alone, never creating the supplier twice on its own.
        $exporter->export($this->book, ...$period);
        self::assertSame([ExportState::UNKNOWN, 1], [$edf->getState(), $suppliers()]);

        // A person checked Pennylane and found none: sent again by hand.
        $exporter->retry($edf, 'gestion');
        self::assertSame([ExportState::SENT, '3301', 2], [$edf->getState(), $edf->getReference(), $suppliers()]);
    }

    public function testARecordChangedSinceItWentIsFlaggedNotSentAgain(): void
    {
        $exporter = $this->exporter($this->registry());
        $exporter->export($this->book, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
        $this->calls = [];

        // The supplier renamed, the purchase's label corrected in the book after it went.
        $this->edf->setName('EDF Entreprises');
        $purchase = array_values(array_filter($this->entries, static fn ($e) => 'supplier-invoice:7' === $e->getSource()))[0];
        (new \ReflectionProperty($purchase, 'label'))->setValue($purchase, 'Facture EDF octobre (corrigée)');

        $run = $exporter->export($this->book, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
        self::assertSame(2, $run->getChanged());
        self::assertSame([], $this->writes(), 'neither the supplier nor the purchase sent again');
        $contact = $this->item(AccountingExportItem::CONTACT, 'siren:552081317');
        $entry = $this->item(AccountingExportItem::ENTRY, 'supplier-invoice:7');
        self::assertSame([ExportState::CHANGED, ExportState::CHANGED], [$contact?->getState(), $entry?->getState()]);

        // A person updates the supplier (its reference kept: a PUT), and marks the entry corrected by hand.
        $exporter->retry($contact);
        self::assertSame(['PUT', '/suppliers/3301', 'EDF Entreprises'], [end($this->calls)[0], end($this->calls)[1], end($this->calls)[2]['name']]);
        self::assertSame(ExportState::SENT, $contact->getState());
        try {
            $exporter->retry($entry);
            self::fail('A changed entry is never sent twice.');
        } catch (LedgerException) {
        }
        $exporter->acknowledge($entry);
        self::assertSame(ExportState::SENT, $entry->getState());

        $this->calls = [];
        $quiet = $exporter->export($this->book, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
        self::assertSame([0, 3], [$quiet->getChanged(), $quiet->getSkipped()]);
    }
}
