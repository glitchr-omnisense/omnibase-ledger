<?php

namespace Tests\Base\Ledger\Reconciliation;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Enum\BankLineStatus;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\RuleSign;
use Base\Ledger\Event\BankLineReconciled;
use Base\Ledger\Event\BankLineUnreconciled;
use Base\Ledger\Exception\ReconciliationException;
use Base\Ledger\Reconciliation\Desk;
use Base\Ledger\Reconciliation\Reconciler;
use Base\Ledger\Repository\BankAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tests\Base\Ledger\InMemoryLedger;

/**
 * The front office's actions, through Reconciliation\Desk as its controller
 * calls them: a suggestion accepted, a category, a split over a category
 * and open items, a line ignored and put back, a rule made from a line and
 * applied to the others then undone, the sure ones applied and undone - each
 * through the Reconciler, BankLineReconciled told as before.
 */
class DeskTest extends InMemoryLedger
{
    /** @var list<object> */
    private array $events = [];
    /** @var list<object> */
    private array $removed = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['455' => 'Associés - comptes courants', '6257' => 'Réceptions', '580' => 'Virements internes', '6063' => 'Petit équipement', '626' => 'Télécommunications'] as $number => $label) {
            $this->account((string) $number, $label);
        }
    }

    public function testASuggestionAcceptedPaysTheRentAndTellsIt(): void
    {
        $muller = $this->party('Léa Muller', reference: 'U3');
        $notice = $this->notice($muller, 101000, 'AVIS-2026-10-U3');
        $line = $this->bankLine(101000, 'VIR SEPA MULLER LEA AVIS-2026-10-U3', name: 'Léa Muller', date: '2026-10-06');
        $desk = $this->desk();

        $suggestions = $desk->suggestions($line);
        self::assertSame('Léa Muller', $desk->label($line), 'a transfer reads as who sent it');
        $entry = $desk->accept($line, $suggestions[0]);

        self::assertSame(BankLineStatus::RECONCILED, $line->getStatus());
        self::assertSame($entry, $line->getEntry());
        self::assertNotNull($notice->getLetter(), 'the notice lettered: paid');
        $told = $this->told(BankLineReconciled::class);
        self::assertCount(1, $told);
        self::assertSame([$notice], $told[0]->openItems, 'estate\'s BankSettlement hears which notice it pays');
        self::assertSame([], $desk->suggestions($line), 'placed: nothing more to suggest');
    }

    public function testACategoryPostsTheWholeLineOnItsAccount(): void
    {
        $line = $this->bankLine(-8916, 'CB DEBIT DIFFERE MONOPRIX');

        $entry = $this->desk()->categorize($line, '6257');

        self::assertSame([['5121', 0, 8916], ['6257', 8916, 0]], self::lines($entry));
        self::assertSame(BankLineStatus::RECONCILED, $line->getStatus());
        self::assertSame([], $this->told(BankLineReconciled::class)[0]->openItems, 'a category settles no open item');
    }

    public function testACategoryWithATaxCodePostsItsBaseAndItsVat(): void
    {
        $this->frenchTaxes();
        $line = $this->bankLine(-12000, 'CB BRICOMARCHE');

        $entry = $this->desk()->categorize($line, '6063', null, 'a20');

        self::assertSame([['5121', 0, 12000], ['6063', 10000, 0], ['44566', 2000, 0]], self::lines($entry));
    }

    public function testAnAccountOutsideTheCategoriesIsRefused(): void
    {
        $line = $this->bankLine(-1000, 'CB QUELQUE CHOSE');

        $this->expectException(ReconciliationException::class);
        $this->desk()->categorize($line, '5121');
    }

    public function testASplitSpreadsTheLineOverACategoryAndOpenItems(): void
    {
        $associate = $this->party('Claire Meyer', PartyKind::ASSOCIATE, 'CM');
        $plumber = $this->party('Plomberie Kieffer', PartyKind::SUPPLIER, 'PK');
        $invoice = $this->invoice($plumber, 40000, 'FA-118');
        $line = $this->bankLine(-61210, 'DEBIT MENSUEL CARTE');

        $entry = $this->desk()->split($line, [
            ['amount' => 40000, 'items' => [$invoice->getId()], 'label' => 'Facture Kieffer'],
            ['amount' => 15000, 'account' => '455', 'party' => $associate->getId()],
            ['amount' => 6210, 'account' => '6257'],
        ]);

        self::assertSame([['5121', 0, 61210], ['401PK', 40000, 0], ['455', 15000, 0], ['6257', 6210, 0]], self::lines($entry));
        self::assertNotNull($invoice->getLetter(), 'the invoice settled by its part');
        self::assertSame($associate, $entry->getLines()->toArray()[2]->getParty());
        self::assertSame([$invoice], $this->told(BankLineReconciled::class)[0]->openItems);
    }

    public function testASplitMustAddUpToTheLine(): void
    {
        $line = $this->bankLine(-10000, 'DEBIT MENSUEL CARTE');

        try {
            $this->desk()->split($line, [['amount' => 6000, 'account' => '6257'], ['amount' => 3000, 'account' => '6063']]);
            self::fail('90 € of 100 € split');
        } catch (ReconciliationException $e) {
            self::assertStringContainsString('90,00', $e->getMessage());
        }
        self::assertTrue($line->getStatus()->isPending(), 'nothing posted');
    }

    public function testAnIgnoredLineComesBackAndAPlacedOneIsReversed(): void
    {
        $desk = $this->desk();
        $ignored = $this->bankLine(-2500, 'VIR INTERNE LIVRET');
        $desk->ignore($ignored);
        self::assertSame(BankLineStatus::IGNORED, $ignored->getStatus());
        $desk->undo($ignored);
        self::assertSame(BankLineStatus::UNMATCHED, $ignored->getStatus());

        $placed = $this->bankLine(-1250, 'FRAIS TENUE DE COMPTE');
        $entry = $desk->categorize($placed, '627');
        $desk->undo($placed);
        self::assertSame(BankLineStatus::UNMATCHED, $placed->getStatus());
        self::assertNull($placed->getEntry());
        $reversal = $this->told(BankLineUnreconciled::class)[0]->reversal;
        self::assertSame($entry, $reversal->getReversalOf(), 'its entry reversed');
    }

    public function testARuleFromALineFilesTheOtherMatchingLinesAndIsUndone(): void
    {
        $desk = $this->desk();
        $first = $this->bankLine(-8916, 'CB DEBIT DIFFERE MONOPRIX', date: '2026-10-31');
        $second = $this->bankLine(-1329, 'CB DEBIT DIFFERE MONOPRIX', date: '2026-10-30');
        $third = $this->bankLine(-5713, 'CB MONOPRIX 12/09', date: '2026-09-30');
        $refund = $this->bankLine(2000, 'REMB MONOPRIX', date: '2026-10-29');
        $other = $this->bankLine(-5196, 'CB DEBIT DIFFERE SNCF');
        $desk->categorize($first, '6257');

        ['rule' => $rule, 'applied' => $applied] = $desk->ruleFrom($first, '6257');

        self::assertSame('Monoprix', $rule->getName());
        self::assertSame('MONOPRIX', $rule->getPattern());
        self::assertSame(RuleSign::OUT, $rule->getSign(), 'the direction of the line it came from');
        self::assertSame('6257', $rule->getAccount()->getNumber());
        self::assertSame([$third, $second], $applied, 'the other expenses at Monoprix, oldest first');
        self::assertSame(BankLineStatus::RECONCILED, $second->getStatus());
        self::assertSame(BankLineStatus::RECONCILED, $third->getStatus());
        self::assertTrue($refund->getStatus()->isPending(), 'money in: not the rule\'s');
        self::assertTrue($other->getStatus()->isPending());
        self::assertSame(['6257', 1329], [$second->getEntry()->getLines()->last()->getAccount()->getNumber(), $second->getEntry()->getLines()->last()->getDebit()]);

        $undone = $desk->undoRule($rule, array_map(fn (BankLine $l) => $l->getId(), $applied));

        self::assertSame(2, $undone);
        self::assertTrue($second->getStatus()->isPending());
        self::assertTrue($third->getStatus()->isPending());
        self::assertSame(BankLineStatus::RECONCILED, $first->getStatus(), 'the line it came from stays filed');
        self::assertContains($rule, $this->removed, 'the rule removed');
    }

    public function testARuleWithATaxCodeSplitsItsVat(): void
    {
        $this->frenchTaxes();
        $line = $this->bankLine(-12000, 'PRLV ORANGE FACTURE');

        ['rule' => $rule] = $this->desk()->ruleFrom($line, '626', null, 'A20');

        self::assertSame([['account' => '626', 'rest' => true, 'tax' => 'A20']], $rule->getSplit());
    }

    public function testTheSureOnesAreAppliedAndUndone(): void
    {
        $muller = $this->party('Léa Muller', reference: 'U3');
        $notice = $this->notice($muller, 101000, 'AVIS-2026-10-U3');
        $sure = $this->bankLine(101000, 'VIR LOYER AVIS-2026-10-U3', date: '2026-10-06');
        $unsure = $this->bankLine(-4200, 'CB INCONNU');
        $desk = $this->desk();

        $applied = $desk->autoReconcile([$this->bankAccount()]);

        self::assertSame([$sure], $applied);
        self::assertTrue($unsure->getStatus()->isPending());
        self::assertSame(1, $desk->undoLines($this->book, [$sure->getId()]));
        self::assertTrue($sure->getStatus()->isPending());
        self::assertNull($notice->getLetter(), 'the notice owed again');
    }

    public function testManualPicksRefuseAnotherBooksRecords(): void
    {
        $line = $this->bankLine(-1000, 'X');
        $stranger = self::withId(new Party(new Book('Autre'), PartyKind::SUPPLIER, 'Ailleurs', '401X'), 999);
        $this->parties[] = $stranger;

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->desk()->manual($line, [], null, 999);
    }

    private function desk(): Desk
    {
        $dispatcher = new EventDispatcher();
        foreach ([BankLineReconciled::class, BankLineUnreconciled::class] as $event) {
            $dispatcher->addListener($event, function (object $told): void { $this->events[] = $told; });
        }
        $em = $this->deskEntityManager();
        $reconciler = new Reconciler($em, $this->posting(), $this->lettering(), $this->matcher(), $this->bankLineRepository(), 0.9, $dispatcher, $this->taxRepository());
        $bankAccounts = $this->createStub(BankAccountRepository::class);
        $bankAccounts->method('forBook')->willReturnCallback(fn () => [$this->bankAccount()]);

        return new Desk($em, $this->matcher(), $reconciler, $this->bankLineRepository(), $this->accountRepository(), $bankAccounts);
    }

    /** The in-memory entity manager, finding what it holds by id and noting what is removed. */
    private function deskEntityManager(): EntityManagerInterface
    {
        $em = $this->entityManager();
        $em->method('find')->willReturnCallback(function (string $class, mixed $id) {
            $pool = match ($class) {
                BankLine::class => $this->bankLines,
                EntryLine::class => $this->allLines(),
                Party::class => $this->parties,
                ReconciliationRule::class => $this->rules,
                BankAccount::class => [$this->bankAccount()],
                default => [],
            };
            foreach ($pool as $record) {
                if ($record->getId() === (int) $id) {
                    return $record;
                }
            }

            return null;
        });
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
            $this->rules = array_values(array_filter($this->rules, fn ($rule) => $rule !== $entity));
        });

        return $em;
    }

    /** @return list<object> */
    private function told(string $class): array
    {
        return array_values(array_filter($this->events, fn (object $event) => $event instanceof $class));
    }

    /** @return list<array{0: string, 1: int, 2: int}> */
    private static function lines(\Base\Ledger\Entity\Entry $entry): array
    {
        return array_map(fn ($l) => [$l->getAccount()->getNumber(), $l->getDebit(), $l->getCredit()], $entry->getLines()->toArray());
    }
}
