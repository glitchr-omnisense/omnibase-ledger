<?php

namespace Base\Ledger\Bank;

use Base\Ledger\Model\Text;

/**
 * A bank label as a person reads it: « CB DEBIT DIFFERE MONOPRIX » is
 * « Monoprix », « PRLV SEPA EDF PARTIES COMMUNES » « EDF Parties Communes »,
 * a transfer the name of who sent or received it. The bank's words before
 * the merchant (card, deferred debit, direct debit, transfer, the card's
 * number, the date of the payment) are left out, the rest put in words with
 * capitals, the acronyms kept. The raw label stays on the line.
 */
final class Label
{
    /** What banks write before the merchant, tried in order and again until none matches. */
    private const PREFIXES = [
        '/^(?:CB|CARTE(?:\s+BANCAIRE)?)\s+(?:DEBIT\s+DIFFERE|DEB\s+DIFF|DIFFERE)\s+/i',
        '/^(?:(?:CB|CARTE)\s+)?DEBIT\s+(?:IMMEDIAT|DIFFERE)\s+/i',
        '/^(?:FACTURE|FACT|PAIEMENT|PAIE?|ACHAT|RETRAIT)\s+(?:PAR\s+)?(?:CB|CARTE)\s+(?:DU\s+)?/i',
        '/^(?:CB|CARTE)\s+/i',
        '/^(?:PRLV|PRELEVEMENT|PRELEV)(?:\s+SEPA)?(?:\s+EUROPEEN)?\s+/i',
        '/^(?:VIR(?:EMENT)?|VRT)(?:\s+(?:SEPA|INST(?:ANTANE)?|PERMANENT))*(?:\s+(?:RECU|EMIS))?(?:\s+(?:DE|A|POUR|\/DE|\/A))?\s+/i',
        '/^X?\d{4}\s+/',
        '/^\d{2}[\/.]\d{2}(?:[\/.]\d{2,4})?\s+/',
        '/^\d{6}\s+/',
    ];

    /** Words kept in capitals: what reads as letters, not as a word. */
    private const ACRONYMS = ['EDF', 'GDF', 'SNCF', 'RATP', 'MMA', 'MAIF', 'MACIF', 'MAAF', 'GMF', 'IARD', 'PNO', 'DGFIP', 'SFR', 'URSSAF', 'CAF', 'CPAM', 'SCI', 'SA', 'SAS', 'SASU', 'SARL', 'EURL', 'TVA', 'UK', 'LTD', 'BNP', 'LCL', 'CIC', 'HSBC', 'SG', 'OVH', 'IKEA', 'BHV', 'TGV', 'TER', 'RIB', 'IBAN', 'SEPA', 'USA', 'EU', 'FR', 'TF', 'CFE', 'DAB', 'GAB'];

    /** Small words left in lower case inside a name. */
    private const SMALL = ['de', 'du', 'des', 'la', 'le', 'les', 'et', 'en', 'au', 'aux', 'sur', 'pour', 'a', 'd', 'l'];

    /**
     * @param string|null $counterparty the counterparty's name, when the bank gives one: a transfer
     *                                  or a direct debit then reads as it
     */
    public static function clean(string $label, ?string $counterparty = null): string
    {
        $label = trim((string) preg_replace('/\s+/', ' ', $label));
        $counterparty = trim((string) $counterparty);
        if ('' !== $counterparty && ('' === $label || preg_match('/^(?:VIR|VRT|PRLV|PRELEV)/i', $label))) {
            return self::words($counterparty);
        }
        if ('' === $label) {
            return '';
        }

        $rest = $label;
        do {
            $before = $rest;
            foreach (self::PREFIXES as $pattern) {
                $rest = trim((string) preg_replace($pattern, '', $rest));
            }
        } while ($rest !== $before && '' !== $rest);
        // A card's number and a payment's date left at the end (« MONOPRIX 12/10 CARTE 4970 »).
        $rest = trim((string) preg_replace(['/\s+CARTE\s+X?\d{4}$/i', '/\s+X\d{4}$/i', '/\s+\d{2}[\/.]\d{2}(?:[\/.]\d{2,4})?$/'], '', $rest));
        // A bank's reference glued to the end (« FREE MOBILE LD229894893289 »).
        $rest = trim((string) preg_replace('/(?:\s+[A-Z]{0,4}\d{6,})+$/i', '', $rest));
        $rest = trim(str_replace(['*', '_'], ' ', $rest));
        $rest = trim((string) preg_replace('/\s+/', ' ', $rest));

        return self::words('' === $rest ? $label : $rest);
    }

    /** The words a rule recognises the label by: the cleaned label, folded (« MONOPRIX »). */
    public static function pattern(string $label, ?string $counterparty = null): string
    {
        return Text::fold(self::clean($label, $counterparty));
    }

    /** « MONOPRIX PARIS 10 » → « Monoprix Paris 10 »; « LÉA MULLER » → « Léa Muller »; acronyms and words with a dot (« alloresto.fr ») as they are read. */
    private static function words(string $text): string
    {
        $words = explode(' ', $text);
        foreach ($words as $i => $word) {
            $plain = Text::fold($word);
            if (\in_array($plain, self::ACRONYMS, true)) {
                $words[$i] = mb_strtoupper($word);
                continue;
            }
            $lower = mb_strtolower($word);
            if ($i > 0 && \in_array($lower, self::SMALL, true)) {
                $words[$i] = $lower;
                continue;
            }
            if (str_contains($lower, '.')) {
                // A web address: a capital at its start only.
                $words[$i] = mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
                continue;
            }
            // Each part of a hyphenated or apostrophed word: « JEAN-PIERRE » → « Jean-Pierre », « HALL'S » → « Hall's ».
            $words[$i] = (string) preg_replace_callback('/(^|[-])(\p{L})/u', fn (array $m) => $m[1].mb_strtoupper($m[2]), $lower);
        }

        return implode(' ', $words);
    }
}
