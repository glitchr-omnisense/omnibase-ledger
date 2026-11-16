<?php

namespace Base\Ledger\EventListener;

use Base\Contract\Lifecycle\Listeners;
use Base\Contract\Lifecycle\PrePersist;
use Base\Contract\Lifecycle\PreRemove;
use Base\Contract\Lifecycle\PreUpdate;
use Base\Contract\UnitOfWork;
use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\EntryLineDimension;
use Base\Ledger\Exception\LockedEntryException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A validated entry is part of the books for good: whatever tries to change
 * it, or one of its lines or their dimensions, or to delete either, or to add a line to it, is
 * refused - the back office, a fixture, a careless service.
 * The lettering fields of its lines are the exception: lettering is
 * done after the fact; and an analytic axis may be added to a line that
 * has not got it (EntryLine::addDimension()), never changed nor removed. A mistake is corrected by a reversal entry
 * (Posting::reverse()).
 *
 * A neutral lifecycle listener (whichever persistence writes the books): an update is refused before it is
 * written (PreUpdate, at flush time), a removal and a new line when the unit of work takes them (PreRemove,
 * PrePersist: at remove() / persist(), or at the flush that cascades them).
 */
#[AutoconfigureTag(Listeners::TAG)]
class ValidatedEntryGuard
{
    private const LETTERING = ['letter', 'letteredOn'];

    public function __invoke(object $event): void
    {
        match (true) {
            $event instanceof PreUpdate => $this->updating($event),
            $event instanceof PreRemove => $this->removing($event->entity, $event->unitOfWork),
            $event instanceof PrePersist => $this->persisting($event->entity, $event->unitOfWork),
            default => null,
        };
    }

    private function updating(PreUpdate $event): void
    {
        $entity = $event->entity;
        // An axis may be added to a validated line (the analytic allocation completed, the amounts untouched), not changed nor removed.
        if ($entity instanceof EntryLineDimension && $this->isFrozen($entity->getLine()->getEntry(), $event->unitOfWork)) {
            throw new LockedEntryException(sprintf('Entry %s is validated: its lines\' dimensions can be added, not changed.', $entity->getLine()->getEntry()->getNumber()));
        }
        if ($entity instanceof Entry && $this->wasValidated($entity, $event->changes())) {
            throw new LockedEntryException(sprintf('Entry %s is validated: it cannot be changed (%s), only reversed.', $entity->getNumber(), implode(', ', array_keys($event->changes()))));
        }
        if ($entity instanceof EntryLine && $this->isFrozen($entity->getEntry(), $event->unitOfWork)) {
            $changed = array_diff(array_keys($event->changes()), self::LETTERING);
            if ([] !== $changed) {
                throw new LockedEntryException(sprintf('Entry %s is validated: its lines cannot be changed (%s), only lettered.', $entity->getEntry()->getNumber(), implode(', ', $changed)));
            }
        }
    }

    private function removing(object $entity, UnitOfWork $unitOfWork): void
    {
        if ($entity instanceof EntryLineDimension && $this->isFrozen($entity->getLine()->getEntry(), $unitOfWork)) {
            throw new LockedEntryException(sprintf('Entry %s is validated: its lines\' dimensions can be added, not changed.', $entity->getLine()->getEntry()->getNumber()));
        }
        if ($entity instanceof Entry && $this->isFrozen($entity, $unitOfWork)) {
            throw new LockedEntryException(sprintf('Entry %s is validated: it cannot be deleted, only reversed.', $entity->getNumber()));
        }
        if ($entity instanceof EntryLine && $this->isFrozen($entity->getEntry(), $unitOfWork)) {
            throw new LockedEntryException(sprintf('Entry %s is validated: its lines cannot be deleted.', $entity->getEntry()->getNumber()));
        }
    }

    private function persisting(object $entity, UnitOfWork $unitOfWork): void
    {
        if ($entity instanceof EntryLine && $this->isFrozen($entity->getEntry(), $unitOfWork)) {
            throw new LockedEntryException(sprintf('Entry %s is validated: no line can be added to it.', $entity->getEntry()->getNumber()));
        }
    }

    /** Validated before this flush: an entry inserted or validated by it is still being written. */
    private function isFrozen(Entry $entry, UnitOfWork $unitOfWork): bool
    {
        if ($unitOfWork->isNew($entry)) {
            return false;
        }

        return $this->wasValidated($entry, $unitOfWork->changes($entry));
    }

    /**
     * Whether the entry was validated as it was read: its validatedAt before the changes.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    private function wasValidated(Entry $entry, array $changes): bool
    {
        return null !== (\array_key_exists('validatedAt', $changes) ? $changes['validatedAt'][0] : $entry->getValidatedAt());
    }
}
