<?php

namespace Base\Ledger\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Ledger\Controller\Admin\LedgerAdminTrait;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The entries, with their lines. They are posted by the application and the
 * reconciliation (Posting), not typed here: a draft's date, label and piece
 * can be corrected, then validated; a validated entry is read-only - it is
 * reversed, never edited.
 */
class EntryCrudController extends AbstractCrudController
{
    use LedgerAdminTrait { configureActions as private ledgerActions; configureCrud as private ledgerCrud; }

    public static function getEntityFqcn(): string
    {
        return Entry::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-file-invoice-dollar';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $this->ledgerCrud($crud)->setEntityLabelInSingular('Écriture')->setEntityLabelInPlural('Écritures')->setDefaultSort(['date' => 'DESC', 'id' => 'DESC'])->setSearchFields(['label', 'piece', 'number', 'source']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $validate = Action::new('ledgerValidate', 'Valider', 'fa-solid fa-lock')
            ->linkToRoute('ledger_admin_entry_validate', fn (Entry $entry) => ['entry' => $entry->getId(), '_token' => $this->actionToken('ledger_entry_validate', $entry->getId())])
            ->displayIf(fn (Entry $entry) => !$entry->isValidated())
            ->askConfirmation('Numéroter et figer cette écriture ? Elle ne pourra plus être modifiée, seulement extournée.');
        $reverse = Action::new('ledgerReverse', 'Extourner', 'fa-solid fa-rotate-left')
            ->linkToRoute('ledger_admin_entry_reverse', fn (Entry $entry) => ['entry' => $entry->getId(), '_token' => $this->actionToken('ledger_entry_reverse', $entry->getId())])
            ->displayIf(fn (Entry $entry) => $entry->isValidated() && null === $entry->getReversalOf())
            ->askConfirmation('Passer l\'écriture inverse, à la même date ?');

        $actions = $this->ledgerActions($actions)->disable(Action::NEW)
            ->add(Action::INDEX, $validate)->add(Action::DETAIL, $validate)
            ->add(Action::DETAIL, $reverse);
        foreach ([Actions::PAGE_INDEX, Actions::PAGE_DETAIL] as $page) {
            if (isset($actions->getAll($page)[Action::EDIT])) {
                $actions->update($page, Action::EDIT, fn (Action $action) => $action->displayIf(fn (mixed $entry) => !$entry instanceof Entry || !$entry->isValidated()));
            }
        }

        return $actions;
    }

    /** The form is closed on a validated entry; a direct request is refused before the flush guard would. */
    public function updateEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        if ($entity instanceof Entry && $entity->isValidated()) {
            throw $this->createAccessDeniedException(sprintf('Entry %s is validated: reverse it instead.', $entity->getNumber()));
        }
        parent::updateEntity($entityManager, $entity);
    }

    public function isDeletable(object $entity): bool
    {
        return $entity instanceof Entry && !$entity->isValidated();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('number', 'N°')->hideOnForm();
        yield DateField::new('date', 'Date')->setColumns(3);
        yield AssociationField::new('journal', 'Journal')->hideOnForm();
        yield TextField::new('piece', 'Pièce')->setRequired(false)->setColumns(3);
        yield TextField::new('label', 'Libellé')->setColumns(6);
        yield TextField::new('totalDebit', 'Montant')->hideOnForm()
            ->formatValue(fn ($value, Entry $entry) => number_format($entry->getTotalDebit() / 100, 2, ',', ' ').' '.$entry->getBook()->getCurrency());
        yield AssociationField::new('book', 'Livre')->onlyOnDetail();
        yield TextField::new('source', 'Origine')->onlyOnDetail();
        yield DateTimeField::new('validatedAt', 'Validée le')->hideOnForm();
        yield AssociationField::new('reversalOf', 'Extourne de')->onlyOnDetail();
        yield TextField::new('lines', 'Lignes')->onlyOnDetail()->renderAsHtml()
            ->formatValue(fn ($value, Entry $entry) => self::linesTable($entry));
    }

    private static function linesTable(Entry $entry): string
    {
        $e = fn (?string $text) => htmlspecialchars((string) $text, \ENT_QUOTES);
        $amount = fn (int $minor) => 0 === $minor ? '' : number_format($minor / 100, 2, ',', ' ');
        $rows = '';
        foreach ($entry->getLines() as $line) {
            /* @var EntryLine $line */
            $rows .= sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td>%s</td></tr>',
                $e($line->getAccount()->getNumber()), $e($line->getAccount()->getLabel()), $e($line->getParty()?->getName()), $e($line->getLabel()),
                $amount($line->getDebit()), $amount($line->getCredit()), $e($line->getLetter()));
        }

        return '<table class="table table-sm" style="min-width:36rem"><thead><tr><th>Compte</th><th>Intitulé</th><th>Tiers</th><th>Libellé</th><th style="text-align:right">Débit</th><th style="text-align:right">Crédit</th><th>Lettre</th></tr></thead><tbody>'
            .$rows
            .sprintf('</tbody><tfoot><tr><th colspan="4">Total</th><th style="text-align:right">%s</th><th style="text-align:right">%s</th><th></th></tr></tfoot></table>', $amount($entry->getTotalDebit()), $amount($entry->getTotalCredit()));
    }
}
