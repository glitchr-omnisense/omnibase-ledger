<?php

namespace Base\Ledger\Command;

use Base\Ledger\Bank\Aggregates;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BookRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the bank accounts' precomputed rows again (all books, or one):
 * the days, categories, counterparties and recurring flows the dashboard
 * reads. A sync and a statement upload already do it for their account;
 * this catches up after an import by other means, a change of the rules,
 * and moves the recurring flows' "next date" on. Suggested cron, once a
 * day:
 *
 *   30 4 * * *  bin/console ledger:bank:aggregate
 */
#[AsCommand(name: 'ledger:bank:aggregate', description: 'Rebuild the bank accounts\' precomputed rows the dashboard reads')]
class BankAggregateCommand extends Command
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly BankAccountRepository $bankAccounts,
        private readonly Aggregates $aggregates,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('book', InputArgument::OPTIONAL, 'Only this book: its id, SIREN or name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $book = null;
        if (null !== $input->getArgument('book')) {
            $book = $this->books->resolve((string) $input->getArgument('book'));
            if (null === $book) {
                $io->error(sprintf('No book "%s".', $input->getArgument('book')));

                return Command::FAILURE;
            }
        }

        foreach ($this->bankAccounts->forBook($book) as $account) {
            $rows = $this->aggregates->rebuild($account);
            $io->writeln(sprintf('%s / %s: %d day(s), %d category row(s), %d counterparty row(s), %d recurring flow(s).', $account->getBook()->getName(), $account->getName(), $rows['days'], $rows['categories'], $rows['counterparties'], $rows['recurring']));
        }

        return Command::SUCCESS;
    }
}
