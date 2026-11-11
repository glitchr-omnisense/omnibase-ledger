<?php

namespace Base\Ledger\Omnibill\Entity;

use Base\Ledger\Omnibill\Repository\InvoiceInboxRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where receiving stopped on a gateway: the cursor its last receive() of the
 * suppliers' invoices gave (a cursor belongs to the kinds of flows it was
 * read with: the statuses are another reader's, with a cursor of its own),
 * so that the next one starts there and nothing is read twice.
 */
#[ORM\Entity(repositoryClass: InvoiceInboxRepository::class)]
#[ORM\Table(name: 'ledger_invoice_inbox')]
#[ORM\UniqueConstraint(name: 'ledger_invoice_inbox_gateway', fields: ['gateway'])]
class InvoiceInbox
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $gateway;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $cursor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    public function __construct(string $gateway)
    {
        $this->gateway = $gateway;
    }

    public function getId(): ?int { return $this->id; }
    public function getGateway(): string { return $this->gateway; }
    public function getCursor(): ?string { return $this->cursor; }
    public function getReceivedAt(): ?\DateTimeImmutable { return $this->receivedAt; }
    public function getError(): ?string { return $this->error; }

    public function advance(?string $cursor): self
    {
        $this->cursor = $cursor ?? $this->cursor;
        $this->receivedAt = new \DateTimeImmutable();
        $this->error = null;

        return $this;
    }

    public function fail(string $error): self
    {
        $this->error = mb_substr($error, 0, 255);

        return $this;
    }
}
