<?php

namespace Sifrious\AccountsClient;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Sifrious\AccountsClient\Contracts\LoginDriver;
use Sifrious\AccountsClient\Data\AccountReference;

final readonly class LoginManager
{
    public function __construct(
        private LoginDriver $driver,
        private AccountsClient $accounts,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        return $this->driver->redirect($request);
    }

    public function complete(Request $request): AccountReference
    {
        return $this->accounts->resolve($this->driver->verifiedExternalFromCallback($request));
    }
}
