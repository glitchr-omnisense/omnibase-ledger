<?php

namespace Tests\Base\Ledger\Entity;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The entities' mapping as Doctrine reads it, checked without a database:
 * valid both ways, and the MySQL schema it makes (what the host's
 * migration will contain).
 */
class MappingTest extends TestCase
{
    private static function entityManager(): EntityManager
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to build an entity manager.');
        }
        $config = ORMSetup::createAttributeMetadataConfiguration([\dirname(__DIR__, 2).'/src/Entity'], true);
        // As a Symfony application names columns (doctrine.orm.naming_strategy.underscore_number_aware).
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER, true));
        if (method_exists($config, 'enableNativeLazyObjects') && \PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
    }

    public function testTheMappingIsValid(): void
    {
        $errors = (new SchemaValidator(self::entityManager()))->validateMapping();

        self::assertSame([], $errors);
    }

    public function testTheTablesAndTheirUniqueKeys(): void
    {
        $em = self::entityManager();
        $schema = (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());
        $sql = implode(";\n", $schema->toSql(new MySQL80Platform()));

        foreach (['ledger_book', 'ledger_fiscal_year', 'ledger_account', 'ledger_journal', 'ledger_party', 'ledger_entry', 'ledger_entry_line', 'ledger_bank_connection', 'ledger_bank_account', 'ledger_bank_line', 'ledger_reconciliation_rule', 'ledger_tax', 'ledger_entry_line_dimension'] as $table) {
            self::assertTrue($schema->hasTable($table), $table);
        }
        self::assertStringContainsString('UNIQUE INDEX ledger_entry_source (book_id, source)', $sql);
        self::assertStringContainsString('UNIQUE INDEX ledger_account_number (book_id, number)', $sql);
        self::assertStringContainsString('UNIQUE INDEX ledger_bank_line_external (bank_account_id, external_id)', $sql);
        self::assertStringContainsString('UNIQUE INDEX ledger_party_reference (book_id, reference)', $sql);
        self::assertStringContainsString('UNIQUE INDEX ledger_tax_code (book_id, code)', $sql);
        self::assertStringContainsString('UNIQUE INDEX ledger_entry_line_dimension_axis (line_id, axis)', $sql);
        self::assertStringContainsString('INDEX ledger_entry_line_dimension_value (axis, value)', $sql);
        self::assertStringContainsString("vat_regime VARCHAR(20) DEFAULT 'none' NOT NULL", $sql);
    }
}
