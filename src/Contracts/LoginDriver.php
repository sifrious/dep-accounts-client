<?php

namespace Sifrious\AccountsClient\Contracts;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Sifrious\AccountsClient\Data\ExternalIdentity;

interface LoginDriver
{
    public function redirect(Request $request): RedirectResponse;

    public function identityFromCallback(Request $request): ExternalIdentity;
}
