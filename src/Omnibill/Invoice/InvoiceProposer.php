<?php

namespace Base\Ledger\Omnibill\Invoice;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\Tax;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Enum\TaxKind;
use Base\Ledger\Model\EntryDraft;
use Base\Ledger\Omnibill\Entity\SupplierInvoice;
use Base\Ledger\Repository\EntryLineRepository;
use Base\Ledger\Repository\PartyRepository;
use Base\Ledger\Repository\TaxRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the book proposes for a supplier's invoice, read: the supplier as
 * the book knows it (by its SIREN, its IBAN, its name), the expense account
 * its earlier invoices went to, and the entry - the expense per VAT rate,
 * the deductible VAT on the book's VAT code of that rate when the book is
 * liable (all taxes included on the expense otherwise), a reverse charge
 * through the book's reverse-charge code, the supplier credited with the
 * total. A credit note is the same entry the other way round. Nothing is
 * posted here: SupplierInvoices::book() does, when a person says so.
 */
class InvoiceProposer
{
    /** A supplier created from an invoice is referenced by its SIREN, else by this and its name. */
    public const REFERENCE_PREFIX = 'siren:';

    public function __construct(
        private readonly PartyRepository $parties,
        private readonly EntryLineRepository $lines,
        private readonly ?TaxRepository $taxes = null,
        #[Autowire('%ledger.invoices.default_account%')] private readonly ?string $defaultAccount = null,
    ) {
    }

    /** Matches the supplier and suggests the account. */
    public function propose(SupplierInvoice $invoice): SupplierInvoice
    {
        $party = $invoice->getParty() ?? $this->supplier($invoice);
        $invoice->setParty($party);
        if (null === $invoice->getAccount()) {
            $invoice->setAccount($this->suggestedAccount($invoice->getBook(), $party));
        }

        return $invoice;
    }

    /** The book's supplier this invoice is from: its SIREN, then its IBAN, then its name. */
    public function supplier(SupplierInvoice $invoice): ?Party
    {
        $book = $invoice->getBook();
        if (null !== $invoice->getSupplierSiren() && null !== ($party = $this->parties->findOneByReference($book, self::REFERENCE_PREFIX.$invoice->getSupplierSiren()))) {
            return $party;
        }
        foreach ($this->parties->findByIban($book, $invoice->getSupplierIban()) as $party) {
            if (PartyKind::SUPPLIER === $party->getKind()) {
                return $party;
            }
        }
        $name = self::comparable($invoice->getSupplierName());
        if ('' !== $name) {
            foreach ($this->parties->ofKind($book, PartyKind::SUPPLIER) as $party) {
                if (self::comparable($party->getName()) === $name) {
                    return $party;
                }
            }
        }

        return null;
    }

    /** The expense account the supplier's entries went to most, else ledger.invoices.default_account. */
    public function suggestedAccount(Book $book, ?Party $party): ?string
    {
        if (null !== $party) {
            $usual = array_key_first($this->lines->accountsWith($book, $party, ['6', '2']));
            if (null !== $usual) {
                return (string) $usual;
            }
        }

        return $this->defaultAccount;
    }

    /** The reference a supplier created from this invoice gets: its SIREN, else its VAT number, else its name. */
    public function reference(SupplierInvoice $invoice): string
    {
        if (null !== $invoice->getSupplierSiren()) {
            return self::REFERENCE_PREFIX.$invoice->getSupplierSiren();
        }

        return mb_substr(str_replace(['siren:', 'vat:', 'name:'], ['siren:', 'tva:', 'fournisseur:'], (string) $invoice->supplierKey()) ?: 'fournisseur:'.$invoice->getSha256(), 0, 64);
    }

    /** The auxiliary account's suffix of a supplier created from this invoice: its name's letters (401EDF). */
    public static function accountSuffix(string $name): string
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)));

        return substr('' === $letters ? 'FOURNISSEUR' : $letters, 0, 10);
    }

    /**
     * The entry's lines, as they would be posted with $account and the
     * supplier's account $supplierAccount: what the screen shows before a
     * person books it, and what book() posts.
     *
     * @return array{lines: list<array{account: string, debit: int, credit: int, label: ?string, tax: ?Tax, taxBase: ?int, supplier: bool}>, warnings: list<string>}
     */
    public function lines(SupplierInvoice $invoice, ?string $account, string $supplierAccount): array
    {
        $book = $invoice->getBook();
        $account = null === $account || '' === $account ? '?' : $account;
        $liable = $book->getVatRegime()->isLiable();
        $label = $this->label($invoice);
        $lines = [];
        $warnings = [];

        $rows = $invoice->getVat();
        if ([] === $rows) {
            $amount = $invoice->getTotalIncl() ?? $invoice->getTotalExcl() ?? 0;
            $rows = [['rate' => 0, 'base' => $amount, 'vat' => 0, 'category' => 'O']];
        }
        foreach ($rows as $row) {
            $category = strtoupper($row['category']);
            if (!$liable) {
                // Outside VAT, nothing is deductible: the expense is what was paid.
                $lines[] = $this->line($account, $row['base'] + $row['vat'], $label);
                continue;
            }
            if (\in_array($category, ['AE', 'K'], true)) {
                // The buyer accounts for the VAT: due and deducted at once (EntryDraft::taxed()).
                $reverse = $this->tax($book, TaxKind::REVERSE_CHARGE, $row['rate'] ?: null);
                if (null === $reverse) {
                    $warnings[] = sprintf('No reverse-charge VAT code in %s: the %s base is booked without its VAT.', $book->getName(), $category);
                    $lines[] = $this->line($account, $row['base'], $label);
                    continue;
                }
                $draft = (new EntryDraft($book, 'AC', new \DateTimeImmutable(), $label, 'preview'))->taxed($account, $row['base'], 0, $reverse, false, null, $label);
                foreach ($draft->getLines() as $l) {
                    $lines[] = ['account' => $l['account'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'label' => $l['label'], 'tax' => $l['tax'] instanceof Tax ? $l['tax'] : null, 'taxBase' => $l['taxBase'], 'supplier' => false];
                }
                continue;
            }
            $lines[] = $this->line($account, $row['base'], $label);
            if ($row['vat'] <= 0) {
                continue;
            }
            $tax = $this->tax($book, TaxKind::DEDUCTIBLE, $row['rate']);
            if (null === $tax || null === $tax->getAccount()) {
                $warnings[] = sprintf('No deductible VAT code at %s %% in %s: the VAT is booked on the expense.', rtrim(rtrim(number_format($row['rate'] / 100, 2, '.', ''), '0'), '.'), $book->getName());
                $lines[\count($lines) - 1]['debit'] += $row['vat'];
                continue;
            }
            $lines[] = ['account' => $tax->getAccount()->getNumber(), 'debit' => $row['vat'], 'credit' => 0, 'label' => $label, 'tax' => $tax, 'taxBase' => $row['base'], 'supplier' => false];
        }

        $total = array_sum(array_column($lines, 'debit')) - array_sum(array_column($lines, 'credit'));
        $lines[] = ['account' => $supplierAccount, 'debit' => 0, 'credit' => $total, 'label' => $label, 'tax' => null, 'taxBase' => null, 'supplier' => true];
        if (null !== $invoice->getTotalIncl() && $invoice->getTotalIncl() !== $total && [] !== $invoice->getVat()) {
            $warnings[] = sprintf('The VAT breakdown adds up to %s, the invoice says %s.', number_format($total / 100, 2, ',', ' '), number_format($invoice->getTotalIncl() / 100, 2, ',', ' '));
        }

        if ($this->isCreditNote($invoice)) {
            $lines = array_map(static fn (array $l) => ['debit' => $l['credit'], 'credit' => $l['debit']] + $l, $lines);
        }

        return ['lines' => $lines, 'warnings' => $warnings];
    }

    /** The draft book() posts: the lines with the supplier's party on its own. */
    public function draft(SupplierInvoice $invoice, string $account, Party $supplier, string $source, ?\DateTimeImmutable $date = null): EntryDraft
    {
        $draft = new EntryDraft($invoice->getBook(), 'AC', $date ?? $invoice->getIssuedOn() ?? new \DateTimeImmutable('today'), $this->label($invoice), $source, $invoice->getNumber());
        foreach ($this->lines($invoice, $account, $supplier->getAccountNumber())['lines'] as $line) {
            if ($line['debit'] > 0 || $line['credit'] > 0) {
                $draft->line($line['account'], $line['debit'], $line['credit'], $line['supplier'] ? $supplier : null, $line['label'], [], $line['tax'], $line['taxBase']);
            }
        }

        return $draft;
    }

    public function label(SupplierInvoice $invoice): string
    {
        return trim(sprintf('%s %s %s', $this->isCreditNote($invoice) ? 'Avoir' : 'Facture', $invoice->getSupplierName() ?? $invoice->getParty()?->getName() ?? '', $invoice->getNumber() ?? ''));
    }

    public function isCreditNote(SupplierInvoice $invoice): bool
    {
        return $invoice->isCreditNote();
    }

    /** The book's VAT code of this kind (and rate): the first by code. */
    private function tax(Book $book, TaxKind $kind, ?int $rate): ?Tax
    {
        $candidates = array_values(array_filter($this->taxes?->forBook($book) ?? [], static fn (Tax $t) => $t->getKind() === $kind && (null === $rate || $t->getRate() === $rate)));
        usort($candidates, static fn (Tax $a, Tax $b) => strcmp($a->getCode(), $b->getCode()));

        return $candidates[0] ?? null;
    }

    /** @return array{account: string, debit: int, credit: int, label: ?string, tax: ?Tax, taxBase: ?int, supplier: bool} */
    private function line(string $account, int $amount, string $label): array
    {
        return ['account' => $account, 'debit' => $amount, 'credit' => 0, 'label' => $label, 'tax' => null, 'taxBase' => null, 'supplier' => false];
    }

    private static function comparable(?string $name): string
    {
        $name = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $name));
        $name = (string) preg_replace('/\b(sas|sasu|sarl|eurl|sa|sci|snc|selarl|ets|societe)\b/', ' ', $name);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name));
    }
}
