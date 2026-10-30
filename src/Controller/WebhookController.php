<?php

namespace Base\Ledger\Controller;

use Base\Ledger\Bank\Connections;
use Base\Ledger\Bank\Sync;
use Base\Ledger\Enum\ConsentState;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Reconciliation\Reconciler;
use Doctrine\ORM\EntityManagerInterface;
use Omnibank\Exception\InvalidNotificationException;
use Omnibank\Model\ConsentStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where a bank aggregator tells us something changed (Powens, Bridge):
 * public, checked by the gateway against its signature (notify()). The
 * connection it is about gets its consent updated, then its accounts
 * synced and reconciled.
 */
class WebhookController extends AbstractController
{
    public function __construct(
        private readonly Connections $connections,
        private readonly Sync $sync,
        private readonly Reconciler $reconciler,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/ledger/bank/{gateway}/webhook', name: 'ledger_bank_webhook', requirements: ['gateway' => '[a-z0-9_\-]+'], methods: ['POST'])]
    public function __invoke(Request $request, string $gateway): Response
    {
        if (!\in_array($gateway, $this->connections->gateways(), true)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        try {
            $notification = $this->connections->gateway($gateway)->notify($request->getContent(), $request->headers->all());
        } catch (InvalidNotificationException $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $connection = $this->connections->findByProviderId($gateway, $notification->connectionId);
        if (null === $connection) {
            return new Response('', Response::HTTP_ACCEPTED); // not one of ours, or not connected yet
        }

        if (null !== $notification->state) {
            $connection->setConnectionState($notification->state);
            $this->entityManager->flush();
        }

        if (ConsentStatus::NONE !== $notification->consent->status) {
            $connection->setConsentStatus(ConsentState::of($notification->consent->status));
            $connection->setExpiresAt($notification->consent->expiresAt ?? $connection->getExpiresAt());
            $omnibank = $this->connections->open($connection);
            $this->connections->store($connection, $omnibank->withConsent($notification->consent));
            $this->entityManager->flush();
        }

        if (!$connection->needsRenewal()) {
            foreach ($connection->getAccounts() as $account) {
                try {
                    $this->sync->sync($account);
                    $this->reconciler->autoReconcile($account);
                } catch (LedgerException) {
                    // Recorded on the connection (lastError): the provider needs no more than a 2xx.
                }
            }
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
