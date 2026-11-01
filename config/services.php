<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * This file is part of the Glitchr package.
 *
 * (c) Marco Meyer <marco.meyer@glitchr.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Plain autowiring over src/, as omnibase/forge does. Entities, enums,
 * models and exceptions are not services; the admin controllers and the
 * dashboard widgets are loaded only when omnibase/admin is there. The bank feeds go through
 * glitchr/omnibank's gateways when that package is installed (its bundle
 * registered): without it, the ledger, the lettering and the reports work,
 * and the bank screens say Omnibank is missing.
 */
return function (ContainerConfigurator $configurator) {

    $src = dirname(__DIR__).'/src';

    $services = $configurator->services();
    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false);

    $services->load('Base\\Ledger\\', $src.'/')
        ->exclude([
            $src.'/DependencyInjection/',
            $src.'/Entity/',
            $src.'/Enum/',
            $src.'/Model/',
            $src.'/Event/',
            $src.'/Exception/',
            $src.'/Reconciliation/Suggestion.php',
            $src.'/Reconciliation/Part.php',
            $src.'/Bank/Label.php',
            $src.'/Controller/Admin/',
            $src.'/Admin/',
            $src.'/LedgerBundle.php',
        ]);

    // The public webhook (the admin controllers are loaded below).
    $services->load('Base\\Ledger\\Controller\\', $src.'/Controller/')
        ->exclude([$src.'/Controller/Admin/'])
        ->tag('controller.service_arguments');

    // The bank accesses (glitchr/omnibank): Bank\Connections, which every
    // bank service goes through, gets the Registry when the package is
    // there, null otherwise.
    if (class_exists('Omnibank\\Registry')) {
        $services->get('Base\\Ledger\\Bank\\Connections')
            ->arg('$registry', service('Omnibank\\Registry')->nullOnInvalid());
        $services->get('Base\\Ledger\\Bank\\Webhooks')
            ->arg('$registry', service('Omnibank\\Registry')->nullOnInvalid());
    }

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController') && is_dir($src.'/Controller/Admin')) {
        $services->load('Base\\Ledger\\Controller\\Admin\\', $src.'/Controller/Admin/')
            ->tag('controller.service_arguments');

        // The dashboard's blocks (ledger_bank, ledger_books): autoconfigured
        // as omnibase/admin widget types.
        $services->load('Base\\Ledger\\Admin\\', $src.'/Admin/');
    }
};
