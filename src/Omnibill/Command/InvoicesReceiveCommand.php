<?php

namespace Base\Ledger\Omnibill\Command;

use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Omnibill\Invoice\SupplierInvoices;
use Base\Ledger\Repository\BookRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Receives the suppliers' invoices of the receiving gateway (an approved
 * platform through omnibill/afnor) from where the last run stopped, then
 * sends again the lifecycle statuses the gateway could not take. --file
 * takes invoice files in instead (those a supplier mailed: Factur-X, CII,
 * UBL). Run it from a scheduler; nothing is posted, everything is proposed.
 */
#[AsCommand(name: 'ledger:invoices:receive', description: 'Receive the suppliers\' invoices through omnibill and propose them as entries')]
class InvoicesReceiveCommand extends Command
{
    public function __construct(
        private readonly SupplierInvoices $invoices,
        private readonly BookRepository $books,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('gateway', InputArgument::OPTIONAL, 'The omnibill gateway (ledger.invoices.gateway, else the first that receives)')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'An invoice file to take in instead (repeatable)')
            ->addOption('book', 'b', InputOption::VALUE_REQUIRED, 'The book the files go to: its id, SIREN or name (the buyer\'s SIREN on the invoice otherwise)')
            ->addOption('pages', 'p', InputOption::VALUE_REQUIRED, 'At most this many pages of 25 flows', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $files = (array) $input->getOption('file');
        if ([] !== $files) {
            $book = null;
            if (null !== $input->getOption('book') && null === ($book = $this->books->resolve((string) $input->getOption('book')))) {
                $io->error(sprintf('No book "%s".', $input->getOption('book')));

                return Command::FAILURE;
            }
            foreach ($files as $path) {
                $content = @file_get_contents((string) $path);
                if (false === $content) {
                    $io->error(sprintf('Cannot read %s.', $path));

                    return Command::FAILURE;
                }
                try {
                    $invoice = $this->invoices->import($content, basename((string) $path), $book);
                } catch (LedgerException $e) {
                    $io->error($e->getMessage());

                    return Command::FAILURE;
                }
                $io->writeln(sprintf('%s: %s, %s %s', basename((string) $path), $invoice->getStatus()->value, $invoice->getSupplierName() ?? '?', $invoice->getNumber() ?? ''));
            }

            return Command::SUCCESS;
        }

        $report = $this->invoices->receive($input->getArgument('gateway'), max(1, (int) $input->getOption('pages')));
        $sent = $this->invoices->sendPending();
        if (null !== $report['error']) {
            $io->warning(sprintf('%s%s', null !== $report['gateway'] ? '['.$report['gateway'].'] ' : '', $report['error']));
        }
        $io->writeln(sprintf('%s: %d received, %d duplicate(s), %d unreadable, %d already there; %d status(es) sent.', $report['gateway'] ?? 'no gateway', $report['received'], $report['duplicates'], $report['unreadable'], $report['skipped'], $sent));

        return null === $report['error'] ? Command::SUCCESS : Command::FAILURE;
    }
}
