<?php

namespace Sifrious\AccountsClient\Data;

final readonly class EntitlementDecision
{
    public function __construct(
        public bool $allowed,
        public string $accountId,
        public string $accountStatus,
        public string $product,
        public string $entitlement,
        public string $evaluatedAt,
        public ?string $grantId,
    ) {}
}
