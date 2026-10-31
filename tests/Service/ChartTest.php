<?php

namespace Tests\Base\Ledger\Service;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Journal;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\JournalRepository;
use Base\Ledger\Service\Chart;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ChartTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    private function chart(array $existing = []): Chart
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) { $this->persisted[] = $entity; });
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('chart')->willReturn($existing);
        $journals = $this->createStub(JournalRepository::class);
        $journals->method('findOneByCode')->willReturn(null);

        return new Chart($em, $accounts, $journals);
    }

    public function testTheSciChartHasWhatARealEstateCompanyPostsTo(): void
    {
        $book = new Book('SCI');
        $this->chart()->seed($book);

        $accounts = [];
        $journals = [];
        foreach ($this->persisted as $entity) {
            if ($entity instanceof Account) {
                $accounts[$entity->getNumber()] = $entity;
            } elseif ($entity instanceof Journal) {
                $journals[] = $entity->getCode();
            }
        }

        foreach (['101', '108', '119', '120', '129', '164', '165', '401', '411', '419', '455', '4711', '512', '5112', '6061', '611', '614', '615', '616', '6227', '6231', '627', '6278', '635', '63512', '6611', '681', '706', '708', '7088', '758', '791'] as $number) {
            self::assertArrayHasKey($number, $accounts, $number.' is missing');
            self::assertSame($book, $accounts[$number]->getBook());
        }
        self::assertSame(['BQ', 'VT', 'AC', 'OD', 'AN'], $journals);

        self::assertSame(AccountType::LIABILITY, $accounts['165']->getType());
        self::assertSame(AccountType::LIABILITY, $accounts['455']->getType());
        self::assertSame(AccountType::LIABILITY, $accounts['164']->getType());
        self::assertSame(AccountType::EQUITY, $accounts['101']->getType());
        self::assertSame(AccountType::ASSET, $accounts['411']->getType());
        self::assertSame(AccountType::ASSET, $accounts['5112']->getType());
        self::assertSame(AccountType::EXPENSE, $accounts['63512']->getType());
        self::assertSame(AccountType::INCOME, $accounts['706']->getType());
        foreach ($accounts as $number => $account) {
            self::assertSame($account->getType(), AccountType::guess((string) $number), $number.': its type is not the PCG\'s default');
        }
    }

    public function testTheFrenchVatCodesComeWithTheChart(): void
    {
        $book = new Book('SCI');
        $this->chart()->seed($book);

        $taxes = [];
        foreach ($this->persisted as $entity) {
            if ($entity instanceof Tax) {
                $taxes[$entity->getCode()] = [$entity->getRate(), $entity->getKind(), $entity->getAccount()?->getNumber(), $entity->getDeductibleAccount()?->getNumber()];
            }
        }

        self::assertSame([2000, TaxKind::COLLECTED, '44571', null], $taxes['V20']);
        self::assertSame([1000, TaxKind::COLLECTED, '44571', null], $taxes['V10']);
        self::assertSame([550, TaxKind::COLLECTED, '44571', null], $taxes['V55']);
        self::assertSame([210, TaxKind::COLLECTED, '44571', null], $taxes['V21']);
        self::assertSame([2000, TaxKind::DEDUCTIBLE, '44566', null], $taxes['A20']);
        self::assertSame([2000, TaxKind::DEDUCTIBLE_ASSETS, '44562', null], $taxes['I20']);
        self::assertSame([0, TaxKind::EXEMPT, null, null], $taxes['EXO']);
        self::assertSame([2000, TaxKind::REVERSE_CHARGE, '4452', '44566'], $taxes['AIC20']);
    }

    public function testSeedingAgainKeepsWhatTheBookHas(): void
    {
        $book = new Book('SCI');
        $mine = new Account($book, '706', 'Loyers d\'habitation');
        $this->chart([$mine])->seed($book);

        $numbers = array_map(fn ($a) => $a->getNumber(), array_filter($this->persisted, fn ($e) => $e instanceof Account));
        self::assertNotContains('706', $numbers);
        self::assertContains('708', $numbers);
        self::assertSame('Loyers d\'habitation', $mine->getLabel());
    }
}
