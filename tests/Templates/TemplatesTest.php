<?php

namespace Tests\Base\Ledger\Templates;

use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Enum\RuleSign;
use Base\Ledger\Report\BalanceSheet;
use Base\Ledger\Report\GeneralLedger;
use Base\Ledger\Report\IncomeStatement;
use Base\Ledger\Report\OpenItems;
use Base\Ledger\Report\TrialBalance;
use Base\Ledger\Twig\LedgerExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Tests\Base\Ledger\InMemoryLedger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The back-office pages' own block (the admin layout around it needs the
 * admin's runtime) and the dashboard widgets, rendered with an in-memory
 * book and the bundle's French translations.
 */
class TemplatesTest extends InMemoryLedger
{
    private Environment $twig;

    protected function setUp(): void
    {
        if (!class_exists(Environment::class) || !class_exists(TranslationExtension::class)) {
            self::markTestSkipped('Twig and the Twig bridge are needed.');
        }
        parent::setUp();

        $root = \dirname(__DIR__, 2);
        $loader = new FilesystemLoader();
        $loader->addPath($root.'/templates', 'Ledger');
        $admin = \dirname((new \ReflectionClass(\Base\Admin\Controller\AbstractCrudController::class))->getFileName(), 3).'/templates';
        $loader->addPath(is_dir($admin) ? $admin : $root.'/templates', 'Admin');

        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $root.'/translations/ledger+intl-icu.fr.yaml', 'fr', 'ledger+intl-icu');

        $this->twig = new Environment($loader, ['strict_variables' => true]);
        $this->twig->addExtension(new TranslationExtension($translator));
        $this->twig->addExtension(new \Twig\Extension\AttributeExtension(LedgerExtension::class));
        $this->twig->addRuntimeLoader(new \Twig\RuntimeLoader\FactoryRuntimeLoader([LedgerExtension::class => fn () => new LedgerExtension()]));
        $this->twig->addFunction(new TwigFunction('path', fn (string $route, array $parameters = []) => '/'.$route.'?'.http_build_query($parameters)));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn (string $id) => 'token-'.$id));
    }

    private function block(string $template, array $context): string
    {
        return $this->twig->load($template)->renderBlock('content', $context + ['admin_context' => null]);
    }

    public function testTheReconciliationPage(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3');
        $this->notice($dupont, 85000, 'AVIS-2026-10-U3');
        $this->rules = [$this->persisted(new ReconciliationRule($this->book, 'Frais', 'FRAIS', $this->accounts['627'], RuleSign::OUT))];
        $line = $this->bankLine(85000, 'VIR SEPA AVIS-2026-10-U3', 'FR7610278060000002027280109', 'M DUPONT');
        $done = $this->bankLine(-1250, 'FRAIS TENUE');
        $this->reconciler()->apply($done, $this->matcher()->suggest($done)[0]);
        $openItems = $this->matcher()->openItems($this->book);

        $html = $this->block('@Ledger/admin/reconcile.html.twig', [
            'account' => $this->bankAccount(), 'book' => $this->book,
            'pending' => [['line' => $line, 'suggestions' => $this->matcher()->suggest($line, $openItems), 'candidates' => $openItems]],
            'done' => [$done], 'rules' => $this->rules, 'parties' => $this->parties, 'threshold' => 0.9, 'can_manage' => true,
        ]);

        self::assertStringContainsString('1 ligne à rapprocher', $html);
        self::assertStringContainsString('99 %', $html);
        self::assertStringContainsString('Référence AVIS202610U3', $html);
        self::assertStringContainsString('name="items[]"', $html);
        self::assertStringContainsString('token-ledger_line_'.$line->getId(), $html);
        self::assertStringContainsString('/ledger_admin_reconcile_undo?line='.$done->getId(), $html);
        self::assertStringContainsString('Rapprochée', $html);
    }

    public function testTheReports(): void
    {
        $dupont = $this->party('Jean Dupont', reference: 'U3');
        $this->notice($dupont, 85000, 'A1');
        $context = ['book' => $this->book, 'from' => new \DateTimeImmutable('2026-01-01'), 'to' => new \DateTimeImmutable('2026-12-31'), 'year' => '2026'];

        $totals = [];
        foreach ($this->allLines() as $line) {
            $n = $line->getAccount()->getNumber();
            $totals[$n] ??= ['number' => $n, 'label' => $line->getAccount()->getLabel(), 'type' => $line->getAccount()->getType(), 'debit' => 0, 'credit' => 0];
            $totals[$n]['debit'] += $line->getDebit();
            $totals[$n]['credit'] += $line->getCredit();
        }
        $html = $this->block('@Ledger/admin/trial_balance.html.twig', $context + ['report' => TrialBalance::fromTotals(array_values($totals))]);
        self::assertStringContainsString('411U3', $html);
        self::assertStringContainsString('850,00', $html);
        self::assertStringContainsString('/ledger_admin_fec?book=1&amp;year=2026', $html);

        $html = $this->block('@Ledger/admin/income_statement.html.twig', $context + [
            'compare' => true, 'previous_from' => new \DateTimeImmutable('2025-01-01'), 'previous_to' => new \DateTimeImmutable('2025-12-31'),
            'report' => IncomeStatement::fromTotals(array_values($totals), [['number' => '706', 'label' => 'Loyers', 'type' => 'income', 'debit' => 0, 'credit' => 60000]]),
        ]);
        self::assertStringContainsString('Compte de résultat', $html);
        self::assertStringContainsString('Loyers', $html);
        self::assertStringContainsString('N-1', $html);
        self::assertStringContainsString('600,00', $html);
        self::assertStringContainsString('bénéfice', $html);
        self::assertStringContainsString('name="n1" value="1" checked', $html);
        self::assertStringContainsString('/ledger_admin_general_ledger?book=1&amp;from=2026-01-01&amp;to=2026-12-31&amp;account=706', $html);
        self::assertStringContainsString('format=csv', $html);
        self::assertStringNotContainsString('Autres charges', $html);

        $html = $this->block('@Ledger/admin/balance_sheet.html.twig', ['book' => $this->book, 'at' => new \DateTimeImmutable('2026-12-31'), 'year' => '2026', 'compare' => false,
            'previous_at' => new \DateTimeImmutable('2025-12-31'), 'report' => BalanceSheet::fromTotals(array_values($totals))]);
        self::assertStringContainsString('Actif', $html);
        self::assertStringContainsString('Créances locataires et clients', $html);
        self::assertStringContainsString('Résultat de l&#039;exercice', $html);
        self::assertStringNotContainsString('<th class="num">N-1</th>', $html);
        self::assertStringNotContainsString('actif et passif diffèrent', $html);
        self::assertMatchesRegularExpression('#Total actif</th>\s*<th class="num">850,00</th>#', $html);
        self::assertMatchesRegularExpression('#Total passif</th>\s*<th class="num">850,00</th>#', $html);

        $html = $this->block('@Ledger/admin/general_ledger.html.twig', $context + ['prefix' => '411', 'accounts' => GeneralLedger::fromLines($this->allLines())]);
        self::assertStringContainsString('Loyer Jean Dupont', $html);

        $html = $this->block('@Ledger/admin/open_items.html.twig', ['book' => $this->book, 'prefix' => '411', 'report' => OpenItems::fromLines($this->lettering()->openItems($this->book))]);
        self::assertStringContainsString('Jean Dupont', $html);
        self::assertStringContainsString('A1', $html);
    }

    public function testTheUploadAndConnectPages(): void
    {
        $html = $this->block('@Ledger/admin/upload.html.twig', ['account' => $this->bankAccount(), 'available' => true]);
        self::assertStringContainsString('type="file"', $html);
        self::assertStringContainsString('token-ledger_upload_1', $html);

        $html = $this->block('@Ledger/admin/connect_files.html.twig', ['book' => $this->book]);
        self::assertStringContainsString('name="iban"', $html);
    }

    public function testTheWidgets(): void
    {
        $widget = new class {
            public string $label = 'Banque';
        };
        $line = $this->bankLine(-1250, 'FRAIS TENUE COMPTE');
        $connection = $this->bankAccount()->getConnection();
        $connection->setConsentStatus(\Base\Ledger\Enum\ConsentState::NEEDS_RENEWAL);

        $html = $this->twig->render('@Ledger/admin/widget/bank.html.twig', ['widget' => $widget, 'allowed' => true, 'can_manage' => true, 'book' => $this->book, 'files' => true, 'gateways' => ['powens' => 'Powens'],
            'accounts' => [['account' => $this->bankAccount(), 'balance' => 125000, 'bankBalance' => 124000, 'bankBalanceAt' => new \DateTimeImmutable(), 'connection' => $connection, 'needsRenewal' => true, 'pending' => 1]],
            'latest' => [$line]]);
        self::assertStringContainsString('FR76 •••• 0189', $html);
        self::assertStringContainsString('Renouveler', $html);
        self::assertStringContainsString('1 à rapprocher', $html);
        self::assertStringContainsString('is-out', $html);
        self::assertStringContainsString('À rapprocher', $html);
        // A book that already has an account (a statement imported) can still connect a bank.
        self::assertSame(1, substr_count($html, 'Connecter une banque (Powens)'));

        $html = $this->twig->render('@Ledger/admin/widget/bank.html.twig', ['widget' => $widget, 'allowed' => true, 'can_manage' => false, 'book' => $this->book, 'files' => true, 'gateways' => ['powens' => 'Powens'],
            'accounts' => [['account' => $this->bankAccount(), 'balance' => 125000, 'bankBalance' => null, 'bankBalanceAt' => null, 'connection' => $connection, 'needsRenewal' => false, 'pending' => 0]], 'latest' => []]);
        self::assertStringNotContainsString('Connecter une banque', $html, 'only who manages the books connects a bank');

        $html = $this->twig->render('@Ledger/admin/widget/bank.html.twig', ['widget' => $widget, 'allowed' => true, 'can_manage' => true, 'book' => $this->book, 'files' => true, 'gateways' => ['powens' => 'Powens', 'bridge' => 'Bridge'], 'accounts' => [], 'latest' => []]);
        self::assertSame(2, substr_count($html, 'Connecter une banque'));
        self::assertStringContainsString('Importer un relevé', $html);

        $html = $this->twig->render('@Ledger/admin/widget/books.html.twig', ['widget' => $widget, 'books' => [[
            'book' => $this->book, 'year' => '2026', 'yearEnd' => '2026', 'from' => new \DateTimeImmutable('2026-01-01'), 'to' => new \DateTimeImmutable('2026-10-03'),
            'income' => 1020000, 'expenses' => 240000, 'result' => 780000, 'receivables' => 85000, 'payables' => 0, 'cash' => 1250000,
        ]]]);
        self::assertStringContainsString('Résultat', $html);
        self::assertStringContainsString('/ledger_admin_open_items?book=1&amp;account=411', $html);
        self::assertStringContainsString('/ledger_admin_income_statement?book=1&amp;from=2026-01-01&amp;to=2026-10-03', $html);
        self::assertStringContainsString('/ledger_admin_balance_sheet?book=1&amp;at=2026-10-03', $html);
    }
}
