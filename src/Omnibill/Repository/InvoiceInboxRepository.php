<?php

namespace Base\Ledger\Omnibill\Repository;

use Base\Ledger\Omnibill\Entity\InvoiceInbox;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<InvoiceInbox> */
class InvoiceInboxRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, InvoiceInbox::class);
    }

    public function findOneByGateway(string $gateway): ?InvoiceInbox
    {
        return $this->findOneBy(['gateway' => $gateway]);
    }
}
