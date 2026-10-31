<?php

namespace Tests\Base\Ledger\Service;

use Base\Ledger\Enum\AccountType;
use Base\Ledger\Enum\PartyKind;
use Base\Ledger\Service\Parties;
use Tests\Base\Ledger\InMemoryLedger;

class PartiesTest extends InMemoryLedger
{
    private function parties(): Parties
    {
        return new Parties($this->entityManager(), $this->partyRepository(), $this->accountRepository());
    }

    public function testAPartyIsCreatedOnceWithItsAuxiliaryAccount(): void
    {
        $parties = $this->parties();
        $party = $parties->partyFor($this->book, 'tenant', 'U3-2026', 'Jean Dupont', 'fr76 1027 8060 0000 0202 7280 109');

        self::assertSame(PartyKind::TENANT, $party->getKind());
        self::assertSame('411U32026', $party->getAccountNumber());
        self::assertSame('FR7610278060000002027280109', $party->getIban());
        self::assertSame('Jean Dupont', $this->accounts['411U32026']->getLabel());
        self::assertSame(AccountType::ASSET, $this->accounts['411U32026']->getType());

        $again = $this->parties()->partyFor($this->book, PartyKind::TENANT, 'U3-2026', 'Jean et Marie Dupont');
        self::assertSame($party, $again);
        self::assertSame('Jean et Marie Dupont', $party->getName());
        self::assertSame('FR7610278060000002027280109', $party->getIban());
    }

    public function testEachKindUnderItsCollectiveAccountAndNumbersStayUnique(): void
    {
        $supplier = $this->parties()->partyFor($this->book, PartyKind::SUPPLIER, 'EDF', 'EDF');
        $associate = $this->parties()->partyFor($this->book, PartyKind::ASSOCIATE, 'M1', 'Marco Meyer');
        $twin = $this->parties()->partyFor($this->book, PartyKind::SUPPLIER, 'E.D.F', 'EDF Entreprises');

        self::assertSame('401EDF', $supplier->getAccountNumber());
        self::assertSame(AccountType::LIABILITY, $this->accounts['401EDF']->getType());
        self::assertSame('455M1', $associate->getAccountNumber());
        self::assertSame('401EDF2', $twin->getAccountNumber());
    }

    public function testAnOwnerUnderManagementHasTheirAccountUnderTheMandatorsOne(): void
    {
        $owner = $this->parties()->partyFor($this->book, PartyKind::MANDATOR, 'M12', 'Hélène Roth (mandant)');

        self::assertSame(PartyKind::MANDATOR, $owner->getKind());
        self::assertSame('4671M12', $owner->getAccountNumber(), '4671, the owners\' money the agency holds');
        self::assertSame(AccountType::LIABILITY, $this->accounts['4671M12']->getType());
    }

    public function testACustomerHasTheirAccountUnderTheClientsOne(): void
    {
        $seller = $this->parties()->partyFor($this->book, PartyKind::CUSTOMER, 'seller:5', 'Paul Klein (vendeur)');

        self::assertSame(PartyKind::CUSTOMER, $seller->getKind());
        self::assertSame('411SELLER5', $seller->getAccountNumber(), 'under 411, as a tenant: a receivable');
        self::assertSame(AccountType::ASSET, $this->accounts['411SELLER5']->getType());
    }
}
