<?php

namespace Base\Ledger\Omnibill\Reading;

use Base\Ledger\Omnibill\Model\ReadInvoice;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use horstoeko\zugferd\ZugferdDocumentReader;

/**
 * Reads a supplier's invoice file - Factur-X (a PDF with its CII inside),
 * CII, or UBL through horstoeko/zugferdublbridge - with horstoeko/zugferd:
 * the format is not omnibill's, nor the ledger's (the family carries files,
 * the reading is the library's). Without horstoeko/zugferd nothing is read
 * (available() is false) and an invoice arrives UNREADABLE: a person types
 * what the book needs.
 */
class InvoiceReader
{
    private const UBL_BRIDGE = 'horstoeko\\zugferdublbridge\\XmlConverterUblToCii';

    public static function available(): bool
    {
        return class_exists(ZugferdDocumentReader::class);
    }

    /** Factur-X, CII or UBL, from the file itself (omnibill's Syntax names). */
    public static function guessSyntax(string $content): string
    {
        $head = substr($content, 0, 4096);

        return match (true) {
            str_starts_with(ltrim($head), '%PDF') => 'Factur-X',
            str_contains($head, 'CrossIndustryInvoice') => 'CII',
            str_contains($head, 'urn:oasis:names:specification:ubl') => 'UBL',
            default => 'CII',
        };
    }

    /**
     * @param string|null $syntax Factur-X, CII, UBL; told by the file when null
     *
     * @throws InvoiceReadException
     */
    public function read(string $content, ?string $syntax = null): ReadInvoice
    {
        if (!self::available()) {
            throw new InvoiceReadException('Nothing reads invoice files here: install horstoeko/zugferd.');
        }
        $syntax ??= self::guessSyntax($content);
        if ('Factur-X' !== $syntax && str_starts_with(ltrim(substr($content, 0, 16)), '%PDF')) {
            $syntax = 'Factur-X';
        }

        try {
            $reader = match ($syntax) {
                'Factur-X' => ZugferdDocumentPdfReader::readAndGuessFromContent($content),
                'UBL' => ZugferdDocumentReader::readAndGuessFromContent($this->ublToCii($content)),
                default => ZugferdDocumentReader::readAndGuessFromContent($content),
            };
        } catch (InvoiceReadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InvoiceReadException(sprintf('The %s file could not be read: %s', $syntax, $e->getMessage()), 0, $e);
        }
        if (!$reader instanceof ZugferdDocumentReader) {
            throw new InvoiceReadException(sprintf('The %s file carries no invoice (a PDF without its Factur-X XML?).', $syntax));
        }

        try {
            return $this->describe($reader);
        } catch (\Throwable $e) {
            throw new InvoiceReadException(sprintf('The invoice could not be read: %s', $e->getMessage()), 0, $e);
        }
    }

    private function describe(ZugferdDocumentReader $reader): ReadInvoice
    {
        $reader->getDocumentInformation($number, $typeCode, $date, $currency, $taxCurrency, $name, $language, $period);
        $reader->getDocumentSeller($sellerName, $sellerIds, $description);
        $reader->getDocumentSellerGlobalId($globalIds);
        $reader->getDocumentSellerTaxRegistration($taxRegistration);
        $reader->getDocumentSellerLegalOrganisation($legalId, $legalType, $legalName);
        $reader->getDocumentBuyerLegalOrganisation($buyerLegalId, $buyerLegalType, $buyerLegalName);
        $reader->getDocumentBuyerGlobalId($buyerGlobalIds);
        $reader->getDocumentSummation($grandTotal, $duePayable, $lineTotal, $chargeTotal, $allowanceTotal, $taxBasisTotal, $taxTotal, $rounding, $prepaid);

        // The SIRET (scheme 0009) and the SIREN (0002), wherever the seller put them.
        $ids = array_filter([...(array) $globalIds, ...($legalType ? [(string) $legalType => $legalId] : []), ...(array) $sellerIds], static fn ($v) => null !== $v && '' !== $v);
        $siret = self::digits($ids['0009'] ?? null, 14);
        $siren = self::digits($ids['0002'] ?? $legalId, 9) ?? (null !== $siret ? substr($siret, 0, 9) : null);
        $vatNumber = $taxRegistration['VA'] ?? null;
        if (null === $siren && \is_string($vatNumber) && preg_match('/^FR[0-9A-Z]{2}(\d{9})$/', strtoupper(str_replace(' ', '', $vatNumber)), $m)) {
            $siren = $m[1];
        }
        $buyerIds = array_filter([...(array) $buyerGlobalIds, ...($buyerLegalType ? [(string) $buyerLegalType => $buyerLegalId] : [])], static fn ($v) => null !== $v && '' !== $v);
        $buyerSiren = self::digits($buyerIds['0002'] ?? $buyerLegalId, 9) ?? (null !== ($s = self::digits($buyerIds['0009'] ?? null, 14)) ? substr($s, 0, 9) : null);

        $iban = null;
        if ($reader->firstGetDocumentPaymentMeans()) {
            $reader->getDocumentPaymentMeans($meansType, $information, $cardType, $cardId, $cardHolder, $buyerIban, $payeeIban, $payeeName, $payeePropId, $payeeBic);
            $iban = '' !== (string) $payeeIban ? (string) $payeeIban : null;
        }
        $due = null;
        if ($reader->firstDocumentPaymentTerms()) {
            $reader->getDocumentPaymentTerm($termDescription, $dueDate, $mandate);
            $due = $dueDate instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($dueDate) : null;
        }

        $vat = [];
        if ($reader->firstDocumentTax()) {
            do {
                $reader->getDocumentTax($category, $type, $basis, $calculated, $rate, $exemption, $exemptionCode, $lineBasis, $allowanceBasis, $taxPoint, $dueDateType);
                $vat[] = ['rate' => (int) round((float) $rate * 100), 'base' => self::minor($basis), 'vat' => self::minor($calculated), 'category' => (string) ($category ?: 'S')];
            } while ($reader->nextDocumentTax());
        }

        $profile = null;
        if (method_exists($reader, 'getProfileDefinitionParameter')) {
            try {
                $profile = (string) $reader->getProfileDefinitionParameter('name');
            } catch (\Throwable) {
            }
        }

        return new ReadInvoice(
            '' === (string) $number ? null : (string) $number,
            $date instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($date)->setTime(0, 0) : null,
            $due?->setTime(0, 0),
            strtoupper((string) ($currency ?: 'EUR')),
            '' === trim((string) $sellerName) ? (('' === trim((string) $legalName)) ? null : trim((string) $legalName)) : trim((string) $sellerName),
            $siren,
            $siret,
            \is_string($vatNumber) && '' !== $vatNumber ? strtoupper(str_replace(' ', '', $vatNumber)) : null,
            $iban,
            $buyerSiren,
            null === $taxBasisTotal ? null : self::minor($taxBasisTotal),
            null === $taxTotal ? null : self::minor($taxTotal),
            null === $grandTotal ? null : self::minor($grandTotal),
            $vat,
            '381' === (string) $typeCode,
            '' === (string) $profile ? null : $profile,
        );
    }

    /** UBL as CII, through horstoeko/zugferdublbridge. */
    private function ublToCii(string $ubl): string
    {
        if (!class_exists(self::UBL_BRIDGE)) {
            throw new InvoiceReadException('A UBL invoice is read through horstoeko/zugferdublbridge: install it.');
        }
        $converter = (self::UBL_BRIDGE)::fromString($ubl);

        return $converter->convert()->saveXmlString();
    }

    private static function minor(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function digits(mixed $value, int $length): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return \strlen((string) $digits) === $length ? $digits : null;
    }
}
