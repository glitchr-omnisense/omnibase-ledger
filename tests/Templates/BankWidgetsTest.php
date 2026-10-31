<?php

namespace Tests\Base\Ledger\Templates;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\PaletteDashboardWidgetTypeInterface;
use Base\Ledger\Admin\Widget\Bank;
use Base\Ledger\Twig\LedgerExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Tests\Base\Ledger\Bank\InMemoryDashboard;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Every bank card rendered as the dashboard renders it: the widget type's
 * variables (getTemplateVars(), read through the Dashboard from a year of
 * precomputed rows) into its template, in French.
 */
class BankWidgetsTest extends InMemoryDashboard
{
    private Environment $twig;

    protected function setUp(): void
    {
        if (!class_exists(Environment::class) || !class_exists(TranslationExtension::class) || !class_exists(MenuItem::class)) {
            self::markTestSkipped('Twig, the Twig bridge and omnibase/admin are needed.');
        }
        parent::setUp();
        // Intl's default in another language than the page: amounts must follow the translator's.
        \Locale::setDefault('en_US');

        $root = \dirname(__DIR__, 2);
        $loader = new FilesystemLoader();
        $loader->addPath($root.'/templates', 'Ledger');
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $root.'/translations/ledger+intl-icu.fr.yaml', 'fr', 'ledger+intl-icu');
        $translator->addResource('yaml', $root.'/translations/admin.fr.yaml', 'fr', 'admin');

        $this->twig = new Environment($loader, ['strict_variables' => true]);
        $this->twig->addExtension(new TranslationExtension($translator));
        $this->twig->addExtension(new \Twig\Extension\AttributeExtension(LedgerExtension::class));
        $extension = new LedgerExtension($translator);
        $this->twig->addRuntimeLoader(new \Twig\RuntimeLoader\FactoryRuntimeLoader([LedgerExtension::class => fn () => $extension]));
        $this->twig->addFunction(new TwigFunction('path', fn (string $route, array $parameters = []) => '/'.$route.'?'.http_build_query($parameters)));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn (string $id) => 'token-'.$id));
    }

    /** @param class-string<Bank\AbstractBankWidgetType> $type */
    private function render(string $type, array $params = []): string
    {
        $widget = new $type($this->dashboard());
        \assert($widget instanceof PaletteDashboardWidgetTypeInterface);
        $item = (new MenuItem(MenuItem::TYPE_BLOCK, $widget->getDefaultLabel(), $widget->getDefaultIcon()))->setBlockName($widget::getName())->setParams($params);

        return $this->twig->render($widget->getTemplate(), $widget->getTemplateVars($item) + ['widget' => $item]);
    }

    public function testEveryCardIsAPaletteWidgetWithItsSettings(): void
    {
        $html = $this->render(Bank\StatisticsWidgetType::class, ['period' => '6m', 'accounts' => [1]]);

        self::assertStringContainsString('Statistiques générales', $html, 'the palette\'s name, from the admin domain');
        self::assertStringContainsString('data-widget-settings-toggle', $html);
        self::assertStringContainsString('data-widget-param="period"', $html);
        self::assertStringContainsString('<option value="6m" selected>', $html);
        self::assertMatchesRegularExpression('/data-widget-param="accounts" value="1" checked/', $html);
        self::assertStringContainsString('· 1 compte', $html, 'a pick says how many accounts');
        self::assertSame(1, substr_count($html, '<style>'), 'the style once');

        $second = $this->render(Bank\KindsWidgetType::class);
        self::assertStringNotContainsString('<style>', $second, 'not twice in a page');
    }

    public function testStatistics(): void
    {
        $html = $this->render(Bank\StatisticsWidgetType::class, ['period' => '12m']);

        self::assertSame(5, substr_count($html, 'class="ld-chart"'), 'every period drawn, switched in the card');
        self::assertStringContainsString('value="12m" checked', $html);
        self::assertStringContainsString('data-series="balance"', $html);
        self::assertStringContainsString('<polyline class="ld-line"', $html);
        self::assertStringContainsString('class="ld-tip"', $html);
        self::assertStringContainsString('<table class="ld-sr">', $html, 'a table for screen readers');
        self::assertMatchesRegularExpression('/40\s200,00/u', $html, 'the cash accounts\' balance at the last bucket');
    }

    public function testKindsBalanceConnectionsTransfers(): void
    {
        $kinds = $this->render(Bank\KindsWidgetType::class);
        self::assertStringContainsString('Compte courant', $kinds);
        self::assertStringContainsString('Prêt', $kinds);
        self::assertSame(3, substr_count($kinds, '<circle class="ld-arc'), 'one arc a kind');
        self::assertStringContainsString('33 %', $kinds);

        $balance = $this->render(Bank\BalanceWidgetType::class);
        self::assertMatchesRegularExpression('/40\s200,00/u', $balance, 'the current account and the Livret A');
        self::assertStringContainsString('-169', $balance, 'the loan beside, not in it');
        self::assertStringContainsString('Net', $balance);

        $connections = $this->render(Bank\ConnectionsWidgetType::class);
        self::assertStringContainsString('3 connexions', $connections, 'the page left three days ago apart');
        self::assertStringContainsString('Action requise', $connections);
        self::assertStringContainsString('67 %', $connections);
        self::assertStringContainsString('ledger_admin_bank_renew', $connections, 'the way to act');

        $transfers = $this->render(Bank\TransfersWidgetType::class);
        self::assertStringContainsString('virement', $transfers);
        self::assertStringContainsString('le mois dernier', $transfers);
    }

    public function testCategoriesCounterpartiesRecurringForecast(): void
    {
        $categories = $this->render(Bank\CategoriesWidgetType::class, ['period' => '12m']);
        self::assertStringContainsString('Emprunts', $categories);
        self::assertStringContainsString('Frais bancaires', $categories);
        self::assertStringContainsString('dépensés', $categories);

        $counterparties = $this->render(Bank\CounterpartiesWidgetType::class, ['period' => '12m']);
        self::assertStringContainsString('Thomas Weber', $counterparties);
        self::assertStringContainsString('Ont le plus reçu', $counterparties);

        $recurring = $this->render(Bank\RecurringWidgetType::class);
        self::assertStringContainsString('chaque mois', $recurring);
        self::assertStringContainsString('Pret Immobilier', $recurring);
        self::assertStringNotContainsString('Old Software', $recurring);

        $forecast = $this->render(Bank\ForecastWidgetType::class);
        self::assertStringContainsString('Dans 90 jours', $forecast);
        self::assertStringContainsString('Prochaines échéances', $forecast);
        self::assertSame(3, substr_count($forecast, 'class="ld-mark"'));
    }

    public function testReconciliationFreshnessMovements(): void
    {
        $reconciliation = $this->render(Bank\ReconciliationWidgetType::class);
        self::assertStringContainsString('lignes à traiter', $reconciliation);
        self::assertStringContainsString('/ledger_admin_reconcile?bankAccount=1', $reconciliation);

        $freshness = $this->render(Bank\FreshnessWidgetType::class);
        self::assertStringContainsString('jamais', $freshness, 'the loan never synced');
        self::assertStringContainsString('il y a 10 jours', $freshness);
        self::assertStringContainsString('ledger_admin_bank_sync', $freshness);

        $movements = $this->render(Bank\MovementsWidgetType::class);
        self::assertStringContainsString('Plomberie Kieffer', $movements, 'the largest of the month');
        self::assertMatchesRegularExpression('/2\s350,00/u', $movements);
    }

    public function testAmountsInThePagesLanguageWhateverIntlsDefault(): void
    {
        $html = $this->render(Bank\BalanceWidgetType::class);

        self::assertMatchesRegularExpression('/40\s200,00\s€/u', $html, 'French, as the page (the translator), with intl\'s default in American English');
        self::assertStringNotContainsString('40,200.00', $html);
        self::assertDoesNotMatchRegularExpression('/\(\+?-?\d+\.\d %\)/', $html, 'the change\'s percentage with a decimal comma too');
    }

    public function testAHeavyMonthIsClippedNotFlatteningTheOthers(): void
    {
        // A term deposit opened two months ago: 200 000,00 out in a day, where a month moves 2 000,00.
        $this->fold($this->bankAccount(), [...$this->checkingLines, $this->line(-20000000, 'VIR COMPTE A TERME', 'Meyers', null, \Omnibank\Model\TransactionCategory::SAVINGS, $this->today()->modify('first day of this month')->modify('-2 months +5 days'))]);
        $html = $this->render(Bank\StatisticsWidgetType::class, ['period' => '12m']);

        self::assertStringContainsString('ld-bar ld-bar-out is-clipped', $html);
        self::assertStringContainsString('Barres coupées au-delà de', $html);
        self::assertMatchesRegularExpression('/ld-clip-value">200\sk€/u', $html, 'its value written in full');
        self::assertSame(2400, \Base\Ledger\Admin\Widget\Chart::robustMax([2400, 2000, 1500, 1800]), 'nothing towering: the largest');
        self::assertSame(2000, \Base\Ledger\Admin\Widget\Chart::robustMax([2000000, 2000, 1500, 1800]));
        self::assertSame(2000, \Base\Ledger\Admin\Widget\Chart::robustMax([2000000, 900000, 2000, 1500]), 'two towering ones');
        self::assertSame(2500, \Base\Ledger\Admin\Widget\Chart::robustMax([2500, 2000]), 'close: the largest kept');
    }

    public function testAbandonedConnectionsApartAndRemovable(): void
    {
        $html = $this->render(Bank\ConnectionsWidgetType::class);

        self::assertStringContainsString('3 connexions', $html, 'the abandoned one not counted');
        self::assertStringContainsString('Abandonnée', $html);
        self::assertStringContainsString('1 abandonnée, hors décompte', $html);
        self::assertStringContainsString('action="/ledger_admin_bank_remove?connection=3"', $html);
        self::assertStringContainsString('value="token-ledger_bank_remove_3"', $html);
        self::assertStringContainsString('action="/ledger_admin_bank_remove?connection=4"', $html, 'the one just opened: removable too, still counted');
        self::assertStringNotContainsString('ledger_admin_bank_remove?connection=1"', $html, 'never one with accounts');
    }

    public function testNoAccountYet(): void
    {
        $this->bankAccounts = [];
        $html = $this->render(Bank\StatisticsWidgetType::class);

        self::assertStringContainsString('Aucun compte bancaire', $html);
    }
}
