<?php

namespace Tests\Base\Ledger\Controller\Admin;

use Base\Admin\Controller\AbstractCrudController;
use Base\Database\Attribute\Backend\Backends;
use Base\Database\Repository\Entities;
use Base\Database\Repository\Repositories;
use Base\Ledger\Controller\Admin\Crud\PartyCrudController;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Party;
use Base\Ledger\Enum\PartyKind;
use Omnibridge\Persistence\UnitOfWork;
use PHPUnit\Framework\TestCase;

/**
 * The back office's writes go through omnibase/admin's hooks on the persistence the site chose (persistRecord(),
 * updateRecord(), removeRecord(), since the admin's step B1a), which this bundle's screens override in place of the
 * former persistEntity()/updateEntity()/removeEntity() on Doctrine's entity manager: none of them is left, so the
 * admin calls this bundle's hooks on Doctrine and on omnibridge/eloquent alike. A tiers saved from the back office
 * gets its auxiliary account through the unit of work and its repositories.
 */
final class RecordHooksTest extends TestCase
{
    public function testNoScreenOverridesAFormerHook(): void
    {
        foreach (glob(\dirname(__DIR__, 3).'/src/Controller/Admin/Crud/*CrudController.php') ?: [] as $file) {
            $class = 'Base\\Ledger\\Controller\\Admin\\Crud\\'.basename($file, '.php');
            if (!is_subclass_of($class, AbstractCrudController::class)) {
                continue;
            }
            foreach (['persist', 'update', 'delete', 'remove'] as $hook) {
                self::assertSame(AbstractCrudController::class, (new \ReflectionMethod($class, $hook.'Entity'))->getDeclaringClass()->getName(), "$class::{$hook}Entity()");
            }
        }
    }

    public function testATiersSavedGetsItsAuxiliaryAccountThroughTheUnitOfWork(): void
    {
        $book = new Book('Livre de test');
        $supplier = new Party($book, PartyKind::SUPPLIER, 'EDF', '', 'EDF-1');
        $collective = new Account($book, '401', 'Fournisseurs');

        $persisted = [];
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $unitOfWork->expects(self::once())->method('flush');

        $accounts = $this->createMock(Entities::class);
        $accounts->method('findOneBy')->willReturnCallback(static fn (array $criteria) => '401' === $criteria['number'] ? $collective : null);
        $parties = $this->createMock(Entities::class);
        $parties->method('findOneBy')->willReturn(null);
        $repositories = $this->createMock(Repositories::class);
        $repositories->method('of')->willReturnCallback(static fn (string $class) => Account::class === $class ? $accounts : $parties);
        Backends::register($unitOfWork::class, Repositories::class, static fn () => $repositories);

        (new \ReflectionClass(PartyCrudController::class))->newInstanceWithoutConstructor()->persistRecord($unitOfWork, $supplier);

        self::assertSame('401EDF1', $supplier->getAccountNumber(), 'numbered from its kind and its reference');
        self::assertCount(2, $persisted);
        self::assertInstanceOf(Account::class, $persisted[0]);
        self::assertSame('401EDF1', $persisted[0]->getNumber());
        self::assertSame($supplier, $persisted[1]);
    }
}
