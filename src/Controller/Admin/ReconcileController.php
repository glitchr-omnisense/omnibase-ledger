<?php

namespace Base\Ledger\Controller\Admin;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Enum\BankLineStatus;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Reconciliation\Desk;
use Base\Ledger\Reconciliation\Reconciler;
use Base\Ledger\Repository\BankLineRepository;
use Base\Ledger\Repository\PartyRepository;
use Base\Ledger\Repository\ReconciliationRuleRepository;
use Base\Ledger\Security\Voter\LedgerVoter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A bank account's reconciliation: the lines still to place with what they
 * may settle, best first - accept a suggestion, pick the open items (or a
 * rule, or a party) by hand, ignore the line -, and the lines placed
 * lately, which can be undone. Every change is a POST with its token.
 * The day-to-day work has its own screen in the front office
 * (Controller\Front\BankReviewController); this page stays for the back
 * office's monitoring. Both go through Reconciliation\Desk.
 */
#[IsGranted(LedgerVoter::VIEW)]
#[Route('/admin/ledger')]
class ReconcileController extends AbstractLedgerPageController
{
    public function __construct(
        private readonly Reconciler $reconciler,
        private readonly BankLineRepository $bankLines,
        private readonly ReconciliationRuleRepository $rules,
        private readonly PartyRepository $parties,
        private readonly Desk $desk,
    ) {
    }

    #[Route('/reconcile/{bankAccount}', name: 'ledger_admin_reconcile', requirements: ['bankAccount' => '\d+'], methods: ['GET'])]
    public function index(int $bankAccount): Response
    {
        $account = $this->load(BankAccount::class, $bankAccount);
        $book = $account->getBook();
        $pending = [];
        foreach ($this->bankLines->pending($account) as $line) {
            $pending[] = [
                'line' => $line,
                'suggestions' => $this->desk->suggestions($line),
                'candidates' => $this->desk->candidates($line),
            ];
        }

        return $this->page('@Ledger/admin/reconcile.html.twig', [
            'account' => $account,
            'book' => $book,
            'pending' => $pending,
            'done' => array_values(array_filter($this->bankLines->latest($account, 40), fn (BankLine $line) => !$line->getStatus()->isPending())),
            'rules' => $this->rules->forBook($book),
            'parties' => $this->parties->findBy(['book' => $book], ['name' => 'ASC']),
            'threshold' => $this->reconciler->getThreshold(),
            'can_manage' => $this->isGranted(LedgerVoter::MANAGE, $book),
        ]);
    }

    /** A suggestion accepted, or the open items / rule / party picked by hand. */
    #[Route('/reconcile/line/{line}/apply', name: 'ledger_admin_reconcile_apply', requirements: ['line' => '\d+'], methods: ['POST'])]
    public function apply(Request $request, int $line): RedirectResponse
    {
        $bankLine = $this->load(BankLine::class, $line, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_line_'.$bankLine->getId());
        // What was picked, each of the line's book (Desk::manual() refuses another's); an empty select is none.
        $suggestion = $this->desk->manual($bankLine, $request->request->all('items'), (int) $request->request->get('rule'), (int) $request->request->get('party'));

        try {
            $entry = $this->desk->accept($bankLine, $suggestion);
            $this->addFlash('success', sprintf('%s : passée en %s (écriture %s).', $bankLine->getLabel(), $entry->getJournal()->getCode(), $entry->getNumber()));
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->toPage($bankLine);
    }

    #[Route('/reconcile/line/{line}/ignore', name: 'ledger_admin_reconcile_ignore', requirements: ['line' => '\d+'], methods: ['POST'])]
    public function ignore(Request $request, int $line): RedirectResponse
    {
        $bankLine = $this->load(BankLine::class, $line, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_line_'.$bankLine->getId());
        try {
            $this->desk->ignore($bankLine);
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->toPage($bankLine);
    }

    #[Route('/reconcile/line/{line}/undo', name: 'ledger_admin_reconcile_undo', requirements: ['line' => '\d+'], methods: ['POST'])]
    public function undo(Request $request, int $line): RedirectResponse
    {
        $bankLine = $this->load(BankLine::class, $line, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_line_'.$bankLine->getId());
        try {
            $wasReconciled = BankLineStatus::RECONCILED === $bankLine->getStatus();
            $this->desk->undo($bankLine);
            $this->addFlash('success', $wasReconciled ? sprintf('%s : écriture extournée, ligne à rapprocher.', $bankLine->getLabel()) : sprintf('%s : ligne à rapprocher.', $bankLine->getLabel()));
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->toPage($bankLine);
    }

    #[Route('/reconcile/{bankAccount}/auto', name: 'ledger_admin_reconcile_auto', requirements: ['bankAccount' => '\d+'], methods: ['POST'])]
    public function auto(Request $request, int $bankAccount): RedirectResponse
    {
        $account = $this->load(BankAccount::class, $bankAccount, LedgerVoter::MANAGE);
        $this->assertPostToken($request, 'ledger_reconcile_auto_'.$account->getId());
        $applied = $this->reconciler->autoReconcile($account);
        $this->addFlash($applied > 0 ? 'success' : 'info', sprintf('%d ligne(s) rapprochée(s) automatiquement.', $applied));

        return $this->redirectToRoute('ledger_admin_reconcile', ['bankAccount' => $account->getId()]);
    }

    private function toPage(BankLine $line): RedirectResponse
    {
        return $this->redirectToRoute('ledger_admin_reconcile', ['bankAccount' => $line->getBankAccount()->getId(), '_fragment' => 'line-'.$line->getId()]);
    }
}
