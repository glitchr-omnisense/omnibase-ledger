<?php

namespace Base\Ledger\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\PaletteDashboardWidgetTypeInterface;
use Base\Ledger\Entity\Book;
use Base\Ledger\Report\IncomeStatement;
use Base\Ledger\Repository\BookRepository;
use Base\Ledger\Repository\EntryLineRepository;
use Base\Ledger\Repository\FiscalYearRepository;
use Base\Ledger\Security\Voter\LedgerVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Each book's current fiscal year at a glance: income (class 7) and
 * expenses (class 6) to date and the result they make, what tenants still
 * owe (open debits on 411), what is still to pay (open credits on 401), and
 * the cash in the bank accounts (512). Each tile opens its report - the
 * compte de résultat for the first three - and the bilan to date is a link
 * away.
 *
 *   MenuItem::block('ledger_books', 'Comptabilité', 'fa-solid fa-book')
 */
final class BooksWidgetType implements PaletteDashboardWidgetTypeInterface
{
    private const MAX_BOOKS = 4;

    public function __construct(
        private readonly BookRepository $books,
        private readonly FiscalYearRepository $fiscalYears,
        private readonly EntryLineRepository $lines,
        private readonly IncomeStatement $incomeStatement,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    public static function getName(): string
    {
        return 'ledger_books';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.books';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-book';
    }

    public function getTemplate(): string
    {
        return '@Ledger/admin/widget/books.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        $today = new \DateTimeImmutable('today');
        $rows = [];
        foreach ($this->books->findBy([], ['id' => 'ASC']) as $book) {
            if (\count($rows) >= self::MAX_BOOKS) {
                break;
            }
            if (!$this->authorization->isGranted(LedgerVoter::VIEW, $book)) {
                continue;
            }
            $rows[] = $this->figures($book, $today);
        }

        return ['books' => $rows];
    }

    /** @return array<string, mixed> */
    public function figures(Book $book, \DateTimeImmutable $today): array
    {
        $year = $this->fiscalYears->current($book, $today);
        $to = min($today, $year->getEnd());

        // The compte de résultat's own totals: the tiles say what it says.
        $statement = $this->incomeStatement->compute($book, $year->getStart(), $to)['totals'];
        $receivables = $this->lines->sumUnder($book, '411', null, null, true);
        $payables = $this->lines->sumUnder($book, '401', null, null, true);
        $cash = $this->lines->sumUnder($book, '512', null, $today);

        return [
            'book' => $book,
            'year' => (string) $year,
            'yearEnd' => $year->getEnd()->format('Y'),
            'from' => $year->getStart(),
            'to' => $to,
            'income' => $statement['income'],
            'expenses' => $statement['expenses'],
            'result' => $statement['result'],
            'receivables' => $receivables['debit'],
            'payables' => $payables['credit'],
            'cash' => $cash['debit'] - $cash['credit'],
        ];
    }
}
