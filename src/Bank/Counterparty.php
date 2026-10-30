<?php

namespace Base\Ledger\Bank;

/**
 * Who a bank line is with, the same for every line of theirs: the name the
 * bank gives, else the IBAN, else the label stripped of the bank's own words
 * (PRLV SEPA, VIR, CB, dates, references with digits). key() gathers the
 * lines; name() is what a screen shows.
 */
final class Counterparty
{
    /** The bank's words a label starts or is cut with, not the counterparty's. */
    private const NOISE = ['PRLV', 'PRELEVEMENT', 'SEPA', 'VIR', 'VIREMENT', 'RECU', 'EMIS', 'INST', 'INSTANTANE', 'CB', 'CARTE', 'PAIEMENT', 'PAIEMT', 'ACHAT', 'ECHEANCE', 'FACTURE', 'DE', 'DU', 'DES', 'LA', 'LE', 'LES', 'A', 'AU', 'POUR', 'MOTIF', 'REF', 'REFERENCE', 'ORDRE', 'BENEF', 'EUR'];

    public static function key(?string $name, ?string $iban, string $label): string
    {
        if (null !== $name && '' !== ($normalized = self::normalize($name))) {
            return 'n:'.$normalized;
        }
        if (null !== $iban && '' !== $iban) {
            return 'i:'.strtoupper($iban);
        }

        return 'l:'.self::normalize(self::fromLabel($label));
    }

    public static function name(?string $name, string $label): string
    {
        if (null !== $name && '' !== trim($name)) {
            return trim($name);
        }
        $words = self::fromLabel($label);

        return '' !== $words ? mb_convert_case(mb_strtolower($words), \MB_CASE_TITLE) : trim($label);
    }

    /** The label's first words that are not the bank's, up to three. */
    public static function fromLabel(string $label): string
    {
        $label = strtoupper(self::ascii($label));
        $kept = [];
        foreach (preg_split('/[^A-Z0-9]+/', $label) ?: [] as $word) {
            if ('' === $word || preg_match('/\d/', $word) || \in_array($word, self::NOISE, true) || \strlen($word) < 2) {
                continue;
            }
            $kept[] = $word;
            if (3 === \count($kept)) {
                break;
            }
        }
        if ([] === $kept) {
            $words = array_values(array_filter(preg_split('/[^A-Z]+/', $label) ?: [], static fn ($w) => \strlen($w) > 1));

            return implode(' ', \array_slice($words, 0, 3));
        }

        return implode(' ', $kept);
    }

    private static function normalize(string $text): string
    {
        return trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(self::ascii($text))) ?? '');
    }

    private static function ascii(string $text): string
    {
        $ascii = \function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : false;

        return false === $ascii ? $text : $ascii;
    }
}
