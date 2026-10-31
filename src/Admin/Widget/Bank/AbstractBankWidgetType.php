<?php

namespace Base\Ledger\Admin\Widget\Bank;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Router\AdminRouteRegistry;
use Base\Admin\Widget\PaletteDashboardWidgetTypeInterface;
use Base\Ledger\Bank\Dashboard;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Security\Voter\LedgerVoter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * A bank card of the dashboard: one of omnibase/admin's palette widget
 * types, so any site with omnibase/ledger offers it in "+ Add widget" and a
 * dashboard controller can place it by name:
 *
 *   MenuItem::block('ledger_bank_statistics', 'Statistiques', 'fa-solid fa-chart-column')->setSize(3)->setParams(['period' => '6m'])
 *
 * Its parameters, set in the layout editor through the card's ⋮ panel:
 * "period" (10d, 1m, 3m, 6m, 12m; for the cards with one) and "accounts"
 * (bank account ids; none is every account the reader may see). What it
 * shows is read from the precomputed rows (Bank\Dashboard), never the lines.
 */
abstract class AbstractBankWidgetType implements PaletteDashboardWidgetTypeInterface
{
    /** The card's default period, null for a card that has none. */
    protected const PERIOD = null;

    /**
     * Whether the card's money figures leave the credits out (loans,
     * consumer and revolving credits) when the reader picked no account: a
     * mortgage's balance and capital lines are not cash, and would swamp
     * the flows, the forecast and the categories.
     */
    protected const CASH = false;

    /** A small card - one figure and its context -, two to a row on a phone (admin's .dash-card--compact). */
    protected const COMPACT = false;

    public function __construct(
        protected readonly Dashboard $dashboard,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
        private readonly ?AdminRouteRegistry $routes = null,
        private readonly ?UrlGeneratorInterface $urls = null,
    ) {
    }

    /** The back office's list of $entityClass, when the admin has one and the reader may open it. */
    protected function listUrl(string $entityClass): ?string
    {
        $controller = $this->routes?->getControllerForEntity($entityClass);
        if (null === $controller || null === $this->urls) {
            return null;
        }
        try {
            return $this->urls->generate($this->routes->getRouteName($controller, 'index'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** The template's name under @Ledger/admin/widget/bank/. */
    abstract protected function template(): string;

    /**
     * The card's own figures.
     *
     * @param list<BankAccount>                                                                       $accounts
     * @param array{key: string, from: \DateTimeImmutable, to: \DateTimeImmutable, bucket: string}|null $period
     *
     * @return array<string, mixed>
     */
    abstract protected function data(array $accounts, ?array $period, array $params): array;

    public function getTemplate(): string
    {
        return '@Ledger/admin/widget/bank/'.$this->template().'.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        $params = $widget->getParams();
        $books = $this->dashboard->books();
        $vars = ['allowed' => [] !== $books, 'card' => $this->template(), 'compact' => static::COMPACT, 'periods' => null === static::PERIOD ? [] : array_keys(Dashboard::PERIODS), 'period' => null, 'accounts' => [], 'allAccounts' => [], 'picked' => [], 'currency' => 'EUR', 'book' => $books[0] ?? null, 'can_manage' => false];
        if (!$vars['allowed']) {
            return $vars;
        }
        $accounts = $this->dashboard->accounts($params, static::CASH);
        $period = null === static::PERIOD ? null : Dashboard::period(\is_string($params['period'] ?? null) ? $params['period'] : null, static::PERIOD);

        return array_replace($vars, [
            'period' => $period,
            'accounts' => $accounts['shown'],
            'allAccounts' => $accounts['all'],
            'picked' => array_values(array_intersect(array_map(static fn (BankAccount $a) => $a->getId(), $accounts['all']), array_map('intval', array_filter((array) ($params['accounts'] ?? []), 'is_numeric')))),
            'currency' => $this->dashboard->currency($accounts['shown']),
            'can_manage' => null === $this->authorization || $this->authorization->isGranted(LedgerVoter::MANAGE, $books[0]),
        ]) + $this->data($accounts['shown'], $period, $params);
    }
}
