<?php

namespace Tests\Base\Ledger\Omnibill;

use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\VatRegime;
use Base\Ledger\Event\BankLineReconciled;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Omnibill\Entity\SupplierInvoice;
use Base\Ledger\Omnibill\Enum\SupplierInvoiceStatus;
use Base\Ledger\Omnibill\Invoice\PaidInvoiceListener;
use Base\Ledger\Reconciliation\Reconciler;
use Omnibill\Afnor\AfnorGatewayFactory;
use Omnibill\Model\Flow;
use Omnibill\Model\StatusChange;
use Omnibill\Registry;
use Omnibill\Writer\LifecycleMessageWriterInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Suppliers' invoices through omnibill's afnor gateway - the approved
 * platform's flow service, XP Z12-013, answered by a MockHttpClient that
 * records each request - or dropped by hand: read, never proposed twice,
 * booked only by a person, refused with a reason, paid by a bank line,
 * each step declared back to the platform; only the invoices read from the
 * inbox; a status whose answer was lost left to a person.
 */
class SupplierInvoicesTest extends InMemoryOmnibill
{
    private const FLOW_URL = 'https://api.flow.pa.example/flow-service';

    /** @var list<array{string, string, string}> method, URL, body */
    private array $calls = [];
    /** @var array<string, string> flow id => the invoice file the platform holds */
    private array $flows = [];
    /** @var list<string> the flows the next search lists: a supplier's invoice, or "lc-..." a status about one of the application's own */
    private array $inbox = [];
    private bool $down = false;
    /** The next status posted is taken by the platform, then answered 503. */
    private bool $lostStatus = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->book->setVatRegime(VatRegime::NORMAL_MONTHLY);
        $this->frenchTaxes();
        $this->account('6061', 'Fournitures non stockables (eau, énergie)');
        $this->account('606', 'Achats non stockés');
    }

    private function registry(): Registry
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = (string) ($options['body'] ?? '');
            $this->calls[] = [$method, $url, $body];
            $path = (string) parse_url($url, \PHP_URL_PATH);
            if (str_ends_with($path, '/token')) {
                return new MockResponse('{"access_token":"t0k3n","token_type":"Bearer","expires_in":3600}');
            }
            if ($this->down) {
                return new MockResponse('', ['http_code' => 503]);
            }
            if ('POST' === $method && str_ends_with($path, '/v1/flows/search')) {
                $results = array_map(static fn (string $id) => ['flowId' => $id, 'submittedAt' => '2026-11-03T08:00:00Z', 'updatedAt' => '2026-11-03T08:00:01Z', 'name' => $id.'.xml', 'processingRule' => 'B2B', 'flowSyntax' => str_starts_with($id, 'lc-') ? 'CDAR' : 'CII', 'flowProfile' => 'CIUS', 'flowType' => str_starts_with($id, 'lc-') ? 'CustomerInvoiceLC' : 'SupplierInvoice', 'flowDirection' => 'In'], $this->inbox);
                // The platform filters its search by flowType when asked.
                $types = json_decode($body, true)['where']['flowType'] ?? null;
                $results = array_values(array_filter($results, static fn (array $r) => null === $types || \in_array($r['flowType'], $types, true)));
                $this->inbox = [];

                return new MockResponse((string) json_encode(['limit' => 25, 'results' => $results, 'nextCursor' => [] === $results ? null : 'cursor-'.\count($this->calls)]));
            }
            if ('GET' === $method && preg_match('~/v1/flows/([^/?]+)$~', $path, $m) && isset($this->flows[$m[1]])) {
                return new MockResponse($this->flows[$m[1]]);
            }
            if ('POST' === $method && str_ends_with($path, '/v1/flows')) {
                if ($this->lostStatus) {
                    $this->lostStatus = false;

                    return new MockResponse('', ['http_code' => 503]);
                }

                return new MockResponse((string) json_encode(['flowId' => 'cdar-'.\count($this->calls), 'submittedAt' => '2026-11-03T09:00:00Z', 'flowSyntax' => 'CDAR']), ['http_code' => 202]);
            }

            return new MockResponse('{"errorCode":"NOT_FOUND"}', ['http_code' => 404]);
        });

        return new Registry([new AfnorGatewayFactory($http)], ['platform' => ['factory' => 'afnor', 'options' => [
            'flow_url' => self::FLOW_URL, 'directory_url' => 'https://api.directory.pa.example/directory-service', 'token_url' => 'https://auth.pa.example/token',
            'client_id' => 'sci', 'client_secret' => 's3cret',
        ]]]);
    }

    /**
     * The application's lifecycle message writer (omnibill's extension point): the platform
     * carries a status as a message. A stand-in here that echoes what it is given - the
     * message's real content is the application's, written by a person.
     */
    private function messages(): LifecycleMessageWriterInterface
    {
        return new class implements LifecycleMessageWriterInterface {
            public function write(StatusChange $change, ?Flow $flow = null): StatusChange
            {
                $amounts = implode(',', array_map(static fn (int $rate, string $amount) => $rate.':'.$amount, array_keys($change->amounts), $change->amounts));

                return $change->withFile('<CDAR status="'.$change->status->value.'" invoice="'.$change->invoiceNumber.'" reason="'.$change->reason.'" amount="'.$change->amount.'"'.('' === $amounts ? '' : ' amounts="'.$amounts.'"').' flow="'.$flow?->reference.'"/>', 'CDV-'.$change->status->value.'-'.$change->invoiceNumber.'.xml');
            }
        };
    }

    /** @return list<string> the CDAR bodies posted to the platform, in order */
    private function statusesPosted(): array
    {
        $posted = [];
        foreach ($this->calls as [$method, $url, $body]) {
            if ('POST' === $method && str_ends_with((string) parse_url($url, \PHP_URL_PATH), '/v1/flows') && preg_match('~<CDAR [^>]*/>~', $body, $m)) {
                $posted[] = $m[0];
            }
        }

        return $posted;
    }

    private function edfParty(): \Base\Ledger\Entity\Party
    {
        $edf = $this->party('EDF', PartyKind::SUPPLIER, 'siren:552081317');
        // Its earlier invoice went to 6061: the account proposed next time.
        $this->posting()->post((new EntryDraft($this->book, 'AC', new \DateTimeImmutable('2026-10-03'), 'Facture EDF octobre', 'invoice:edf-10', 'FA-2026-101'))
            ->line('6061', 9000, 0)->line('44566', 1800, 0, tax: 'A20', taxBase: 9000)->line($edf->getAccountNumber(), 0, 10800, $edf));

        return $edf;
    }

    public function testAnInvoiceArrivesThroughThePlatformIsReadAndProposedNotPosted(): void
    {
        $edf = $this->edfParty();
        $before = \count($this->entries);
        $this->flows['f-1'] = Invoices::cii();
        $this->inbox = ['f-1'];

        $report = $this->supplierInvoices($this->registry(), null, $this->messages())->receive();

        self::assertSame(['gateway' => 'platform', 'received' => 1, 'duplicates' => 0, 'unreadable' => 0, 'skipped' => 0, 'error' => null], $report);
        $invoice = $this->supplierInvoices[0];
        self::assertSame(SupplierInvoiceStatus::PROPOSED, $invoice->getStatus());
        self::assertSame(['FA-2026-118', '2026-11-03', '2026-12-03', 'EDF Entreprises', '552081317', 'FR03552081317', 'FR7630004000031234567890143', 10000, 2000, 12000],
            [$invoice->getNumber(), $invoice->getIssuedOn()?->format('Y-m-d'), $invoice->getDueOn()?->format('Y-m-d'), $invoice->getSupplierName(), $invoice->getSupplierSiren(), $invoice->getSupplierVat(), $invoice->getSupplierIban(), $invoice->getTotalExcl(), $invoice->getTotalVat(), $invoice->getTotalIncl()]);
        self::assertSame([['rate' => 2000, 'base' => 10000, 'vat' => 2000, 'category' => 'S']], $invoice->getVat());
        self::assertSame($edf, $invoice->getParty(), 'matched by its SIREN');
        self::assertSame('6061', $invoice->getAccount(), 'the account its earlier invoice went to');
        self::assertSame(['platform', 'f-1'], [$invoice->getGateway(), $invoice->getFlowReference()]);
        self::assertCount($before, $this->entries, 'nothing posted on arrival');
        self::assertStringStartsWith('local:2026/11/2026-11-03 EDF Entreprises FA-2026-118.xml', (string) $invoice->getFile());
        self::assertFileExists($this->receiptsDirectory.'/'.substr((string) $invoice->getFile(), 6));

        // The platform hears it was taken over; the cursor is kept for the next time.
        self::assertSame(['<CDAR status="taken_over" invoice="FA-2026-118" reason="" amount="" flow="f-1"/>'], $this->statusesPosted());
        self::assertTrue($invoice->getStatuses()[0]['sent']);
        self::assertSame('cursor-2', $this->inboxes[0]->getCursor());

        // The proposal, as the screen shows it.
        $proposal = $this->proposer()->lines($invoice, $invoice->getAccount(), $edf->getAccountNumber());
        self::assertSame([['6061', 10000, 0, null], ['44566', 2000, 0, 'A20'], [$edf->getAccountNumber(), 0, 12000, null]], array_map(static fn (array $l) => [$l['account'], $l['debit'], $l['credit'], $l['tax']?->getCode()], $proposal['lines']));
        self::assertSame([], $proposal['warnings']);
    }

    public function testTheSameInvoiceIsNeverProposedTwice(): void
    {
        $this->edfParty();
        $invoices = $this->supplierInvoices($this->registry(), null, $this->messages());
        $this->flows['f-1'] = Invoices::cii();
        $this->inbox = ['f-1'];
        $invoices->receive();

        // The same flow listed again: already there.
        $this->inbox = ['f-1'];
        self::assertSame(1, $invoices->receive()['skipped']);
        // Another flow with the same supplier and number (a deposit made twice): a duplicate, not proposed.
        $this->flows['f-2'] = Invoices::cii(['date' => '2026-11-04']);
        $this->inbox = ['f-2'];
        self::assertSame(1, $invoices->receive()['duplicates']);
        $duplicate = $this->supplierInvoices[1];
        self::assertSame(SupplierInvoiceStatus::DUPLICATE, $duplicate->getStatus());
        self::assertSame($this->supplierInvoices[0], $duplicate->getDuplicateOf());
        self::assertNull($duplicate->getDedupe());
        self::assertSame([], $duplicate->getStatuses(), 'nothing declared for a duplicate');

        // The same file dropped by hand: the invoice already there; another file of the same invoice: a duplicate.
        self::assertSame($this->supplierInvoices[0], $invoices->import(Invoices::cii(), 'FA-2026-118.xml'));
        $pdf = $invoices->import(Invoices::facturX(), 'FA-2026-118.pdf');
        self::assertSame([SupplierInvoiceStatus::DUPLICATE, 'Factur-X'], [$pdf->getStatus(), $pdf->getSyntax()]);
        self::assertCount(3, $this->supplierInvoices);
        self::assertCount(1, array_filter($this->supplierInvoices, static fn (SupplierInvoice $i) => $i->getStatus()->isOpen()));
    }

    public function testBookingPostsTheProposedEntryWhenAPersonSaysSo(): void
    {
        $edf = $this->edfParty();
        $invoices = $this->supplierInvoices($this->registry(), null, $this->messages());
        $this->flows['f-1'] = Invoices::cii();
        $this->inbox = ['f-1'];
        $invoices->receive();
        $invoice = $this->supplierInvoices[0];

        $entry = $invoices->book($invoice, '6061', null, 'gestion');

        self::assertTrue($entry->isValidated());
        self::assertSame(['AC', '2026-11-03', 'Facture EDF Entreprises FA-2026-118', 'FA-2026-118', 'supplier-invoice:'.$invoice->getId()], [$entry->getJournal()->getCode(), $entry->getDate()->format('Y-m-d'), $entry->getLabel(), $entry->getPiece(), $entry->getSource()]);
        self::assertSame([['6061', 10000, 0, null, null, null], ['44566', 2000, 0, null, 'A20', 10000], [$edf->getAccountNumber(), 0, 12000, $edf, null, null]],
            array_map(static fn ($l) => [$l->getAccount()->getNumber(), $l->getDebit(), $l->getCredit(), $l->getParty(), $l->getTax()?->getCode(), $l->getTaxBase()], $entry->getLines()->toArray()));
        self::assertSame([SupplierInvoiceStatus::BOOKED, $entry, 'gestion'], [$invoice->getStatus(), $invoice->getEntry(), $invoice->getDecidedBy()]);
        self::assertSame('<CDAR status="approved" invoice="FA-2026-118" reason="" amount="" flow="f-1"/>', $this->statusesPosted()[1]);

        $this->expectException(LedgerException::class);
        $invoices->book($invoice, '6061');
    }

    public function testASupplierTheBookDoesNotKnowIsCreatedWhenBookedAndAnAccountIsAPersonsChoice(): void
    {
        $invoices = $this->supplierInvoices();
        $invoice = $invoices->import(Invoices::cii(['seller' => 'Plomberie Kléber', 'siren' => '901234567', 'vat_number' => null, 'iban' => null]), 'kleber.xml');

        self::assertSame(SupplierInvoiceStatus::PROPOSED, $invoice->getStatus());
        self::assertNull($invoice->getParty());
        self::assertNull($invoice->getAccount(), 'no history, no default: a person chooses');
        self::assertNull($invoice->getGateway());

        $entry = $invoices->book($invoice, '615');
        $party = $invoice->getParty();
        self::assertSame([PartyKind::SUPPLIER, 'Plomberie Kléber', 'siren:901234567', '401PLOMBERIEK'], [$party?->getKind(), $party?->getName(), $party?->getReference(), $party?->getAccountNumber()]);
        self::assertSame('401PLOMBERIEK', $entry->getLines()->last()->getAccount()->getNumber());
        self::assertSame([], $invoice->getStatuses(), 'dropped by hand: no platform to tell');

        // Next time, the supplier and its account are proposed.
        $next = $invoices->import(Invoices::cii(['seller' => 'Plomberie Kleber SARL', 'siren' => '901234567', 'number' => 'K-77', 'vat_number' => null, 'iban' => null]), 'kleber-2.xml');
        self::assertSame([$party, '615'], [$next->getParty(), $next->getAccount()]);
    }

    public function testARefusalSendsItsReasonAndAStatusThePlatformCouldNotTakeIsSentAgain(): void
    {
        $this->edfParty();
        // No CDAR writer: the platform cannot take the status, it waits in the invoice.
        $this->flows['f-1'] = Invoices::cii();
        $this->inbox = ['f-1'];
        $this->supplierInvoices($this->registry())->receive();
        $invoice = $this->supplierInvoices[0];
        self::assertFalse($invoice->getStatuses()[0]['sent']);
        self::assertStringContainsString('CDAR', (string) $invoice->getStatuses()[0]['error']);

        $invoices = $this->supplierInvoices($this->registry(), null, $this->messages());
        $invoices->refuse($invoice, 'Commande annulée le 2026-10-28', 'gestion');
        self::assertSame([SupplierInvoiceStatus::REFUSED, 'Commande annulée le 2026-10-28'], [$invoice->getStatus(), $invoice->getReason()]);
        self::assertSame(['<CDAR status="refused" invoice="FA-2026-118" reason="Commande annulée le 2026-10-28" amount="" flow="f-1"/>'], $this->statusesPosted());

        self::assertSame(1, $invoices->sendPending(), 'the taken-over status, sent now');
        self::assertSame([true, true], array_column($invoice->getStatuses(), 'sent'));
        self::assertSame(0, $invoices->sendPending());

        $this->expectException(LedgerException::class);
        $invoices->refuse($invoice, 'Encore');
    }

    public function testPaidOnceABankLineSettlesItsEntry(): void
    {
        $edf = $this->edfParty();
        $invoices = $this->supplierInvoices($this->registry(), null, $this->messages());
        $this->flows['f-1'] = Invoices::cii();
        $this->inbox = ['f-1'];
        $invoices->receive();
        $invoice = $this->supplierInvoices[0];
        $invoices->book($invoice, '6061');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BankLineReconciled::class, new PaidInvoiceListener($this->supplierInvoiceRepository(), $invoices));
        $reconciler = new Reconciler($this->entityManager(), $this->posting(), $this->lettering(), $this->matcher(), $this->bankLineRepository(), 0.9, $dispatcher);
        $line = $this->bankLine(-12000, 'PRLV SEPA EDF FA-2026-118', $invoice->getSupplierIban(), 'EDF', 'FA-2026-118', '2026-11-20');
        $suggestion = array_values(array_filter($this->matcher()->suggest($line), static fn ($s) => [] !== $s->openItems && $s->openItems[0]->getEntry() === $invoice->getEntry()))[0];
        $reconciler->apply($line, $suggestion);

        self::assertSame([SupplierInvoiceStatus::PAID, '2026-11-20'], [$invoice->getStatus(), $invoice->getPaidAt()?->format('Y-m-d')]);
        self::assertSame('<CDAR status="paid" invoice="FA-2026-118" reason="" amount="120.00" amounts="2000:120.00" flow="f-1"/>', $this->statusesPosted()[2]);
        self::assertSame([2000 => '120.00'], $invoice->getStatuses()[2]['amounts'], 'the amount paid at each VAT rate, kept with the status');
        // The earlier invoice, not settled, stays as it was: none of the supplier's other entries is touched.
        self::assertCount(1, array_filter($this->supplierInvoices, static fn (SupplierInvoice $i) => SupplierInvoiceStatus::PAID === $i->getStatus()));
        self::assertNotNull($edf);
    }

    public function testOutsideVatTheExpenseIsTheTotalAndAReverseChargeIsSelfAssessed(): void
    {
        $invoices = $this->supplierInvoices();
        $mixed = $invoices->import(Invoices::facturX(['number' => 'DE-55', 'seller' => 'Heizung GmbH', 'siren' => null, 'vat_number' => 'DE123456789', 'iban' => null, 'lines' => [[100.0, 20.0], [50.0, 0.0, 'AE']]]), 'heizung.pdf');
        $supplier = '401HEIZUNG';
        $lines = fn () => array_map(static fn (array $l) => [$l['account'], $l['debit'], $l['credit'], $l['tax']?->getCode()], $this->proposer()->lines($mixed, '615', $supplier)['lines']);

        self::assertSame([['615', 10000, 0, null], ['44566', 2000, 0, 'A20'], ['615', 5000, 0, null], ['44566', 1000, 0, 'AIC20'], ['4452', 0, 1000, 'AIC20'], [$supplier, 0, 17000, null]], $lines());

        $this->book->setVatRegime(VatRegime::NONE);
        self::assertSame([['615', 12000, 0, null], ['615', 5000, 0, null], [$supplier, 0, 17000, null]], $lines(), 'not liable: nothing is deductible');
        self::assertSame('vat:DE123456789|DE-55', $mixed->getDedupe());
    }

    public function testACreditNoteIsTheEntryTheOtherWayRoundAndAnUnreadableFileWaitsForAPerson(): void
    {
        $invoices = $this->supplierInvoices();
        $credit = $invoices->import(Invoices::cii(['number' => 'AV-9', 'type' => '381']), 'av-9.xml');
        self::assertTrue($credit->isCreditNote());
        self::assertSame([['6061', 0, 10000], ['44566', 0, 2000], ['401EDF', 12000, 0]], array_map(static fn (array $l) => [$l['account'], $l['debit'], $l['credit']], $this->proposer()->lines($credit, '6061', '401EDF')['lines']));
        self::assertSame('Avoir EDF Entreprises AV-9', $this->proposer()->label($credit));

        $unreadable = $invoices->import('<?xml version="1.0"?><Invoice>not an invoice</Invoice>', 'scan.xml');
        self::assertSame(SupplierInvoiceStatus::UNREADABLE, $unreadable->getStatus());
        self::assertNotNull($unreadable->getReason());
        $this->expectException(LedgerException::class);
        $invoices->book($unreadable, '615');
    }

    public function testOnlyTheInvoicesAreReadFromTheInbox(): void
    {
        $this->edfParty();
        $this->flows['f-1'] = Invoices::cii();
        // A status about one of the application's own invoices, in the same inbox: another reader's (omnibase/marketplace).
        $this->flows['lc-1'] = '<status/>';
        $this->inbox = ['lc-1', 'f-1'];

        $report = $this->supplierInvoices($this->registry(), null, $this->messages())->receive();

        self::assertSame([1, 0, null], [$report['received'], $report['skipped'], $report['error']]);
        $search = array_values(array_filter($this->calls, static fn (array $c) => str_ends_with($c[1], '/v1/flows/search')))[0];
        self::assertSame(['flowDirection' => ['In'], 'flowType' => ['SupplierInvoice', 'CustomerInvoice']], json_decode($search[2], true)['where']);
        self::assertSame([], array_filter($this->calls, static fn (array $c) => str_contains($c[1], 'lc-1')), 'the status is never downloaded');
        self::assertSame('cursor-2', $this->inboxes[0]->getCursor());
    }

    public function testAStatusWhoseAnswerWasLostWaitsForAPersonAndIsNeverSentAgainOnItsOwn(): void
    {
        $this->edfParty();
        $invoices = $this->supplierInvoices($this->registry(), null, $this->messages());
        $this->flows['f-1'] = Invoices::cii();
        $this->inbox = ['f-1'];
        $this->lostStatus = true;
        $invoices->receive();
        $invoice = $this->supplierInvoices[0];

        // Taken over: posted, its answer lost. It may be on the platform: not sent again by the scheduler.
        self::assertCount(1, $this->statusesPosted());
        $status = $invoice->getStatuses()[0];
        self::assertSame([false, true], [$status['sent'], $status['unknown'] ?? false]);
        self::assertStringContainsString('may have been taken', (string) $status['error']);
        self::assertSame(0, $invoices->sendPending());
        self::assertCount(1, $this->statusesPosted(), 'nothing sent twice');

        // A person found it was not there: sent again, once.
        self::assertTrue($invoices->confirmStatus($invoice, 0, false));
        self::assertCount(2, $this->statusesPosted());
        self::assertSame([true, false], [$invoice->getStatuses()[0]['sent'], $invoice->getStatuses()[0]['unknown']]);
        self::assertFalse($invoices->confirmStatus($invoice, 0, false), 'settled: nothing more to confirm');

        // The approval's answer lost too; the person sees it on the platform: marked sent, nothing posted.
        $this->lostStatus = true;
        $invoices->book($invoice, '6061');
        self::assertCount(3, $this->statusesPosted());
        self::assertTrue($invoices->confirmStatus($invoice, 1, true));
        self::assertCount(3, $this->statusesPosted());
        self::assertSame([true, true], array_column($invoice->getStatuses(), 'sent'));
    }

    public function testThePlatformUnreachableKeepsTheCursorAndSaysSo(): void
    {
        $invoices = $this->supplierInvoices($this->registry(), 'platform');
        $this->down = true;
        $report = $invoices->receive();

        self::assertSame(0, $report['received']);
        self::assertStringContainsString('afnor', (string) $report['error']);
        self::assertNull($this->inboxes[0]->getCursor());
        self::assertNotNull($this->inboxes[0]->getError());

        self::assertStringContainsString('No gateway receives', (string) $this->supplierInvoices()->receive()['error'], 'without omnibill configured');
    }
}
