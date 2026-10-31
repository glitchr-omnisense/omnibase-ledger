<?php

namespace Base\Ledger\Enum;

/**
 * What a tax code does in the VAT return: VAT collected on sales (44571),
 * deductible on goods and services (44566) or on fixed assets (44562), a
 * reverse charge (intra-EU acquisitions: due on 4452 and deducted on 44566
 * at once), or an exempt operation (no VAT, its base reported).
 */
enum TaxKind: string
{
    case COLLECTED = 'collected';
    case DEDUCTIBLE = 'deductible';
    case DEDUCTIBLE_ASSETS = 'deductible_assets';
    case REVERSE_CHARGE = 'reverse_charge';
    case EXEMPT = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::COLLECTED => 'TVA collectée',
            self::DEDUCTIBLE => 'TVA déductible sur biens et services',
            self::DEDUCTIBLE_ASSETS => 'TVA déductible sur immobilisations',
            self::REVERSE_CHARGE => 'Autoliquidation',
            self::EXEMPT => 'Exonéré',
        };
    }

    /** Whether its VAT lines sit on the credit side when normal (VAT owed to the State). */
    public function isCreditNormal(): bool
    {
        return self::COLLECTED === $this || self::EXEMPT === $this || self::REVERSE_CHARGE === $this;
    }
}
