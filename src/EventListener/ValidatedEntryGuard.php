<?php

namespace Base\Ledger\EventListener;

use Base\Ledger\Entity\Entry;
use Base\Ledger\Entity\EntryLine;
use Base\Ledger\Entity\EntryLineDimension;
use Base\Ledger\Exception\LockedEntryException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * A validated entry is part of the books for good: whatever tries to change
 * it, or one of its lines or their dimensions, or to delete either, or to add a line to it, is
 * refused at flush time - the back office, a fixture, a careless service.
 * The lettering fields of its lines are the exception: lettering is
 * done after the fact; and an analytic axis may be added to a line that
 * has not got it (EntryLine::addDimension()), never changed nor removed. A mistake is corrected by a reversal entry
 * (Posting::reverse()).
 */
#[AsDoctrineListener(event: Events::onFlush)]
class ValidatedEntryGuard
{
    private const LETTERING = ['letter', 'letteredOn'];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        // An axis may be added to a validated line (the analytic allocation completed, the amounts untouched), not changed nor removed.
        foreach ([...$unitOfWork->getScheduledEntityUpdates(), ...$unitOfWork->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof EntryLineDimension && $this->isFrozen($entity->getLine()->getEntry(), $unitOfWork)) {
                throw new LockedEntryException(sprintf('Entry %s is validated: its lines\' dimensions can be added, not changed.', $entity->getLine()->getEntry()->getNumber()));
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Entry && $this->isFrozen($entity, $unitOfWork)) {
                throw new LockedEntryException(sprintf('Entry %s is validated: it cannot be changed (%s), only reversed.', $entity->getNumber(), implode(', ', array_keys($unitOfWork->getEntityChangeSet($entity)))));
            }
            if ($entity instanceof EntryLine && $this->isFrozen($entity->getEntry(), $unitOfWork)) {
                $changed = array_diff(array_keys($unitOfWork->getEntityChangeSet($entity)), self::LETTERING);
                if ([] !== $changed) {
                    throw new LockedEntryException(sprintf('Entry %s is validated: its lines cannot be changed (%s), only lettered.', $entity->getEntry()->getNumber(), implode(', ', $changed)));
                }
            }
        }

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof EntryLine && $this->isFrozen($entity->getEntry(), $unitOfWork)) {
                throw new LockedEntryException(sprintf('Entry %s is validated: no line can be added to it.', $entity->getEntry()->getNumber()));
            }
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Entry && $this->isFrozen($entity, $unitOfWork)) {
                throw new LockedEntryException(sprintf('Entry %s is validated: it cannot be deleted, only reversed.', $entity->getNumber()));
            }
            if ($entity instanceof EntryLine && $this->isFrozen($entity->getEntry(), $unitOfWork)) {
                throw new LockedEntryException(sprintf('Entry %s is validated: its lines cannot be deleted.', $entity->getEntry()->getNumber()));
            }
        }
    }

    /** Validated before this flush: an entry inserted or validated by it is still being written. */
    private function isFrozen(Entry $entry, UnitOfWork $unitOfWork): bool
    {
        if ($unitOfWork->isScheduledForInsert($entry)) {
            return false;
        }

        return null !== ($unitOfWork->getOriginalEntityData($entry)['validatedAt'] ?? null);
    }
}
