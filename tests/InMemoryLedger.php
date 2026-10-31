<?php

namespace Tests\Base\Ledger;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankConnection;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\FiscalYear;
use Base\Ledger\Entity\Journal;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\BankLineStatus;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Reconciliation\Matcher;
use Base\Ledger\Reconciliation\Reconciler;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\BankLineRepository;
use Base\Ledger\Repository\EntryLineRepository;
use Base\Ledger\Repository\EntryRepository;
use Base\Ledger\Repository\FiscalYearRepository;
use Base\Ledger\Repository\JournalRepository;
use Base\Ledger\Repository\PartyRepository;
use Base\Ledger\Repository\ReconciliationRuleRepository;
use Base\Ledger\Repository\TaxRepository;
use Base\Ledger\Service\Lettering;
use Base\Ledger\Service\Posting;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A book kept in memory: the repositories are stubs answering from arrays,
 * the entity manager's persist() files what it is given (with an id) and
 * flush() does nothing - so the services run as they would, without a
 * database.
 */
abstract class InMemoryLedger extends TestCase
{
    protected Book $book;
    /** @var array<string, Account> */
    protected array $accounts = [];
    /** @var array<string, Journal> */
    protected array $journals = [];
    /** @var list<Entry> */
    protected array $entries = [];
    /** @var list<Party> */
    protected array $parties = [];
    /** @var list<ReconciliationRule> */
    protected array $rules = [];
    /** @var list<BankLine> */
    protected array $bankLines = [];
    /** @var list<FiscalYear> */
    protected array $fiscalYears = [];
    /** @var array<string, Tax> */
    protected array $taxes = [];
    protected int $flushes = 0;
    private int $ids = 0;

    protected function setUp(): void
    {
        $this->book = self::withId(new Book('SCI Les Tilleuls', '123 456 789'), 1);
        foreach (['411' => 'Locataires', '401' => 'Fournisseurs', '455' => 'Associés', '164' => 'Emprunts', '165' => 'Dépôts reçus', '512' => 'Banques', '5121' => 'Banque - Compte courant', '5112' => 'Paiements à encaisser', '6278' => 'Commissions', '627' => 'Services bancaires', '6611' => 'Intérêts', '706' => 'Loyers', '708' => 'Charges récupérées', '615' => 'Entretien'] as $number => $label) {
            $this->account((string) $number, $label);
        }
        foreach (['BQ' => 'Banque', 'VT' => 'Ventes', 'AC' => 'Achats', 'OD' => 'Opérations diverses'] as $code => $label) {
            $this->journals[$code] = $this->persisted(new Journal($this->book, $code, $label));
        }
    }

    protected function account(string $number, string $label = 'Compte'): Account
    {
        return $this->accounts[Account::normalize($number)] ??= $this->persisted(new Account($this->book, $number, $label));
    }

    protected function party(string $name, PartyKind $kind = PartyKind::TENANT, ?string $reference = null, ?string $iban = null): Party
    {
        $reference ??= strtoupper(substr(preg_replace('/\W/', '', $name), 0, 6));
        $number = $kind->collective().$reference;
        $this->account($number, $name);

        return $this->parties[] = $this->persisted(new Party($this->book, $kind, $name, $number, $reference, $iban));
    }

    /** A validated sales entry: the party owes $amount (a rent notice). */
    protected function notice(Party $party, int $amount, string $piece, string $date = '2026-10-01', string $credit = '706'): EntryLine
    {
        $entry = $this->posting()->post((new \Base\Ledger\Model\EntryDraft($this->book, 'VT', new \DateTimeImmutable($date), 'Loyer '.$party->getName(), 'notice:'.$piece, $piece))
            ->line($party->getAccountNumber(), $amount, 0, $party)
            ->line($credit, 0, $amount));

        return $entry->getLines()->first();
    }

    /** A validated purchase: the book owes $amount to the party (a supplier invoice). */
    protected function invoice(Party $party, int $amount, string $piece, string $date = '2026-10-01'): EntryLine
    {
        $entry = $this->posting()->post((new \Base\Ledger\Model\EntryDraft($this->book, 'AC', new \DateTimeImmutable($date), 'Facture '.$party->getName(), 'invoice:'.$piece, $piece))
            ->line('615', $amount, 0)
            ->line($party->getAccountNumber(), 0, $amount, $party));

        return $entry->getLines()->last();
    }

    /**
     * The book's French VAT codes, as Chart::seed() gives them (the VAT
     * accounts added): V20, A20, I20, EXO, AIC20...
     *
     * @return array<string, Tax>
     */
    protected function frenchTaxes(): array
    {
        foreach (['4452' => 'TVA due intracommunautaire', '44562' => 'TVA déductible sur immobilisations', '44566' => 'TVA déductible', '44571' => 'TVA collectée'] as $number => $label) {
            $this->account((string) $number, $label);
        }
        $definition = (require \dirname(__DIR__).'/config/charts/sci.php')['taxes'];
        foreach ($definition as $code => [$label, $rate, $kind, $account, $deductible]) {
            $this->taxes[$code] ??= $this->persisted(new Tax($this->book, $code, $label, $rate, $kind, null === $account ? null : $this->accounts[$account], null === $deductible ? null : $this->accounts[$deductible]));
        }

        return $this->taxes;
    }

    protected function taxRepository(): TaxRepository
    {
        $repository = $this->createStub(TaxRepository::class);
        $repository->method('findOneByCode')->willReturnCallback(fn (Book $book, string $code) => $this->taxes[Tax::normalize($code)] ?? null);
        $repository->method('forBook')->willReturnCallback(fn () => array_values($this->taxes));

        return $repository;
    }

    private ?BankAccount $bankAccount = null;

    protected function bankAccount(): BankAccount
    {
        return $this->bankAccount ??= self::withId(new BankAccount(self::withId(new BankConnection($this->book, 'files'), 1), 'FR7612345', 'Compte courant', $this->accounts['5121'], $this->journals['BQ'], 'FR76 3000 6000 0112 3456 7890 189'), 1);
    }

    protected function bankLine(int $amount, string $label, ?string $iban = null, ?string $name = null, ?string $reference = null, string $date = '2026-10-05'): BankLine
    {
        $line = new BankLine($this->bankAccount(), 'tx'.(\count($this->bankLines) + 1), new \DateTimeImmutable($date), $amount, $label);
        $line->setCounterpartyIban($iban)->setCounterpartyName($name)->setReference($reference);

        return $this->bankLines[] = $this->persisted($line);
    }

    protected function entityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Entry) {
                if (!\in_array($entity, $this->entries, true)) {
                    $this->entries[] = $this->persisted($entity);
                    foreach ($entity->getLines() as $line) {
                        $this->persisted($line);
                    }
                }
            } elseif ($entity instanceof Account) {
                $this->accounts[$entity->getNumber()] = $this->persisted($entity);
            } elseif ($entity instanceof Journal) {
                $this->journals[$entity->getCode()] = $this->persisted($entity);
            } elseif ($entity instanceof Party) {
                $this->parties[] = $this->persisted($entity);
            } elseif ($entity instanceof BankLine) {
                $this->bankLines[] = $this->persisted($entity);
            } else {
                $this->persisted($entity);
            }
        });
        $em->method('flush')->willReturnCallback(function (): void { ++$this->flushes; });

        return $em;
    }

    protected function accountRepository(): AccountRepository
    {
        $repository = $this->createStub(AccountRepository::class);
        $repository->method('findOneByNumber')->willReturnCallback(fn (Book $book, string $number) => $this->accounts[Account::normalize($number)] ?? null);
        $repository->method('chart')->willReturnCallback(fn () => array_values($this->accounts));
        $repository->method('findParent')->willReturnCallback(function (Book $book, string $number) {
            for ($length = \strlen($number) - 1; $length >= 1; --$length) {
                if (isset($this->accounts[substr($number, 0, $length)])) {
                    return $this->accounts[substr($number, 0, $length)];
                }
            }

            return null;
        });

        return $repository;
    }

    protected function journalRepository(): JournalRepository
    {
        $repository = $this->createStub(JournalRepository::class);
        $repository->method('findOneByCode')->willReturnCallback(fn (Book $book, string $code) => $this->journals[strtoupper($code)] ?? null);

        return $repository;
    }

    protected function entryRepository(): EntryRepository
    {
        $repository = $this->createStub(EntryRepository::class);
        $repository->method('findOneBySource')->willReturnCallback(function (Book $book, string $source) {
            foreach ($this->entries as $entry) {
                if ($entry->getBook() === $book && $entry->getSource() === $source) {
                    return $entry;
                }
            }

            return null;
        });
        $repository->method('lastNumber')->willReturnCallback(function (Book $book, string $prefix) {
            $numbers = array_filter(array_map(fn (Entry $e) => $e->getNumber(), $this->entries), fn (?string $n) => null !== $n && str_starts_with($n, $prefix));
            sort($numbers);

            return $numbers ? end($numbers) : null;
        });
        $repository->method('findReversal')->willReturnCallback(function (Entry $entry) {
            foreach ($this->entries as $candidate) {
                if ($candidate->getReversalOf() === $entry) {
                    return $candidate;
                }
            }

            return null;
        });
        $repository->method('forPeriod')->willReturnCallback(fn (Book $book, \DateTimeImmutable $from, \DateTimeImmutable $to) => array_values(array_filter($this->entries, fn (Entry $e) => $e->isValidated() && $e->getDate() >= $from && $e->getDate() <= $to)));

        return $repository;
    }

    protected function fiscalYearRepository(): FiscalYearRepository
    {
        $repository = $this->createStub(FiscalYearRepository::class);
        $repository->method('containing')->willReturnCallback(function (Book $book, \DateTimeInterface $date) {
            foreach ($this->fiscalYears as $year) {
                if ($year->contains($date)) {
                    return $year;
                }
            }

            return null;
        });
        $repository->method('current')->willReturnCallback(function (Book $book, ?\DateTimeImmutable $date = null) {
            $date ??= new \DateTimeImmutable('today');
            foreach ($this->fiscalYears as $year) {
                if ($year->contains($date)) {
                    return $year;
                }
            }

            return FiscalYear::calendar($book, (int) $date->format('Y'));
        });

        return $repository;
    }

    protected function lineRepository(): EntryLineRepository
    {
        $repository = $this->createStub(EntryLineRepository::class);
        $repository->method('openItems')->willReturnCallback(function (Book $book, ?Party $party = null, ?string $prefix = null) {
            return array_values(array_filter($this->allLines(), fn (EntryLine $l) => !$l->isLettered() && (null === $party || $l->getParty() === $party) && (null === $prefix || $l->getAccount()->isUnder($prefix))));
        });
        $repository->method('findByLetter')->willReturnCallback(fn (Book $book, string $letter) => array_values(array_filter($this->allLines(), fn (EntryLine $l) => $l->getLetter() === $letter)));
        $repository->method('lastLetter')->willReturnCallback(function () {
            $letters = array_filter(array_map(fn (EntryLine $l) => $l->getLetter(), $this->allLines()));
            usort($letters, fn ($a, $b) => [\strlen($a), $a] <=> [\strlen($b), $b]);

            return $letters ? end($letters) : null;
        });
        // What the reports read: debit and credit per account over a period, drafts included unless asked.
        $repository->method('totalsByAccount')->willReturnCallback(function (Book $book, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, bool $validatedOnly = false, array $dimensions = [], ?string $groupBy = null) {
            $totals = [];
            foreach ($this->linesFor($from, $to, $validatedOnly, $dimensions) as $line) {
                $account = $line->getAccount();
                $value = null === $groupBy ? null : $line->getDimension($groupBy);
                $key = $account->getNumber().'|'.$value;
                $totals[$key] ??= ['number' => $account->getNumber(), 'label' => $account->getLabel(), 'type' => $account->getType(), 'debit' => 0, 'credit' => 0] + (null === $groupBy ? [] : ['dimension' => $value]);
                $totals[$key]['debit'] += $line->getDebit();
                $totals[$key]['credit'] += $line->getCredit();
            }
            ksort($totals, \SORT_STRING);

            return array_values($totals);
        });
        $repository->method('forLedger')->willReturnCallback(function (Book $book, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, ?string $prefix = null, array $dimensions = []) {
            $lines = array_values(array_filter($this->linesFor($from, $to, false, $dimensions), fn (EntryLine $l) => null === $prefix || $l->getAccount()->isUnder($prefix)));
            usort($lines, fn (EntryLine $a, EntryLine $b) => [$a->getAccount()->getNumber(), $a->getEntry()->getDate(), $a->getId()] <=> [$b->getAccount()->getNumber(), $b->getEntry()->getDate(), $b->getId()]);

            return $lines;
        });
        // As the SQL groups them: per tax and account, debit, credit and the bases on each side.
        $repository->method('taxTotals')->willReturnCallback(function (Book $book, \DateTimeImmutable $from, \DateTimeImmutable $to) {
            $rows = [];
            foreach ($this->linesFor($from, $to, false, []) as $line) {
                if (null === $tax = $line->getTax()) {
                    continue;
                }
                $key = $tax->getCode().'|'.$line->getAccount()->getNumber();
                $rows[$key] ??= ['code' => $tax->getCode(), 'label' => $tax->getLabel(), 'rate' => $tax->getRate(), 'kind' => $tax->getKind()->value, 'account' => $line->getAccount()->getNumber(),
                    'tax_account' => $tax->getAccount()?->getNumber(), 'deductible_account' => $tax->getDeductibleAccount()?->getNumber(), 'debit' => 0, 'credit' => 0, 'base_debit' => 0, 'base_credit' => 0];
                $rows[$key]['debit'] += $line->getDebit();
                $rows[$key]['credit'] += $line->getCredit();
                $rows[$key][$line->getDebit() > 0 ? 'base_debit' : 'base_credit'] += (int) $line->getTaxBase();
            }
            ksort($rows, \SORT_STRING);

            return array_values($rows);
        });
        $repository->method('axes')->willReturnCallback(function () {
            $axes = [];
            foreach ($this->allLines() as $line) {
                foreach ($line->getDimensions() as $axis => $value) {
                    $axes[$axis][$value] = true;
                }
            }
            ksort($axes);

            return array_map('count', $axes);
        });

        return $repository;
    }

    protected function partyRepository(): PartyRepository
    {
        $repository = $this->createStub(PartyRepository::class);
        $repository->method('findByIban')->willReturnCallback(fn (Book $book, ?string $iban) => array_values(array_filter($this->parties, fn (Party $p) => null !== $iban && $p->getIban() === Party::normalizeIban($iban))));
        $repository->method('findOneByReference')->willReturnCallback(function (Book $book, string $reference) {
            foreach ($this->parties as $party) {
                if ($party->getReference() === $reference) {
                    return $party;
                }
            }

            return null;
        });
        $repository->method('accountNumberTaken')->willReturnCallback(fn (Book $book, string $number) => [] !== array_filter($this->parties, fn (Party $p) => $p->getAccountNumber() === $number));

        return $repository;
    }

    protected function ruleRepository(): ReconciliationRuleRepository
    {
        $repository = $this->createStub(ReconciliationRuleRepository::class);
        $repository->method('forBook')->willReturnCallback(function () {
            $rules = array_values(array_filter($this->rules, fn (ReconciliationRule $r) => $r->isEnabled()));
            usort($rules, fn ($a, $b) => $b->getPriority() <=> $a->getPriority());

            return $rules;
        });

        return $repository;
    }

    protected function bankLineRepository(): BankLineRepository
    {
        $repository = $this->createStub(BankLineRepository::class);
        $repository->method('pending')->willReturnCallback(fn (BankAccount $account) => array_values(array_filter($this->bankLines, fn (BankLine $l) => $l->getBankAccount() === $account && $l->getStatus()->isPending())));
        $repository->method('findOneByExternalId')->willReturnCallback(function (BankAccount $account, string $id) {
            foreach ($this->bankLines as $line) {
                if ($line->getBankAccount() === $account && $line->getExternalId() === $id) {
                    return $line;
                }
            }

            return null;
        });

        return $repository;
    }

    protected function posting(bool $autoAccounts = false): Posting
    {
        return new Posting($this->entityManager(), $this->accountRepository(), $this->journalRepository(), $this->entryRepository(), $this->fiscalYearRepository(), $autoAccounts, null, $this->taxRepository());
    }

    protected function lettering(): Lettering
    {
        return $this->lettering ??= new Lettering($this->entityManager(), $this->lineRepository());
    }

    private ?Lettering $lettering = null;

    protected function matcher(): Matcher
    {
        return new Matcher($this->lineRepository(), $this->partyRepository(), $this->ruleRepository());
    }

    protected function reconciler(float $threshold = 0.9): Reconciler
    {
        return new Reconciler($this->entityManager(), $this->posting(), $this->lettering(), $this->matcher(), $this->bankLineRepository(), $threshold, null, $this->taxRepository());
    }

    /**
     * The lines over a period, maybe validated only, carrying each axis => value of $dimensions.
     *
     * @return list<EntryLine>
     */
    protected function linesFor(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, bool $validatedOnly, array $dimensions): array
    {
        return array_values(array_filter($this->allLines(), function (EntryLine $line) use ($from, $to, $validatedOnly, $dimensions) {
            $entry = $line->getEntry();
            $day = $entry->getDate()->format('Y-m-d');
            if ((null !== $from && $day < $from->format('Y-m-d')) || (null !== $to && $day > $to->format('Y-m-d')) || ($validatedOnly && !$entry->isValidated())) {
                return false;
            }
            foreach ($dimensions as $axis => $value) {
                if ($line->getDimension((string) $axis) !== (string) $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return list<EntryLine> */
    protected function allLines(): array
    {
        $lines = [];
        foreach ($this->entries as $entry) {
            foreach ($entry->getLines() as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @template T of object @param T $entity @return T */
    protected function persisted(object $entity): object
    {
        $property = new \ReflectionProperty($entity, 'id');
        if (null === $property->getValue($entity)) {
            $property->setValue($entity, ++$this->ids + 100);
        }

        return $entity;
    }

    /** @template T of object @param T $entity @return T */
    protected static function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }
}
