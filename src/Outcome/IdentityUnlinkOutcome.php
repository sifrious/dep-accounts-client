<?php

namespace Sifrious\AccountsClient\Outcome;

/**
 * What happened when a product asked Zahir to remove an external identity.
 *
 * Stable string codes, matching Zahir's own vocabulary.
 */
enum IdentityUnlinkOutcome: string
{
    /** The identity was removed. */
    case Unlinked = 'unlinked';

    /** There was nothing to remove. Repeating an unlink lands here. */
    case Unchanged = 'unchanged';

    /**
     * Refused: this was the account's last usable identity.
     *
     * Removing it would strand the account with no way back in, so it needs an
     * accepted recovery reference from a lifecycle-authorized caller. This is a
     * recoverable state to offer a path out of, not an error to report.
     */
    case RecoveryRequired = 'recovery_required';

    public function removedAnIdentity(): bool
    {
        return $this === self::Unlinked;
    }
}
