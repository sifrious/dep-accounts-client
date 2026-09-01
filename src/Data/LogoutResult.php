<?php

namespace Sifrious\AccountsClient\Data;

use Illuminate\Http\RedirectResponse;
use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;

/**
 * The result of ending a product session.
 *
 * Sign-out is modelled as an outcome rather than a bare redirect so that the
 * deliberate case and the involuntary ones (an aged-out session, a revoked
 * entitlement) travel through the same contract and land in the same logs.
 */
final readonly class LogoutResult
{
    public function __construct(
        public AuthenticationOutcome $outcome,
        public RedirectResponse $response,
    ) {}
}
