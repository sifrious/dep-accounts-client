<?php

namespace Sifrious\AccountsClient\Tests;

use PHPUnit\Framework\TestCase;
use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;

final class AuthenticationOutcomeTest extends TestCase
{
    /**
     * The published vocabulary, frozen.
     *
     * These strings travel into product logs, telemetry, and every consumer's
     * conformance expectations. Renaming one silently breaks dashboards and
     * rendering in applications this package never sees, so a change here has
     * to be a deliberate edit to this list rather than a side effect.
     */
    public function test_the_published_outcome_codes_are_stable(): void
    {
        $codes = array_map(
            static fn (AuthenticationOutcome $case): string => $case->value,
            AuthenticationOutcome::cases(),
        );

        sort($codes);

        $this->assertSame([
            'authenticated',
            'callback_expired',
            'callback_invalid',
            'canceled',
            'logged_out',
            'provider_failure',
            'replay_rejected',
            'session_expired',
            'suspended',
            'unauthorized_product',
            'zahir_unavailable',
        ], $codes);
    }

    /**
     * Admission is a single door. If a second case ever answered true here, a
     * consumer branching on grantsAccess() would start admitting people it had
     * never been reviewed to admit.
     */
    public function test_exactly_one_outcome_grants_access(): void
    {
        $granting = array_filter(
            AuthenticationOutcome::cases(),
            static fn (AuthenticationOutcome $case): bool => $case->grantsAccess(),
        );

        $this->assertSame([AuthenticationOutcome::Authenticated], array_values($granting));
    }

    public function test_denials_end_a_session_and_are_not_retryable(): void
    {
        foreach ([AuthenticationOutcome::Suspended, AuthenticationOutcome::UnauthorizedProduct] as $denial) {
            $this->assertTrue($denial->endsSession(), "{$denial->value} must end an existing session");
            $this->assertFalse($denial->isRetryable(), "{$denial->value} must not invite a retry");
        }
    }

    /**
     * A dependency failure must never end a session or read as a denial: an
     * outage would otherwise sign every entitled person out and look, in the
     * logs, exactly like a mass revocation.
     */
    public function test_a_dependency_outage_is_retryable_and_leaves_sessions_alone(): void
    {
        $outage = AuthenticationOutcome::ZahirUnavailable;

        $this->assertTrue($outage->isRetryable());
        $this->assertFalse($outage->endsSession());
        $this->assertFalse($outage->grantsAccess());
    }

    public function test_cancellation_is_retryable_and_not_a_failure_state(): void
    {
        $this->assertTrue(AuthenticationOutcome::Canceled->isRetryable());
        $this->assertFalse(AuthenticationOutcome::Canceled->endsSession());
    }
}
