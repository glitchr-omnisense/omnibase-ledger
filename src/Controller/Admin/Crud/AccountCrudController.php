<?php

namespace Base\Ledger\Controller\Admin\Crud;

use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filter;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Ledger\Controller\Admin\LedgerAdminTrait;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Enum\AccountType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/** The chart of accounts, by number. An account with lines is not deleted. */
class AccountCrudController extends AbstractCrudController
{
    use LedgerAdminTrait { configureCrud as private ledgerCrud; }

    public static function getEntityFqcn(): string
    {
        return Account::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-list-ol';
    }

    public function configureCrud(\Base\Admin\Config\Crud $crud): \Base\Admin\Config\Crud
    {
        return $this->ledgerCrud($crud)->setEntityLabelInSingular('Compte')->setEntityLabelInPlural('Plan comptable')->setDefaultSort(['number' => 'ASC'])->setPaginatorPageSize(50)->setSearchFields(['number', 'label']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(Filter::new('type', 'Nature')->asChoice(array_combine(
            array_map(fn (AccountType $type) => $type->value, AccountType::cases()),
            array_map(fn (AccountType $type) => $type->value, AccountType::cases()),
        )));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        // Its book and its number are an account's identity: chosen when it is created, shown afterwards.
        yield $this->bookField()->setColumns(3)->setDisabled(Crud::PAGE_EDIT === $pageName);
        yield TextField::new('number', 'Numéro')->setColumns(3)->setDisabled(Crud::PAGE_EDIT === $pageName);
        yield TextField::new('label', 'Intitulé')->setColumns(6);
        yield TextField::new('type', 'Nature')->setColumns(3)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => AccountType::class])
            ->formatValue(fn ($value) => self::enumValue($value));
    }

    public function createEntity(string $entityFqcn): object
    {
        return new Account($this->defaultBook(), '', '');
    }

    public function isDeletable(object $entity): bool
    {
        return null === $this->entityManager->getRepository(EntryLine::class)->findOneBy(['account' => $entity]);
    }
}
