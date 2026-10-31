<?php

namespace Base\Ledger\Model;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\TaxKind;

/**
 * An entry to post, as the application describes it - account numbers and
 * a journal code rather than entities:
 *
 *   $posting->post((new EntryDraft($book, 'VT', $date, 'Loyer octobre U3', 'notice:2026-10-U3', 'AVIS-2026-10-U3'))
 *       ->line('411U3', 85000, 0, $tenant, dimensions: ['unit' => '3'])
 *       ->line('706', 0, 85000, dimensions: ['unit' => '3']));
 *
 * A taxed amount is split into its base and its VAT by taxed(), given all
 * taxes included (what an invoice's total says) or before tax:
 *
 *   ->taxed('615', 12000, 0, $a20)            // 615 D 100,00 and 44566 D 20,00
 *   ->line('401EDF', 0, 12000, $supplier)
 *
 * $source identifies what the entry records, once: posting the same source
 * again returns the entry already there.
 */
final class EntryDraft
{
    /** @var list<array{account: string, debit: int, credit: int, party: ?Party, label: ?string, dimensions: array<string, string>, tax: Tax|string|null, taxBase: ?int}> */
    private array $lines = [];

    public function __construct(
        public readonly Book $book,
        public readonly string $journal,
        public readonly \DateTimeImmutable $date,
        public readonly string $label,
        public readonly string $source,
        public readonly ?string $piece = null,
    ) {
    }

    /**
     * A line: amounts in minor units, one of them zero. $dimensions are its
     * analytic axes (['unit' => '12']); $tax (a code of the book, or the
     * Tax) and $taxBase make it a VAT line.
     *
     * @param array<string, string|int> $dimensions
     */
    public function line(string $account, int $debit, int $credit, ?Party $party = null, ?string $label = null, array $dimensions = [], Tax|string|null $tax = null, ?int $taxBase = null): self
    {
        $this->lines[] = [
            'account' => $account, 'debit' => $debit, 'credit' => $credit, 'party' => $party, 'label' => $label,
            'dimensions' => array_map('strval', $dimensions), 'tax' => $tax, 'taxBase' => $taxBase,
        ];

        return $this;
    }

    /**
     * A taxed amount on $account (on the debit or the credit side, the other
     * zero), split by the tax's rate: the base on $account, the VAT on the
     * tax's account, rounded to the cent half up, the base taking what the
     * rounding leaves (Tax::split()). The VAT line knows its tax and its
     * base. A reverse charge adds the VAT due and the VAT deducted, which
     * cancel out: the amount is the base. An exempt code adds no VAT line;
     * the base line carries the code. The dimensions go on every line.
     *
     * @param array<string, string|int> $dimensions
     */
    public function taxed(string $account, int $debit, int $credit, Tax $tax, bool $taxIncluded = true, ?Party $party = null, ?string $label = null, array $dimensions = []): self
    {
        if (($debit > 0) === ($credit > 0)) {
            throw new \InvalidArgumentException(sprintf('"%s" (%s): a taxed amount is on the debit or the credit side.', $this->label, $account));
        }
        $onDebit = $debit > 0;
        [$base, $vat] = $tax->split($onDebit ? $debit : $credit, $taxIncluded);
        // [debit, credit] of an amount on the base's side, or on the other one.
        $same = fn (int $amount) => $onDebit ? [$amount, 0] : [0, $amount];
        $other = fn (int $amount) => $onDebit ? [0, $amount] : [$amount, 0];

        if (TaxKind::EXEMPT === $tax->getKind() || null === $tax->getAccount()) {
            [$d, $c] = $same($base);

            return $this->line($account, $d, $c, $party, $label, $dimensions, $tax, $base);
        }

        [$d, $c] = $same($base);
        $this->line($account, $d, $c, $party, $label, $dimensions);
        if (0 === $vat) {
            return $this;
        }
        [$d, $c] = $same($vat);
        if (TaxKind::REVERSE_CHARGE === $tax->getKind()) {
            // Deducted on the base's side, due on the other: the supplier invoiced the base only.
            $deductible = $tax->getDeductibleAccount() ?? throw new \InvalidArgumentException(sprintf('Tax %s is a reverse charge without a deductible account.', $tax->getCode()));
            $this->line($deductible->getNumber(), $d, $c, null, $label, $dimensions, $tax, $base);
            [$d, $c] = $other($vat);
        }

        return $this->line($tax->getAccount()->getNumber(), $d, $c, null, $label, $dimensions, $tax, $base);
    }

    /** @return list<array{account: string, debit: int, credit: int, party: ?Party, label: ?string, dimensions: array<string, string>, tax: Tax|string|null, taxBase: ?int}> */
    public function getLines(): array
    {
        return $this->lines;
    }

    public function getTotalDebit(): int
    {
        return array_sum(array_column($this->lines, 'debit'));
    }

    public function getTotalCredit(): int
    {
        return array_sum(array_column($this->lines, 'credit'));
    }
}
