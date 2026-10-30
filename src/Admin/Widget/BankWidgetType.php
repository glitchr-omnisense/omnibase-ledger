<?php

namespace Base\Ledger\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\PaletteDashboardWidgetTypeInterface;
use Base\Ledger\Bank\Connections;
use Base\Ledger\Bank\StatementUpload;
use Base\Ledger\Entity\Book;
use Base\Ledger\Enum\BankLineStatus;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankLineRepository;
use Base\Ledger\Repository\BookRepository;
use Base\Ledger\Repository\EntryLineRepository;
use Base\Ledger\Security\Voter\LedgerVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The banks at a glance, on the back office's dashboard: each account with
 * its balance in the books (its 512) and at the bank, when it last synced,
 * its consent ("Renouveler" when the bank asks), what waits to be
 * reconciled; then the latest lines across accounts. Without any account
 * yet: the buttons to connect a bank (the gateways configured in Omnibank)
 * or to import a statement.
 *
 *   MenuItem::block('ledger_bank', 'Banque', 'fa-solid fa-building-columns')
 */
final class BankWidgetType implements PaletteDashboardWidgetTypeInterface
{
    /** Gateways a user connects to through the provider's page, by their usual names. */
    private const LABELS = ['powens' => 'Powens', 'bridge' => 'Bridge', 'qonto' => 'Qonto'];

    public function __construct(
        private readonly BankAccountRepository $bankAccounts,
        private readonly BankLineRepository $bankLines,
        private readonly EntryLineRepository $entryLines,
        private readonly BookRepository $books,
        private readonly Connections $connections,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    public static function getName(): string
    {
        return 'ledger_bank';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.bank';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-building-columns';
    }

    public function getTemplate(): string
    {
        return '@Ledger/admin/widget/bank.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        $books = array_values(array_filter($this->books->findBy([], ['id' => 'ASC']), fn (Book $book) => $this->authorization->isGranted(LedgerVoter::VIEW, $book)));
        if ([] === $books) {
            return ['allowed' => false, 'accounts' => [], 'latest' => [], 'gateways' => [], 'files' => false, 'book' => null];
        }

        $accounts = [];
        $all = [];
        foreach ($books as $book) {
            foreach ($this->bankAccounts->forBook($book) as $account) {
                $all[] = $account;
                $counts = $this->bankLines->countByStatus($account);
                $accounts[] = [
                    'account' => $account,
                    'balance' => $this->entryLines->balance($account->getAccount()),
                    'bankBalance' => $account->getBankBalance(),
                    'bankBalanceAt' => $account->getBankBalanceAt(),
                    'connection' => $account->getConnection(),
                    'needsRenewal' => StatementUpload::GATEWAY !== $account->getConnection()->getGateway() && $account->getConnection()->needsRenewal(),
                    'pending' => ($counts[BankLineStatus::UNMATCHED->value] ?? 0) + ($counts[BankLineStatus::SUGGESTED->value] ?? 0),
                ];
            }
        }

        $latest = [];
        foreach ($all as $account) {
            foreach ($this->bankLines->latest($account, 6) as $line) {
                $latest[] = $line;
            }
        }
        usort($latest, fn ($a, $b) => [$b->getBookedOn(), $b->getId()] <=> [$a->getBookedOn(), $a->getId()]);

        $gateways = [];
        foreach ($this->connections->gateways() as $name) {
            if (StatementUpload::GATEWAY !== $name) {
                $gateways[$name] = self::LABELS[$name] ?? ucfirst($name);
            }
        }

        return [
            'allowed' => true,
            'book' => $books[0],
            'accounts' => $accounts,
            'latest' => \array_slice($latest, 0, 6),
            'gateways' => $gateways,
            'files' => \in_array(StatementUpload::GATEWAY, $this->connections->gateways(), true),
            'can_manage' => $this->authorization->isGranted(LedgerVoter::MANAGE, $books[0]),
        ];
    }
}
