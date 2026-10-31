<?php

namespace Base\Ledger\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Ledger\Controller\Admin\LedgerAdminTrait;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Twig\LedgerExtension;
use Base\Ledger\Entity\Journal;

/**
 * The bank accounts, each with its ledger account (512x) and bank journal:
 * reconcile its lines, upload a statement, sync it from the bank.
 */
class BankAccountCrudController extends AbstractCrudController
{
    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Compte bancaire')->setEntityLabelInPlural('Comptes bancaires');
    }

    use LedgerAdminTrait { configureActions as private ledgerActions; }

    public static function getEntityFqcn(): string
    {
        return BankAccount::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-piggy-bank';
    }

    public function configureActions(Actions $actions): Actions
    {
        $reconcile = Action::new('ledgerReconcile', 'Rapprocher', 'fa-solid fa-code-merge')
            ->linkToRoute('ledger_admin_reconcile', fn (BankAccount $account) => ['bankAccount' => $account->getId()]);
        $upload = Action::new('ledgerUpload', 'Importer un relevé', 'fa-solid fa-file-arrow-up')
            ->linkToRoute('ledger_admin_bank_upload', fn (BankAccount $account) => ['bankAccount' => $account->getId()]);
        $sync = Action::new('ledgerSync', 'Synchroniser', 'fa-solid fa-arrows-rotate')
            ->linkToRoute('ledger_admin_bank_sync', fn (BankAccount $account) => ['bankAccount' => $account->getId(), '_token' => $this->actionToken('ledger_bank_sync', $account->getId())])
            ->displayIf(fn (BankAccount $account) => 'files' !== $account->getConnection()->getGateway());

        return $this->ledgerActions($actions)->disable(Action::NEW)
            ->add(Action::INDEX, $reconcile)->add(Action::DETAIL, $reconcile)
            ->add(Action::INDEX, $upload)->add(Action::DETAIL, $upload)
            ->add(Action::INDEX, $sync)->add(Action::DETAIL, $sync);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Nom')->setColumns(6);
        yield TextField::new('iban', 'IBAN')->setRequired(false)->setColumns(6)->formatValue(fn ($value) => BankAccount::mask($value));
        yield AssociationField::new('connection', 'Connexion')->hideOnForm();
        yield $this->pick('account', 'Compte (512)', Account::class, 'number')->setColumns(6);
        yield $this->pick('journal', 'Journal', Journal::class, 'code')->setColumns(6);
        yield BooleanField::new('escrow', 'Compte séquestre')->setColumns(6)->hideOnIndex()
            ->setHelp('L\'argent de tiers (les loyers des propriétaires en gérance, loi Hoguet) : leurs encaissements et leurs reversements y passent.');
        yield TextField::new('currency', 'Devise')->hideOnForm();
        yield TextField::new('bankBalance', 'Solde banque')->hideOnForm()
            // In the page's language (intl's default follows it), as the dashboard's cards print it.
            ->formatValue(fn ($value, BankAccount $account) => null === $account->getBankBalance() ? null : (new LedgerExtension())->money($account->getBankBalance(), $account->getCurrency()));
        yield DateTimeField::new('lastSyncedAt', 'Dernière synchro')->hideOnForm();
    }
}
