<?php

namespace Sifrious\AccountsClient\Data;

use Sifrious\AccountsClient\Outcome\IdentityUnlinkOutcome;

final readonly class IdentityUnlinkResult
{
    public function __construct(
        public string $accountId,
        public IdentityUnlinkOutcome $outcome,
    ) {}
}
