<?php

namespace Tests\Base\Ledger\Bank;

use Base\Ledger\Bank\Label;
use PHPUnit\Framework\TestCase;

/** A bank label as a person reads it, on the labels of meyers' demo statements. */
class LabelTest extends TestCase
{
    public static function labels(): iterable
    {
        yield 'deferred card' => ['CB DEBIT DIFFERE MONOPRIX', null, 'Monoprix'];
        yield 'an acronym kept' => ['CB DEBIT DIFFERE SNCF', null, 'SNCF'];
        yield 'a web address' => ['CB DEBIT DIFFERE ALLORESTO.FR PARIS', null, 'Alloresto.fr Paris'];
        yield 'a star' => ['CB DEBIT DIFFERE GOOGLE *BUDGEA', null, 'Google Budgea'];
        yield 'an apostrophe' => ['CB DEBIT DIFFERE HALL\'S BEER', null, 'Hall\'s Beer'];
        yield 'an immediate debit' => ['DEBIT IMMEDIAT HALL\'S BEER', null, 'Hall\'s Beer'];
        yield 'a direct debit' => ['PRLV SEPA EDF PARTIES COMMUNES 12 ORPHELINS', null, 'EDF Parties Communes 12 Orphelins'];
        yield 'a direct debit, its creditor named' => ['PRLV MMA IARD ASSURANCE PNO', 'MMA IARD', 'MMA IARD'];
        yield 'a transfer reads as who sent it' => ['VIR SEPA MULLER LEA LOYER AVIS-2026-10-U3', 'Léa Muller', 'Léa Muller'];
        yield 'a card number and a date' => ['CARTE X4970 12/10 MONOPRIX', null, 'Monoprix'];
        yield 'a reference glued at the end' => ['FREE MOBILE LD229894893289', null, 'Free Mobile'];
        yield 'nothing to take off' => ['DEBIT MENSUEL CARTE', null, 'Debit Mensuel Carte'];
        yield 'a cash withdrawal' => ['RETRAIT DAB SOCIETE GENERALE', null, 'Retrait DAB Societe Generale'];
        yield 'empty' => ['', null, ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('labels')]
    public function testALabelIsCleaned(string $label, ?string $counterparty, string $expected): void
    {
        self::assertSame($expected, Label::clean($label, $counterparty));
    }

    public function testARulesWordsAreTheCleanedLabelFolded(): void
    {
        self::assertSame('MONOPRIX', Label::pattern('CB DEBIT DIFFERE MONOPRIX'));
        self::assertSame('HALL S BEER', Label::pattern('CB DEBIT DIFFERE HALL\'S BEER'));
    }
}
