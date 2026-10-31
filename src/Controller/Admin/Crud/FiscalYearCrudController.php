<?php

namespace Base\Ledger\Controller\Admin\Crud;

use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Ledger\Controller\Admin\LedgerAdminTrait;
use Base\Ledger\Entity\FiscalYear;

/** A book's fiscal years: entry numbers run per year; a closed year takes no entry. Without any, calendar years. */
class FiscalYearCrudController extends AbstractCrudController
{
    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Exercice')->setEntityLabelInPlural('Exercices');
    }

    use LedgerAdminTrait;

    public static function getEntityFqcn(): string
    {
        return FiscalYear::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-calendar-days';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield $this->bookField()->setColumns(4)->setDisabled(Crud::PAGE_EDIT === $pageName);
        yield DateField::new('start', 'Début')->setColumns(4);
        yield DateField::new('end', 'Fin')->setColumns(4);
        yield DateTimeField::new('closedAt', 'Clôturé le')->setRequired(false)->setColumns(4);
    }

    public function createEntity(string $entityFqcn): object
    {
        $year = (int) date('Y');

        return FiscalYear::calendar($this->defaultBook(), $year);
    }
}
