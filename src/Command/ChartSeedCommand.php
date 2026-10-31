<?php

namespace Base\Ledger\Command;

use Base\Ledger\Repository\BookRepository;
use Base\Ledger\Service\Chart;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Gives a book the accounts and journals of a chart (config/charts/), keeping what it has. */
#[AsCommand(name: 'ledger:chart:seed', description: 'Add a chart\'s accounts, journals and VAT codes to a book (the missing ones)')]
class ChartSeedCommand extends Command
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly Chart $chart,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('book', InputArgument::REQUIRED, 'The book: its id, SIREN or name')
            ->addOption('chart', null, InputOption::VALUE_REQUIRED, 'The chart, a file of config/charts/ (default: ledger.chart)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $book = $this->books->resolve((string) $input->getArgument('book'));
        if (null === $book) {
            $io->error(sprintf('No book "%s".', $input->getArgument('book')));

            return Command::FAILURE;
        }

        $this->chart->seed($book, $input->getOption('chart'));
        $io->success(sprintf('%s: chart seeded.', $book->getName()));

        return Command::SUCCESS;
    }
}
