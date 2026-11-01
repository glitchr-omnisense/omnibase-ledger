<?php

namespace Base\Ledger\Twig;

use Base\Ledger\Entity\BankAccount;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * {{ 123456|ledger_money }} → 1 234,56 € (minor units, the locale's way);
 * {{ 123456|ledger_amount }} → 1 234,56 (no currency: a column of a report);
 * {{ iban|ledger_iban }} → FR76 •••• 4321;
 * {{ 1250000|ledger_compact('EUR') }} → 12,5 k€ (a chart's axis, a tile);
 * {{ day|ledger_month }} → oct. (the locale's short month);
 * {% if ledger_once('bank-style') %} → true the first time in a request
 * only (a style shared by several cards, written once);
 * {{ ledger_category('627') }} → bank_fees, the key of an account's plain
 * words (review.category.<key> in the ledger domain), null without;
 * {{ label|ledger_label(counterparty) }} → « Monoprix » for « CB DEBIT
 * DIFFERE MONOPRIX ».
 *
 * Amounts, numbers and months take the page's language - the translator's
 * locale, the one its labels are in -, not intl's default, which a host may
 * not keep in step with it.
 */
final class LedgerExtension implements ResetInterface
{
    /** @var array<string, true> */
    private array $once = [];

    public function __construct(private readonly ?TranslatorInterface $translator = null)
    {
    }

    /** $locale, else the page's (the translator's), else intl's default. */
    public function locale(?string $locale = null): string
    {
        return $locale ?? (null !== $this->translator && method_exists($this->translator, 'getLocale') ? $this->translator->getLocale() : null) ?? \Locale::getDefault();
    }

    #[AsTwigFunction('ledger_once')]
    public function once(string $key): bool
    {
        if (isset($this->once[$key])) {
            return false;
        }

        return $this->once[$key] = true;
    }

    #[AsTwigFunction('ledger_category')]
    public function category(string $number): ?string
    {
        return \Base\Ledger\Reconciliation\Categories::key($number);
    }

    #[AsTwigFilter('ledger_label')]
    public function label(?string $label, ?string $counterparty = null): string
    {
        return \Base\Ledger\Bank\Label::clean((string) $label, $counterparty);
    }

    public function reset(): void
    {
        $this->once = [];
    }

    #[AsTwigFilter('ledger_compact')]
    public function compact(int|string|null $minor, ?string $currency = 'EUR', ?string $locale = null): string
    {
        $value = ((int) $minor) / 100;
        $locale = $this->locale($locale);
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

    /** {{ -5.7|ledger_number(1) }} → -5,7 in French: a number in the page's language. */
    #[AsTwigFilter('ledger_number')]
    public function number(int|float|null $value, int $decimals = 0, ?string $locale = null): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($this->locale($locale), \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 0);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

            return (string) $formatter->format((float) $value);
        }

        return number_format((float) $value, $decimals, ',', ' ');
    }

    #[AsTwigFilter('ledger_month')]
    public function month(\DateTimeInterface $date, ?string $locale = null): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $formatted = (new \IntlDateFormatter($this->locale($locale), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMM'))->format($date);
            if (false !== $formatted) {
                return $formatted;
            }
        }

        return $date->format('M');
    }

    /** « 2026-10 » → « octobre 2026 » (« octobre » without the year): a month of the front office's filter, in the page's language. */
    #[AsTwigFilter('ledger_month_name')]
    public function monthName(string $month, bool $year = true, ?string $locale = null): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $month;
        }
        $date = new \DateTimeImmutable($month.'-01');
        if (class_exists(\IntlDateFormatter::class)) {
            $formatted = (new \IntlDateFormatter($this->locale($locale), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, $year ? 'LLLL y' : 'LLLL'))->format($date);
            if (false !== $formatted) {
                return $formatted;
            }
        }

        return $date->format($year ? 'F Y' : 'F');
    }

    #[AsTwigFilter('ledger_money')]
    public function money(int|string|null $minor, string $currency = 'EUR', ?string $locale = null): string
    {
        $value = ((int) $minor) / 100;
        if (class_exists(\NumberFormatter::class)) {
            $formatted = (new \NumberFormatter($this->locale($locale), \NumberFormatter::CURRENCY))->formatCurrency($value, $currency);
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
