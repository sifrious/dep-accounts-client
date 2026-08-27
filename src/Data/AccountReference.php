<?php

namespace Sifrious\AccountsClient\Data;

final readonly class AccountReference
{
    public function __construct(
        public string $id,
        public string $status,
    ) {}
}
