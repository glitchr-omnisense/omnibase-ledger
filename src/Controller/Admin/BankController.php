<?php

namespace Base\Ledger\Controller\Admin;

use Base\Ledger\Bank\Connections;
use Base\Ledger\Bank\StatementUpload;
use Base\Ledger\Bank\Sync;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankConnection;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Reconciliation\Reconciler;
use Base\Ledger\Security\Voter\LedgerVoter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Banks in the back office: connecting one (the bank's or aggregator's
 * consent page, then back here), renewing a consent, syncing an account,
 * and uploading a statement file - after which what is sure is reconciled.
 */
#[IsGranted(LedgerVoter::VIEW)]
#[Route('/admin/ledger/bank')]
class BankController extends AbstractLedgerPageController
{
    public function __construct(
        private readonly Connections $connections,
        private readonly StatementUpload $statements,
        private readonly Sync $sync,
        private readonly Reconciler $reconciler,
    ) {
    }

    #[Route('/{bankAccount}/upload', name: 'ledger_admin_bank_upload', requirements: ['bankAccount' => '\d+'], methods: ['GET', 'POST'])]
    public function upload(Request $request, int $bankAccount): Response
    {
        $account = $this->load(BankAccount::class, $bankAccount, LedgerVoter::MANAGE);

        if ($request->isMethod('POST')) {
            $this->assertPostToken($request, 'ledger_upload_'.$account->getId());
            $file = $request->files->get('statement');
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $this->addFlash('error', 'Choisissez un relevé (CAMT.053, OFX ou CSV).');
            } else {
                try {
                    $created = $this->statements->upload($account, $file->getClientOriginalName(), (string) file_get_contents($file->getPathname()));
                    $reconciled = $this->reconciler->autoReconcile($account);
                    $this->addFlash('success', sprintf('%s : %d nouvelle(s) ligne(s), %d rapprochée(s) automatiquement.', $file->getClientOriginalName(), $created, $reconciled));

                    return $this->redirectToRoute('ledger_admin_reconcile', ['bankAccount' => $account->getId()]);
                } catch (LedgerException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        return $this->page('@Ledger/admin/upload.html.twig', ['account' => $account, 'available' => $this->connections->isAvailable()]);
    }

    /**
     * Connecting a bank: for statement files, the account's name and IBAN
     * (nothing to consent to); for a bank API or an aggregator, off to its
     * consent page, back on ledger_admin_bank_return.
     */
    #[Route('/connect/{gateway}', name: 'ledger_admin_bank_connect', requirements: ['gateway' => '[a-z0-9_\-]+'], methods: ['GET', 'POST'])]
    public function connect(Request $request, string $gateway): Response
    {
        $book = $this->bookFrom($request, LedgerVoter::MANAGE);
        if (!\in_array($gateway, $this->connections->gateways(), true)) {
            $this->addFlash('error', $this->connections->isAvailable()
                ? sprintf('Aucune passerelle « %s » n\'est configurée (omnibank.gateways).', $gateway)
                : 'Les flux bancaires demandent glitchr/omnibank.');

            return $this->back($request);
        }

        if (StatementUpload::GATEWAY === $gateway) {
            if (!$request->isMethod('POST')) {
                return $this->page('@Ledger/admin/connect_files.html.twig', ['book' => $book]);
            }
            $this->assertPostToken($request, 'ledger_bank_connect');
            $name = trim((string) $request->request->get('name')) ?: 'Compte bancaire';
            $iban = trim((string) $request->request->get('iban')) ?: null;
            try {
                $connection = $this->connections->start($book, $gateway, $this->generateUrl('admin', [], UrlGeneratorInterface::ABSOLUTE_URL), $name)['connection'];
                $account = $this->connections->attach($connection, 'files-'.bin2hex(random_bytes(6)), $name, $iban, $book->getCurrency());
                $this->entityManager->flush();
            } catch (LedgerException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->page('@Ledger/admin/connect_files.html.twig', ['book' => $book]);
            }

            return $this->redirectToRoute('ledger_admin_bank_upload', ['bankAccount' => $account->getId()]);
        }

        if (!$this->isCsrfTokenValid('ledger_bank_connect', (string) ($request->request->get('_token') ?? $request->query->get('_token')))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
        try {
            $started = $this->connections->start($book, $gateway, fn (BankConnection $connection) => $this->generateUrl('ledger_admin_bank_return', ['connection' => $connection->getId()], UrlGeneratorInterface::ABSOLUTE_URL), ucfirst($gateway));
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->back($request);
        }
        if (null !== $started['url']) {
            return $this->redirect($started['url']);
        }
        $this->addFlash('success', sprintf('%s connecté : %d compte(s).', ucfirst($gateway), $started['connection']->getAccounts()->count()));

        return $this->redirectToRoute('admin');
    }

    /** Back from the bank's consent page: the gateway finishes the connection, the accounts are discovered. */
    #[Route('/return/{connection}', name: 'ledger_admin_bank_return', requirements: ['connection' => '\d+'], methods: ['GET'])]
    public function return(Request $request, int $connection): RedirectResponse
    {
        $bankConnection = $this->load(BankConnection::class, $connection, LedgerVoter::MANAGE);
        try {
            $next = $this->connections->complete($bankConnection, $this->generateUrl('ledger_admin_bank_return', ['connection' => $bankConnection->getId()], UrlGeneratorInterface::ABSOLUTE_URL), $request->query->all());
            if (null !== $next) {
                return $this->redirect($next);
            }
            $this->addFlash('success', sprintf('%s connecté : %d compte(s).', $bankConnection, $bankConnection->getAccounts()->count()));
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin');
    }

    #[Route('/connection/{connection}/renew', name: 'ledger_admin_bank_renew', requirements: ['connection' => '\d+'], methods: ['GET'])]
    public function renew(Request $request, int $connection): RedirectResponse
    {
        $bankConnection = $this->load(BankConnection::class, $connection, LedgerVoter::MANAGE);
        $this->assertLinkToken($request, 'ledger_bank_renew', $bankConnection->getId());
        try {
            $next = $this->connections->renew($bankConnection, $this->generateUrl('ledger_admin_bank_return', ['connection' => $bankConnection->getId()], UrlGeneratorInterface::ABSOLUTE_URL));
            if (null !== $next) {
                return $this->redirect($next);
            }
            $this->addFlash('success', sprintf('%s : consentement à jour.', $bankConnection));
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($request);
    }

    /**
     * Removes a connection left unfinished on the provider's page: no
     * consent given, no account (BankConnection::isUnfinished()). One that
     * holds accounts is never removed from here. POST, with its token.
     */
    #[Route('/connection/{connection}/remove', name: 'ledger_admin_bank_remove', requirements: ['connection' => '\d+'], methods: ['POST'])]
    public function remove(Request $request, int $connection): RedirectResponse
    {
        $bankConnection = $this->load(BankConnection::class, $connection, LedgerVoter::MANAGE);
        if (!$this->isCsrfTokenValid('ledger_bank_remove_'.$bankConnection->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
        if (!$bankConnection->isUnfinished()) {
            $this->addFlash('error', sprintf('%s : seule une connexion jamais finalisée, sans compte, se supprime ici.', $bankConnection));

            return $this->back($request);
        }
        $this->connections->remove($bankConnection);
        $this->addFlash('success', sprintf('%s : connexion inachevée supprimée.', $bankConnection));

        return $this->back($request);
    }

    #[Route('/{bankAccount}/sync', name: 'ledger_admin_bank_sync', requirements: ['bankAccount' => '\d+'], methods: ['GET'])]
    public function sync(Request $request, int $bankAccount): RedirectResponse
    {
        $account = $this->load(BankAccount::class, $bankAccount, LedgerVoter::MANAGE);
        $this->assertLinkToken($request, 'ledger_bank_sync', $account->getId());
        try {
            $created = $this->sync->sync($account);
            $reconciled = $this->reconciler->autoReconcile($account);
            $this->addFlash('success', sprintf('%s : %d nouvelle(s) ligne(s), %d rapprochée(s) automatiquement.', $account->getName(), $created, $reconciled));
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($request);
    }
}
