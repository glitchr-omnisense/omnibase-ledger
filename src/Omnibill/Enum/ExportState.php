<?php

namespace Base\Ledger\Omnibill\Enum;

/**
 * What became of one record (an entry, a contact) handed to the accounting
 * software, or of an export run.
 */
enum ExportState: string
{
    /** A run under way, or a record not sent yet. */
    case PENDING = 'pending';
    /** Accepted by the software: it has its reference. */
    case SENT = 'sent';
    /** Refused by the software (an account it has not, a journal unknown): fixed there, then sent again. */
    case REFUSED = 'refused';
    /** The software could not be reached: nothing reached it, the next run sends it. */
    case FAILED = 'failed';
    /**
     * Sent, and the software's answer lost (a timeout, a server error after
     * the request left: omnibill's OutcomeUnknownException): it may have it.
     * Never sent again as it is: an entry is looked up first by its key where
     * the gateway can (findEntry()); otherwise a person checks in the software
     * and sends it again by hand.
     */
    case UNKNOWN = 'unknown';
    /** Changed in the book since it was sent: flagged, never sent again on its own. */
    case CHANGED = 'changed';
    /** A run that went through: every record sent, refused or flagged. */
    case DONE = 'done';
}
