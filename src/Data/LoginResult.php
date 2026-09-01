<?php

namespace Sifrious\AccountsClient\Data;

use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;

/**
 * The complete answer to "may this person into this product, and as whom?".
 *
 * Every login attempt produces one of these. The seam does not throw on a
 * failed attempt, because a cancelled login and a suspended account are both
 * ordinary results a product must render, not exceptional conditions.
 *
 * The companion fields are populated only as far as the attempt actually got:
 * an attempt that never reached Zahir carries no account, and only an
 * authenticated result carries an entitlement decision.
 */
final readonly class LoginResult
{
    private function __construct(
        public AuthenticationOutcome $outcome,
        public ?AccountReference $account = null,
        public ?VerifiedExternal $identity = null,
        public ?EntitlementDecision $access = null,
    ) {}

    public static function authenticated(
        AccountReference $account,
        VerifiedExternal $identity,
        EntitlementDecision $access,
    ): self {
        return new self(AuthenticationOutcome::Authenticated, $account, $identity, $access);
    }

    public static function unauthorizedProduct(AccountReference $account, EntitlementDecision $access): self
    {
        return new self(AuthenticationOutcome::UnauthorizedProduct, $account, null, $access);
    }

    public static function suspended(AccountReference $account): self
    {
        return new self(AuthenticationOutcome::Suspended, $account);
    }

    /** A login that ended before it produced an account. */
    public static function failed(AuthenticationOutcome $outcome): self
    {
        return new self($outcome);
    }

    /**
     * The only safe admission test.
     *
     * Products call this instead of comparing outcomes themselves, so a product
     * cannot accidentally treat a new or unfamiliar outcome as permission.
     *
     * The assertions publish what the check already guarantees, so a consumer
     * that has passed this gate can reach the account and identity directly
     * instead of writing `?->` against a null case that cannot happen.
     *
     * @phpstan-assert-if-true !null $this->account
     * @phpstan-assert-if-true !null $this->identity
     */
    public function grantsAccess(): bool
    {
        return $this->outcome->grantsAccess() && $this->account !== null && $this->identity !== null;
    }

    /**
     * The stable global account ID, when the attempt got far enough to have one.
     *
     * This is the only identifier a product may key its local projection on.
     */
    public function accountId(): ?string
    {
        return $this->account?->id;
    }
}
