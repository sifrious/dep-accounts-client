<?php

namespace Sifrious\AccountsClient\Outcome;

/**
 * The stable, provider-neutral vocabulary every consuming product renders and
 * every conformance fixture asserts against.
 *
 * These string values are the contract. They appear in product logs, telemetry,
 * and conformance expectations, so a case may be added but an existing value is
 * never renamed or repurposed. Nothing here names an identity provider, a
 * transport, or a product.
 */
enum AuthenticationOutcome: string
{
    /** A verified identity resolved to an active account holding the product entitlement. */
    case Authenticated = 'authenticated';

    /** The account is real and active but holds no grant for this product. */
    case UnauthorizedProduct = 'unauthorized_product';

    /** The person declined at the provider. Not a failure; offer the same door again. */
    case Canceled = 'canceled';

    /** The login transaction outlived its time-to-live before the callback arrived. */
    case CallbackExpired = 'callback_expired';

    /** The callback was malformed, or its state, nonce, issuer, audience, or signature failed. */
    case CallbackInvalid = 'callback_invalid';

    /** The login transaction was already consumed. A single-use callback was presented twice. */
    case ReplayRejected = 'replay_rejected';

    /** The global account exists but its lifecycle status forbids establishing access. */
    case Suspended = 'suspended';

    /** The identity provider itself failed, timed out, or answered unusably. */
    case ProviderFailure = 'provider_failure';

    /** Zahir could not be reached or could not answer. Distinct from a denial. */
    case ZahirUnavailable = 'zahir_unavailable';

    /** The person deliberately ended the product session. */
    case LoggedOut = 'logged_out';

    /** An established product session aged out and must be re-established. */
    case SessionExpired = 'session_expired';

    /**
     * Whether this outcome authorizes the product to establish a session.
     *
     * Exactly one case may ever answer true. Consumers branch on this rather
     * than comparing cases, so a future case cannot accidentally admit anyone.
     */
    public function grantsAccess(): bool
    {
        return $this === self::Authenticated;
    }

    /**
     * Whether the product must destroy any local session it already holds.
     *
     * Denials are as session-ending as an explicit sign-out: an account that has
     * been suspended or had its entitlement revoked must not keep an authorized
     * session that predates the change.
     */
    public function endsSession(): bool
    {
        return match ($this) {
            self::LoggedOut, self::SessionExpired, self::Suspended, self::UnauthorizedProduct => true,
            default => false,
        };
    }

    /**
     * Whether presenting the same door again could plausibly succeed.
     *
     * A cancellation or a transient dependency failure is retryable. A denial is
     * not: retrying cannot manufacture a grant, and offering a retry there
     * teaches people to hammer a door that will not open.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Canceled, self::CallbackExpired, self::CallbackInvalid, self::ReplayRejected,
            self::ProviderFailure, self::ZahirUnavailable, self::SessionExpired => true,
            default => false,
        };
    }
}
