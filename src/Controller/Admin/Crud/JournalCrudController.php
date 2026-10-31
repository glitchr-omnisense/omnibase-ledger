<?php

namespace Base\Ledger\Controller\Admin\Crud;

use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Ledger\Controller\Admin\LedgerAdminTrait;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\Journal;

/** The journals: BQ (bank), VT (rent notices), AC (purchases), OD, AN (opening balances). */
class JournalCrudController extends AbstractCrudController
{
    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Journal')->setEntityLabelInPlural('Journaux');
    }

    use LedgerAdminTrait;

    public static function getEntityFqcn(): string
    {
        return Journal::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-book-open';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        // Its book and its code are a journal's identity: chosen when it is created, shown afterwards.
        yield $this->bookField()->setColumns(4)->setDisabled(Crud::PAGE_EDIT === $pageName);
        yield TextField::new('code', 'Code')->setColumns(2)->setDisabled(Crud::PAGE_EDIT === $pageName);
        yield TextField::new('label', 'Intitulé')->setColumns(6);
    }

    public function createEntity(string $entityFqcn): object
    {
        return new Journal($this->defaultBook(), '', '');
    }

    public function isDeletable(object $entity): bool
    {
        return null === $this->entityManager->getRepository(Entry::class)->findOneBy(['journal' => $entity]);
    }
}
