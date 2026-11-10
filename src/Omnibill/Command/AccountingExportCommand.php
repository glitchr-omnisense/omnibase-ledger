<?php

namespace Base\Ledger\Omnibill\Command;

use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Omnibill\Enum\ExportState;
use Base\Ledger\Omnibill\Export\AccountingExporter;
use Base\Ledger\Repository\BookRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exports a book's validated entries of a period (the last month by
 * default) to the accountant's software through omnibill: contacts first,
 * then entries; what went before is not sent again, a run that stopped is
 * resumed.
 */
#[AsCommand(name: 'ledger:accounting:export', description: 'Export the validated entries of a period to the accounting software (omnibill: odoo, pennylane)')]
class AccountingExportCommand extends Command
{
    public function __construct(
        private readonly AccountingExporter $exporter,
        private readonly BookRepository $books,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('book', InputArgument::REQUIRED, 'The book: its id, SIREN or name')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'The first day (2026-10-01); the first of last month by default')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'The last day (2026-10-31); the end of last month by default')
            ->addOption('gateway', 'g', InputOption::VALUE_REQUIRED, 'The omnibill gateway (ledger.accounting.gateway, else the first that keeps journal entries)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $book = $this->books->resolve((string) $input->getArgument('book'));
        if (null === $book) {
            $io->error(sprintf('No book "%s".', $input->getArgument('book')));

            return Command::FAILURE;
        }
        try {
            $from = new \DateTimeImmutable((string) ($input->getOption('from') ?? 'first day of last month'));
            $to = new \DateTimeImmutable((string) ($input->getOption('to') ?? 'last day of last month'));
        } catch (\Exception) {
            $io->error('Dates are written 2026-10-31.');

            return Command::INVALID;
        }
        if ($to < $from) {
            $io->error('The period ends before it starts.');

            return Command::INVALID;
        }

        try {
            $run = $this->exporter->export($book, $from->setTime(0, 0), $to->setTime(0, 0), $input->getOption('gateway'), 'console');
        } catch (LedgerException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $summary = sprintf('[%s] %s to %s: %d contact(s), %d entr(ies) sent, %d already there, %d refused, %d changed since sent.', $run->getGateway(), $run->getFromDate()->format('d/m/Y'), $run->getToDate()->format('d/m/Y'), $run->getContacts(), $run->getEntries(), $run->getSkipped(), $run->getRefused(), $run->getChanged());
        if (ExportState::FAILED === $run->getState()) {
            $io->error($summary.' Stopped: '.$run->getError().' The next export of this period resumes it.');

            return Command::FAILURE;
        }
        $run->getRefused() + $run->getChanged() > 0 ? $io->warning($summary) : $io->success($summary);

        return Command::SUCCESS;
    }
}
