<?php

namespace Base\Ledger\Reconciliation;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\EntryLineRepository;
use Base\Ledger\Repository\TaxRepository;

/**
 * The chart of accounts as the front office offers it for a bank line: in
 * plain words (« Frais bancaires » for 627, « Assurance » for 616: the
 * translation review.category.<key> of the ledger domain, the account's own
 * label otherwise), the ones the book used most for its bank lines over the
 * last year first, then those a small company uses most (FREQUENT), then
 * the rest by kind. The third parties' auxiliary accounts (411U3...) are
 * not offered: a line settles them through its open items or its party; nor
 * are the bank accounts, the VAT accounts or the result accounts.
 *
 * Each category says which VAT it takes when the book is liable:
 * deductible for a charge or a fixed asset, collected for an income.
 */
class Categories
{
    /** Account prefix => plain words' key, the longest prefix winning. */
    public const PLAIN = [
        '164' => 'loan_capital',
        '165' => 'deposit_received',
        '211' => 'land',
        '213' => 'building',
        '2135' => 'fit_out',
        '218' => 'equipment',
        '231' => 'works_in_progress',
        '275' => 'deposit_paid',
        '455' => 'associate',
        '4551' => 'associate_contribution',
        '4711' => 'suspense',
        '580' => 'internal_transfer',
        '6061' => 'utilities',
        '6063' => 'supplies',
        '6064' => 'office_supplies',
        '611' => 'management',
        '614' => 'condominium',
        '615' => 'repairs',
        '616' => 'insurance',
        '6226' => 'fees',
        '6227' => 'legal_costs',
        '6231' => 'advertising',
        '6251' => 'travel',
        '6256' => 'missions',
        '6257' => 'meals',
        '626' => 'telecom',
        '627' => 'bank_fees',
        '6278' => 'payment_fees',
        '635' => 'taxes',
        '63512' => 'property_tax',
        '6354' => 'stamp_duty',
        '658' => 'misc_expense',
        '6611' => 'loan_interest',
        '6615' => 'current_account_interest',
        '671' => 'exceptional_expense',
        '695' => 'income_tax',
        '706' => 'rent',
        '708' => 'recovered_charges',
        '7083' => 'parking_rent',
        '7088' => 'other_income',
        '758' => 'misc_income',
        '768' => 'financial_income',
        '771' => 'exceptional_income',
    ];

    /** What a small company's bank lines go to most, offered first when the book has no history yet. */
    public const FREQUENT = ['6257', '6251', '6063', '627', '616', '6061', '615', '626', '455', '580'];

    /** Prefixes of the accounts offered: charges, income, fixed assets, loans and deposits, associates, internal transfers, the suspense account. */
    private const OFFERED = ['6', '7', '2', '164', '165', '455', '4711', '580'];

    /** Never offered: depreciation, the result, VAT, the clearing account. */
    private const LEFT_OUT = ['28', '681', '781', '791', '120', '129', '445'];

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly EntryLineRepository $lines,
        private readonly ?TaxRepository $taxes = null,
    ) {
    }

    /**
     * Each category: number, label (the account's), key (its plain words, or null), group
     * (charges, income, assets, other), vat (deductible, collected, or null outside VAT).
     *
     * @return array{frequent: list<array>, groups: array<string, list<array>>, taxes: array{deductible: list<Tax>, collected: list<Tax>}}
     */
    public function of(Book $book, int $frequent = 8, ?\DateTimeImmutable $today = null): array
    {
        $usage = $this->lines->bankUsage($book, ($today ?? new \DateTimeImmutable())->modify('-1 year'));
        $liable = $book->getVatRegime()->isLiable();

        $categories = [];
        foreach ($this->accounts->chart($book) as $account) {
            if (!self::offered($account)) {
                continue;
            }
            $categories[$account->getNumber()] = self::describe($account, $liable);
        }

        $first = [];
        foreach ([...array_keys($usage), ...self::FREQUENT] as $number) {
            $number = (string) $number;
            if (isset($categories[$number]) && !isset($first[$number]) && \count($first) < $frequent) {
                $first[$number] = $categories[$number] + ['uses' => $usage[$number] ?? 0];
            }
        }

        $groups = ['charges' => [], 'income' => [], 'assets' => [], 'other' => []];
        foreach ($categories as $category) {
            $groups[$category['group']][] = $category;
        }

        $taxes = ['deductible' => [], 'collected' => []];
        if ($liable && null !== $this->taxes) {
            foreach ($this->taxes->forBook($book) as $tax) {
                $taxes[TaxKind::COLLECTED === $tax->getKind() ? 'collected' : 'deductible'][] = $tax;
                if (TaxKind::EXEMPT === $tax->getKind()) {
                    $taxes['collected'][] = $tax;
                }
            }
        }

        return ['frequent' => array_values($first), 'groups' => array_filter($groups), 'taxes' => $taxes];
    }

    /** Whether the front office offers $account as a category. */
    public static function offered(Account $account): bool
    {
        $number = $account->getNumber();
        if (!ctype_digit($number)) {
            return false; // an auxiliary account (411U3, 401PLB): its party's
        }
        foreach (self::LEFT_OUT as $prefix) {
            if (str_starts_with($number, $prefix)) {
                return false;
            }
        }
        foreach (self::OFFERED as $prefix) {
            if (str_starts_with($number, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** The plain words' key of an account number: its longest prefix's in PLAIN, or null. */
    public static function key(string $number): ?string
    {
        for ($length = \strlen($number); $length >= 3; --$length) {
            if (isset(self::PLAIN[substr($number, 0, $length)])) {
                return self::PLAIN[substr($number, 0, $length)];
            }
        }

        return null;
    }

    /** @return array{number: string, label: string, key: ?string, group: string, vat: ?string} */
    private static function describe(Account $account, bool $liable): array
    {
        $number = $account->getNumber();
        $group = match (true) {
            str_starts_with($number, '6') => 'charges',
            str_starts_with($number, '7') => 'income',
            str_starts_with($number, '2') => 'assets',
            default => 'other',
        };

        return [
            'number' => $number,
            'label' => $account->getLabel(),
            'key' => self::key($number),
            'group' => $group,
            'vat' => !$liable ? null : match ($group) { 'charges', 'assets' => 'deductible', 'income' => 'collected', default => null },
        ];
    }
}
