<?php

namespace Sifrious\AccountsClient\Contracts;

/**
 * The minimal contract a product's local user record satisfies.
 *
 * A projection is a convenience copy, not an authority. It exists so the
 * product can hang sessions, preferences, and its own domain data off something
 * local, and it is keyed by the opaque Zahir account ID and nothing else.
 *
 * Implementations must enforce uniqueness on that key in storage. Two
 * projections for one account is the bug this contract exists to prevent: it
 * splits a person's product history in half at the second sign-in.
 *
 * Never key a projection on an email, a provider subject, a GitHub login, or a
 * tenant. All of those are mutable metadata, and two of them can be reassigned
 * to a different human being.
 */
interface AccountProjection
{
    /** The opaque `acc_*` identifier this local record projects. */
    public function zahirAccountId(): string;
}
