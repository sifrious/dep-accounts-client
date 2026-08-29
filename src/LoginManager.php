<?php

namespace Sifrious\AccountsClient;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Sifrious\AccountsClient\Contracts\LoginDriver;
use Sifrious\AccountsClient\Data\AccountReference;
use Sifrious\AccountsClient\Data\LoginCompletion;

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

    public function completeWithIdentity(Request $request): LoginCompletion
    {
        $identity = $this->driver->verifiedExternalFromCallback($request);

        return new LoginCompletion($this->accounts->resolve($identity), $identity);
    }

    public function logout(Request $request, string $postLogoutRedirect): RedirectResponse
    {
        return $this->driver->logout($request, $postLogoutRedirect);
    }
}
