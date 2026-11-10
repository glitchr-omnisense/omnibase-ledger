<?php

namespace Base\Ledger\Controller\Admin;

use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Omnibill\Entity\AccountingExport;
use Base\Ledger\Omnibill\Entity\AccountingExportItem;
use Base\Ledger\Omnibill\Enum\ExportState;
use Base\Ledger\Omnibill\Export\AccountingExporter;
use Base\Ledger\Omnibill\Repository\AccountingExportItemRepository;
use Base\Ledger\Omnibill\Repository\AccountingExportRepository;
use Base\Ledger\Repository\FiscalYearRepository;
use Base\Ledger\Security\Voter\LedgerVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The books handed to the accountant's software (omnisense/omnibill's
 * odoo, pennylane): a period exported, the runs and what each sent, what
 * waits for a person - a record refused, one changed since it went. The
 * work is Omnibill\Export\AccountingExporter's; the same as
 * ledger:accounting:export.
 */
#[IsGranted(LedgerVoter::VIEW)]
#[Route('/admin/ledger/accounting')]
class AccountingExportController extends AbstractLedgerPageController
{
    public function __construct(
        private readonly FiscalYearRepository $fiscalYears,
        private readonly ?AccountingExporter $exporter = null,
        private readonly ?AccountingExportRepository $runs = null,
        private readonly ?AccountingExportItemRepository $items = null,
        private readonly ?ValidatorInterface $validator = null,
    ) {
    }

    #[Route('', name: 'ledger_admin_accounting', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $book = $this->bookFrom($request);
        if (null === $this->exporter || null === $this->runs || null === $this->items) {
            return $this->page('@Ledger/admin/accounting/index.html.twig', ['book' => $book, 'available' => false]);
        }
        $year = $this->fiscalYears->current($book);

        return $this->page('@Ledger/admin/accounting/index.html.twig', [
            'book' => $book,
            'available' => true,
            'gateway' => $this->exporter->accountingGateway(),
            'from' => new \DateTimeImmutable('first day of last month'),
            'to' => new \DateTimeImmutable('last day of last month'),
            'year' => $year,
            'runs' => $this->runs->recent($book),
            'attention' => $this->items->attention($book),
        ]);
    }

    #[Route('/export', name: 'ledger_admin_accounting_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        $exporter = $this->available();
        $book = $this->bookFrom($request, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_accounting_export_'.$book->getId());
        $period = ['from' => (string) $request->request->get('from'), 'to' => (string) $request->request->get('to')];
        $violations = $this->validator?->validate($period, new Assert\Collection([
            'from' => [new Assert\NotBlank(message: 'Le premier jour de la période.'), new Assert\Date()],
            'to' => [new Assert\NotBlank(message: 'Le dernier jour de la période.'), new Assert\Date()],
        ]));
        if ((null !== $violations && \count($violations) > 0) || $period['to'] < $period['from']) {
            $this->addFlash('error', null !== $violations && \count($violations) > 0 ? (string) $violations->get(0)->getMessage() : 'La période finit avant de commencer.');

            return $this->redirectToRoute('ledger_admin_accounting', ['book' => $book->getId()]);
        }

        try {
            $run = $exporter->export($book, new \DateTimeImmutable($period['from']), new \DateTimeImmutable($period['to']), null, $this->getUser()?->getUserIdentifier());
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('ledger_admin_accounting', ['book' => $book->getId()]);
        }
        ExportState::FAILED === $run->getState()
            ? $this->addFlash('error', sprintf('Export interrompu : %s Le prochain export de la période le reprendra là où il s\'est arrêté.', $run->getError()))
            : $this->addFlash($run->getRefused() + $run->getChanged() > 0 ? 'warning' : 'success', sprintf('%d tiers et %d écriture(s) transmis, %d déjà présents, %d refusé(s), %d modifié(s) depuis leur envoi.', $run->getContacts(), $run->getEntries(), $run->getSkipped(), $run->getRefused(), $run->getChanged()));

        return $this->redirectToRoute('ledger_admin_accounting_run', ['id' => $run->getId()]);
    }

    #[Route('/run/{id}', name: 'ledger_admin_accounting_run', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function run(int $id): Response
    {
        $this->available();
        $run = $this->load(AccountingExport::class, $id);

        return $this->page('@Ledger/admin/accounting/run.html.twig', ['book' => $run->getBook(), 'run' => $run, 'items' => $this->items?->forRun($run) ?? []]);
    }

    /** A refused or failed record sent again, a changed contact updated in the software. */
    #[Route('/item/{id}/retry', name: 'ledger_admin_accounting_retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retry(Request $request, int $id): Response
    {
        $exporter = $this->available();
        $item = $this->load(AccountingExportItem::class, $id, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_accounting_item_'.$item->getId());
        try {
            $exporter->retry($item, $this->getUser()?->getUserIdentifier());
            ExportState::SENT === $item->getState()
                ? $this->addFlash('success', sprintf('%s transmis.', $item->getRecordKey()))
                : $this->addFlash('error', sprintf('%s : %s', $item->getRecordKey(), $item->getError()));
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($request, $this->generateUrl('ledger_admin_accounting', ['book' => $item->getBook()->getId()]));
    }

    /** A changed entry corrected in the software by hand: what the book says now is taken as sent. */
    #[Route('/item/{id}/acknowledge', name: 'ledger_admin_accounting_acknowledge', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function acknowledge(Request $request, int $id): Response
    {
        $exporter = $this->available();
        $item = $this->load(AccountingExportItem::class, $id, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_accounting_item_'.$item->getId());
        $exporter->acknowledge($item);
        $this->addFlash('success', sprintf('%s marqué comme corrigé dans le logiciel comptable.', $item->getRecordKey()));

        return $this->back($request, $this->generateUrl('ledger_admin_accounting', ['book' => $item->getBook()->getId()]));
    }

    private function available(): AccountingExporter
    {
        return $this->exporter ?? throw $this->createNotFoundException('Exporting the books needs omnisense/omnibill.');
    }
}
