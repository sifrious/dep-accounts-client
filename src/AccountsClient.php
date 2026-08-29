<?php

namespace Sifrious\AccountsClient;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Sifrious\AccountsClient\Data\AccountReference;
use Sifrious\AccountsClient\Data\EntitlementDecision;
use Sifrious\AccountsClient\Data\VerifiedExternal;
use UnexpectedValueException;

final readonly class AccountsClient
{
    public function __construct(
        private Factory $http,
        private string $baseUrl,
        private string $serviceToken,
    ) {}

    public function resolve(VerifiedExternal $identity): AccountReference
    {
        $response = $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($this->serviceToken)
            ->acceptJson()
            ->post('/api/v1/accounts/resolve', [
                'external' => [
                    'provider' => $identity->provider,
                    'provider_subject' => $identity->providerSubject,
                    'claims' => $identity->claims,
                    'provenance' => $identity->provenance,
                    'authenticated_at' => $identity->authenticatedAt,
                ],
            ])
            ->throw();

        return new AccountReference(
            id: $this->string($response, 'account.id'),
            status: $this->string($response, 'account.status'),
            created: $this->boolean($response, 'account.created'),
        );
    }

    public function entitlement(string $accountId, string $product, string $entitlement): EntitlementDecision
    {
        $response = $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($this->serviceToken)
            ->acceptJson()
            ->post('/api/v1/entitlements/decide', [
                'account_id' => $accountId,
                'product' => $product,
                'entitlement' => $entitlement,
            ])
            ->throw();

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
