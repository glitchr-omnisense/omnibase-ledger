<?php

namespace Tests\Base\Ledger\EventListener;

use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\EventListener\ValidatedEntryGuard;
use Base\Ledger\Exception\LockedEntryException;
use Base\Contract\Lifecycle\PrePersist;
use Base\Contract\Lifecycle\PreRemove;
use Base\Contract\Lifecycle\PreUpdate;
use Base\Contract\UnitOfWork;
use Tests\Base\Ledger\InMemoryLedger;

class ValidatedEntryGuardTest extends InMemoryLedger
{
    /**
     * A flush as the neutral lifecycle tells it: PreUpdate for each update (with its changes), PrePersist for each
     * insertion, PreRemove for each deletion; the unit of work says which entities are new (the insertions) and
     * what changed on each ($changes: validatedAt [before, after] tells an entry validated by this flush).
     * $validatedBefore is what the entries' own validatedAt says already (posted entries are validated).
     *
     * @param list<object>      $updates
     * @param array<int, array> $changes   spl_object_id => change set
     * @param list<object>      $insertions
     * @param list<object>      $deletions
     */
    private function flush(array $updates = [], array $changes = [], array $insertions = [], array $deletions = [], array $validatedBefore = []): void
    {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('isNew')->willReturnCallback(fn (object $entity) => \in_array($entity, $insertions, true));
        $unitOfWork->method('changes')->willReturnCallback(fn (object $entity) => $changes[spl_object_id($entity)] ?? []);
        $guard = new ValidatedEntryGuard();
        foreach ($updates as $entity) {
            $guard(new PreUpdate($entity, $unitOfWork, $changes[spl_object_id($entity)] ?? []));
        }
        foreach ($insertions as $entity) {
            $guard(new PrePersist($entity, $unitOfWork));
        }
        foreach ($deletions as $entity) {
            $guard(new PreRemove($entity, $unitOfWork));
        }
    }

    private function entry(bool $validate = true): Entry
    {
        $tenant = $this->party('Jean Dupont', reference: 'U3');

        return $this->notice($tenant, 100, 'A'.\count($this->entries))->getEntry();
    }

    public function testLetteringAValidatedEntrysLinesIsAllowed(): void
    {
        $entry = $this->entry();
        $line = $entry->getLines()->first();
        $this->flush([$line], [spl_object_id($line) => ['letter' => [null, 'AAA'], 'letteredOn' => [null, new \DateTimeImmutable()]]], validatedBefore: [$entry]);
        $this->addToAssertionCount(1);
    }

    public function testChangingAValidatedEntrysLineIsRefused(): void
    {
        $entry = $this->entry();
        $line = $entry->getLines()->first();
        $this->expectException(LockedEntryException::class);
        $this->flush([$line], [spl_object_id($line) => ['debit' => [100, 200]]], validatedBefore: [$entry]);
    }

    public function testChangingOrDeletingAValidatedEntryIsRefused(): void
    {
        $entry = $this->entry();
        try {
            $this->flush([$entry], [spl_object_id($entry) => ['label' => ['a', 'b']]], validatedBefore: [$entry]);
            self::fail('Changed a validated entry.');
        } catch (LockedEntryException) {
        }
        try {
            $this->flush(deletions: [$entry->getLines()->first()], validatedBefore: [$entry]);
            self::fail('Deleted a validated line.');
        } catch (LockedEntryException) {
        }
        $this->expectException(LockedEntryException::class);
        $this->flush(deletions: [$entry], validatedBefore: [$entry]);
    }

    public function testAddingALineToAValidatedEntryIsRefused(): void
    {
        $entry = $this->entry();
        $line = new EntryLine($entry, $this->accounts['512'], 1, 0);
        $this->expectException(LockedEntryException::class);
        $this->flush(insertions: [$line], validatedBefore: [$entry]);
    }

    public function testADraftOrAnEntryBeingWrittenIsFree(): void
    {
        $entry = $this->entry();
        $line = $entry->getLines()->first();
        // A draft (validatedAt was null when loaded), being validated now.
        $this->flush([$entry, $line], [spl_object_id($entry) => ['validatedAt' => [null, new \DateTimeImmutable()]], spl_object_id($line) => ['debit' => [1, 2]]]);
        // A new entry and its lines.
        $this->flush(insertions: [$entry, $line]);
        $this->addToAssertionCount(1);
    }
}
