<?php

namespace Base\Ledger\Command;

use Base\Ledger\Bank\Connections;
use Base\Ledger\Bank\Opening;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BookRepository;
use Base\Ledger\Service\Posting;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The bank accounts' opening entries (Bank\Opening), for the accounts a
 * sync or an upload has not given one yet: so that the books hold what the
 * bank says. With --adjust, an opening the bank's balance no longer agrees
 * with is reversed and posted again. With --reclassify, each account is
 * first moved onto the ledger account its kind belongs under (a loan from
 * 512x to 164x, Connections::reclassify()).
 */
#[AsCommand(name: 'ledger:bank:opening', description: 'Post the bank accounts\' opening entries so the books match the bank')]
class BankOpeningCommand extends Command
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly BankAccountRepository $bankAccounts,
        private readonly Opening $opening,
        private readonly Connections $connections,
        private readonly Posting $posting,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('book', InputArgument::OPTIONAL, 'Only this book: its id, SIREN or name')
            ->addOption('adjust', null, InputOption::VALUE_NONE, 'Replace an opening entry the bank\'s balance no longer agrees with')
            ->addOption('reclassify', null, InputOption::VALUE_NONE, 'First move each account onto the ledger account its kind belongs under (a loan on 164x)');
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

        $failed = 0;
        foreach ($this->bankAccounts->forBook($book) as $account) {
            $name = sprintf('%s / %s', $account->getBook()->getName(), $account->getName());
            try {
                if ($input->getOption('reclassify') && null !== ($moved = $this->connections->reclassify($account, $this->posting))) {
                    $io->writeln(sprintf('%s: now on %s.', $name, $moved->getNumber()));
                }
                $entry = $input->getOption('adjust') ? $this->opening->adjust($account) : $this->opening->open($account);
                $io->writeln(null === $entry
                    ? sprintf('%s: no opening entry (no bank balance, or nothing before the lines).', $name)
                    : sprintf('%s: opening %s on %s (%s, %s).', $name, number_format($this->opening->amountOf($entry, $account) / 100, 2, ',', ' '), $entry->getDate()->format('d/m/Y'), $account->getAccount()->getNumber(), $entry->getNumber() ?? 'draft'));
            } catch (LedgerException $e) {
                ++$failed;
                $io->warning(sprintf('%s: %s', $name, $e->getMessage()));
            }
        }

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
