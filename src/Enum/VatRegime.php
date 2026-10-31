<?php

namespace Base\Ledger\Enum;

/**
 * How a book's VAT is declared in France. A property company (SCI) letting
 * homes is outside VAT unless it opts in (commercial rents): NONE, the
 * default. FRANCHISE invoices without VAT (franchise en base); SIMPLIFIED
 * files one CA12 a year; NORMAL_MONTHLY and NORMAL_QUARTERLY a CA3 each
 * month or each quarter.
 */
enum VatRegime: string
{
    case NONE = 'none';
    case FRANCHISE = 'franchise';
    case SIMPLIFIED = 'simplified';
    case NORMAL_MONTHLY = 'normal_monthly';
    case NORMAL_QUARTERLY = 'normal_quarterly';

    /** Whether the book collects and deducts VAT. */
    public function isLiable(): bool
    {
        return self::NONE !== $this && self::FRANCHISE !== $this;
    }

    /** The return it files: CA3 (normal regime), CA12 (simplified), none otherwise. */
    public function form(): ?string
    {
        return match ($this) {
            self::NORMAL_MONTHLY, self::NORMAL_QUARTERLY => 'CA3',
            self::SIMPLIFIED => 'CA12',
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Non assujetti',
            self::FRANCHISE => 'Franchise en base',
            self::SIMPLIFIED => 'Réel simplifié (CA12)',
            self::NORMAL_MONTHLY => 'Réel normal mensuel (CA3)',
            self::NORMAL_QUARTERLY => 'Réel normal trimestriel (CA3)',
        };
    }

    /**
     * The period a return is due for on $today: the month or the quarter
     * before (CA3), the calendar year before (CA12), null when nothing is
     * filed.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    public function lastPeriod(\DateTimeImmutable $today): ?array
    {
        $today = $today->setTime(0, 0);

        return match ($this) {
            self::NORMAL_MONTHLY => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            self::NORMAL_QUARTERLY => (function () use ($today) {
                $quarter = intdiv((int) $today->format('n') - 1, 3);
                $start = $today->setDate((int) $today->format('Y'), $quarter * 3 + 1, 1)->modify('-3 months');

                return [$start, $start->modify('+3 months -1 day')];
            })(),
            self::SIMPLIFIED => [$today->setDate((int) $today->format('Y') - 1, 1, 1), $today->setDate((int) $today->format('Y') - 1, 12, 31)],
            default => null,
        };
    }
}
