<?php

namespace Base\Ledger;

use Base\Bundle\AbstractBaseBundle;
use Base\Traits\SingletonTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Double-entry accounting on top of omnibase: books (one per legal entity),
 * a French chart of accounts, journals, parties and their lettering, bank
 * feeds through omnisense/omnibank (statement files or bank APIs), the
 * reconciliation of bank lines against open items with ranked suggestions,
 * and the reports a French accountant expects - trial balance, general
 * ledger, open items and the FEC.
 */
class LedgerBundle extends AbstractBaseBundle
{
    // Own singleton storage rather than sharing AbstractBaseBundle's - see
    // that class's constructor for why every concrete bundle needs this.
    use SingletonTrait;

    // The trait's protected no-op __construct() would hide the inherited
    // one; redeclared public, as ForgeBundle and AdminBundle do.
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Modern bundle layout: the class lives in src/, the bundle root is the
     * package root - so TwigBundle picks up ./templates as @Ledger and
     * Doctrine's auto_mapping ./src/Entity.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // omnibase's App\-wins convention: every Base\Ledger\Entity\* is
        // aliased onto App\Entity\Ledger\* unless the application declares a
        // real class there - which is how an app extends a ledger entity (a
        // Book tied to its own company, say) without touching the bundle.
        $this->setMapping($this->getPath().'/src/Entity', 'Base\Ledger\Entity', 'App\Entity\Ledger');
        $this->setMapping($this->getPath().'/src/Repository', 'Base\Ledger\Repository', 'App\Repository\Ledger');
    }
}
