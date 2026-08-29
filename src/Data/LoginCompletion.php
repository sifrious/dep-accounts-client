<?php

namespace Sifrious\AccountsClient\Data;

final readonly class LoginCompletion
{
    public function __construct(
        public AccountReference $account,
        public VerifiedExternal $identity,
    ) {}
}
