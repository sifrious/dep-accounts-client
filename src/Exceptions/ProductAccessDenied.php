<?php

namespace Sifrious\AccountsClient\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;

/**
 * A protected request was refused because the account may not use this product
 * right now.
 *
 * The outcome is the payload; the default rendering is a deliberately terse
 * fallback. Products are expected to render their own words for each outcome —
 * "your access was revoked" and "we can't reach the account service" call for
 * different pages and different next actions — which they do by handling this
 * exception in their own exception handler.
 */
final class ProductAccessDenied extends RuntimeException
{
    public function __construct(public readonly AuthenticationOutcome $outcome)
    {
        parent::__construct("Product access denied: {$outcome->value}.");
    }

    /**
     * Whether the refusal is the dependency's fault rather than the account's.
     * Drives the 503-versus-403 split, and should drive the product's wording.
     */
    public function isDependencyFailure(): bool
    {
        return $this->outcome === AuthenticationOutcome::ZahirUnavailable;
    }

    /**
     * A machine-readable body for API-shaped requests, and nothing at all for
     * anyone else.
     *
     * Returning null lets the application's own handler take a browser request
     * and render its words for this outcome. Answering here unconditionally
     * would silently win over that handler — Laravel consults an exception's
     * own render() before the application's registered one — and every consumer
     * would get a bare 403 while believing it had configured otherwise.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'outcome' => $this->outcome->value,
            'message' => $this->isDependencyFailure()
                ? 'Product access cannot be confirmed right now.'
                : 'Product access denied.',
        ], $this->isDependencyFailure() ? 503 : 403);
    }
}
