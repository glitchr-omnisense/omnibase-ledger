<?php

namespace Tests\Base\Ledger\Controller\Admin;

use Base\Field\FieldDescriptor;
use Base\Field\Type\AssociationType;
use Base\Ledger\Controller\Admin\Crud\AccountCrudController;
use Base\Ledger\Controller\Admin\Crud\BookCrudController;
use Base\Ledger\Controller\Admin\Crud\FiscalYearCrudController;
use Base\Ledger\Controller\Admin\Crud\JournalCrudController;
use Base\Ledger\Controller\Admin\Crud\PartyCrudController;
use Base\Ledger\Controller\Admin\Crud\ReconciliationRuleCrudController;
use Base\Ledger\Controller\Admin\Crud\VatCodeCrudController;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Enum\VatRegime;
use Base\Ledger\Entity\Account;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\FiscalYear;
use Base\Ledger\Entity\Journal;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Enum\AccountType;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\RuleSign;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Base\Field\Type\BooleanType;
use Base\Field\Type\NumberType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\ConstraintValidatorFactoryInterface;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Every "new" form of the ledger, submitted: each field it shows is written
 * on the record. Opening the forms was tested; sending them was not, and
 * five of the six answered 500 - "Could not determine access type for
 * property code" (Journal, and Account::$number, FiscalYear::$start: read
 * only since the constructor), "Expected argument of type string, null
 * given" (a party's auxiliary account left empty, as its help suggests).
 *
 * No kernel here: each field of the screen writes the record the way the
 * form does - through the field's own setter, else Symfony's property
 * accessor - with what a form hands (null for a text left empty, a case for
 * an enum). The host application's tests send the real requests.
 */
class NewFormsSubmitTest extends TestCase
{
    /** @return iterable<string, array{class-string, \Closure(): object, array<string, mixed>, \Closure(object): array<string, mixed>, array<string, mixed>}> */
    public static function screens(): iterable
    {
        $book = fn () => new Book('Livres');

        yield 'Journal' => [
            JournalCrudController::class,
            fn () => new Journal($book(), '', ''),
            ['code' => 'zz', 'label' => 'Journal de test'],
            fn (Journal $j) => ['code' => $j->getCode(), 'label' => $j->getLabel()],
            ['code' => 'ZZ', 'label' => 'Journal de test'],
        ];
        yield 'Account' => [
            AccountCrudController::class,
            fn () => new Account($book(), '', ''),
            ['number' => '411 u3', 'label' => 'Locataire U3', 'type' => 'asset'],
            fn (Account $a) => ['number' => $a->getNumber(), 'label' => $a->getLabel(), 'type' => $a->getType()],
            ['number' => '411U3', 'label' => 'Locataire U3', 'type' => AccountType::ASSET],
        ];
        yield 'Book' => [
            BookCrudController::class,
            fn () => new Book(''),
            ['name' => 'SCI du Ried', 'siren' => '123 456 789', 'currency' => 'eur', 'lockedUntil' => '2025-12-31', 'vatRegime' => 'normal_quarterly'],
            fn (Book $b) => ['name' => $b->getName(), 'siren' => $b->getSiren(), 'currency' => $b->getCurrency(), 'lockedUntil' => $b->getLockedUntil()?->format('Y-m-d'), 'vatRegime' => $b->getVatRegime()],
            ['name' => 'SCI du Ried', 'siren' => '123456789', 'currency' => 'EUR', 'lockedUntil' => '2025-12-31', 'vatRegime' => VatRegime::NORMAL_QUARTERLY],
        ];
        yield 'Tax' => [
            VatCodeCrudController::class,
            fn () => new Tax($book(), '', '', 2000, TaxKind::COLLECTED),
            ['code' => 'v 85', 'label' => 'TVA collectée 8,5 %', 'rate' => '850', 'kind' => 'collected'],
            fn (Tax $t) => ['code' => $t->getCode(), 'label' => $t->getLabel(), 'rate' => $t->getRate(), 'kind' => $t->getKind()],
            ['code' => 'V85', 'label' => 'TVA collectée 8,5 %', 'rate' => 850, 'kind' => TaxKind::COLLECTED],
        ];
        yield 'FiscalYear' => [
            FiscalYearCrudController::class,
            fn () => FiscalYear::calendar($book(), 2026),
            ['start' => '2027-07-01', 'end' => '2028-06-30', 'closedAt' => ''],
            fn (FiscalYear $y) => ['start' => $y->getStart()->format('Y-m-d H:i'), 'end' => $y->getEnd()->format('Y-m-d H:i'), 'closed' => $y->isClosed()],
            ['start' => '2027-07-01 00:00', 'end' => '2028-06-30 00:00', 'closed' => false],
        ];
        yield 'Party' => [
            PartyCrudController::class,
            fn () => new Party($book(), PartyKind::TENANT, '', ''),
            ['kind' => 'supplier', 'name' => 'EDF', 'reference' => 'EDF-1', 'iban' => 'fr76 3000 6000 0112 3456 7890 189', 'accountNumber' => ''],
            fn (Party $p) => ['kind' => $p->getKind(), 'name' => $p->getName(), 'reference' => $p->getReference(), 'iban' => $p->getIban(), 'accountNumber' => $p->getAccountNumber()],
            ['kind' => PartyKind::SUPPLIER, 'name' => 'EDF', 'reference' => 'EDF-1', 'iban' => 'FR7630006000011234567890189', 'accountNumber' => ''],
        ];
        yield 'ReconciliationRule' => [
            ReconciliationRuleCrudController::class,
            fn () => new ReconciliationRule($b = $book(), '', null, new Account($b, '627', 'Services bancaires')),
            ['name' => 'Frais bancaires', 'priority' => '5', 'enabled' => '1', 'pattern' => 'FRAIS TENUE', 'iban' => '', 'sign' => 'out', 'split' => '[{"account": "627", "rest": true}]'],
            fn (ReconciliationRule $r) => ['name' => $r->getName(), 'priority' => $r->getPriority(), 'enabled' => $r->isEnabled(), 'pattern' => $r->getPattern(), 'sign' => $r->getSign(), 'split' => $r->getSplit()],
            ['name' => 'Frais bancaires', 'priority' => 5, 'enabled' => true, 'pattern' => 'FRAIS TENUE', 'sign' => RuleSign::OUT, 'split' => [['account' => '627', 'rest' => true]]],
        ];
    }

    /** @dataProvider screens */
    #[DataProvider('screens')]
    public function testTheNewFormWritesEveryFieldItShows(string $controller, \Closure $new, array $submitted, \Closure $read, array $expected): void
    {
        $record = $new();
        $accessor = PropertyAccess::createPropertyAccessor();

        $shown = [];
        foreach (self::fields($controller, FieldDescriptor::PAGE_NEW) as $property => $field) {
            if (\in_array($field->getFormType(), [AssociationType::class, EntityType::class], true)) {
                // A related record, chosen in a list by the back office: the record takes it through a setter.
                self::assertTrue($accessor->isWritable($record, $property), $record::class.'::$'.$property.' is chosen in the form: it takes a setter');
                continue;
            }
            $shown[] = $property;
            self::assertArrayHasKey($property, $submitted, 'the test submits every field the form shows');

            // What the form hands the record: the field's own setter when it has one, else the property,
            // with what its type makes of the request - null for a text left empty, a case for an enum.
            $value = self::norm($field, $submitted[$property]);
            if ($setter = $field->getFormTypeOption('setter')) {
                $setter($record, $value);
            } else {
                self::assertTrue($accessor->isWritable($record, $property), $record::class.'::$'.$property.' is shown on "new": it must be writable');
                $accessor->setValue($record, $property, $value);
            }
        }

        self::assertSame([], array_values(array_diff(array_keys($submitted), $shown)), 'the test submits only fields the form shows');
        self::assertEquals($expected, $read($record));
        self::assertSame('', (string) self::validator()->validate($record), 'nothing the validator refuses');
    }

    /** What a field's type makes of a submitted string. */
    private static function norm(FieldDescriptor $field, string $value): mixed
    {
        if (EnumType::class === $field->getFormType()) {
            return $field->getFormTypeOption('class')::from($value);
        }

        return match (true) {
            '' === $value => null,
            is_a($field->getFormType(), BooleanType::class, true) => (bool) $value,
            is_a($field->getFormType(), IntegerType::class, true), is_a($field->getFormType(), NumberType::class, true) => (int) $value,
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $value) => new \DateTimeImmutable($value.' 15:42'),     // a date field hands a day, whatever the hour
            default => $value,
        };
    }

    /** What an entity's constructor refuses, a form says. */
    public function testAYearThatEndsBeforeItStartsIsAnErrorOfTheForm(): void
    {
        $year = FiscalYear::calendar(new Book('Livres'), 2026)->setStart(new \DateTimeImmutable('2027-06-01'))->setEnd(new \DateTimeImmutable('2027-01-31'));
        $violations = self::validator()->validate($year);

        self::assertCount(1, $violations);
        self::assertSame('end', $violations[0]->getPropertyPath());

        self::assertCount(2, self::validator()->validate(new Journal(new Book('Livres'), '', '')), 'a journal without a code nor a label');
    }

    /** The entities' own constraints; the uniqueness ones ask Doctrine, which is not here. */
    private static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()
            ->setConstraintValidatorFactory(new class implements ConstraintValidatorFactoryInterface {
                public function getInstance(Constraint $constraint): ConstraintValidatorInterface
                {
                    if ($constraint instanceof UniqueEntity) {
                        return new class extends ConstraintValidator {
                            public function validate(mixed $value, Constraint $constraint): void
                            {
                            }
                        };
                    }

                    return new ($constraint->validatedBy())();
                }
            })
            ->getValidator();
    }

    /** The identity of a record is chosen when it is created and shown afterwards. */
    public function testTheBookAndTheCodeAreFixedOnceCreated(): void
    {
        foreach ([JournalCrudController::class => ['book', 'code'], AccountCrudController::class => ['book', 'number'], FiscalYearCrudController::class => ['book'], PartyCrudController::class => ['book'], ReconciliationRuleCrudController::class => ['book'], VatCodeCrudController::class => ['book', 'code']] as $controller => $properties) {
            $new = self::fields($controller, FieldDescriptor::PAGE_NEW);
            $edit = self::fields($controller, FieldDescriptor::PAGE_EDIT);
            foreach ($properties as $property) {
                self::assertNotTrue($new[$property]->isDisabled(), $controller.'::'.$property.' is written on "new"');
                self::assertTrue($edit[$property]->isDisabled(), $controller.'::'.$property.' is only shown on "edit"');
            }
        }
    }

    /** @return array<string, FieldDescriptor> */
    private static function fields(string $controller, string $page): array
    {
        $crud = (new \ReflectionClass($controller))->newInstanceWithoutConstructor();
        $fields = [];
        foreach ($crud->configureFields($page) as $field) {
            $descriptor = $field->getAsDto();
            if ($descriptor->isDisplayedOn($page)) {
                $fields[(string) $descriptor->getProperty()] = $descriptor;
            }
        }

        return $fields;
    }
}
