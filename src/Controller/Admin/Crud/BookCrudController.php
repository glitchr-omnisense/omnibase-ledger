<?php

namespace Base\Ledger\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Ledger\Controller\Admin\LedgerAdminTrait;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Entry;

/** The books - one per legal entity - with their reports: trial balance, open items, the FEC, and seeding the chart. */
class BookCrudController extends AbstractCrudController
{
    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Livre')->setEntityLabelInPlural('Livres comptables');
    }

    use LedgerAdminTrait { configureActions as private ledgerActions; }

    public static function getEntityFqcn(): string
    {
        return Book::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-book';
    }

    public function configureActions(Actions $actions): Actions
    {
        $balance = Action::new('ledgerTrialBalance', 'Balance', 'fa-solid fa-scale-balanced')
            ->linkToRoute('ledger_admin_trial_balance', fn (Book $book) => ['book' => $book->getId()]);
        $openItems = Action::new('ledgerOpenItems', 'Impayés', 'fa-solid fa-hourglass-half')
            ->linkToRoute('ledger_admin_open_items', fn (Book $book) => ['book' => $book->getId()]);
        $seed = Action::new('ledgerSeed', 'Plan comptable', 'fa-solid fa-list-ol')
            ->linkToRoute('ledger_admin_book_seed', fn (Book $book) => ['book' => $book->getId(), '_token' => $this->actionToken('ledger_book_seed', $book->getId())])
            ->askConfirmation('Ajouter au livre les comptes et journaux du plan SCI qui lui manquent ?');

        return $this->ledgerActions($actions)
            ->add(Action::INDEX, $balance)->add(Action::DETAIL, $balance)
            ->add(Action::INDEX, $openItems)->add(Action::DETAIL, $openItems)
            ->add(Action::DETAIL, $seed);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Nom')->setColumns(6);
        yield TextField::new('siren', 'SIREN')->setRequired(false)->setColumns(3);
        yield TextField::new('currency', 'Devise')->setColumns(3);
        yield DateField::new('lockedUntil', 'Verrouillé jusqu\'au')->setRequired(false)->setColumns(4)
            ->setHelp('Rien ne peut plus être passé, modifié ni extourné à cette date ou avant.');
        yield DateTimeField::new('createdAt', 'Créé le')->hideOnForm();
    }

    public function createEntity(string $entityFqcn): object
    {
        return new Book('');
    }

    /** A book with entries is part of the accounts: it stays. */
    public function isDeletable(object $entity): bool
    {
        return null === $this->entityManager->getRepository(Entry::class)->findOneBy(['book' => $entity]);
    }
}
