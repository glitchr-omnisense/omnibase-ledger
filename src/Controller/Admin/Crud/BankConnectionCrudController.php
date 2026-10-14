<?php

namespace Base\Ledger\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Ledger\Controller\Admin\LedgerAdminTrait;
use Base\Ledger\Entity\BankConnection;
use Base\Ledger\Enum\ConsentState;

/**
 * The accesses to banks: opened from the "connect" page (the bank's own
 * consent page), renewed when the bank asks. Their state - tokens - is
 * sealed and never shown.
 */
class BankConnectionCrudController extends AbstractCrudController
{
    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Connexion bancaire')->setEntityLabelInPlural('Connexions bancaires');
    }

    use LedgerAdminTrait { configureActions as private ledgerActions; }

    public static function getEntityFqcn(): string
    {
        return BankConnection::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-building-columns';
    }

    public function configureActions(Actions $actions): Actions
    {
        $renew = Action::new('ledgerRenew', 'Renouveler', 'fa-solid fa-rotate')
            ->linkToRoute('ledger_admin_bank_renew', fn (BankConnection $connection) => ['connection' => $connection->getId(), '_token' => $this->actionToken('ledger_bank_renew', $connection->getId())])
            ->displayIf(fn (BankConnection $connection) => 'files' !== $connection->getGateway());

        return $this->ledgerActions($actions)->disable(Action::NEW)
            ->add(Action::INDEX, $renew)->add(Action::DETAIL, $renew);
    }

    public function configureRecordNote(object $entity): ?string
    {
        if (!$entity instanceof BankConnection) {
            return null;
        }
        if ($entity->needsRenewal()) {
            return 'La banque demande de renouveler le consentement : « Renouveler ».';
        }

        return $entity->getLastError();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('book', 'Livre')->hideOnForm();
        yield TextField::new('gateway', 'Passerelle')->hideOnForm();
        yield TextField::new('name', 'Nom')->setRequired(false)->setColumns(6);
        yield TextField::new('consentStatus', 'Consentement')->hideOnForm()
            ->formatValue(fn ($value) => match ($value) {
                ConsentState::ACTIVE => 'actif',
                ConsentState::NEEDS_RENEWAL => 'à renouveler',
                ConsentState::REVOKED => 'révoqué',
                default => '—',
            });
        yield DateTimeField::new('expiresAt', 'Expire le')->hideOnForm();
        yield TextareaField::new('lastError', 'Dernière erreur')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();
    }
}
