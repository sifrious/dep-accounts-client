<?php

namespace Sifrious\AccountsClient\Data;

final readonly class ExternalIdentity
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public ?string $displayName = null,
    ) {}
}
