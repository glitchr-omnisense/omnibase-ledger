<?php

namespace Base\Ledger\Twig;

use Base\Ledger\Entity\BankAccount;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * {{ 123456|ledger_money }} → 1 234,56 € (minor units, the locale's way);
 * {{ 123456|ledger_amount }} → 1 234,56 (no currency: a column of a report);
 * {{ iban|ledger_iban }} → FR76 •••• 4321;
 * {{ 1250000|ledger_compact('EUR') }} → 12,5 k€ (a chart's axis, a tile);
 * {{ day|ledger_month }} → oct. (the locale's short month);
 * {% if ledger_once('bank-style') %} → true the first time in a request
 * only (a style shared by several cards, written once).
 */
final class LedgerExtension implements ResetInterface
{
    /** @var array<string, true> */
    private array $once = [];

    #[AsTwigFunction('ledger_once')]
    public function once(string $key): bool
    {
        if (isset($this->once[$key])) {
            return false;
        }

        return $this->once[$key] = true;
    }

    public function reset(): void
    {
        $this->once = [];
    }

    #[AsTwigFilter('ledger_compact')]
    public function compact(int|string|null $minor, ?string $currency = 'EUR', ?string $locale = null): string
    {
        $value = ((int) $minor) / 100;
        $locale ??= \Locale::getDefault();
        [$divided, $suffix] = match (true) {
            abs($value) >= 1_000_000 => [$value / 1_000_000, ' M'],
            abs($value) >= 10_000 => [$value / 1_000, ' k'],
            default => [$value, ''],
        };
        $digits = '' === $suffix ? 0 : (abs($divided) < 100 ? 1 : 0);
        $symbol = null === $currency ? '' : $currency;
        if (class_exists(\NumberFormatter::class)) {
            $number = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $number->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $digits);
            $text = (string) $number->format($divided);
            if (null !== $currency) {
                $symbol = (new \NumberFormatter($locale.'@currency='.$currency, \NumberFormatter::CURRENCY))->getSymbol(\NumberFormatter::CURRENCY_SYMBOL) ?: $currency;
            }
        } else {
            $text = number_format($divided, $digits, ',', ' ');
        }

        return trim($text.$suffix.('' !== $suffix ? '' : ' ').$symbol);
    }

    #[AsTwigFilter('ledger_month')]
    public function month(\DateTimeInterface $date, ?string $locale = null): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $formatted = (new \IntlDateFormatter($locale ?? \Locale::getDefault(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMM'))->format($date);
            if (false !== $formatted) {
                return $formatted;
            }
        }

        return $date->format('M');
    }
    #[AsTwigFilter('ledger_money')]
    public function money(int|string|null $minor, string $currency = 'EUR', ?string $locale = null): string
    {
        $value = ((int) $minor) / 100;
        if (class_exists(\NumberFormatter::class)) {
            $formatted = (new \NumberFormatter($locale ?? \Locale::getDefault(), \NumberFormatter::CURRENCY))->formatCurrency($value, $currency);
            if (false !== $formatted) {
                return $formatted;
            }
        }

        return number_format($value, 2, ',', ' ').' '.$currency;
    }

    #[AsTwigFilter('ledger_amount')]
    public function amount(int|string|null $minor): string
    {
        return null === $minor || 0 === (int) $minor ? '' : number_format(((int) $minor) / 100, 2, ',', ' ');
    }

    #[AsTwigFilter('ledger_iban')]
    public function iban(?string $iban): string
    {
        return BankAccount::mask($iban);
    }
}
