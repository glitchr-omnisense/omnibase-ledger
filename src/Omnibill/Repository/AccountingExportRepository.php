<?php

namespace Base\Ledger\Omnibill\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Omnibill\Entity\AccountingExport;
use Base\Ledger\Omnibill\Enum\ExportState;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<AccountingExport> */
class AccountingExportRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, AccountingExport::class);
    }

    /** The run of this period that did not finish (stopped, or cut short): the one to resume. */
    public function unfinished(Book $book, string $gateway, \DateTimeImmutable $from, \DateTimeImmutable $to): ?AccountingExport
    {
        return $this->findOneBy([
            'book' => Key::of($book),
            'gateway' => $gateway,
            'fromDate' => $from->setTime(0, 0),
            'toDate' => $to->setTime(0, 0),
            Group::of(Parser::SEPARATOR_AND, Criterion::of('state', ExportState::DONE, Parser::OPTION_NOT_EQUAL)),
        ], ['id' => 'DESC']);
    }

    /** @return list<AccountingExport> the book's runs, the latest first */
    public function recent(Book $book, int $limit = 30): array
    {
        return $this->findBy(['book' => $book], ['id' => 'DESC'], $limit);
    }
}
