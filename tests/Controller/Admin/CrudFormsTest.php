<?php

namespace Tests\Base\Ledger\Controller\Admin;

use Base\Field\FieldDescriptor;
use Base\Field\Type\AssociationType;
use Base\Ledger\Controller\Admin\Crud\AccountCrudController;
use Base\Ledger\Controller\Admin\Crud\BankAccountCrudController;
use Base\Ledger\Controller\Admin\Crud\BankConnectionCrudController;
use Base\Ledger\Controller\Admin\Crud\BookCrudController;
use Base\Ledger\Controller\Admin\Crud\EntryCrudController;
use Base\Ledger\Controller\Admin\Crud\FiscalYearCrudController;
use Base\Ledger\Controller\Admin\Crud\JournalCrudController;
use Base\Ledger\Controller\Admin\Crud\PartyCrudController;
use Base\Ledger\Controller\Admin\Crud\ReconciliationRuleCrudController;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Journal;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\RuleSign;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Forms;

/**
 * The "new" and "edit" forms of the ledger's screens, as their fields
 * declare them - no kernel here, the screens themselves are opened by the
 * host application's tests. What made a form answer 500:
 *
 * - a PHP enum behind a field that is not Symfony's EnumType: a text input
 *   prints the case ("could not be converted to string"), a SelectField
 *   finds no choices;
 * - a related record through AssociationType, which embeds that record's
 *   own form - its fields guessed from the mapping, an enum among them as a
 *   text input: the rule's account (Account::$type), its party
 *   (Party::$kind).
 */
class CrudFormsTest extends TestCase
{
    /**
     * The screens that have a form - the bank lines have none (they come from
     * the bank: "new" and "edit" are disabled).
     *
     * @return iterable<string, array{class-string}>
     */
    public static function controllers(): iterable
    {
        foreach ([
            AccountCrudController::class, BankAccountCrudController::class, BankConnectionCrudController::class,
            BookCrudController::class, EntryCrudController::class, FiscalYearCrudController::class,
            JournalCrudController::class, PartyCrudController::class, ReconciliationRuleCrudController::class,
        ] as $controller) {
            yield substr(strrchr($controller, '\\'), 1) => [$controller];
        }
    }

    /** @dataProvider controllers */
    #[DataProvider('controllers')]
    public function testAnEnumIsChosenThroughEnumTypeAndARelatedRecordIsNotEmbeddedWithOne(string $controller): void
    {
        $entity = $controller::getEntityFqcn();
        $pages = self::formFields($controller);
        self::assertNotSame([], array_merge(...array_values($pages)), 'the screen has a form');
        foreach ($pages as $page => $fields) {
            foreach ($fields as $field) {
                $where = \sprintf('%s::$%s on "%s"', $entity, $field->getProperty(), $page);
                if (null !== $enum = self::enumOf($entity, (string) $field->getProperty())) {
                    self::assertSame(EnumType::class, $field->getFormType(), $where.': an enum takes Symfony\'s EnumType');
                    self::assertSame($enum, $field->getFormTypeOption('class'), $where);
                }
                if (null !== $target = self::targetOf($entity, (string) $field->getProperty())) {
                    if (AssociationType::class === $field->getFormType()) {
                        self::assertSame([], self::enumsOf($target), $where.': AssociationType embeds '.$target.'\'s form, whose enum it prints in a text input');
                    } else {
                        self::assertSame(EntityType::class, $field->getFormType(), $where);
                        self::assertSame($target, $field->getFormTypeOption('class'), $where);
                    }
                }
            }
        }
    }

    public function testTheRuleChoosesItsAccountAndItsPartyInAList(): void
    {
        $fields = self::formFields(ReconciliationRuleCrudController::class)[FieldDescriptor::PAGE_NEW];

        self::assertSame(EntityType::class, $fields['account']->getFormType());
        self::assertSame(Account::class, $fields['account']->getFormTypeOption('class'));
        self::assertTrue($fields['account']->isRequired());
        self::assertSame(EntityType::class, $fields['party']->getFormType());
        self::assertSame(Party::class, $fields['party']->getFormTypeOption('class'));
        self::assertFalse($fields['party']->isRequired(), 'a rule may name no party');

        $bank = self::formFields(BankAccountCrudController::class)[FieldDescriptor::PAGE_EDIT];
        self::assertSame(Account::class, $bank['account']->getFormTypeOption('class'));
        self::assertSame(Journal::class, $bank['journal']->getFormTypeOption('class'));
    }

    /** The enum fields built and rendered as the screen does it: the case selected, each case a choice. */
    public function testTheEnumFieldsRenderOnARecord(): void
    {
        $book = new Book('Livres');
        $account = new Account($book, '627', 'Services bancaires', AccountType::EXPENSE);
        $records = [
            ReconciliationRuleCrudController::class => [new ReconciliationRule($book, 'Frais', 'FRAIS', $account, RuleSign::OUT), 'sign', RuleSign::OUT],
            AccountCrudController::class => [$account, 'type', AccountType::EXPENSE],
            PartyCrudController::class => [new Party($book, PartyKind::SUPPLIER, 'EDF', '401EDF'), 'kind', PartyKind::SUPPLIER],
        ];

        foreach ($records as $controller => [$record, $property, $case]) {
            $field = self::formFields($controller)[FieldDescriptor::PAGE_EDIT][$property];
            $form = Forms::createFormFactory()->createNamedBuilder('crud_form', FormType::class, $record, ['data_class' => $record::class])
                ->add($property, $field->getFormType(), $field->getFormTypeOptions())
                ->getForm();
            $view = $form->createView()[$property];

            self::assertSame($case->value, $view->vars['value'], $record::class.'::$'.$property);
            self::assertCount(\count($case::cases()), $view->vars['choices']);
        }
    }

    /** @return array<string, array<string, FieldDescriptor>> the fields of the "new" and "edit" forms, by property */
    private static function formFields(string $controller): array
    {
        $crud = (new \ReflectionClass($controller))->newInstanceWithoutConstructor();
        $pages = [];
        foreach ([FieldDescriptor::PAGE_NEW, FieldDescriptor::PAGE_EDIT] as $page) {
            $pages[$page] = [];
            foreach ($crud->configureFields($page) as $field) {
                $descriptor = $field->getAsDto();
                if ($descriptor->isDisplayedOn($page)) {
                    $pages[$page][(string) $descriptor->getProperty()] = $descriptor;
                }
            }
        }

        return $pages;
    }

    /** The PHP enum a property is mapped to, if any. */
    private static function enumOf(string $entity, string $property): ?string
    {
        if (!property_exists($entity, $property)) {
            return null;
        }
        foreach ((new \ReflectionProperty($entity, $property))->getAttributes(ORM\Column::class) as $column) {
            return $column->newInstance()->enumType;
        }

        return null;
    }

    /** @return list<string> the properties of an entity mapped to a PHP enum */
    private static function enumsOf(string $entity): array
    {
        return array_values(array_filter(
            array_map(fn (\ReflectionProperty $property) => $property->getName(), (new \ReflectionClass($entity))->getProperties()),
            fn (string $property) => null !== self::enumOf($entity, $property),
        ));
    }

    /** The entity a to-one association points to, if the property is one. */
    private static function targetOf(string $entity, string $property): ?string
    {
        if (!property_exists($entity, $property)) {
            return null;
        }
        $reflection = new \ReflectionProperty($entity, $property);
        foreach ([ORM\ManyToOne::class, ORM\OneToOne::class] as $association) {
            foreach ($reflection->getAttributes($association) as $attribute) {
                return $attribute->newInstance()->targetEntity ?? ltrim((string) $reflection->getType(), '?');
            }
        }

        return null;
    }
}
