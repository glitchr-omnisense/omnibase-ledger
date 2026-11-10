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
    /** The software did not answer: nothing was recorded there, the next run sends it. */
    case FAILED = 'failed';
    /** Changed in the book since it was sent: flagged, never sent again on its own. */
    case CHANGED = 'changed';
    /** A run that went through: every record sent, refused or flagged. */
    case DONE = 'done';
}
