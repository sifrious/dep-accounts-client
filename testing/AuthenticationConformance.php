<?php

namespace Sifrious\AccountsClient\Testing;

use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;

/**
 * The shared authentication release gate.
 *
 * Compose this trait into a product's own test case and implement
 * {@see consumerUnderTest()}. Every assertion below is identical for every
 * product; only the wiring differs. A trait rather than a base class, because
 * products already have their own framework TestCase to extend.
 *
 * Deliberately absent: anything about a specific product's onboarding, wording,
 * or navigation. Those belong in the product's own tests. What is shared here
 * is the boundary — identity, entitlement, session, and the ten outcomes.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait AuthenticationConformance
{
    abstract protected function consumerUnderTest(): ConsumerUnderTest;

    // ---------------------------------------------------------------- identity

    public function test_conformance_first_login_creates_exactly_one_projection(): void
    {
        $consumer = $this->consumerUnderTest();
        $account = $this->entitle($consumer, 'first-timer');

        $this->assertSame(AuthenticationOutcome::Authenticated, $this->login($consumer));
        $this->assertSame($account, $consumer->signedInAccountId());
        $this->assertSame(1, $consumer->projectionCount($account));
    }

    /**
     * The idempotency property the whole projection contract rests on. If a
     * second sign-in minted a second local record, a person's history would
     * silently split in two.
     */
    public function test_conformance_repeated_login_never_duplicates_the_projection(): void
    {
        $consumer = $this->consumerUnderTest();
        $account = $this->entitle($consumer, 'returning');

        foreach (range(1, 3) as $attempt) {
            $this->assertSame(AuthenticationOutcome::Authenticated, $this->login($consumer));
            $this->assertSame($account, $consumer->signedInAccountId());
            $consumer->signOut();
        }

        $this->assertSame(1, $consumer->projectionCount($account));
        $this->assertSame(1, $consumer->zahir()->accountCount(), 'Resolution must be idempotent by subject.');
    }

    // ------------------------------------------------------------- entitlement

    public function test_conformance_an_account_without_the_entitlement_is_refused(): void
    {
        $consumer = $this->consumerUnderTest();
        $consumer->zahir()->accountFor('unentitled');
        $consumer->provider()->willAuthenticate('unentitled');

        $this->assertSame(AuthenticationOutcome::UnauthorizedProduct, $this->login($consumer));
        $this->assertNull($consumer->signedInAccountId(), 'A refused login must not leave a session.');
    }

    /**
     * The second-consumer proof in one assertion: holding another product's
     * entitlement must open nothing here.
     */
    public function test_conformance_another_products_entitlement_grants_nothing(): void
    {
        $consumer = $this->consumerUnderTest();
        $account = $consumer->zahir()->accountFor('other-product-user');
        $consumer->zahir()->grant($account, 'some-other-product');
        $consumer->provider()->willAuthenticate('other-product-user');

        $this->assertSame(AuthenticationOutcome::UnauthorizedProduct, $this->login($consumer));
        $this->assertNull($consumer->signedInAccountId());
    }

    public function test_conformance_entitlement_resolution_cannot_be_bypassed(): void
    {
        $consumer = $this->consumerUnderTest();
        $consumer->zahir()->accountFor('bypasser');
        $consumer->provider()->willAuthenticate('bypasser');

        $before = $consumer->zahir()->entitlementCalls;
        $this->login($consumer);

        $this->assertGreaterThan($before, $consumer->zahir()->entitlementCalls, 'Login must consult Zahir for an entitlement decision.');
        $this->assertFalse($consumer->reachProtectedSurface(), 'An unentitled account must not reach a protected surface.');
    }

    // ---------------------------------------------------------------- failures

    public function test_conformance_a_cancelled_login_is_distinct_and_creates_nothing(): void
    {
        $consumer = $this->consumerUnderTest();
        $consumer->provider()->willAuthenticate('canceller')->willFailWith(AuthenticationOutcome::Canceled);

        $this->assertSame(AuthenticationOutcome::Canceled, $this->login($consumer));
        $this->assertNull($consumer->signedInAccountId());
        $this->assertSame(0, $consumer->zahir()->accountCount(), 'A cancelled login must never reach Zahir.');
    }

    public function test_conformance_an_expired_callback_is_distinct(): void
    {
        $this->assertScriptedFailure(AuthenticationOutcome::CallbackExpired);
    }

    public function test_conformance_a_malformed_callback_is_distinct(): void
    {
        $this->assertScriptedFailure(AuthenticationOutcome::CallbackInvalid);
    }

    public function test_conformance_a_provider_failure_is_distinct(): void
    {
        $this->assertScriptedFailure(AuthenticationOutcome::ProviderFailure);
    }

    /**
     * Replay is proven, not scripted: the callback is completed twice against a
     * single begun transaction, so a product that reuses a transaction fails
     * here for the real reason.
     */
    public function test_conformance_a_replayed_callback_is_rejected(): void
    {
        $consumer = $this->consumerUnderTest();
        $account = $this->entitle($consumer, 'replayer');

        $this->assertSame(AuthenticationOutcome::Authenticated, $this->login($consumer));
        $consumer->signOut();

        // No fresh begin: the same callback presented a second time.
        $this->assertSame(AuthenticationOutcome::ReplayRejected, $consumer->completeLogin());
        $this->assertNull($consumer->signedInAccountId());
        $this->assertSame(1, $consumer->projectionCount($account), 'A replay must not mint a second projection.');
    }

    /**
     * An outage must never read as a denial. If it did, every entitled person
     * would be locked out during an incident and the logs would be
     * indistinguishable from a mass revocation.
     */
    public function test_conformance_a_zahir_outage_is_not_a_denial(): void
    {
        $consumer = $this->consumerUnderTest();
        $this->entitle($consumer, 'unlucky');
        $consumer->zahir()->goOffline();

        $outcome = $this->login($consumer);

        $this->assertSame(AuthenticationOutcome::ZahirUnavailable, $outcome);
        $this->assertTrue($outcome->isRetryable());
        $this->assertNotSame(AuthenticationOutcome::UnauthorizedProduct, $outcome);
        $this->assertNull($consumer->signedInAccountId());
    }

    /**
     * Every failure mode, retried, must still leave exactly one account and one
     * projection. Retry storms are how duplicate accounts get made.
     */
    public function test_conformance_retrying_after_failure_never_duplicates_anything(): void
    {
        $consumer = $this->consumerUnderTest();
        $account = $this->entitle($consumer, 'persistent');

        foreach ([
            AuthenticationOutcome::Canceled,
            AuthenticationOutcome::CallbackExpired,
            AuthenticationOutcome::CallbackInvalid,
            AuthenticationOutcome::ProviderFailure,
        ] as $failure) {
            $consumer->provider()->willAuthenticate('persistent')->willFailWith($failure);
            $this->assertSame($failure, $this->login($consumer));
        }

        $consumer->provider()->willAuthenticate('persistent');
        $this->assertSame(AuthenticationOutcome::Authenticated, $this->login($consumer));
        $this->assertSame(1, $consumer->projectionCount($account));
        $this->assertSame(1, $consumer->zahir()->accountCount());
    }

    // --------------------------------------------------------------- lifecycle

    public function test_conformance_a_suspended_account_cannot_establish_a_session(): void
    {
        $consumer = $this->consumerUnderTest();
        $account = $this->entitle($consumer, 'suspended-person');
        $consumer->zahir()->suspend($account);

        $this->assertSame(AuthenticationOutcome::Suspended, $this->login($consumer));
        $this->assertNull($consumer->signedInAccountId());
    }

    public function test_conformance_revoking_the_entitlement_closes_active_access(): void
    {
        $consumer = $this->consumerUnderTest();
        $account = $this->entitle($consumer, 'revoked-person');

        $this->assertSame(AuthenticationOutcome::Authenticated, $this->login($consumer));
        $this->assertTrue($consumer->reachProtectedSurface());

        $fingerprint = $consumer->durableStateFingerprint();
        $consumer->zahir()->revoke($account, $consumer->productKey());

        $this->assertFalse($consumer->reachProtectedSurface(), 'Revocation must fail closed on the next protected request.');
        $this->assertSame($fingerprint, $consumer->durableStateFingerprint(), 'Revocation must not destroy product data.');
        $this->assertSame(1, $consumer->projectionCount($account), 'Revocation must not delete the projection.');
    }

    public function test_conformance_suspension_closes_active_access_without_deleting_anything(): void
    {
        $consumer = $this->consumerUnderTest();
        $account = $this->entitle($consumer, 'later-suspended');

        $this->assertSame(AuthenticationOutcome::Authenticated, $this->login($consumer));
        $fingerprint = $consumer->durableStateFingerprint();

        $consumer->zahir()->suspend($account);

        $this->assertFalse($consumer->reachProtectedSurface());
        $this->assertSame($fingerprint, $consumer->durableStateFingerprint());
        $this->assertSame(1, $consumer->projectionCount($account));

        // And reinstating restores access to the same projection, not a new one.
        $consumer->zahir()->reactivate($account);
        $this->assertTrue($consumer->reachProtectedSurface());
        $this->assertSame(1, $consumer->projectionCount($account));
    }

    public function test_conformance_logout_clears_the_session_and_keeps_product_data(): void
    {
        $consumer = $this->consumerUnderTest();
        $account = $this->entitle($consumer, 'leaver');

        $this->assertSame(AuthenticationOutcome::Authenticated, $this->login($consumer));
        $fingerprint = $consumer->durableStateFingerprint();

        $consumer->signOut();

        $this->assertNull($consumer->signedInAccountId());
        $this->assertFalse($consumer->reachProtectedSurface());
        $this->assertSame($fingerprint, $consumer->durableStateFingerprint(), 'Signing out is not deleting an account.');
        $this->assertSame(1, $consumer->projectionCount($account));
    }

    public function test_conformance_session_expiry_preserves_durable_state_and_resumes(): void
    {
        $consumer = $this->consumerUnderTest();
        $account = $this->entitle($consumer, 'timed-out');

        $this->assertSame(AuthenticationOutcome::Authenticated, $this->login($consumer));
        $fingerprint = $consumer->durableStateFingerprint();

        $consumer->expireSession();

        $this->assertNull($consumer->signedInAccountId());
        $this->assertFalse($consumer->reachProtectedSurface());
        $this->assertSame($fingerprint, $consumer->durableStateFingerprint());

        // Coming back lands on the same projection and the same durable state.
        $consumer->provider()->willAuthenticate('timed-out');
        $this->assertSame(AuthenticationOutcome::Authenticated, $this->login($consumer));
        $this->assertSame($account, $consumer->signedInAccountId());
        $this->assertSame(1, $consumer->projectionCount($account));
        $this->assertSame($fingerprint, $consumer->durableStateFingerprint());
    }

    // ----------------------------------------------------------------- secrets

    /**
     * A product session may carry the opaque account ID and its own local state.
     * It may not carry provider credentials, raw assertions, or the Zahir
     * service token — a session store is readable in ways a secret store is not.
     */
    public function test_conformance_a_successful_session_leaks_no_credentials(): void
    {
        $consumer = $this->consumerUnderTest();
        $account = $this->entitle($consumer, 'inspected');

        $this->assertSame(AuthenticationOutcome::Authenticated, $this->login($consumer));

        $payload = $consumer->sessionPayload();

        foreach (['access_token', 'refresh_token', 'id_token', 'client_secret', 'code_verifier', 'Bearer ', 'zhr.'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload, "The product session must not carry [{$forbidden}].");
        }

        $this->assertNotSame('', $account);
    }

    // ------------------------------------------------------------------ helpers

    private function assertScriptedFailure(AuthenticationOutcome $outcome): void
    {
        $consumer = $this->consumerUnderTest();
        $this->entitle($consumer, 'unlucky-'.$outcome->value);
        $consumer->provider()->willFailWith($outcome);

        $this->assertSame($outcome, $this->login($consumer));
        $this->assertNull($consumer->signedInAccountId());
    }

    /** Give a subject a real account holding this product's own entitlement. */
    private function entitle(ConsumerUnderTest $consumer, string $subject): string
    {
        $account = $consumer->zahir()->accountFor($subject);
        $consumer->zahir()->grant($account, $consumer->productKey());
        $consumer->provider()->willAuthenticate($subject);

        return $account;
    }

    private function login(ConsumerUnderTest $consumer): AuthenticationOutcome
    {
        $consumer->beginLogin();

        return $consumer->completeLogin();
    }
}
