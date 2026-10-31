<?php

namespace Base\Ledger\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class LedgerConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('ledger');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('auto_accounts')->defaultFalse()
                    ->info('Posting to an account number the book does not have creates it, as a sub-account of the longest existing account its number starts with (4011 under 401). Off: such an entry is refused.')->end()
                ->scalarNode('chart')->defaultValue('sci')
                    ->info('The chart Chart::seed() uses when none is named: a file of config/charts/ (sci: a French real-estate SCI).')->end()
                ->scalarNode('admin_role')->defaultValue('ROLE_ADMIN')
                    ->info('Who views and manages every book (LEDGER_VIEW, LEDGER_MANAGE). Services implementing BookAccessInterface may grant more.')->end()
                ->scalarNode('secret')->defaultValue('%kernel.secret%')
                    ->info('What the key sealing the bank connections\' state (tokens) is derived from. Changing it makes the stored connections unreadable: they must be connected again.')->end()
                ->arrayNode('reconciliation')->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('auto_threshold')->min(0)->max(1)->defaultValue(0.9)
                            ->info('A bank line is reconciled on its own only when exactly one suggestion scores at least this much.')->end()
                        ->integerNode('max_group_items')->min(2)->max(20)->defaultValue(12)
                            ->info('How many open items of one party a grouped payment is looked for among (subset sum, oldest first).')->end()
                        ->integerNode('max_advance_days')->min(0)->defaultValue(45)
                            ->info('How many days before an open item\'s date a bank line may settle it (a rent paid ahead): a line older than that is never offered it.')->end()
                    ->end()
                ->end()
                ->arrayNode('bank')->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('sync_overlap_days')->min(0)->defaultValue(5)
                            ->info('A sync asks again for the days before the last one: banks book late. Lines already imported are matched on their id.')->end()
                        ->scalarNode('opening_account')->defaultValue('890')
                            ->info('The account a bank account\'s opening entry is balanced against (the bank\'s balance less the lines imported): 890, Bilan d\'ouverture. Created when the chart does not have it.')->end()
                        ->scalarNode('opening_journal')->defaultValue('AN')
                            ->info('The journal the opening entries are posted in: AN, the à-nouveaux. Created when the book does not have it.')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
