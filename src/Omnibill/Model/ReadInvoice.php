<?php

namespace Base\Ledger\Omnibill\Model;

/**
 * What a supplier's invoice file says, as InvoiceReader read it: amounts in
 * minor units, the VAT breakdown per rate (basis points) and EN 16931
 * category, the seller's identifiers. A credit note (type 381) says so.
 */
final readonly class ReadInvoice
{
    /** @param list<array{rate: int, base: int, vat: int, category: string}> $vat */
    public function __construct(
        public ?string $number,
        public ?\DateTimeImmutable $issuedOn = null,
        public ?\DateTimeImmutable $dueOn = null,
        public string $currency = 'EUR',
        public ?string $sellerName = null,
        public ?string $sellerSiren = null,
        public ?string $sellerSiret = null,
        public ?string $sellerVat = null,
        public ?string $sellerIban = null,
        public ?string $buyerSiren = null,
        public ?int $totalExcl = null,
        public ?int $totalVat = null,
        public ?int $totalIncl = null,
        public array $vat = [],
        public bool $creditNote = false,
        public ?string $profile = null,
    ) {
    }
}
