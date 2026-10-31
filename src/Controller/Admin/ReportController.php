<?php

namespace Base\Ledger\Controller\Admin;

use Base\Ledger\Dimension\Dimensions;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLineDimension;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Export\FecWriter;
use Base\Ledger\Report\BalanceSheet;
use Base\Ledger\Report\GeneralLedger;
use Base\Ledger\Report\IncomeStatement;
use Base\Ledger\Report\OpenItems;
use Base\Ledger\Report\TrialBalance;
use Base\Ledger\Report\VatReturn;
use Base\Ledger\Repository\EntryLineRepository;
use Base\Ledger\Repository\FiscalYearRepository;
use Base\Ledger\Security\Voter\LedgerVoter;
use Base\Ledger\Service\Chart;
use Base\Ledger\Service\Posting;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A book's reports - trial balance, general ledger, open items, income
 * statement, balance sheet and VAT return (on screen, printed or as CSV),
 * the general ledger and the income statement filtered by analytic
 * dimensions (?dimension[unit]=12) or per value of an axis (?by=unit), the FEC to
 * download - and its buttons: seeding the chart, validating or reversing an
 * entry.
 */
#[IsGranted(LedgerVoter::VIEW)]
#[Route('/admin/ledger')]
class ReportController extends AbstractLedgerPageController
{
    public function __construct(
        private readonly FiscalYearRepository $fiscalYears,
        private readonly EntryLineRepository $lines,
        private readonly Dimensions $dimensions,
    ) {
    }

    #[Route('/book/{book}/trial-balance', name: 'ledger_admin_trial_balance', requirements: ['book' => '\d+'], methods: ['GET'])]
    public function trialBalance(Request $request, int $book, TrialBalance $trialBalance): Response
    {
        $ledger = $this->load(Book::class, $book);
        [$from, $to, $year] = $this->period($request, $ledger);

        return $this->page('@Ledger/admin/trial_balance.html.twig', ['book' => $ledger, 'from' => $from, 'to' => $to, 'year' => $year, 'report' => $trialBalance->compute($ledger, $from, $to)]);
    }

    #[Route('/book/{book}/general-ledger', name: 'ledger_admin_general_ledger', requirements: ['book' => '\d+'], methods: ['GET'])]
    public function generalLedger(Request $request, int $book, GeneralLedger $generalLedger): Response
    {
        $ledger = $this->load(Book::class, $book);
        [$from, $to, $year] = $this->period($request, $ledger);
        $prefix = preg_replace('/[^0-9A-Za-z]/', '', (string) $request->query->get('account')) ?: null;
        [$filter, $by] = $this->dimensionQuery($request);
        $groups = null === $by
            ? [['value' => null, 'label' => null, 'accounts' => $generalLedger->compute($ledger, $from, $to, $prefix, $filter)]]
            : $generalLedger->byDimension($ledger, $from, $to, $by, $prefix, $filter);

        return $this->page('@Ledger/admin/general_ledger.html.twig', ['book' => $ledger, 'from' => $from, 'to' => $to, 'year' => $year, 'prefix' => $prefix, 'groups' => $groups] + $this->dimensionVars($ledger, $filter, $by));
    }

    #[Route('/book/{book}/open-items', name: 'ledger_admin_open_items', requirements: ['book' => '\d+'], methods: ['GET'])]
    public function openItems(Request $request, int $book, OpenItems $openItems): Response
    {
        $ledger = $this->load(Book::class, $book);
        $prefix = preg_replace('/[^0-9A-Za-z]/', '', (string) $request->query->get('account', '411')) ?: '411';

        return $this->page('@Ledger/admin/open_items.html.twig', ['book' => $ledger, 'prefix' => $prefix, 'report' => $openItems->compute($ledger, null, $prefix)]);
    }

    /** ?n1=0 leaves out the N-1 column; ?format=csv downloads it. */
    #[Route('/book/{book}/income-statement', name: 'ledger_admin_income_statement', requirements: ['book' => '\d+'], methods: ['GET'])]
    public function incomeStatement(Request $request, int $book, IncomeStatement $incomeStatement): Response
    {
        $ledger = $this->load(Book::class, $book);
        [$from, $to, $year] = $this->period($request, $ledger);
        $compare = $request->query->getBoolean('n1', true);
        [$filter, $by] = $this->dimensionQuery($request);

        if (null !== $by) {
            $grouped = $incomeStatement->byDimension($ledger, $from, $to, $by, $filter);
            if ('csv' === $request->query->get('format')) {
                $rows = [];
                foreach ($grouped['groups'] as $group) {
                    $rows[] = [$by, $group['value'] ?? '', $group['label'] ?? 'Non affecté', $group['report']['totals']['income'], $group['report']['totals']['expenses'], $group['report']['totals']['result']];
                }
                $rows[] = ['', '', 'Total', $grouped['totals']['income'], $grouped['totals']['expenses'], $grouped['totals']['result']];

                return $this->table(sprintf('compte-de-resultat-par-%s-%s-%s.csv', $by, $from->format('Ymd'), $to->format('Ymd')), ['Axe', 'Valeur', 'Libellé', 'Produits', 'Charges', 'Résultat'], $rows, 3);
            }

            return $this->page('@Ledger/admin/income_statement_by.html.twig', [
                'book' => $ledger, 'from' => $from, 'to' => $to, 'year' => $year, 'report' => $grouped,
            ] + $this->dimensionVars($ledger, $filter, $by));
        }

        $report = $incomeStatement->compute($ledger, $from, $to, $compare, $filter);

        if ('csv' === $request->query->get('format')) {
            $rows = [];
            foreach (['income' => 'Produits', 'expenses' => 'Charges'] as $side => $heading) {
                foreach (array_filter($report[$side], fn (array $group) => [] !== $group['lines']) as $group) {
                    foreach ($group['lines'] as $line) {
                        $rows[] = [$heading, $group['label'], $line['number'], $line['label'], $line['amount'], $line['previous']];
                    }
                    $rows[] = [$heading, $group['label'], '', 'Total', $group['total'], $group['previous']];
                }
            }
            $rows[] = ['', '', '', 'Résultat', $report['totals']['result'], $report['previous']['result'] ?? null];

            return $this->csv(sprintf('compte-de-resultat-%s-%s.csv', $from->format('Ymd'), $to->format('Ymd')), $rows, $compare);
        }

        return $this->page('@Ledger/admin/income_statement.html.twig', [
            'book' => $ledger, 'from' => $from, 'to' => $to, 'year' => $year, 'compare' => $compare,
            'previous_from' => IncomeStatement::yearBefore($from), 'previous_to' => IncomeStatement::yearBefore($to), 'report' => $report,
        ] + $this->dimensionVars($ledger, $filter, null));
    }

    /** ?at= (Y-m-d), else the fiscal year's end - or today, while it runs; ?n1=0 and ?format=csv as for the income statement. */
    #[Route('/book/{book}/balance-sheet', name: 'ledger_admin_balance_sheet', requirements: ['book' => '\d+'], methods: ['GET'])]
    public function balanceSheet(Request $request, int $book, BalanceSheet $balanceSheet): Response
    {
        $ledger = $this->load(Book::class, $book);
        [, $to, $year] = $this->period($request, $ledger);
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('at')) ?: min($to, new \DateTimeImmutable('today'));
        $compare = $request->query->getBoolean('n1', true);
        $report = $balanceSheet->compute($ledger, $at, $compare);

        if ('csv' === $request->query->get('format')) {
            $rows = [];
            foreach (['assets' => 'Actif', 'liabilities' => 'Passif'] as $side => $heading) {
                foreach (array_filter($report[$side], fn (array $group) => [] !== $group['lines']) as $group) {
                    foreach ($group['lines'] as $line) {
                        $rows[] = [$heading, $group['label'], $line['number'], $line['label'], $line['amount'], $line['previous']];
                    }
                    $rows[] = [$heading, $group['label'], '', 'Total', $group['total'], $group['previous']];
                }
                $rows[] = [$heading, '', '', 'Total '.mb_strtolower($heading), $report['totals'][$side], $report['previous'][$side] ?? null];
            }

            return $this->csv(sprintf('bilan-%s.csv', $at->format('Ymd')), $rows, $compare);
        }

        return $this->page('@Ledger/admin/balance_sheet.html.twig', [
            'book' => $ledger, 'at' => $at, 'year' => $year, 'compare' => $compare,
            'previous_at' => IncomeStatement::yearBefore($at), 'report' => $report,
        ]);
    }

    /**
     * The VAT return over ?from=&to=, else the last period the book's regime
     * declares (the month or the quarter before, the year before for the
     * CA12), else the current fiscal year; ?format=csv downloads it.
     */
    #[Route('/book/{book}/vat-return', name: 'ledger_admin_vat_return', requirements: ['book' => '\d+'], methods: ['GET'])]
    public function vatReturn(Request $request, int $book, VatReturn $vatReturn): Response
    {
        $ledger = $this->load(Book::class, $book);
        [$from, $to, $year] = $this->period($request, $ledger);
        if (!$request->query->has('from') && !$request->query->has('to') && !$request->query->has('year')
            && null !== ($last = $ledger->getVatRegime()->lastPeriod(new \DateTimeImmutable('today')))) {
            [$from, $to] = $last;
        }
        $report = $vatReturn->compute($ledger, $from, $to);

        if ('csv' === $request->query->get('format')) {
            $rows = array_map(fn (array $box) => [$box['box'], $box['label'], $box['base'], $box['amount']], $report['boxes']);

            return $this->table(sprintf('tva-%s-%s.csv', $from->format('Ymd'), $to->format('Ymd')), ['Case', 'Libellé', 'Base HT', 'TVA'], $rows, 2);
        }

        return $this->page('@Ledger/admin/vat_return.html.twig', ['book' => $ledger, 'from' => $from, 'to' => $to, 'year' => $year, 'report' => $report]);
    }

    #[Route('/book/{book}/fec/{year}', name: 'ledger_admin_fec', requirements: ['book' => '\d+', 'year' => '\d{4}'], methods: ['GET'])]
    public function fec(int $book, int $year, FecWriter $writer): Response
    {
        $ledger = $this->load(Book::class, $book);
        $fiscalYear = $this->fiscalYears->forYear($ledger, $year);
        $response = new Response($writer->write($ledger, $fiscalYear));
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, FecWriter::filename($ledger, $fiscalYear)));

        return $response;
    }

    #[Route('/book/{book}/seed', name: 'ledger_admin_book_seed', requirements: ['book' => '\d+'], methods: ['GET'])]
    public function seed(Request $request, int $book, Chart $chart): RedirectResponse
    {
        $ledger = $this->load(Book::class, $book, LedgerVoter::MANAGE);
        $this->assertLinkToken($request, 'ledger_book_seed', $ledger->getId());
        $chart->seed($ledger);
        $this->addFlash('success', sprintf('%s : plan comptable et journaux en place.', $ledger->getName()));

        return $this->back($request);
    }

    #[Route('/entry/{entry}/validate', name: 'ledger_admin_entry_validate', requirements: ['entry' => '\d+'], methods: ['GET'])]
    public function validate(Request $request, int $entry, Posting $posting): RedirectResponse
    {
        $record = $this->load(Entry::class, $entry, LedgerVoter::MANAGE);
        $this->assertLinkToken($request, 'ledger_entry_validate', $record->getId());
        try {
            $posting->validate($record);
            $this->addFlash('success', sprintf('Écriture %s validée.', $record->getNumber()));
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($request);
    }

    #[Route('/entry/{entry}/reverse', name: 'ledger_admin_entry_reverse', requirements: ['entry' => '\d+'], methods: ['GET'])]
    public function reverse(Request $request, int $entry, Posting $posting): RedirectResponse
    {
        $record = $this->load(Entry::class, $entry, LedgerVoter::MANAGE);
        $this->assertLinkToken($request, 'ledger_entry_reverse', $record->getId());
        try {
            $reversal = $posting->reverse($record, 'reversal:'.$record->getId());
            $this->addFlash('success', sprintf('Écriture %s extournée par %s.', $record->getNumber(), $reversal->getNumber()));
        } catch (LedgerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->back($request);
    }

    /**
     * A statement as a spreadsheet opens it in France: semicolons, a BOM for
     * the accents, amounts with a decimal comma.
     *
     * @param list<list<int|string|null>> $rows section, group, account, label, N, N-1
     */
    private function csv(string $filename, array $rows, bool $compare): Response
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\u{FEFF}");
        fputcsv($out, array_slice(['Section', 'Rubrique', 'Compte', 'Libellé', 'N', 'N-1'], 0, $compare ? 6 : 5), ';', '"', '');
        foreach ($rows as $row) {
            $row = array_map(fn (int|string|null $value, int $i) => $i >= 4 ? (null === $value ? '' : number_format(((int) $value) / 100, 2, ',', '')) : (string) $value, $row, array_keys($row));
            fputcsv($out, array_slice($row, 0, $compare ? 6 : 5), ';', '"', '');
        }
        rewind($out);
        $response = new Response(stream_get_contents($out));
        fclose($out);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename));

        return $response;
    }

    /**
     * A spreadsheet of $headers and $rows, as csv() writes it: the columns
     * from $firstAmount on are amounts.
     *
     * @param list<list<int|string|null>> $rows
     */
    private function table(string $filename, array $headers, array $rows, int $firstAmount): Response
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\u{FEFF}");
        fputcsv($out, $headers, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn (int|string|null $value, int $i) => $i >= $firstAmount ? (null === $value ? '' : number_format(((int) $value) / 100, 2, ',', '')) : (string) $value, $row, array_keys($row)), ';', '"', '');
        }
        rewind($out);
        $response = new Response(stream_get_contents($out));
        fclose($out);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename));

        return $response;
    }

    /**
     * ?dimension[axis]=value (the lines kept) and ?by=axis (the grouping),
     * an axis or a value that is not one left out.
     *
     * @return array{0: array<string, string>, 1: ?string}
     */
    private function dimensionQuery(Request $request): array
    {
        $filter = [];
        foreach ((array) ($request->query->all()['dimension'] ?? []) as $axis => $value) {
            try {
                if (\is_scalar($value) && '' !== trim((string) $value)) {
                    $filter[EntryLineDimension::axis((string) $axis)] = EntryLineDimension::value((string) $value);
                }
            } catch (\InvalidArgumentException) {
            }
        }
        try {
            $by = '' === trim((string) $request->query->get('by')) ? null : EntryLineDimension::axis((string) $request->query->get('by'));
        } catch (\InvalidArgumentException) {
            $by = null;
        }

        return [$filter, $by];
    }

    /** What the pages show of the axes: the book's, the filter with its labels, the grouping. */
    private function dimensionVars(Book $book, array $filter, ?string $by): array
    {
        $labels = [];
        foreach ($filter as $axis => $value) {
            $labels[$axis] = $this->dimensions->label($axis, $value);
        }

        return ['axes' => $this->lines->axes($book), 'filter' => $filter, 'filter_labels' => $labels, 'by' => $by];
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string} ?from=&to= (Y-m-d), ?year=, else the current fiscal year */
    private function period(Request $request, Book $book): array
    {
        if (ctype_digit((string) $request->query->get('year'))) {
            $year = $this->fiscalYears->forYear($book, (int) $request->query->get('year'));
        } else {
            $year = $this->fiscalYears->current($book);
        }
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('from')) ?: $year->getStart();
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('to')) ?: $year->getEnd();

        return [$from, $to, $year->getEnd()->format('Y')];
    }
}
