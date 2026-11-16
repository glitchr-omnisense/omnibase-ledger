<?php

namespace Base\Ledger\Omnibill\Repository;

use Base\Ledger\Entity\Book;
use Base\Ledger\Omnibill\Entity\AccountingExport;
use Base\Ledger\Omnibill\Entity\AccountingExportItem;
use Base\Ledger\Omnibill\Enum\ExportState;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<AccountingExportItem> */
class AccountingExportItemRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, AccountingExportItem::class);
    }

    public function findOneByKey(Book $book, string $gateway, string $kind, string $key): ?AccountingExportItem
    {
        return $this->findOneBy(['book' => $book, 'gateway' => $gateway, 'kind' => $kind, 'recordKey' => mb_substr($key, 0, 191)]);
    }

    /**
     * What waits for a person on this gateway: refused, failed, sent with
     * its outcome unknown, changed.
     *
     * @return list<AccountingExportItem>
     */
    public function attention(Book $book, ?string $gateway = null, int $limit = 200): array
    {
        $criteria = [
            'book' => Key::of($book),
            'state' => [ExportState::REFUSED, ExportState::FAILED, ExportState::UNKNOWN, ExportState::CHANGED],
        ];
        if (null !== $gateway) {
            $criteria['gateway'] = $gateway;
        }

        return $this->findBy($criteria, ['updatedAt' => 'DESC'], $limit);
    }

    /** @return list<AccountingExportItem> what a run touched */
    public function forRun(AccountingExport $run): array
    {
        return $this->findBy(['run' => $run], ['kind' => 'ASC', 'id' => 'ASC']);
    }
}
