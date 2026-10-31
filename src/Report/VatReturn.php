<?php

namespace Base\Ledger\Report;

use Base\Ledger\Entity\Book;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Enum\VatRegime;
use Base\Ledger\Repository\EntryLineRepository;

/**
 * The VAT return of a book over a period, from its taxed lines (those whose
 * tax code is set, EntryLine::$tax): the CA3's boxes - operations (01 sales,
 * 03 intra-EU acquisitions, 05 exempt), the VAT due per rate (08, 9B, 09,
 * 13), 16 the gross VAT, 17 the part of it on intra-EU acquisitions, 19 and
 * 20 the VAT deductible on fixed assets and on goods and services, 23 their
 * total, then 25 the credit or 28 the net VAT due - and the CA12's yearly
 * totals for the simplified regime.
 *
 * A credit note (a VAT line on the other side) counts negatively. Draft
 * entries count, as in the other reports. The credit carried over from the
 * previous return (box 22) is not known here: the return gives the period's
 * own figures.
 */
class VatReturn
{
    /** Rate (basis points) => [CA3 box, label]. 2.1 % in mainland France goes in box 13 (annex 3310 A). */
    public const RATES = [
        2000 => ['08', 'Taux normal 20 %'],
        1000 => ['9B', 'Taux réduit 10 %'],
        550 => ['09', 'Taux réduit 5,5 %'],
        210 => ['13', 'Taux particulier 2,1 % (annexe 3310 A)'],
    ];

    public function __construct(private readonly EntryLineRepository $lines)
    {
    }

    public function compute(Book $book, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return self::fromTotals($this->lines->taxTotals($book, $from, $to), $book->getVatRegime());
    }

    /**
     * Amounts in minor units.
     *
     * @param list<array{code: string, label: string, rate: int, kind: mixed, account: string, tax_account: ?string, deductible_account: ?string, debit: int, credit: int, base_debit: int, base_credit: int}> $rows EntryLineRepository::taxTotals()
     *
     * @return array{
     *     regime: VatRegime, liable: bool, form: ?string,
     *     operations: array{sales: int, acquisitions: int, exempt: int},
     *     rates: list<array{rate: int, box: ?string, label: string, base: int, vat: int}>,
     *     gross: int, reverse_charge: array{base: int, vat: int},
     *     deductible: array{fixed_assets: int, goods_services: int, total: int},
     *     net_due: int, credit: int,
     *     boxes: list<array{box: string, label: string, base: ?int, amount: ?int}>,
     *     ca12: array{collected: int, deductible_goods_services: int, deductible_fixed_assets: int, deductible_total: int, net_due: int, credit: int},
     *     taxes: list<array{code: string, label: string, kind: TaxKind, rate: int, base: int, vat: int}>
     * }
     */
    public static function fromTotals(array $rows, VatRegime $regime = VatRegime::NORMAL_MONTHLY): array
    {
        $operations = ['sales' => 0, 'acquisitions' => 0, 'exempt' => 0];
        $rates = [];
        $reverse = ['base' => 0, 'vat' => 0];
        $deductible = ['fixed_assets' => 0, 'goods_services' => 0];
        $taxes = [];

        foreach ($rows as $row) {
            $kind = $row['kind'] instanceof TaxKind ? $row['kind'] : TaxKind::from((string) $row['kind']);
            $rate = (int) $row['rate'];
            $credit = (int) $row['credit'] - (int) $row['debit'];
            $baseCredit = (int) $row['base_credit'] - (int) $row['base_debit'];
            $taxes[$row['code']] ??= ['code' => (string) $row['code'], 'label' => (string) $row['label'], 'kind' => $kind, 'rate' => $rate, 'base' => 0, 'vat' => 0];

            switch ($kind) {
                case TaxKind::COLLECTED:
                    $operations['sales'] += $baseCredit;
                    self::addRate($rates, $rate, $baseCredit, $credit);
                    $taxes[$row['code']]['base'] += $baseCredit;
                    $taxes[$row['code']]['vat'] += $credit;
                    break;
                case TaxKind::EXEMPT:
                    $operations['exempt'] += $baseCredit;
                    $taxes[$row['code']]['base'] += $baseCredit;
                    break;
                case TaxKind::DEDUCTIBLE:
                    $deductible['goods_services'] -= $credit;
                    $taxes[$row['code']]['base'] -= $baseCredit;
                    $taxes[$row['code']]['vat'] -= $credit;
                    break;
                case TaxKind::DEDUCTIBLE_ASSETS:
                    $deductible['fixed_assets'] -= $credit;
                    $taxes[$row['code']]['base'] -= $baseCredit;
                    $taxes[$row['code']]['vat'] -= $credit;
                    break;
                case TaxKind::REVERSE_CHARGE:
                    if (null !== $row['deductible_account'] && $row['account'] === $row['deductible_account']) {
                        // The deductible half: what was due is deducted at once.
                        $deductible['goods_services'] -= $credit;
                        break;
                    }
                    $operations['acquisitions'] += $baseCredit;
                    $reverse['base'] += $baseCredit;
                    $reverse['vat'] += $credit;
                    self::addRate($rates, $rate, $baseCredit, $credit);
                    $taxes[$row['code']]['base'] += $baseCredit;
                    $taxes[$row['code']]['vat'] += $credit;
                    break;
            }
        }

        krsort($rates);
        $rates = array_values($rates);
        $gross = array_sum(array_column($rates, 'vat'));
        $deductible['total'] = $deductible['fixed_assets'] + $deductible['goods_services'];
        $netDue = max(0, $gross - $deductible['total']);
        $credit = max(0, $deductible['total'] - $gross);

        $boxes = [
            ['box' => '01', 'label' => 'Ventes, prestations de services', 'base' => $operations['sales'], 'amount' => null],
            ['box' => '03', 'label' => 'Acquisitions intracommunautaires', 'base' => $operations['acquisitions'], 'amount' => null],
            ['box' => '05', 'label' => 'Autres opérations non imposables', 'base' => $operations['exempt'], 'amount' => null],
        ];
        foreach ($rates as $line) {
            $boxes[] = ['box' => $line['box'] ?? '', 'label' => $line['label'], 'base' => $line['base'], 'amount' => $line['vat']];
        }
        array_push($boxes,
            ['box' => '16', 'label' => 'Total de la TVA brute due', 'base' => null, 'amount' => $gross],
            ['box' => '17', 'label' => 'Dont TVA sur acquisitions intracommunautaires', 'base' => null, 'amount' => $reverse['vat']],
            ['box' => '19', 'label' => 'TVA déductible : biens constituant des immobilisations', 'base' => null, 'amount' => $deductible['fixed_assets']],
            ['box' => '20', 'label' => 'TVA déductible : autres biens et services', 'base' => null, 'amount' => $deductible['goods_services']],
            ['box' => '23', 'label' => 'Total de la TVA déductible', 'base' => null, 'amount' => $deductible['total']],
            ['box' => '25', 'label' => 'Crédit de TVA', 'base' => null, 'amount' => $credit],
            ['box' => '28', 'label' => 'TVA nette due', 'base' => null, 'amount' => $netDue],
        );

        return [
            'regime' => $regime,
            'liable' => $regime->isLiable(),
            'form' => $regime->form(),
            'operations' => $operations,
            'rates' => $rates,
            'gross' => $gross,
            'reverse_charge' => $reverse,
            'deductible' => $deductible,
            'net_due' => $netDue,
            'credit' => $credit,
            'boxes' => $boxes,
            'ca12' => [
                'collected' => $gross,
                'deductible_goods_services' => $deductible['goods_services'],
                'deductible_fixed_assets' => $deductible['fixed_assets'],
                'deductible_total' => $deductible['total'],
                'net_due' => $netDue,
                'credit' => $credit,
            ],
            'taxes' => array_values($taxes),
        ];
    }

    private static function addRate(array &$rates, int $rate, int $base, int $vat): void
    {
        [$box, $label] = self::RATES[$rate] ?? [null, sprintf('Taux %s %%', rtrim(rtrim(number_format($rate / 100, 2, ',', ''), '0'), ','))];
        $rates[$rate] ??= ['rate' => $rate, 'box' => $box, 'label' => $label, 'base' => 0, 'vat' => 0];
        $rates[$rate]['base'] += $base;
        $rates[$rate]['vat'] += $vat;
    }
}
