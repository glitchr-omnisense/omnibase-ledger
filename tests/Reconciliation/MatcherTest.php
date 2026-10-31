<?php

namespace Tests\Base\Ledger\Reconciliation;

use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\RuleSign;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Reconciliation\Matcher;
use Base\Ledger\Reconciliation\Suggestion;
use Tests\Base\Ledger\InMemoryLedger;

class MatcherTest extends InMemoryLedger
{
    private const DUPONT_IBAN = 'FR76 1027 8060 0000 0202 7280 109';

    /** @return list<Suggestion> at or above the auto threshold */
    private static function sure(array $suggestions): array
    {
        return array_values(array_filter($suggestions, fn (Suggestion $s) => $s->score >= 0.9));
    }

    public function testAKnownReferenceInTheLabelWins(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3');
        $martin = $this->party('Claire Martin', reference: 'U4');
        $notice = $this->notice($dupont, 85000, 'AVIS-2026-10-U3');
        $this->notice($martin, 85000, 'AVIS-2026-10-U4');

        $suggestions = $this->matcher()->suggest($this->bankLine(85000, 'VIR SEPA LOYER AVIS 2026/10 U3'));

        self::assertSame(Matcher::REFERENCE, $suggestions[0]->score);
        self::assertSame([$notice], $suggestions[0]->openItems);
        self::assertSame($dupont, $suggestions[0]->party);
        self::assertCount(1, self::sure($suggestions));
    }

    public function testAReferenceWithAnotherAmountIsOnlyAHint(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3');
        $this->notice($dupont, 85000, 'AVIS-2026-10-U3');

        $suggestions = $this->matcher()->suggest($this->bankLine(40000, 'VIR AVIS-2026-10-U3 ACOMPTE'));

        self::assertSame(Matcher::REFERENCE_PARTIAL, $suggestions[0]->score);
        self::assertSame([], self::sure($suggestions));
    }

    public function testTheCounterpartyIbanOfAPartyWithAnItemOfThatAmount(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3', iban: self::DUPONT_IBAN);
        $notice = $this->notice($dupont, 85000, 'A1');
        $this->notice($this->party('Claire Martin', reference: 'U4'), 85000, 'A2');

        $suggestions = $this->matcher()->suggest($this->bankLine(85000, 'VIR SEPA LOYER OCTOBRE', iban: self::DUPONT_IBAN));

        self::assertSame(Matcher::IBAN_EXACT, $suggestions[0]->score);
        self::assertSame([$notice], $suggestions[0]->openItems);
        self::assertCount(1, self::sure($suggestions));
    }

    public function testALineNeverSettlesANoticeIssuedLongAfterIt(): void
    {
        $muller = $this->party('Léa Muller', reference: 'U2', iban: self::DUPONT_IBAN);
        $november = $this->notice($muller, 101000, 'AVIS-2026-11-U2', '2026-11-01');

        // A year-old transfer of the same amount, still to reconcile when the notice is issued:
        // her IBAN is known (a payment on account, someone decides), the notice is not offered.
        $suggestions = $this->matcher()->suggest($this->bankLine(101000, 'VIR SEPA MULLER LEA LOYER', iban: self::DUPONT_IBAN, date: '2025-10-04'));
        self::assertSame([[]], array_map(fn ($suggestion) => $suggestion->openItems, $suggestions));
        self::assertSame(Matcher::IBAN_ONLY, $suggestions[0]->score);

        // Paid a few days ahead, it still is.
        $suggestions = $this->matcher()->suggest($this->bankLine(101000, 'VIR SEPA MULLER LEA LOYER', iban: self::DUPONT_IBAN, date: '2026-10-28'));
        self::assertSame([$november], $suggestions[0]->openItems);
    }

    public function testTheOldestOfSeveralEqualItemsOfTheSameParty(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3', iban: self::DUPONT_IBAN);
        $september = $this->notice($dupont, 85000, 'A9', '2026-09-01');
        $this->notice($dupont, 85000, 'A10', '2026-10-01');

        $suggestions = $this->matcher()->suggest($this->bankLine(85000, 'VIR SEPA', iban: self::DUPONT_IBAN));

        self::assertSame(Matcher::IBAN_OLDEST, $suggestions[0]->score);
        self::assertSame([$september], $suggestions[0]->openItems);
    }

    public function testAPartysNameInTheLabel(): void
    {
        $martin = $this->party('Claire Martin', reference: 'U4');
        $notice = $this->notice($martin, 62000, 'A1');
        $this->notice($this->party('Jean Dupont', reference: 'U3'), 85000, 'A2');

        $suggestions = $this->matcher()->suggest($this->bankLine(62000, 'VIREMENT DE MME MARTIN CLAIRE'));

        self::assertSame(Matcher::NAME_EXACT, $suggestions[0]->score);
        self::assertSame([$notice], $suggestions[0]->openItems);
        self::assertCount(1, self::sure($suggestions));
    }

    public function testOneTransferPayingSeveralItemsOfOneParty(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3', iban: self::DUPONT_IBAN);
        $september = $this->notice($dupont, 85000, 'A9', '2026-09-01');
        $this->notice($dupont, 85000, 'A10', '2026-10-01');
        $charges = $this->notice($dupont, 12000, 'REGUL-2025', '2026-10-02', '708');

        $suggestions = $this->matcher()->suggest($this->bankLine(97000, 'VIR SEPA', iban: self::DUPONT_IBAN));

        self::assertSame(Matcher::IBAN_GROUP, $suggestions[0]->score);
        self::assertSame([$september, $charges], $suggestions[0]->openItems);
        self::assertTrue($suggestions[0]->settles(97000));
    }

    public function testAllOfAPartysItemsPaidAtOnce(): void
    {
        $martin = $this->party('Claire Martin', reference: 'U4');
        $one = $this->notice($martin, 62000, 'A9', '2026-09-01');
        $two = $this->notice($martin, 62000, 'A10', '2026-10-01');

        $suggestions = $this->matcher()->suggest($this->bankLine(124000, 'VIR CLAIRE MARTIN LOYERS'));

        self::assertSame(Matcher::NAME_ALL, $suggestions[0]->score);
        self::assertSame([$one, $two], $suggestions[0]->openItems);
    }

    public function testASupplierPaidFromTheBank(): void
    {
        $plumber = $this->party('Plomberie Durand', PartyKind::SUPPLIER, 'PLB', 'FR76 3000 4000 0500 0012 3456 789');
        $invoice = $this->invoice($plumber, 24000, 'F-2026-118');

        $suggestions = $this->matcher()->suggest($this->bankLine(-24000, 'VIR SEPA FACTURE 118', iban: 'FR7630004000050000123456789'));

        self::assertSame(Matcher::IBAN_EXACT, $suggestions[0]->score);
        self::assertSame([$invoice], $suggestions[0]->openItems);
    }

    public function testTwoPartiesOwingTheSameAmountWithNothingElseStaysUnderTheThreshold(): void
    {
        $this->notice($this->party('Jean Dupont', reference: 'U3'), 85000, 'A1');
        $this->notice($this->party('Claire Martin', reference: 'U4'), 85000, 'A2');

        $suggestions = $this->matcher()->suggest($this->bankLine(85000, 'VIREMENT LOYER'));

        self::assertCount(2, $suggestions);
        self::assertSame([], self::sure($suggestions));
        self::assertSame(Matcher::AMBIGUOUS, $suggestions[0]->score);
    }

    public function testOneItemOfThatAmountButNobodyRecognisedIsNotSureEither(): void
    {
        $this->notice($this->party('Jean Dupont', reference: 'U3'), 85000, 'A1');

        $suggestions = $this->matcher()->suggest($this->bankLine(85000, 'VIREMENT RECU'));

        self::assertSame(Matcher::AMOUNT_ONLY, $suggestions[0]->score);
        self::assertSame([], self::sure($suggestions));
    }

    public function testTwoPartiesSharingAnIbanAreAmbiguous(): void
    {
        $this->notice($this->party('Jean Dupont', reference: 'U3', iban: self::DUPONT_IBAN), 85000, 'A1');
        $this->notice($this->party('Lucie Dupont', reference: 'U5', iban: self::DUPONT_IBAN), 85000, 'A2');

        $suggestions = $this->matcher()->suggest($this->bankLine(85000, 'VIR SEPA', iban: self::DUPONT_IBAN));

        self::assertSame([], self::sure($suggestions));
        self::assertSame(Matcher::AMBIGUOUS_PARTY, $suggestions[0]->score);
    }

    public function testARuleWhenNothingOpenMatches(): void
    {
        $fees = (new ReconciliationRule($this->book, 'Frais bancaires', 'FRAIS TENUE COMPTE', $this->accounts['627'], RuleSign::OUT));
        $other = (new ReconciliationRule($this->book, 'Tout prélèvement', 'PRLV', $this->accounts['615'], RuleSign::OUT))->setPriority(-1);
        $this->rules = [$this->persisted($other), $this->persisted($fees)];
        $this->rules[] = $this->persisted(new ReconciliationRule($this->book, 'Entrées', 'FRAIS', $this->accounts['708'], RuleSign::IN));

        $suggestions = $this->matcher()->suggest($this->bankLine(-1250, 'PRLV FRAIS TENUE COMPTE OCT'));

        self::assertSame(Matcher::RULE, $suggestions[0]->score);
        self::assertSame($fees, $suggestions[0]->rule);
        self::assertSame(Matcher::AMBIGUOUS, $suggestions[1]->score);
        self::assertSame($other, $suggestions[1]->rule);
        self::assertCount(2, $suggestions); // the "in" rule does not look at money going out
    }

    public function testAStripePayoutSettlesTheClearingAccountThroughItsRule(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3');
        $one = $this->card($dupont, 10000, 'pi_1');
        $two = $this->card($dupont, 20000, 'pi_2');
        $fee = $this->posting()->post((new EntryDraft($this->book, 'OD', new \DateTimeImmutable('2026-10-03'), 'Commission Stripe', 'stripe:fee:po_1'))
            ->line('6278', 870, 0)->line('5112', 0, 870))->getLines()->last();
        $this->rules = [$this->persisted(new ReconciliationRule($this->book, 'Virements Stripe', 'STRIPE', $this->accounts['5112'], RuleSign::IN))];

        $suggestions = $this->matcher()->suggest($this->bankLine(29130, 'VIR STRIPE PAYMENTS UK STRIPE'));

        self::assertSame(Matcher::RULE_ITEMS, $suggestions[0]->score);
        self::assertSame([$one, $two, $fee], $suggestions[0]->openItems);
        self::assertTrue($suggestions[0]->settles(29130));
    }

    /** A card payment: into the 5112 clearing account, off the tenant's account. */
    private function card(\Base\Ledger\Entity\Party $party, int $amount, string $reference)
    {
        return $this->posting()->post((new EntryDraft($this->book, 'OD', new \DateTimeImmutable('2026-10-02'), 'Paiement carte', 'stripe:'.$reference, $reference))
            ->line('5112', $amount, 0)->line($party->getAccountNumber(), 0, $amount, $party))->getLines()->first();
    }
}
