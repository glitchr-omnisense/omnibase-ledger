<?php

namespace Base\Ledger\DependencyInjection;

use Omnibridge\Symfony\Omnibase\Bundle\AbstractBaseExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class LedgerExtension extends AbstractBaseExtension implements PrependExtensionInterface
{
    /**
     * The suppliers' invoices and the exports to the accountant's software
     * (src/Omnibill/Entity) are mapped only with omnisense/omnibill: a site
     * without it has none of their tables, and needs none.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (class_exists('Omnibill\\Registry')) {
            $container->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['LedgerOmnibill' => [
                'is_bundle' => false,
                'type' => 'attribute',
                'dir' => \dirname(__DIR__).'/Omnibill/Entity',
                'prefix' => 'Base\\Ledger\\Omnibill\\Entity',
                'alias' => 'LedgerOmnibill',
            ]]]]);
        }
    }

    public function getConfiguration(array $config, ContainerBuilder $container): LedgerConfiguration
    {
        return new LedgerConfiguration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $configuration = new LedgerConfiguration();
        $config = (new Processor())->processConfiguration($configuration, $configs);

        // ledger.auto_accounts, ledger.reconciliation.auto_threshold...
        $this->setConfiguration($container, $config, $configuration->getConfigTreeBuilder()->buildTree()->getName());
    }
}
