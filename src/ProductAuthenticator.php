<?php

namespace Sifrious\AccountsClient;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Sifrious\AccountsClient\Contracts\LoginDriver;
use Sifrious\AccountsClient\Data\LoginResult;
use Sifrious\AccountsClient\Data\LogoutResult;
use Sifrious\AccountsClient\Exceptions\LoginVerificationFailed;
use Sifrious\AccountsClient\Exceptions\ZahirRejected;
use Sifrious\AccountsClient\Exceptions\ZahirUnavailable;
use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;
use UnexpectedValueException;

/**
 * The published consumer seam. A product needs this and nothing else to sign
 * someone in through Zahir.
 *
 * The entitlement check lives inside {@see complete()} rather than beside it.
 * That is the whole point of the class: a product cannot receive an
 * authenticated result without an allowed decision for its own product key,
 * so there is no ordering a consumer can get wrong and no path that reaches
 * `Auth::login()` on identity alone. A product that forgets to check
 * entitlements does not get a permissive failure — it gets no admission at all.
 *
 * Products own everything downstream: routes, the framework session, the local
 * projection, policies, onboarding, and every rendered word.
 */
final readonly class ProductAuthenticator
{
    public function __construct(
        private LoginDriver $driver,
        private AccountsClient $accounts,
        private string $product,
        private string $entitlement,
    ) {}

    /** The product key this seam admits to. Products log it; they never widen it. */
    public function product(): string
    {
        return $this->product;
    }

    public function entitlement(): string
    {
        return $this->entitlement;
    }

    /**
     * Send the person to the identity provider.
     *
     * The driver generates and stores the single-use state, nonce, and PKCE
     * verifier in the product's own session. Nothing durable is written here, so
     * an abandoned redirect costs nothing and leaves nothing to clean up.
     */
    public function begin(Request $request): RedirectResponse
    {
        return $this->driver->redirect($request);
    }

    /**
     * Turn a callback into a decision.
     *
     * Never throws. A cancelled login, an expired callback, a suspended account
     * and an unentitled one are all ordinary states a product must render, and
     * making the caller distinguish them through exception types would push the
     * vocabulary back into free-text messages.
     *
     * Repeating this call with the same callback yields a replay rejection
     * rather than a second account: the transaction is single-use, and Zahir's
     * resolution is idempotent by `(provider, provider_subject)` besides.
     */
    public function complete(Request $request): LoginResult
    {
        try {
            $identity = $this->driver->verifiedExternalFromCallback($request);
        } catch (LoginVerificationFailed $failure) {
            return LoginResult::failed($failure->outcome);
        }

        try {
            $account = $this->accounts->resolve($identity);

            // Lifecycle is checked before entitlement deliberately. A suspended
            // account must read as suspended, not as "no product access" — the
            // two need different words and different next steps.
            if ($account->status !== 'active') {
                return LoginResult::suspended($account);
            }

            $access = $this->accounts->entitlement($account->id, $this->product, $this->entitlement);
        } catch (ZahirUnavailable) {
            return LoginResult::failed(AuthenticationOutcome::ZahirUnavailable);
        } catch (ZahirRejected $rejected) {
            // A 4xx here is an operator fault (a bad or revoked service
            // credential, a contract drift), not a statement about this person.
            // Failing closed as "unavailable" keeps us from telling an entitled
            // human they have been denied because our own token expired.
            return LoginResult::failed($rejected->status === 404
                ? AuthenticationOutcome::UnauthorizedProduct
                : AuthenticationOutcome::ZahirUnavailable);
        } catch (UnexpectedValueException) {
            return LoginResult::failed(AuthenticationOutcome::ZahirUnavailable);
        }

        if (! $access->allowed
            || $access->accountId !== $account->id
            || $access->accountStatus !== 'active'
            || $access->product !== $this->product
            || $access->entitlement !== $this->entitlement) {
            // Every field is re-checked against what was asked. A decision that
            // answers a different account or a different product is not a grant,
            // however affirmative its `allowed` flag looks.
            return LoginResult::unauthorizedProduct($account, $access);
        }

        return LoginResult::authenticated($account, $identity, $access);
    }

    /**
     * End the product session at the provider as well as locally.
     *
     * The caller is responsible for destroying its own session; this returns the
     * redirect that finishes the job upstream. The post-logout URL is checked
     * against the exact allowlist by the driver, so a caller cannot be talked
     * into bouncing someone to an attacker's page on the way out.
     */
    public function logout(Request $request, string $postLogoutRedirect): LogoutResult
    {
        return new LogoutResult(
            AuthenticationOutcome::LoggedOut,
            $this->driver->logout($request, $postLogoutRedirect),
        );
    }
}
