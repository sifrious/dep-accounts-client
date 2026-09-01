<?php

namespace Sifrious\AccountsClient\Tests\Doubles;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Sifrious\AccountsClient\Contracts\LoginDriver;
use Sifrious\AccountsClient\Data\VerifiedExternal;
use Sifrious\AccountsClient\Exceptions\LoginVerificationFailed;

/**
 * A driver that answers with whatever the test decided, so seam behaviour can be
 * exercised without generating keys or replaying a protocol. Protocol
 * verification itself is covered by WorkOsAuthKitDriverTest.
 */
final class StubLoginDriver implements LoginDriver
{
    private function __construct(
        private readonly ?VerifiedExternal $identity,
        private readonly ?LoginVerificationFailed $failure,
    ) {}

    public static function verifying(?VerifiedExternal $identity = null): self
    {
        return new self($identity ?? self::identity(), null);
    }

    public static function failingWith(LoginVerificationFailed $failure): self
    {
        return new self(null, $failure);
    }

    public static function identity(string $subject = 'user_123'): VerifiedExternal
    {
        return new VerifiedExternal(
            provider: 'workos',
            providerSubject: $subject,
            claims: ['email' => 'person@example.test', 'email_verified' => true, 'name' => 'Person'],
            provenance: [
                'issuer' => 'https://api.workos.com/',
                'audience' => 'client_123',
                'asserted_at' => '2026-08-29T12:00:00Z',
            ],
            authenticatedAt: '2026-08-29T12:00:00Z',
        );
    }

    public function redirect(Request $request): RedirectResponse
    {
        return new RedirectResponse('https://provider.example/authorize');
    }

    public function verifiedExternalFromCallback(Request $request): VerifiedExternal
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        assert($this->identity !== null);

        return $this->identity;
    }

    public function logout(Request $request, string $postLogoutRedirect): RedirectResponse
    {
        return new RedirectResponse($postLogoutRedirect);
    }
}
