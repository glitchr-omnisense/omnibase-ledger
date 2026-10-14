<?php

namespace Base\Ledger\Controller\Admin\Crud;

use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Ledger\Controller\Admin\LedgerAdminTrait;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\Party;
use Base\Ledger\Enum\PartyKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * Tenants, suppliers, associates: their auxiliary account (411U3), the IBAN
 * their transfers come from, the application's reference. Usually created
 * by the application (Parties::partyFor()); one typed here gets its
 * auxiliary account when saved.
 */
class PartyCrudController extends AbstractCrudController
{
    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Tiers')->setEntityLabelInPlural('Tiers');
    }

    use LedgerAdminTrait;

    public static function getEntityFqcn(): string
    {
        return Party::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-address-book';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('book', 'Livre')->setColumns(4)->setDisabled(Crud::PAGE_EDIT === $pageName);
        yield TextField::new('kind', 'Type')->setColumns(4)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => PartyKind::class])
            ->formatValue(fn ($value) => self::enumValue($value));
        yield TextField::new('name', 'Nom')->setColumns(4);
        yield TextField::new('reference', 'Référence')->setRequired(false)->setColumns(4)->setHelp('L\'identifiant de l\'application (un bail, un code fournisseur) : unique dans le livre.');
        yield TextField::new('iban', 'IBAN')->setRequired(false)->setColumns(4)->setHelp('Ses virements sont reconnus à cet IBAN.');
        yield TextField::new('accountNumber', 'Compte auxiliaire')->setColumns(4)->setRequired(false)
            ->setHelp('Laissé vide : 411, 401, 455 ou 467 suivi de la référence.');
    }

    public function createEntity(string $entityFqcn): object
    {
        return new Party($this->defaultBook(), PartyKind::TENANT, '', '');
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        if ($entity instanceof Party) {
            $book = $entity->getBook();
            $accounts = $entityManager->getRepository(Account::class);
            if ('' === $entity->getAccountNumber()) {
                $suffix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($entity->getReference() ?: $entity->getName())));
                $number = $base = substr($entity->getKind()->collective().($suffix ?: 'X'), 0, 17);
                for ($n = 2; null !== $entityManager->getRepository(Party::class)->findOneBy(['book' => $book, 'accountNumber' => $number]); ++$n) {
                    $number = $base.$n;
                }
                $entity->setAccountNumber($number);
            }
            if (null === $accounts->findOneBy(['book' => $book, 'number' => $entity->getAccountNumber()])) {
                $collective = $accounts->findOneBy(['book' => $book, 'number' => $entity->getKind()->collective()]);
                $entityManager->persist(new Account($book, $entity->getAccountNumber(), $entity->getName(), $collective?->getType()));
            }
        }
        parent::persistEntity($entityManager, $entity);
    }

    public function isDeletable(object $entity): bool
    {
        return null === $this->entityManager->getRepository(EntryLine::class)->findOneBy(['party' => $entity]);
    }
}
