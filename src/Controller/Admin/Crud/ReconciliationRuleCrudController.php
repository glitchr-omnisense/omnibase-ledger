<?php

namespace Base\Ledger\Controller\Admin\Crud;

use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Ledger\Controller\Admin\LedgerAdminTrait;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Enum\RuleSign;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * What recurring bank lines are posted against when nothing open matches:
 * bank fees to 627, the loan instalment split between 164 and 6611, Stripe
 * payouts to 5112. The highest priority matching rule wins.
 */
class ReconciliationRuleCrudController extends AbstractCrudController
{
    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Règle de rapprochement')->setEntityLabelInPlural('Règles de rapprochement');
    }

    use LedgerAdminTrait;

    public static function getEntityFqcn(): string
    {
        return ReconciliationRule::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-wand-magic-sparkles';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('book', 'Livre')->setColumns(4)->setDisabled(Crud::PAGE_EDIT === $pageName);
        yield TextField::new('name', 'Nom')->setColumns(4);
        yield IntegerField::new('priority', 'Priorité')->setColumns(2);
        yield BooleanField::new('enabled', 'Active')->setColumns(2);
        yield TextField::new('pattern', 'Libellé contient')->setRequired(false)->setColumns(6)
            ->setHelp('Des mots qui doivent tous figurer dans le libellé (FRAIS TENUE COMPTE), ou une /expression régulière/.');
        yield TextField::new('iban', 'IBAN de la contrepartie')->setRequired(false)->setColumns(3);
        yield TextField::new('sign', 'Sens')->setColumns(3)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => RuleSign::class])
            ->formatValue(fn ($value) => match ($value) { RuleSign::IN => 'entrées', RuleSign::OUT => 'sorties', default => 'tous' });
        yield $this->pick('account', 'Compte', Account::class, 'number')->setColumns(4);
        yield $this->pick('party', 'Tiers', Party::class, 'name', false)->setColumns(4);
        yield TextareaField::new('split', 'Ventilation (JSON)')->setRequired(false)->hideOnIndex()->setColumns(12)
            ->setHelp('Facultatif : [{"account": "164", "amount": 85000}, {"account": "6611", "rest": true}] - "amount" en centimes, "percent", ou "rest" pour le reste.')
            ->setFormTypeOption('getter', fn (ReconciliationRule $rule) => null === $rule->getSplit() ? '' : json_encode($rule->getSplit(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT))
            ->setFormTypeOption('setter', function (ReconciliationRule $rule, ?string $json) {
                $json = trim((string) $json);
                $rule->setSplit('' === $json ? null : (\is_array($decoded = json_decode($json, true)) ? array_values($decoded) : null));
            })
            ->formatValue(fn ($value) => null === $value ? null : json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    public function createEntity(string $entityFqcn): object
    {
        $book = $this->defaultBook();
        $accounts = $this->entityManager->getRepository(Account::class);
        $account = $accounts->findOneBy(['book' => $book, 'number' => '627']) ?? $accounts->findOneBy(['book' => $book], ['number' => 'ASC'])
            ?? throw $this->createNotFoundException('Seed the book\'s chart of accounts first.');

        return new ReconciliationRule($book, '', null, $account);
    }
}
