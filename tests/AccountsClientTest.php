<?php

namespace Sifrious\AccountsClient\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use Sifrious\AccountsClient\AccountsClient;
use Sifrious\AccountsClient\Data\VerifiedExternal;

class AccountsClientTest extends TestCase
{
    public function test_it_resolves_an_external_identity(): void
    {
        $http = new Factory;
        $http->fake([
            'https://accounts.example/api/v1/accounts/resolve' => $http->response([
                'account' => ['id' => 'acc_01test', 'status' => 'active', 'created' => true],
            ]),
        ]);

        $client = new AccountsClient($http, 'https://accounts.example', 'service-token');
        $account = $client->resolve(new VerifiedExternal(
            provider: 'workos',
            providerSubject: 'user_123',
            claims: ['email' => 'person@example.test', 'email_verified' => true, 'name' => 'Person'],
            provenance: ['issuer' => 'https://api.workos.com/', 'audience' => 'client_123', 'asserted_at' => '2026-08-29T12:00:00Z'],
            authenticatedAt: '2026-08-29T12:00:00Z',
        ));

        $this->assertSame('acc_01test', $account->id);
        $this->assertSame('active', $account->status);
        $this->assertTrue($account->created);

        $http->assertSent(function (Request $request): bool {
            $external = $request->data()['external'] ?? null;

            return $request->hasHeader('Authorization', 'Bearer service-token')
                && is_array($external)
                && ($external['provider'] ?? null) === 'workos'
                && ($external['provider_subject'] ?? null) === 'user_123'
                && ! isset($external['issuer']);
        });
    }

    public function test_it_requests_an_entitlement_decision(): void
    {
        $http = new Factory;
        $http->fake([
            'https://accounts.example/api/v1/entitlements/decide' => $http->response([
                'allowed' => true,
                'account_id' => 'acc_01test',
                'account_status' => 'active',
                'product' => 'logres',
                'entitlement' => 'logres.access',
                'evaluated_at' => '2026-08-27T12:00:00Z',
                'grant_id' => '01GRANT',
            ]),
        ]);

        $client = new AccountsClient($http, 'https://accounts.example', 'service-token');
        $decision = $client->entitlement('acc_01test', 'logres', 'logres.access');

        $this->assertTrue($decision->allowed);
        $this->assertSame('active', $decision->accountStatus);
        $this->assertSame('01GRANT', $decision->grantId);
    }
}
