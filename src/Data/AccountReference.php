<?php

namespace Sifrious\AccountsClient\Data;

final readonly class AccountReference
{
    public function __construct(
        public string $id,
        public string $status,
        public bool $created,
        /**
         * The address to reach this account at, or null when no linked identity
         * has asserted one.
         *
         * Chosen by Zahir across every linked identity, so it may differ from
         * the email on the assertion that just completed — an account can hold
         * several identities, and the most recently authenticated one wins.
         *
         * Metadata, never identity. Do not key a projection on it, do not match
         * accounts by it, and do not treat two accounts sharing one as the same
         * person.
         */
        public ?string $contactEmail = null,
    ) {}
}
