<?php

namespace Tests\Base\Ledger\Omnibill;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The omnibill side's entities with the ledger's, as Doctrine maps them
 * when omnisense/omnibill is installed: valid, and the tables a site's
 * migration adds then.
 */
class MappingTest extends TestCase
{
    private static function entityManager(): EntityManager
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to build an entity manager.');
        }
        $root = \dirname(__DIR__, 2).'/src';
        $config = ORMSetup::createAttributeMetadataConfiguration([$root.'/Entity', $root.'/Omnibill/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER, true));
        if (method_exists($config, 'enableNativeLazyObjects') && \PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
    }

    public function testTheMappingIsValidAndItsTables(): void
    {
        $em = self::entityManager();
        self::assertSame([], (new SchemaValidator($em))->validateMapping());

        $schema = (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());
        $sql = implode(";\n", $schema->toSql(new MySQL80Platform()));
        foreach (['ledger_supplier_invoice', 'ledger_invoice_inbox', 'ledger_accounting_export', 'ledger_accounting_export_item'] as $table) {
            self::assertTrue($schema->hasTable($table), $table);
        }
        self::assertStringContainsString('UNIQUE INDEX ledger_supplier_invoice_dedupe (book_id, dedupe)', $sql);
        self::assertStringContainsString('UNIQUE INDEX ledger_supplier_invoice_flow (gateway, flow_reference)', $sql);
        self::assertStringContainsString('UNIQUE INDEX ledger_invoice_inbox_gateway (gateway)', $sql);
        self::assertStringContainsString('UNIQUE INDEX ledger_accounting_export_item_key (book_id, gateway, kind, record_key)', $sql);
    }
}
