<?php

namespace Base\Ledger\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filter;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Ledger\Controller\Admin\LedgerAdminTrait;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Enum\BankLineStatus;

/** The bank statements' lines, as imported, filtered by where they stand; they are reconciled from the reconciliation page. */
class BankLineCrudController extends AbstractCrudController
{
    use LedgerAdminTrait { configureActions as private ledgerActions; configureCrud as private ledgerCrud; }

    public const STATUS_LABELS = [
        'unmatched' => 'À rapprocher',
        'suggested' => 'Suggestion',
        'reconciled' => 'Rapprochée',
        'ignored' => 'Ignorée',
    ];

    public static function getEntityFqcn(): string
    {
        return BankLine::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-receipt';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $this->ledgerCrud($crud)->setEntityLabelInSingular('Mouvement bancaire')->setEntityLabelInPlural('Mouvements bancaires')->setDefaultSort(['bookedOn' => 'DESC', 'id' => 'DESC'])->setPaginatorPageSize(50)
            ->setSearchFields(['label', 'counterpartyName', 'reference']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(Filter::new('status', 'Statut')->asChoice(self::STATUS_LABELS));
    }

    public function configureActions(Actions $actions): Actions
    {
        $reconcile = Action::new('ledgerReconcile', 'Rapprocher', 'fa-solid fa-code-merge')
            ->linkToRoute('ledger_admin_reconcile', fn (BankLine $line) => ['bankAccount' => $line->getBankAccount()->getId()]);

        return $this->ledgerActions($actions)->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Action::INDEX, $reconcile)->add(Action::DETAIL, $reconcile);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateField::new('bookedOn', 'Date');
        yield AssociationField::new('bankAccount', 'Compte');
        yield TextField::new('label', 'Libellé');
        yield TextField::new('counterpartyName', 'Contrepartie')->setRequired(false);
        yield TextField::new('amount', 'Montant')
            ->formatValue(fn ($value, BankLine $line) => number_format($line->getAmount() / 100, 2, ',', ' ').' '.$line->getCurrency());
        yield TextField::new('status', 'Statut')
            ->formatValue(fn ($value) => self::STATUS_LABELS[$value instanceof BankLineStatus ? $value->value : (string) $value] ?? $value);
        yield TextField::new('counterpartyIban', 'IBAN contrepartie')->onlyOnDetail();
        yield TextField::new('reference', 'Référence')->onlyOnDetail();
        yield DateField::new('valueOn', 'Date de valeur')->onlyOnDetail();
        yield AssociationField::new('entry', 'Écriture')->onlyOnDetail();
        yield TextField::new('externalId', 'Identifiant banque')->onlyOnDetail();
    }
}
