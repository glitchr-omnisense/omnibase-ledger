<?php

namespace Tests\Base\Ledger\Reconciliation;

use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Enum\BankLineStatus;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\RuleSign;
use Base\Ledger\Exception\ReconciliationException;
use Base\Ledger\Reconciliation\Suggestion;
use Tests\Base\Ledger\InMemoryLedger;

class ReconcilerTest extends InMemoryLedger
{
    public function testAPaymentIsPostedInTheBankJournalAndLetteredWithItsNotice(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3');
        $notice = $this->notice($dupont, 85000, 'AVIS-2026-10-U3');
        $line = $this->bankLine(85000, 'VIR SEPA AVIS-2026-10-U3', date: '2026-10-06');

        $suggestion = $this->matcher()->suggest($line)[0];
        $entry = $this->reconciler()->apply($line, $suggestion);

        self::assertSame('BQ', $entry->getJournal()->getCode());
        self::assertSame('bank:'.$line->getId(), $entry->getSource());
        self::assertSame('2026-10-06', $entry->getDate()->format('Y-m-d'));
        self::assertSame('AVIS-2026-10-U3', $entry->getPiece());
        self::assertTrue($entry->isValidated());
        [$bank, $tenant] = $entry->getLines()->toArray();
        self::assertSame(['5121', 85000, 0], [$bank->getAccount()->getNumber(), $bank->getDebit(), $bank->getCredit()]);
        self::assertSame(['411U3', 0, 85000, $dupont], [$tenant->getAccount()->getNumber(), $tenant->getDebit(), $tenant->getCredit(), $tenant->getParty()]);
        self::assertNotNull($notice->getLetter());
        self::assertSame($notice->getLetter(), $tenant->getLetter());
        self::assertSame(BankLineStatus::RECONCILED, $line->getStatus());
        self::assertSame($entry, $line->getEntry());
    }

    public function testASupplierPaymentDebitsTheSupplier(): void
    {
        $plumber = $this->party('Plomberie Durand', PartyKind::SUPPLIER, 'PLB', 'FR7630004000050000123456789');
        $invoice = $this->invoice($plumber, 24000, 'F-118');
        $line = $this->bankLine(-24000, 'VIR SEPA FACTURE', iban: 'FR7630004000050000123456789');

        $entry = $this->reconciler()->apply($line, $this->matcher()->suggest($line)[0]);

        [$bank, $supplier] = $entry->getLines()->toArray();
        self::assertSame([0, 24000], [$bank->getDebit(), $bank->getCredit()]);
        self::assertSame(['401PLB', 24000, 0], [$supplier->getAccount()->getNumber(), $supplier->getDebit(), $supplier->getCredit()]);
        self::assertSame($invoice->getLetter(), $supplier->getLetter());
    }

    public function testARuleSplitsALoanInstalment(): void
    {
        $rule = (new ReconciliationRule($this->book, 'Prêt immobilier', 'ECHEANCE PRET', $this->accounts['164'], RuleSign::OUT))
            ->setSplit([['account' => '164', 'amount' => 85000, 'label' => 'Capital'], ['account' => '6611', 'rest' => true, 'label' => 'Intérêts']]);
        $this->rules = [$this->persisted($rule)];
        $line = $this->bankLine(-120000, 'PRLV SEPA ECHEANCE PRET 0042');

        $suggestions = $this->matcher()->suggest($line);
        self::assertSame($rule, $suggestions[0]->rule);
        $entry = $this->reconciler()->apply($line, $suggestions[0]);

        $lines = array_map(fn ($l) => [$l->getAccount()->getNumber(), $l->getDebit(), $l->getCredit(), $l->getLabel()], $entry->getLines()->toArray());
        self::assertSame([['5121', 0, 120000, null], ['164', 85000, 0, 'Capital'], ['6611', 35000, 0, 'Intérêts']], $lines);
    }

    public function testAStripePayoutLettersTheClearingAccount(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3');
        foreach ([10000 => 'pi_1', 20000 => 'pi_2'] as $amount => $reference) {
            $this->posting()->post((new \Base\Ledger\Model\EntryDraft($this->book, 'OD', new \DateTimeImmutable('2026-10-02'), 'Paiement carte', 'stripe:'.$reference, $reference))
                ->line('5112', $amount, 0)->line('411U3', 0, $amount, $dupont));
        }
        $this->posting()->post((new \Base\Ledger\Model\EntryDraft($this->book, 'OD', new \DateTimeImmutable('2026-10-03'), 'Commission Stripe', 'stripe:fee'))->line('6278', 870, 0)->line('5112', 0, 870));
        $this->rules = [$this->persisted(new ReconciliationRule($this->book, 'Stripe', 'STRIPE', $this->accounts['5112'], RuleSign::IN))];
        $line = $this->bankLine(29130, 'VIR STRIPE');

        $entry = $this->reconciler()->apply($line, $this->matcher()->suggest($line)[0]);

        self::assertSame([['5121', 29130, 0], ['5112', 0, 29130]], array_map(fn ($l) => [$l->getAccount()->getNumber(), $l->getDebit(), $l->getCredit()], $entry->getLines()->toArray()));
        $clearing = array_filter($this->allLines(), fn ($l) => '5112' === $l->getAccount()->getNumber());
        self::assertCount(4, $clearing);
        self::assertCount(1, array_unique(array_map(fn ($l) => $l->getLetter(), $clearing)));
        self::assertNotNull(reset($clearing)->getLetter());
    }

    public function testAPartialPaymentIsPostedOnTheAccountWithoutLettering(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3');
        $notice = $this->notice($dupont, 85000, 'A1');
        $line = $this->bankLine(40000, 'VIR DUPONT ACOMPTE');

        $entry = $this->reconciler()->apply($line, new Suggestion(1.0, 'à la main', [$notice]));

        self::assertSame(['411U3', 0, 40000], [$entry->getLines()->last()->getAccount()->getNumber(), $entry->getLines()->last()->getDebit(), $entry->getLines()->last()->getCredit()]);
        self::assertNull($notice->getLetter());
        self::assertNull($entry->getLines()->last()->getLetter());
    }

    public function testNothingToPostAgainstIsRefused(): void
    {
        $this->expectException(ReconciliationException::class);
        $this->reconciler()->apply($this->bankLine(100, 'X'), new Suggestion(1.0, 'rien'));
    }

    public function testAutoReconcileAppliesOnlyWhatIsSure(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3');
        $martin = $this->party('Claire Martin', reference: 'U4');
        $this->notice($dupont, 85000, 'AVIS-U3');
        $this->notice($martin, 85000, 'AVIS-U4');
        $this->notice($this->party('Paul Henri', reference: 'U5'), 70000, 'AVIS-U5');

        $sure = $this->bankLine(85000, 'VIR SEPA AVIS-U3');
        $ambiguous = $this->bankLine(70000, 'VIREMENT');
        $unknown = $this->bankLine(-4321, 'CB BOULANGERIE');
        $alsoSure = $this->bankLine(85000, 'VIR CLAIRE MARTIN');

        self::assertSame(2, $this->reconciler()->autoReconcile($this->bankAccount()));
        self::assertSame(BankLineStatus::RECONCILED, $sure->getStatus());
        self::assertSame(BankLineStatus::RECONCILED, $alsoSure->getStatus());
        self::assertSame(BankLineStatus::SUGGESTED, $ambiguous->getStatus());
        self::assertSame(BankLineStatus::UNMATCHED, $unknown->getStatus());
    }

    public function testUndoReversesTheEntryAndReopensTheNotice(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3');
        $notice = $this->notice($dupont, 85000, 'AVIS-U3');
        $line = $this->bankLine(85000, 'VIR SEPA AVIS-U3');
        $events = [];
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $dispatcher->addListener(\Base\Ledger\Event\BankLineUnreconciled::class, function (\Base\Ledger\Event\BankLineUnreconciled $event) use (&$events): void {
            $events[] = $event;
        });
        $reconciler = new \Base\Ledger\Reconciliation\Reconciler($this->entityManager(), $this->posting(), $this->lettering(), $this->matcher(), $this->bankLineRepository(), 0.9, $dispatcher);
        $entry = $reconciler->apply($line, $this->matcher()->suggest($line)[0]);

        $reconciler->undo($line);

        self::assertSame(BankLineStatus::UNMATCHED, $line->getStatus());
        self::assertNull($line->getEntry());
        self::assertNull($notice->getLetter());
        $reversal = end($this->entries);
        self::assertSame($entry, $reversal->getReversalOf());
        self::assertSame('bank:'.$line->getId().':undo', $reversal->getSource());
        // The bank entry's 411 line and its mirror cancel out: lettered together, not open.
        self::assertNotNull($entry->getLines()->last()->getLetter());
        self::assertSame($entry->getLines()->last()->getLetter(), $reversal->getLines()->last()->getLetter());
        self::assertSame([$notice], $this->lettering()->openItems($this->book, $dupont));
        // What followed the reconciliation (a rent call paid) hears of it.
        self::assertCount(1, $events);
        self::assertSame([$line, $entry, $reversal], [$events[0]->line, $events[0]->entry, $events[0]->reversal]);

        // Applied again, it is a new entry.
        $again = $reconciler->apply($line, $this->matcher()->suggest($line)[0]);
        self::assertNotSame($entry, $again);
        self::assertSame('bank:'.$line->getId().'/2', $again->getSource());
        self::assertSame($notice->getLetter(), $again->getLines()->last()->getLetter());
    }

    public function testUndoIsRefusedInTheLockedPeriod(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3');
        $this->notice($dupont, 85000, 'AVIS-U3');
        $line = $this->bankLine(85000, 'VIR SEPA AVIS-U3', date: '2026-10-05');
        $this->reconciler()->apply($line, $this->matcher()->suggest($line)[0]);
        $this->book->setLockedUntil(new \DateTimeImmutable('2026-10-31'));

        $this->expectException(ReconciliationException::class);
        $this->reconciler()->undo($line);
    }

    public function testIgnoredLinesComeBackWithUndo(): void
    {
        $line = $this->bankLine(100, 'VIREMENT INTERNE');
        $this->reconciler()->ignore($line);
        self::assertSame(BankLineStatus::IGNORED, $line->getStatus());
        $this->reconciler()->undo($line);
        self::assertSame(BankLineStatus::UNMATCHED, $line->getStatus());
    }

    public function testSplitPartsAddUpToTheAmount(): void
    {
        $rule = (new ReconciliationRule($this->book, 'Mixte', null, $this->accounts['615']))
            ->setSplit([['account' => '614', 'percent' => 30], ['account' => '616', 'amount' => 1000]]);

        self::assertSame([['614', 3000, null], ['616', 1000, null], ['615', 6000, null]], $this->reconciler()->split($rule, 10000));
    }
}
