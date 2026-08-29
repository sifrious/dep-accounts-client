<?php

namespace Sifrious\AccountsClient\Data;

final readonly class IdentityUnlinkResult
{
    public function __construct(
        public string $accountId,
        public string $outcome,
    ) {}
}
