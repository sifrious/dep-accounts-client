<?php

namespace Sifrious\AccountsClient\Testing;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Sifrious\AccountsClient\Contracts\LoginDriver;
use Sifrious\AccountsClient\Data\VerifiedExternal;
use Sifrious\AccountsClient\Exceptions\LoginVerificationFailed;
use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;

/**
 * A scriptable identity provider with no network and no cryptography.
 *
 * It models the one protocol property a consumer can actually get wrong at the
 * integration boundary: single use. A callback is only honoured if a login was
 * begun and has not already been consumed, so a replayed callback produces a
 * genuine `replay_rejected` rather than a canned one. Products that reuse a
 * transaction fail here for the real reason.
 *
 * Signature, issuer, audience, nonce, PKCE and allowlist verification belong to
 * the real driver and are proven against locally signed fixtures by
 * WorkOsAuthKitDriverTest. This fake deliberately does not reimplement them —
 * a second implementation of a security check is a second place for it to be
 * subtly wrong.
 */
final class FakeIdentityProvider implements LoginDriver
{
    private string $subject = 'user_default';

    private ?AuthenticationOutcome $nextFailure = null;

    private bool $transactionOpen = false;

    public int $redirects = 0;

    /** The person who will come back from the provider next. */
    public function willAuthenticate(string $subject): self
    {
        $this->subject = $subject;
        $this->nextFailure = null;

        return $this;
    }

    /** The provider will refuse, expire, or break on the next callback. */
    public function willFailWith(AuthenticationOutcome $outcome): self
    {
        $this->nextFailure = $outcome;

        return $this;
    }

    public function currentSubject(): string
    {
        return $this->subject;
    }

    public function redirect(Request $request): RedirectResponse
    {
        $this->redirects++;
        $this->transactionOpen = true;

        return new RedirectResponse('https://provider.test/authorize');
    }

    public function verifiedExternalFromCallback(Request $request): VerifiedExternal
    {
        // Single use, checked first: a replayed callback is a replay even when
        // the scripted outcome was something else.
        if (! $this->transactionOpen) {
            throw LoginVerificationFailed::withOutcome(
                AuthenticationOutcome::ReplayRejected,
                'Login transaction is missing or already consumed.',
            );
        }

        $this->transactionOpen = false;

        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw LoginVerificationFailed::withOutcome($failure, 'Scripted provider failure.');
        }

        return new VerifiedExternal(
            provider: 'workos',
            providerSubject: $this->subject,
            claims: ['email' => "{$this->subject}@example.test", 'email_verified' => true, 'name' => 'Test Person'],
            provenance: [
                'issuer' => 'https://provider.test/',
                'audience' => 'client_test',
                'asserted_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            authenticatedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function logout(Request $request, string $postLogoutRedirect): RedirectResponse
    {
        $this->transactionOpen = false;

        return new RedirectResponse($postLogoutRedirect);
    }
}
