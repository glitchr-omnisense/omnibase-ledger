<?php

namespace Base\Ledger\Controller\Admin;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Field\AssociationField;
use Base\Ledger\Entity\Book;
use Base\Ledger\Security\Voter\LedgerVoter;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

/**
 * The ledger screens' permissions (LedgerVoter), for every ledger CRUD:
 * reading takes LEDGER_VIEW, changing LEDGER_MANAGE - ledger.admin_role
 * (ROLE_ADMIN) by default, rather than the admin's blanket ROLE_SUPERADMIN.
 * Asked about a record, the voter looks at its book.
 */
trait LedgerAdminTrait
{
    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityPermission(LedgerVoter::VIEW);
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->setPermissions(array_fill_keys([
            Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE,
            Action::SAVE_AND_RETURN, Action::SAVE_AND_CONTINUE, Action::SAVE_AND_ADD_ANOTHER,
        ], LedgerVoter::MANAGE));
    }

    /** The book a new record belongs to until the form says otherwise: the first one. */
    protected function defaultBook(): Book
    {
        return $this->entityManager->getRepository(Book::class)->findOneBy([], ['id' => 'ASC'])
            ?? throw $this->createNotFoundException('Create a book first (Ledger > Books).');
    }

    /**
     * A record of the ledger another one points to (a rule's account and
     * party, a bank account's ledger account and journal), chosen in the list
     * of its kind - Symfony's EntityType, each book's under its name, in the
     * order of $sort. AssociationType, the field's own form, embeds the
     * related record's whole form instead: an account is not renamed from a
     * rule, and that form does not open on a record that holds a PHP enum
     * (Account::$type, Party::$kind).
     *
     * @param class-string $class an entity with a book
     */
    protected function pick(string $property, string $label, string $class, string $sort, bool $required = true): AssociationField
    {
        return AssociationField::new($property, $label)
            ->setRequired($required)
            ->setFormType(EntityType::class)
            ->setFormTypeOptions([
                'class' => $class,
                'query_builder' => fn (EntityRepository $repository) => $repository->createQueryBuilder('e')
                    ->join('e.book', 'b')->addSelect('b')->orderBy('b.id', 'ASC')->addOrderBy('e.'.$sort, 'ASC'),
                'group_by' => fn (object $record) => (string) $record->getBook(),
            ] + ($required ? [] : ['placeholder' => '']));
    }

    /**
     * A record's book, chosen in the list of books (EntityType):
     * AssociationType would embed the book's own form, whose VAT regime (an
     * enum) it cannot render.
     */
    protected function bookField(string $label = 'Livre'): AssociationField
    {
        return AssociationField::new('book', $label)
            ->setFormType(EntityType::class)
            ->setFormTypeOptions([
                'class' => Book::class,
                'query_builder' => fn (EntityRepository $repository) => $repository->createQueryBuilder('b')->orderBy('b.name', 'ASC'),
            ]);
    }

    /** A token in an action's link, as for a logout: a link from elsewhere, a prefetch, does nothing. */
    protected function actionToken(string $action, ?int $id): string
    {
        return $this->container->get('security.csrf.token_manager')->getToken($action.'_'.$id)->getValue();
    }

    protected static function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
