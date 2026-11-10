<?php

namespace Base\Ledger\Omnibill\Repository;

use Base\Ledger\Omnibill\Entity\InvoiceInbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InvoiceInbox> */
class InvoiceInboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceInbox::class);
    }

    public function findOneByGateway(string $gateway): ?InvoiceInbox
    {
        return $this->findOneBy(['gateway' => $gateway]);
    }
}
