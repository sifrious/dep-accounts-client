<?php

namespace Sifrious\AccountsClient\Testing;

use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;

/**
 * The extension hook. A product describes how to drive its own login, and the
 * conformance suite supplies every assertion.
 *
 * Deliberately small. Everything here is something only the product can know —
 * where its routes are, what its session looks like, what durable state it owns.
 * Nothing here is an assertion, and nothing here is product onboarding: an
 * implementation that starts making claims about correctness has moved the
 * shared standard into one product, which is the failure this interface exists
 * to prevent.
 */
interface ConsumerUnderTest
{
    /** The scripted identity provider this consumer is wired to. */
    public function provider(): FakeIdentityProvider;

    /** The stateful fake Zahir this consumer is wired to. */
    public function zahir(): FakeZahir;

    /** The product key this consumer asks Zahir about. */
    public function productKey(): string;

    /** Begin a login through the product's own redirect route. */
    public function beginLogin(): void;

    /**
     * Complete a login through the product's real callback path, establishing a
     * session if — and only if — the product decides it should.
     */
    public function completeLogin(): AuthenticationOutcome;

    /**
     * How many local records this product holds for the given account.
     *
     * Must never exceed one. Two projections for one account splits a person's
     * product history in half at their second sign-in.
     */
    public function projectionCount(string $accountId): int;

    /** The account behind the current product session, or null when signed out. */
    public function signedInAccountId(): ?string;

    /** Everything the product keeps in its session, for leak inspection. */
    public function sessionPayload(): string;

    /** Sign out through the product's own logout path. */
    public function signOut(): void;

    /** Lapse the product session without touching any durable data. */
    public function expireSession(): void;

    /** Attempt an entitlement-protected surface. True when access was allowed. */
    public function reachProtectedSurface(): bool;

    /**
     * An opaque fingerprint of durable product state — onboarding progress,
     * preferences, domain records.
     *
     * The suite never interprets it; it only checks the value survives logout,
     * expiry, suspension, and revocation. Products keep their own onboarding
     * assertions in their own tests.
     */
    public function durableStateFingerprint(): string;
}
