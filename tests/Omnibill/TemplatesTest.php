<?php

namespace Tests\Base\Ledger\Omnibill;

use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\VatRegime;
use Base\Ledger\Twig\LedgerExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The suppliers' invoices pages' own block,
 * rendered with an in-memory book and the bundle's French translations.
 */
class TemplatesTest extends InMemoryOmnibill
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
        $loader->addPath($root.'/templates', 'Admin');
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $root.'/translations/ledger+intl-icu.fr.yaml', 'fr', 'ledger+intl-icu');

        $this->twig = new Environment($loader, ['strict_variables' => true]);
        $this->twig->addExtension(new TranslationExtension($translator));
        $this->twig->addExtension(new \Twig\Extension\AttributeExtension(LedgerExtension::class));
        $this->twig->addRuntimeLoader(new \Twig\RuntimeLoader\FactoryRuntimeLoader([LedgerExtension::class => fn () => new LedgerExtension()]));
        $this->twig->addFunction(new TwigFunction('path', fn (string $route, array $parameters = []) => '/'.$route.'?'.http_build_query($parameters)));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn (string $id) => 'token-'.$id));
        $this->twig->addFunction(new TwigFunction('is_granted', fn () => true));
    }

    private function block(string $template, array $context): string
    {
        return $this->twig->load($template)->renderBlock('content', $context + ['admin_context' => null]);
    }

    public function testTheInvoicesListAndAnInvoiceProposed(): void
    {
        $html = $this->block('@Ledger/admin/invoices/index.html.twig', ['book' => $this->book, 'available' => false]);
        self::assertStringContainsString('omnisense/omnibill', $html);

        $this->book->setVatRegime(VatRegime::NORMAL_MONTHLY);
        $this->frenchTaxes();
        $this->account('6061', 'Énergie');
        $edf = $this->party('EDF', PartyKind::SUPPLIER, 'siren:552081317');
        $invoice = $this->supplierInvoices()->import(Invoices::cii(), 'FA-2026-118.xml');
        $invoice->setParty($edf)->setAccount('6061');

        $html = $this->block('@Ledger/admin/invoices/index.html.twig', ['book' => $this->book, 'available' => true, 'readable' => true, 'gateway' => null, 'tab' => 'open', 'tabs' => ['open', 'booked', 'paid', 'refused', 'duplicate'], 'counts' => ['proposed' => 1], 'invoices' => [$invoice]]);
        self::assertStringContainsString('EDF Entreprises', $html);
        self::assertStringContainsString('name="invoice"', $html);
        self::assertStringContainsString('token-ledger_invoices_upload_1', $html);
        self::assertStringContainsString('À valider', $html);

        $html = $this->block('@Ledger/admin/invoices/show.html.twig', [
            'book' => $this->book, 'invoice' => $invoice, 'credit_note' => false,
            'proposal' => $this->proposer()->lines($invoice, '6061', $edf->getAccountNumber()),
            'chart' => [$this->accounts['6061']], 'suppliers' => [$edf],
        ]);
        self::assertStringContainsString('Écriture proposée', $html);
        self::assertStringContainsString('44566', $html);
        self::assertStringContainsString('A20', $html);
        self::assertStringContainsString('name="account"', $html);
        self::assertStringContainsString('value="6061"', $html);
        self::assertStringContainsString('name="reason"', $html);
        self::assertStringContainsString('token-ledger_invoice_'.$invoice->getId(), $html);
    }
}
