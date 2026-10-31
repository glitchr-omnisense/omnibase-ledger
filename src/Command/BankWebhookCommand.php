<?php

namespace Base\Ledger\Command;

use Base\Ledger\Bank\Webhooks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Registers each bank gateway's webhook that the application can register
 * on ledger_bank_webhook, its secret kept sealed: run at each deploy, it
 * does nothing when the webhooks are there, and nothing on a machine no
 * provider can reach.
 */
#[AsCommand(name: 'ledger:bank:webhook', description: 'Register the bank aggregators\' webhooks the application can register, their secrets kept sealed')]
final class BankWebhookCommand extends Command
{
    public function __construct(private readonly Webhooks $webhooks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('gateway', InputArgument::OPTIONAL, 'One gateway only (omnibank.gateways)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Register them again (new secrets) even when they are there');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $gateways = null !== $input->getArgument('gateway') ? [(string) $input->getArgument('gateway')] : $this->webhooks->gateways();
        if ([] === $gateways) {
            $io->note('No bank gateway is configured: no webhook to register.');

            return Command::SUCCESS;
        }

        $catchUp = 'ledger:bank:sync, scheduled, reads the banks without it.';
        $unreachable = array_filter(array_map($this->webhooks->unreachable(...), $gateways));
        if (\count($unreachable) === \count($gateways)) {
            $io->note(sprintf('No webhook registered: %s %s', reset($unreachable), $catchUp));

            return Command::SUCCESS;
        }
        foreach ($gateways as $gateway) {
            try {
                $done = $this->webhooks->ensure($gateway, (bool) $input->getOption('force'));
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s: not registered: %s', $gateway, $e->getMessage()));
                continue;
            }
            match ($done) {
                Webhooks::OFF => $io->warning(sprintf('No "%s" bank gateway is configured (omnibank.gateways).', $gateway)),
                Webhooks::UNSUPPORTED => $io->note(sprintf('%s: the application cannot register its webhook (its provider has no API for it). %s', $gateway, $catchUp)),
                Webhooks::CONFIGURED => $io->note(sprintf('%s: a webhook_secret is configured (an env value): it wins, nothing registered.', $gateway)),
                Webhooks::UNREACHABLE => $io->note(sprintf('%s: no webhook registered: %s %s', $gateway, $this->webhooks->unreachable($gateway), $catchUp)),
                Webhooks::NO_VAULT => $io->warning(sprintf('%s: no webhook registered: the environment has no vault key pair to seal its secret (php bin/console secrets:generate-keys --env=%s, or SYMFONY_DECRYPTION_SECRET). %s', $gateway, $_SERVER['APP_ENV'] ?? 'prod', $catchUp)),
                Webhooks::KEPT => $io->success(sprintf('%s: the webhook is registered on %s, nothing to do.', $gateway, $this->webhooks->url($gateway))),
                Webhooks::REGISTERED => $io->success(sprintf('%s: the webhook is registered on %s (%s), its secret kept sealed.', $gateway, $this->webhooks->url($gateway), $this->webhooks->registered($gateway)['id'])),
            };
        }

        return Command::SUCCESS;
    }
}
