<?php

namespace Sifrious\AccountsClient\Testing;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

/**
 * A stateful, in-memory stand-in for the Zahir service. No network, no
 * database, no clock skew.
 *
 * Statefulness is the point. A fake that returns canned responses cannot prove
 * idempotency: resolving the same `(provider, provider_subject)` twice has to
 * return the *same* account ID for "repeated login does not duplicate the local
 * projection" to mean anything. So this keeps a real mapping, mints stable
 * `acc_*` identifiers, and honours suspension and revocation the way the service
 * does.
 */
final class FakeZahir
{
    public const BASE_URL = 'https://zahir.test';

    /** @var array<string, string> keyed by "provider|subject" */
    private array $accountsBySubject = [];

    /** @var array<string, string> account ID => status */
    private array $status = [];

    /** @var array<string, string> "account|product|entitlement" => grant ID */
    private array $grants = [];

    private bool $offline = false;

    private int $sequence = 0;

    public int $resolveCalls = 0;

    public int $entitlementCalls = 0;

    /** Mint an account up front, so a test can grant access before anyone logs in. */
    public function accountFor(string $subject, string $provider = 'workos'): string
    {
        $key = "{$provider}|{$subject}";

        if (! isset($this->accountsBySubject[$key])) {
            $this->sequence++;
            $id = 'acc_'.str_pad((string) $this->sequence, 26, '0', STR_PAD_LEFT);
            $this->accountsBySubject[$key] = $id;
            $this->status[$id] = 'active';
        }

        return $this->accountsBySubject[$key];
    }

    public function grant(string $accountId, string $product, string $entitlement = 'access'): self
    {
        $this->grants["{$accountId}|{$product}|{$entitlement}"] = 'grant_'.count($this->grants);

        return $this;
    }

    public function revoke(string $accountId, string $product, string $entitlement = 'access'): self
    {
        unset($this->grants["{$accountId}|{$product}|{$entitlement}"]);

        return $this;
    }

    public function suspend(string $accountId): self
    {
        $this->status[$accountId] = 'suspended';

        return $this;
    }

    public function reactivate(string $accountId): self
    {
        $this->status[$accountId] = 'active';

        return $this;
    }

    /** Every call now answers 503 — an outage, which is never a denial. */
    public function goOffline(): self
    {
        $this->offline = true;

        return $this;
    }

    public function comeBackOnline(): self
    {
        $this->offline = false;

        return $this;
    }

    public function isKnownAccount(string $accountId): bool
    {
        return isset($this->status[$accountId]);
    }

    /** How many distinct accounts this fake has ever minted. */
    public function accountCount(): int
    {
        return count($this->accountsBySubject);
    }

    /**
     * An HTTP factory wired to answer as Zahir would.
     *
     * Hand this to AccountsClient. Anything not faked here throws, so a consumer
     * that reaches for an endpoint the contract does not define fails loudly
     * rather than silently receiving an empty response.
     */
    public function httpFactory(): Factory
    {
        $http = new Factory;
        $http->fake([
            self::BASE_URL.'/api/v1/accounts/resolve' => function (Request $request) use ($http) {
                $this->resolveCalls++;

                if ($this->offline) {
                    return $http->response(['message' => 'Service Unavailable'], 503);
                }

                /** @var array<string, mixed> $external */
                $external = $request->data()['external'] ?? [];
                $provider = is_string($external['provider'] ?? null) ? $external['provider'] : 'workos';
                $subject = is_string($external['provider_subject'] ?? null) ? $external['provider_subject'] : '';

                $known = isset($this->accountsBySubject["{$provider}|{$subject}"]);
                $id = $this->accountFor($subject, $provider);

                return $http->response(['account' => [
                    'id' => $id,
                    'status' => $this->status[$id],
                    'created' => ! $known,
                ]]);
            },
            self::BASE_URL.'/api/v1/entitlements/decide' => function (Request $request) use ($http) {
                $this->entitlementCalls++;

                if ($this->offline) {
                    return $http->response(['message' => 'Service Unavailable'], 503);
                }

                $data = $request->data();
                $accountId = is_string($data['account_id'] ?? null) ? $data['account_id'] : '';
                $product = is_string($data['product'] ?? null) ? $data['product'] : '';
                $entitlement = is_string($data['entitlement'] ?? null) ? $data['entitlement'] : '';

                if (! isset($this->status[$accountId])) {
                    return $http->response(['message' => 'Not Found'], 404);
                }

                $status = $this->status[$accountId];
                $grantId = $this->grants["{$accountId}|{$product}|{$entitlement}"] ?? null;

                return $http->response([
                    // A suspended account is denied whatever its grants say.
                    'allowed' => $status === 'active' && $grantId !== null,
                    'account_id' => $accountId,
                    'account_status' => $status,
                    'product' => $product,
                    'entitlement' => $entitlement,
                    'evaluated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'grant_id' => $status === 'active' ? $grantId : null,
                ]);
            },
        ]);

        return $http;
    }
}
