<?php

namespace Tests\Base\Ledger\Omnibill;

use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdProfiles;

/**
 * Suppliers' invoices as a supplier's software writes them: CII (EN 16931)
 * by horstoeko/zugferd's builder, and Factur-X - the same XML inside a PDF.
 * EDF's by default: 100,00 € before tax at 20 %, 120,00 € due.
 */
final class Invoices
{
    /**
     * @param array{number?: string, type?: string, date?: string, seller?: string, siren?: ?string, vat_number?: ?string, iban?: ?string, buyer_siren?: string, lines?: list<array{0: float, 1: float, 2?: string}>} $o lines: [base, rate %, category]
     */
    public static function builder(array $o = []): ZugferdDocumentBuilder
    {
        $o += ['number' => 'FA-2026-118', 'type' => '380', 'date' => '2026-11-03', 'seller' => 'EDF Entreprises', 'siren' => '552081317', 'vat_number' => 'FR03552081317', 'iban' => 'FR7630004000031234567890143', 'buyer_siren' => '123456789', 'lines' => [[100.0, 20.0]]];
        $document = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);
        $document->setDocumentInformation($o['number'], $o['type'], new \DateTime($o['date']), 'EUR');
        $document->setDocumentSeller($o['seller']);
        $document->setDocumentSellerAddress('22-30 avenue de Wagram', null, null, '75008', 'Paris', 'FR');
        if (null !== $o['siren']) {
            $document->setDocumentSellerLegalOrganisation($o['siren'], '0002', $o['seller']);
        }
        if (null !== $o['vat_number']) {
            $document->addDocumentSellerVATRegistrationNumber($o['vat_number']);
        }
        $document->setDocumentBuyer('SCI Les Tilleuls');
        $document->setDocumentBuyerAddress('1 rue des Tilleuls', null, null, '67000', 'Strasbourg', 'FR');
        $document->setDocumentBuyerLegalOrganisation($o['buyer_siren'], '0002', 'SCI Les Tilleuls');
        if (null !== $o['iban']) {
            $document->addDocumentPaymentMeanToCreditTransfer($o['iban']);
        }
        $document->addDocumentPaymentTerm('Payable sous 30 jours', new \DateTime('2026-12-03'));

        $base = $vat = 0.0;
        foreach ($o['lines'] as $i => $line) {
            [$amount, $rate] = $line;
            $category = $line[2] ?? 'S';
            $document->addNewPosition((string) ($i + 1));
            $document->setDocumentPositionProductDetails('Électricité');
            $document->setDocumentPositionNetPrice($amount);
            $document->setDocumentPositionQuantity(1.0, 'C62');
            $document->addDocumentPositionTax($category, 'VAT', $rate);
            $document->setDocumentPositionLineSummation($amount);
            $tax = 'S' === $category ? round($amount * $rate / 100, 2) : 0.0;
            $document->addDocumentTax($category, 'VAT', $amount, $tax, $rate, 'S' === $category ? null : 'Autoliquidation', 'S' === $category ? null : 'VATEX-EU-AE');
            $base += $amount;
            $vat += $tax;
        }
        $document->setDocumentSummation($base + $vat, $base + $vat, $base, 0.0, 0.0, $base, $vat);

        return $document;
    }

    /** @param array<string, mixed> $o */
    public static function cii(array $o = []): string
    {
        return self::builder($o)->getContent();
    }

    /** The invoice as a PDF/A-3 with its XML inside. */
    public static function facturX(array $o = []): string
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml('<html><body><h1>Facture '.($o['number'] ?? 'FA-2026-118').'</h1></body></html>');
        $dompdf->render();

        return (new ZugferdDocumentPdfBuilder(self::builder($o), (string) $dompdf->output()))->generateDocument()->downloadString();
    }
}
