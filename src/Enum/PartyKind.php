<?php

namespace Base\Ledger\Enum;

/** Who a party is to the book: the collective account its own account sits under. */
enum PartyKind: string
{
    case TENANT = 'tenant';
    case SUPPLIER = 'supplier';
    case ASSOCIATE = 'associate';
    case OTHER = 'other';
    /** An owner whose property the book's company manages (a mandant): the money it holds for them. */
    case MANDATOR = 'mandator';
    /** A client the book's company invoices outside any lease (a seller or a buyer owing an agency's fee): a customer. */
    case CUSTOMER = 'customer';

    /** The PCG collective account a party of this kind gets an auxiliary account under. */
    public function collective(): string
    {
        return match ($this) {
            self::TENANT => '411',
            self::SUPPLIER => '401',
            self::ASSOCIATE => '455',
            self::OTHER => '467',
            self::MANDATOR => '4671',
            self::CUSTOMER => '411',
        };
    }
}
