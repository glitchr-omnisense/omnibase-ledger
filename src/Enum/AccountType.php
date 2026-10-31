<?php

namespace Base\Ledger\Enum;

/**
 * What an account is in the balance sheet or the income statement - which
 * side its balance normally sits on (debit for assets and expenses, credit
 * for the others) and whether it is carried forward at year end.
 */
enum AccountType: string
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
    case INCOME = 'income';
    case EXPENSE = 'expense';

    public function isDebitNormal(): bool
    {
        return self::ASSET === $this || self::EXPENSE === $this;
    }

    /** Income and expenses: closed into the result at year end, not carried forward. */
    public function isResult(): bool
    {
        return self::INCOME === $this || self::EXPENSE === $this;
    }

    /** The PCG's default for an account number: its class, and for class 4 the usual side. */
    public static function guess(string $number): self
    {
        if (str_starts_with($number, '4')) {
            // Longest prefix first: the exceptions of each group before its rule.
            foreach (['409' => self::ASSET, '40' => self::LIABILITY, '419' => self::LIABILITY, '41' => self::ASSET, '42' => self::LIABILITY, '43' => self::LIABILITY,
                '441' => self::ASSET, '4456' => self::ASSET, '4458' => self::ASSET, '44' => self::LIABILITY, '456' => self::ASSET, '45' => self::LIABILITY, '4671' => self::LIABILITY,
                '46' => self::ASSET, '47' => self::ASSET, '487' => self::LIABILITY, '48' => self::ASSET, '49' => self::ASSET] as $prefix => $type) {
                if (str_starts_with($number, (string) $prefix)) {
                    return $type;
                }
            }

            return self::ASSET;
        }

        return match ($number[0] ?? '') {
            '1' => str_starts_with($number, '10') || str_starts_with($number, '11') || str_starts_with($number, '12') || str_starts_with($number, '13') || str_starts_with($number, '14') ? self::EQUITY : self::LIABILITY,
            '2', '3' => self::ASSET,
            // 519 (Concours bancaires courants) is a debt; the rest of class 5 what the book holds.
            '5' => str_starts_with($number, '519') ? self::LIABILITY : self::ASSET,
            '6' => self::EXPENSE,
            '7' => self::INCOME,
            // Class 8, special accounts: 890 (Bilan d'ouverture) and 891 balance the opening and closing entries.
            '8' => self::EQUITY,
            default => self::ASSET,
        };
    }
}
