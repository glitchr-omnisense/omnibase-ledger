<?php

namespace Base\Ledger\Controller\Admin;

use Base\Ledger\Entity\Party;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Omnibill\Entity\SupplierInvoice;
use Base\Ledger\Omnibill\Enum\SupplierInvoiceStatus;
use Base\Ledger\Omnibill\Invoice\InvoiceProposer;
use Base\Ledger\Omnibill\Invoice\SupplierInvoices;
use Base\Ledger\Omnibill\Reading\InvoiceReader;
use Base\Ledger\Omnibill\Repository\SupplierInvoiceRepository;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\PartyRepository;
use Base\Ledger\Security\Voter\LedgerVoter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The suppliers' invoices in the back office: those received through
 * omnisense/omnibill or dropped here, each proposed as an entry - the
 * supplier, the account, the VAT lines - for a person to book or refuse.
 * Without omnibill the pages say so; everything is done by
 * Omnibill\Invoice\SupplierInvoices.
 */
#[IsGranted(LedgerVoter::VIEW)]
#[Route('/admin/ledger/invoices')]
class SupplierInvoiceController extends AbstractLedgerPageController
{
    /** The tabs of the list: their statuses. */
    private const TABS = [
        'open' => [SupplierInvoiceStatus::PROPOSED, SupplierInvoiceStatus::UNREADABLE],
        'booked' => [SupplierInvoiceStatus::BOOKED],
        'paid' => [SupplierInvoiceStatus::PAID],
        'refused' => [SupplierInvoiceStatus::REFUSED],
        'duplicate' => [SupplierInvoiceStatus::DUPLICATE],
    ];

    public function __construct(
        private readonly ?SupplierInvoices $invoices = null,
        private readonly ?SupplierInvoiceRepository $repository = null,
        private readonly ?InvoiceProposer $proposer = null,
        private readonly ?AccountRepository $accounts = null,
        private readonly ?PartyRepository $parties = null,
        private readonly ?ValidatorInterface $validator = null,
    ) {
    }

    #[Route('', name: 'ledger_admin_invoices', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $book = $this->bookFrom($request);
        if (null === $this->invoices || null === $this->repository) {
            return $this->page('@Ledger/admin/invoices/index.html.twig', ['book' => $book, 'available' => false]);
        }
        $tab = \array_key_exists((string) $request->query->get('tab'), self::TABS) ? (string) $request->query->get('tab') : 'open';

        return $this->page('@Ledger/admin/invoices/index.html.twig', [
            'book' => $book,
            'available' => true,
            'readable' => InvoiceReader::available(),
            'gateway' => $this->invoices->receivingGateway(),
            'tab' => $tab,
            'tabs' => array_keys(self::TABS),
            'counts' => $this->repository->counts($book),
            'invoices' => $this->repository->forBook($book, self::TABS[$tab]),
        ]);
    }

    /** A file a supplier mailed: Factur-X, CII or UBL. */
    #[Route('/upload', name: 'ledger_admin_invoices_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $book = $this->bookFrom($request, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_invoices_upload_'.$book->getId());
        $invoices = $this->available();
        $file = $request->files->get('invoice');
        $violations = $this->validator?->validate($file, [new Assert\NotNull(message: 'Choisissez une facture (Factur-X, CII ou UBL).'), new Assert\File(maxSize: '20M', extensions: ['pdf', 'xml'], extensionsMessage: 'Une facture électronique est un PDF Factur-X ou un XML (CII, UBL).')]);
        if (!$file instanceof UploadedFile || !$file->isValid() || \count($violations ?? []) > 0) {
            $this->addFlash('error', null !== $violations && \count($violations) > 0 ? (string) $violations->get(0)->getMessage() : 'Choisissez une facture (Factur-X, CII ou UBL).');

            return $this->redirectToRoute('ledger_admin_invoices', ['book' => $book->getId()]);
        }

        try {
            $invoice = $invoices->import((string) file_get_contents($file->getPathname()), $file->getClientOriginalName(), $book);
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('ledger_admin_invoices', ['book' => $book->getId()]);
        }
        $this->addFlash(SupplierInvoiceStatus::PROPOSED === $invoice->getStatus() ? 'success' : 'warning', match ($invoice->getStatus()) {
            SupplierInvoiceStatus::DUPLICATE => sprintf('Cette facture a déjà été reçue (%s) : elle n\'est pas proposée une seconde fois.', $invoice->getDuplicateOf() ?? $invoice),
            SupplierInvoiceStatus::UNREADABLE => sprintf('La facture n\'a pas pu être lue : %s', $invoice->getReason()),
            SupplierInvoiceStatus::PROPOSED => sprintf('%s : proposée en écriture, à vérifier.', $invoice),
            default => sprintf('%s : %s.', $invoice, $invoice->getStatus()->value),
        });

        return $this->redirectToRoute('ledger_admin_invoice', ['id' => ($invoice->getDuplicateOf() ?? $invoice)->getId()]);
    }

    /** What the receiving gateway has, now (the scheduler runs ledger:invoices:receive). */
    #[Route('/receive', name: 'ledger_admin_invoices_receive', methods: ['POST'])]
    public function receive(Request $request): Response
    {
        $book = $this->bookFrom($request, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_invoices_receive_'.$book->getId());
        $report = $this->available()->receive();
        $sent = $this->available()->sendPending();
        null !== $report['error']
            ? $this->addFlash('error', $report['error'])
            : $this->addFlash('success', sprintf('%d facture(s) reçue(s), %d doublon(s), %d illisible(s) ; %d statut(s) transmis.', $report['received'], $report['duplicates'], $report['unreadable'], $sent));

        return $this->redirectToRoute('ledger_admin_invoices', ['book' => $book->getId()]);
    }

    #[Route('/{id}', name: 'ledger_admin_invoice', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $invoices = $this->available();
        $invoice = $this->load(SupplierInvoice::class, $id);
        $supplierAccount = $invoice->getParty()?->getAccountNumber() ?? '401'.InvoiceProposer::accountSuffix($invoice->getSupplierName() ?? '');

        return $this->page('@Ledger/admin/invoices/show.html.twig', [
            'book' => $invoice->getBook(),
            'invoice' => $invoice,
            'proposal' => $this->proposer?->lines($invoice, $invoice->getAccount(), $supplierAccount) ?? ['lines' => [], 'warnings' => []],
            'credit_note' => $this->proposer?->isCreditNote($invoice) ?? false,
            'chart' => array_values(array_filter($this->accounts?->chart($invoice->getBook()) ?? [], static fn ($a) => $a->isUnder('6') || $a->isUnder('2'))),
            'suppliers' => $this->parties?->ofKind($invoice->getBook(), PartyKind::SUPPLIER) ?? [],
            // The statuses whose outcome only a person can settle; the others are looked up by the next pass.
            'awaiting' => $invoices->awaitingConfirmation($invoice),
        ]);
    }

    /** Booked by a person: the account chosen, the supplier matched or created, the entry posted and validated. */
    #[Route('/{id}/book', name: 'ledger_admin_invoice_book', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function book(Request $request, int $id): Response
    {
        $invoices = $this->available();
        $invoice = $this->load(SupplierInvoice::class, $id, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_invoice_'.$invoice->getId());

        $account = strtoupper(trim((string) $request->request->get('account')));
        $date = (string) $request->request->get('date');
        $violations = $this->validator?->validate(['account' => $account, 'date' => $date], new Assert\Collection([
            'account' => [new Assert\NotBlank(message: 'Choisissez le compte de charge.'), new Assert\Regex('/^[0-9][0-9A-Z]{1,19}$/', message: 'Un numéro de compte : 606, 6155...')],
            'date' => [new Assert\Date(message: 'Une date : 2026-11-03.')],
        ]));
        if (null !== $violations && \count($violations) > 0) {
            $this->addFlash('error', (string) $violations->get(0)->getMessage());

            return $this->redirectToRoute('ledger_admin_invoice', ['id' => $invoice->getId()]);
        }
        $party = null;
        if ('' !== ($partyId = (string) $request->request->get('party')) && 'new' !== $partyId) {
            $party = $this->entityManager->find(Party::class, (int) $partyId);
            if (!$party instanceof Party || $party->getBook() !== $invoice->getBook()) {
                throw $this->createNotFoundException();
            }
        }

        if ('new' === $partyId) {
            // A supplier of its own, made from the invoice, rather than the one matched.
            $invoice->setParty(null);
        }

        try {
            $entry = $invoices->book($invoice, $account, $party, $this->getUser()?->getUserIdentifier(), '' === $date ? null : new \DateTimeImmutable($date));
            $this->addFlash('success', sprintf('%s : écriture %s passée au journal %s.', $invoice, $entry->getNumber(), $entry->getJournal()->getCode()));
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('ledger_admin_invoice', ['id' => $invoice->getId()]);
    }

    #[Route('/{id}/refuse', name: 'ledger_admin_invoice_refuse', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refuse(Request $request, int $id): Response
    {
        $invoices = $this->available();
        $invoice = $this->load(SupplierInvoice::class, $id, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_invoice_'.$invoice->getId());
        $reason = trim((string) $request->request->get('reason'));
        $violations = $this->validator?->validate($reason, [new Assert\NotBlank(message: 'Dites pourquoi la facture est refusée.'), new Assert\Length(max: 255)]);
        if (null !== $violations && \count($violations) > 0) {
            $this->addFlash('error', (string) $violations->get(0)->getMessage());

            return $this->redirectToRoute('ledger_admin_invoice', ['id' => $invoice->getId()]);
        }

        try {
            $invoices->refuse($invoice, $reason, $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('%s refusée.', $invoice));
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('ledger_admin_invoice', ['id' => $invoice->getId()]);
    }

    /**
     * A status sent whose outcome is unknown and that the platform cannot settle, settled by a
     * person who looked on the platform: « taken » (it is there) marks it sent; otherwise it is
     * sent again now. One the next pass looks up by its key is not a person's to settle.
     */
    #[Route('/{id}/status/{index}', name: 'ledger_admin_invoice_status', requirements: ['id' => '\d+', 'index' => '\d+'], methods: ['POST'])]
    public function confirmStatus(Request $request, int $id, int $index): Response
    {
        $invoices = $this->available();
        $invoice = $this->load(SupplierInvoice::class, $id, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_invoice_'.$invoice->getId());
        $taken = $request->request->getBoolean('taken');
        if (!$invoices->awaitsConfirmation($invoice, $index)) {
            $this->addFlash('error', ($invoice->getStatuses()[$index]['unknown'] ?? false)
                ? 'Ce statut est recherché sur la plateforme par sa clé à chaque passage : rien à confirmer.'
                : 'Ce statut n\'attend pas de vérification.');

            return $this->redirectToRoute('ledger_admin_invoice', ['id' => $invoice->getId()]);
        }
        $invoices->confirmStatus($invoice, $index, $taken)
            ? $this->addFlash('success', $taken ? 'Statut marqué comme reçu par la plateforme.' : 'Statut transmis de nouveau.')
            : $this->addFlash('error', (string) ($invoice->getStatuses()[$index]['error'] ?? 'Ce statut n\'attend pas de vérification.'));

        return $this->redirectToRoute('ledger_admin_invoice', ['id' => $invoice->getId()]);
    }

    #[Route('/{id}/file', name: 'ledger_admin_invoice_file', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(int $id): Response
    {
        $invoices = $this->available();
        $invoice = $this->load(SupplierInvoice::class, $id);
        $stream = $invoices->open($invoice) ?? throw $this->createNotFoundException('The file is gone.');

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', 'Factur-X' === $invoice->getSyntax() ? 'application/pdf' : 'application/xml');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $invoice->getFilename(), 'facture.'.('Factur-X' === $invoice->getSyntax() ? 'pdf' : 'xml')));
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function available(): SupplierInvoices
    {
        return $this->invoices ?? throw $this->createNotFoundException('The suppliers\' invoices need omnisense/omnibill.');
    }
}
