<?php

namespace Base\Ledger\Controller\Admin\Crud;

use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextField;
use Base\Ledger\Controller\Admin\LedgerAdminTrait;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\TaxKind;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * The VAT codes of each book: rate, kind and account. Chart::seed() gives
 * the French set (V20... collected, A20... deductible, I20, EXO, AIC20); a
 * code a line was posted with is not deleted. Named after the VAT codes
 * rather than the entity: the admin's slug of "Tax" would read "taces".
 */
class VatCodeCrudController extends AbstractCrudController
{
    use LedgerAdminTrait { configureCrud as private ledgerCrud; }

    public static function getEntityFqcn(): string
    {
        return Tax::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-percent';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $this->ledgerCrud($crud)->setEntityLabelInSingular('Code de TVA')->setEntityLabelInPlural('Codes de TVA')->setDefaultSort(['code' => 'ASC'])->setSearchFields(['code', 'label']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield $this->bookField()->setColumns(3)->setDisabled(Crud::PAGE_EDIT === $pageName);
        yield TextField::new('code', 'Code')->setColumns(3)->setDisabled(Crud::PAGE_EDIT === $pageName);
        yield TextField::new('label', 'Intitulé')->setColumns(6);
        yield IntegerField::new('rate', 'Taux (points de base)')->setColumns(3)
            ->setHelp('2000 pour 20 %, 550 pour 5,5 %, 0 pour un code exonéré.')
            ->formatValue(fn ($value) => null === $value ? null : rtrim(rtrim(number_format($value / 100, 2, ',', ''), '0'), ',').' %');
        yield TextField::new('kind', 'Nature')->setColumns(3)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => TaxKind::class, 'choice_label' => fn (TaxKind $kind) => $kind->label()])
            ->formatValue(fn ($value) => $value instanceof TaxKind ? $value->label() : $value);
        yield $this->pick('account', 'Compte de TVA', Account::class, 'number', false)->setColumns(3)
            ->setHelp('44571 collectée, 44566 déductible, 44562 sur immobilisations, 4452 due en autoliquidation ; aucun pour un code exonéré.');
        yield $this->pick('deductibleAccount', 'Compte déductible (autoliquidation)', Account::class, 'number', false)->setColumns(3)->hideOnIndex();
    }

    public function createEntity(string $entityFqcn): object
    {
        return new Tax($this->defaultBook(), '', '', 2000, TaxKind::COLLECTED);
    }

    public function isDeletable(object $entity): bool
    {
        return null === $this->entityManager->getRepository(EntryLine::class)->findOneBy(['tax' => $entity]);
    }
}
