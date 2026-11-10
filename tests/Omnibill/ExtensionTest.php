<?php

namespace Tests\Base\Ledger\Omnibill;

use Base\Ledger\Controller\Admin\AccountingExportController;
use Base\Ledger\Controller\Admin\SupplierInvoiceController;
use Base\Ledger\DependencyInjection\LedgerExtension;
use Omnibill\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * With omnisense/omnibill, its side is registered and mapped; the back
 * office's controllers take it as optional, so that without it they load
 * and say what is missing.
 */
class ExtensionTest extends TestCase
{
    public function testTheServicesAndTheMappingWithOmnibill(): void
    {
        if (!class_exists(Registry::class)) {
            self::markTestSkipped('omnisense/omnibill is not on the autoloader.');
        }
        $container = new ContainerBuilder();
        $extension = new LedgerExtension();
        $extension->prepend($container);
        $extension->load([[]], $container);

        foreach (['Base\\Ledger\\Omnibill\\Invoice\\SupplierInvoices', 'Base\\Ledger\\Omnibill\\Invoice\\InvoiceProposer', 'Base\\Ledger\\Omnibill\\Invoice\\PaidInvoiceListener', 'Base\\Ledger\\Omnibill\\Export\\AccountingExporter', 'Base\\Ledger\\Omnibill\\Command\\InvoicesReceiveCommand', 'Base\\Ledger\\Omnibill\\Command\\AccountingExportCommand'] as $id) {
            self::assertTrue($container->hasDefinition($id), $id);
        }
        foreach (['Base\\Ledger\\Omnibill\\Entity\\SupplierInvoice', 'Base\\Ledger\\Omnibill\\Invoice\\LifecycleMessageInterface'] as $id) {
            self::assertTrue(!$container->hasDefinition($id) || $container->getDefinition($id)->hasTag('container.excluded'), $id);
        }
        self::assertNull($container->getParameter('ledger.invoices.gateway'));
        self::assertNull($container->getParameter('ledger.accounting.gateway'));
        $mappings = $container->getExtensionConfig('doctrine')[0]['orm']['mappings'] ?? [];
        self::assertSame('Base\\Ledger\\Omnibill\\Entity', $mappings['LedgerOmnibill']['prefix'] ?? null);
    }

    public function testTheControllersTakeOmnibillsSideAsOptional(): void
    {
        foreach ([SupplierInvoiceController::class, AccountingExportController::class] as $controller) {
            foreach ((new \ReflectionClass($controller))->getConstructor()->getParameters() as $parameter) {
                $type = (string) $parameter->getType();
                if (str_contains($type, 'Omnibill') || str_contains($type, 'Validator')) {
                    self::assertTrue($parameter->allowsNull() && $parameter->isDefaultValueAvailable(), $controller.'::$'.$parameter->getName());
                }
            }
        }
    }
}
