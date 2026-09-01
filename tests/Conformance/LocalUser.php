<?php

namespace Sifrious\AccountsClient\Tests\Conformance;

use Sifrious\AccountsClient\Contracts\AccountProjection;

/** The reference product's local user record: an account ID and nothing else. */
final readonly class LocalUser implements AccountProjection
{
    public function __construct(private string $accountId) {}

    public function zahirAccountId(): string
    {
        return $this->accountId;
    }
}
