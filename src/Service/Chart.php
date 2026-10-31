<?php

namespace Base\Ledger\Service;

use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Journal;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\JournalRepository;
use Base\Ledger\Repository\TaxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gives a book its chart of accounts, journals and VAT codes from a file of
 * config/charts/ (sci: the PCG accounts a French real-estate SCI uses, and
 * the French VAT codes: 20, 10, 5.5, 2.1 %, exempt, intra-EU reverse charge). It
 * adds what is missing and leaves alone what the book already has, so it
 * can run again after the chart grows.
 */
class Chart
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountRepository $accounts,
        private readonly JournalRepository $journals,
        #[Autowire('%ledger.chart%')] private readonly string $defaultChart = 'sci',
        private readonly ?string $chartsDir = null,
        private readonly ?TaxRepository $taxes = null,
    ) {
    }

    public function seed(Book $book, ?string $chart = null): void
    {
        $definition = $this->definition($chart ?? $this->defaultChart);

        $existing = [];
        foreach ($this->accounts->chart($book) as $account) {
            $existing[$account->getNumber()] = $account;
        }
        foreach ($definition['accounts'] as $number => [$label, $type]) {
            $number = Account::normalize((string) $number);
            if (!isset($existing[$number])) {
                $this->entityManager->persist($existing[$number] = new Account($book, $number, $label, $type));
            }
        }

        foreach ($definition['journals'] as $code => $label) {
            if (null === $this->journals->findOneByCode($book, $code)) {
                $this->entityManager->persist(new Journal($book, $code, $label));
            }
        }

        $codes = [];
        foreach (null === $book->getId() ? [] : ($this->taxes?->forBook($book) ?? []) as $tax) {
            $codes[$tax->getCode()] = true;
        }
        foreach ($definition['taxes'] ?? [] as $code => [$label, $rate, $kind, $account, $deductible]) {
            $code = Tax::normalize((string) $code);
            if (!isset($codes[$code])) {
                $this->entityManager->persist(new Tax($book, $code, $label, $rate, $kind, null === $account ? null : ($existing[Account::normalize($account)] ?? null), null === $deductible ? null : ($existing[Account::normalize($deductible)] ?? null)));
                $codes[$code] = true;
            }
        }

        $this->entityManager->flush();
    }

    /**
     * @return array{accounts: array<string, array{0: string, 1: AccountType}>, journals: array<string, string>, taxes?: array<string, array{0: string, 1: int, 2: TaxKind, 3: ?string, 4: ?string}>}
     */
    public function definition(string $chart): array
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $chart)) {
            throw new \InvalidArgumentException(sprintf('No chart "%s".', $chart));
        }
        $file = ($this->chartsDir ?? \dirname(__DIR__, 2).'/config/charts').'/'.$chart.'.php';
        if (!is_file($file)) {
            throw new \InvalidArgumentException(sprintf('No chart "%s" (%s).', $chart, $file));
        }

        return require $file;
    }
}
