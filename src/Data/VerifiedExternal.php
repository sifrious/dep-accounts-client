<?php

namespace Sifrious\AccountsClient\Data;

use InvalidArgumentException;

final readonly class VerifiedExternal
{
    /**
     * @param  array<string, string|bool>  $claims
     * @param  array{issuer: string, audience: string, asserted_at: string, assertion_id?: string}  $provenance
     */
    public function __construct(
        public string $provider,
        public string $providerSubject,
        public array $claims,
        public array $provenance,
        public string $authenticatedAt,
    ) {
        foreach (array_keys($claims) as $claim) {
            if (! in_array($claim, ['email', 'email_verified', 'name'], true)) {
                throw new InvalidArgumentException("Unsafe external claim [{$claim}].");
            }
        }
    }
}
