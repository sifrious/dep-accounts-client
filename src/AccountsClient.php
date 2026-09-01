<?php

namespace Sifrious\AccountsClient;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Sifrious\AccountsClient\Data\AccountReference;
use Sifrious\AccountsClient\Data\EntitlementDecision;
use Sifrious\AccountsClient\Data\IdentityUnlinkResult;
use Sifrious\AccountsClient\Data\VerifiedExternal;
use Sifrious\AccountsClient\Exceptions\ZahirRejected;
use Sifrious\AccountsClient\Exceptions\ZahirUnavailable;
use UnexpectedValueException;

/**
 * The only route to Zahir.
 *
 * Every call is authenticated with the product's own service credential and
 * returns immutable values. No ORM model, storage handle, or provider object
 * crosses this boundary in either direction.
 */
final readonly class AccountsClient
{
    public function __construct(
        private Factory $http,
        private string $baseUrl,
        private string $serviceToken,
    ) {}

    public function resolve(VerifiedExternal $identity): AccountReference
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->post('/api/v1/accounts/resolve', ['external' => $this->external($identity)]));

        return new AccountReference(
            id: $this->string($response, 'account.id'),
            status: $this->string($response, 'account.status'),
            created: $this->boolean($response, 'account.created'),
        );
    }

    public function entitlement(string $accountId, string $product, string $entitlement): EntitlementDecision
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->post('/api/v1/entitlements/decide', [
                'account_id' => $accountId,
                'product' => $product,
                'entitlement' => $entitlement,
            ]));

        return new EntitlementDecision(
            allowed: $this->boolean($response, 'allowed'),
            accountId: $this->string($response, 'account_id'),
            accountStatus: $this->string($response, 'account_status'),
            product: $this->string($response, 'product'),
            entitlement: $this->string($response, 'entitlement'),
            evaluatedAt: $this->string($response, 'evaluated_at'),
            grantId: $this->nullableString($response, 'grant_id'),
        );
    }

    public function linkIdentity(string $currentAccountId, VerifiedExternal $identity): AccountReference
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->withHeader('X-Zahir-Current-Account', $currentAccountId)
            ->post("/api/v1/accounts/{$currentAccountId}/identities/link", [
                'external' => $this->external($identity),
            ]));

        return new AccountReference(
            id: $this->string($response, 'account.id'),
            status: $this->string($response, 'account.status'),
            created: false,
        );
    }

    public function unlinkIdentity(
        string $currentAccountId,
        string $provider,
        string $providerSubject,
        ?string $acceptedRecoveryReference = null,
    ): IdentityUnlinkResult {
        $payload = ['provider' => $provider, 'provider_subject' => $providerSubject];
        if ($acceptedRecoveryReference !== null) {
            $payload['accepted_recovery_reference'] = $acceptedRecoveryReference;
        }

        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->withHeader('X-Zahir-Current-Account', $currentAccountId)
            ->delete("/api/v1/accounts/{$currentAccountId}/identities", $payload));

        return new IdentityUnlinkResult(
            accountId: $this->string($response, 'account_id'),
            outcome: $this->string($response, 'outcome'),
        );
    }

    public function suspend(string $accountId, string $reason): AccountReference
    {
        return $this->lifecycle($accountId, $reason, true);
    }

    public function reactivate(string $accountId, string $reason): AccountReference
    {
        return $this->lifecycle($accountId, $reason, false);
    }

    private function lifecycle(string $accountId, string $reason, bool $suspend): AccountReference
    {
        $url = "/api/v1/accounts/{$accountId}/suspension";
        $response = $this->send(fn (PendingRequest $request): Response => $suspend
            ? $request->post($url, ['reason' => $reason])
            : $request->delete($url, ['reason' => $reason]));

        return new AccountReference(
            id: $this->string($response, 'account.id'),
            status: $this->string($response, 'account.status'),
            created: false,
        );
    }

    /**
     * Issue one authenticated call and turn transport reality into the two
     * answers a consumer can actually act on.
     *
     * The split matters more than it looks: "Zahir is down" must never reach a
     * product as "you have no access". A 5xx, a timeout, and a throttle are all
     * "ask again later"; only a deliberate 4xx is a refusal.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            $response = $call($this->request());
        } catch (ConnectionException $exception) {
            throw new ZahirUnavailable('Zahir could not be reached.', 0, $exception);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        if ($status >= 500 || $status === 408 || $status === 429) {
            throw new ZahirUnavailable("Zahir answered {$status}.");
        }

        throw new ZahirRejected("Zahir refused the request with {$status}.", $status);
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($this->serviceToken)
            ->acceptJson();
    }

    /** @return array<string, mixed> */
    private function external(VerifiedExternal $identity): array
    {
        return [
            'provider' => $identity->provider,
            'provider_subject' => $identity->providerSubject,
            'claims' => $identity->claims,
            'provenance' => $identity->provenance,
            'authenticated_at' => $identity->authenticatedAt,
        ];
    }

    private function string(Response $response, string $key): string
    {
        $value = $response->json($key);

        if (! is_string($value)) {
            throw new UnexpectedValueException("Accounts response field {$key} must be a string.");
        }

        return $value;
    }

    private function nullableString(Response $response, string $key): ?string
    {
        $value = $response->json($key);

        if ($value !== null && ! is_string($value)) {
            throw new UnexpectedValueException("Accounts response field {$key} must be a string or null.");
        }

        return $value;
    }

    private function boolean(Response $response, string $key): bool
    {
        $value = $response->json($key);

        if (! is_bool($value)) {
            throw new UnexpectedValueException("Accounts response field {$key} must be a boolean.");
        }

        return $value;
    }
}
