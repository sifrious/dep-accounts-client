<?php

namespace Sifrious\AccountsClient\Laravel;

use Closure;
use Illuminate\Http\Request;
use Sifrious\AccountsClient\AccountsClient;
use Sifrious\AccountsClient\Contracts\AccountProjection;
use Sifrious\AccountsClient\Exceptions\ProductAccessDenied;
use Sifrious\AccountsClient\Exceptions\ZahirRejected;
use Sifrious\AccountsClient\Exceptions\ZahirUnavailable;
use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/**
 * Re-asks Zahir on every protected request. This is the entire product-session
 * invalidation contract.
 *
 * Zahir holds no sessions and cannot reach into a product to end one — by
 * design, since a credential-free account service has no business owning
 * browser state. Instead, authority is re-established continuously: a
 * suspension or a revoked grant takes effect on the next protected request, so
 * the blast radius is one decision window rather than one session lifetime.
 *
 * The freshness check is what makes that true. Without it, a cached or replayed
 * decision could keep an account alive indefinitely after its grant was pulled,
 * which is precisely the failure this middleware exists to prevent.
 *
 * A local role, preference, or provider connection can never substitute for
 * this call. Product-local state cannot elevate a Zahir entitlement.
 */
final readonly class RequireProductEntitlement
{
    public function __construct(
        private AccountsClient $accounts,
        private string $product,
        private string $entitlement,
        private int $decisionMaxAgeSeconds,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No projection means no proven account. Absence fails closed rather
        // than falling through to an unchecked request.
        if (! $user instanceof AccountProjection) {
            throw new ProductAccessDenied(AuthenticationOutcome::UnauthorizedProduct);
        }

        $accountId = $user->zahirAccountId();
        if ($accountId === '') {
            throw new ProductAccessDenied(AuthenticationOutcome::UnauthorizedProduct);
        }

        try {
            $decision = $this->accounts->entitlement($accountId, $this->product, $this->entitlement);
        } catch (ZahirUnavailable|UnexpectedValueException) {
            throw new ProductAccessDenied(AuthenticationOutcome::ZahirUnavailable);
        } catch (ZahirRejected $rejected) {
            throw new ProductAccessDenied($rejected->status === 404
                ? AuthenticationOutcome::UnauthorizedProduct
                : AuthenticationOutcome::ZahirUnavailable);
        }

        if (! $this->isFresh($decision->evaluatedAt)) {
            throw new ProductAccessDenied(AuthenticationOutcome::ZahirUnavailable);
        }

        if ($decision->accountStatus !== 'active') {
            throw new ProductAccessDenied(AuthenticationOutcome::Suspended);
        }

        // Re-check that the decision answers the question that was asked. A
        // decision for another account or another product is not an answer,
        // however affirmative it reads.
        if (! $decision->allowed
            || $decision->accountId !== $accountId
            || $decision->product !== $this->product
            || $decision->entitlement !== $this->entitlement) {
            throw new ProductAccessDenied(AuthenticationOutcome::UnauthorizedProduct);
        }

        return $next($request);
    }

    /**
     * A decision must be recent, and must not claim to come from the future —
     * a forward-dated timestamp would otherwise stay "fresh" forever.
     */
    private function isFresh(string $evaluatedAt): bool
    {
        $evaluated = strtotime($evaluatedAt);

        if ($evaluated === false) {
            return false;
        }

        $age = time() - $evaluated;

        return $age >= -$this->decisionMaxAgeSeconds && $age <= $this->decisionMaxAgeSeconds;
    }
}
