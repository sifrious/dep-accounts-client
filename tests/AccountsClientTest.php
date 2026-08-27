<?php

namespace Sifrious\AccountsClient\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use Sifrious\AccountsClient\AccountsClient;
use Sifrious\AccountsClient\Data\ExternalIdentity;

class AccountsClientTest extends TestCase
{
    public function test_it_resolves_an_external_identity(): void
    {
        $http = new Factory;
        $http->fake([
            'https://accounts.example/api/v1/accounts/resolve' => $http->response([
                'account' => ['id' => '01TEST', 'status' => 'active'],
            ]),
        ]);

        $client = new AccountsClient($http, 'https://accounts.example', 'service-token');
        $account = $client->resolve(new ExternalIdentity('https://issuer.example', 'subject-1', 'Person'));

        $this->assertSame('01TEST', $account->id);
        $this->assertSame('active', $account->status);

        $http->assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer service-token'));
    }

    public function test_it_requests_an_entitlement_decision(): void
    {
        $http = new Factory;
        $http->fake([
            'https://accounts.example/api/v1/entitlements/decide' => $http->response([
                'allowed' => true,
                'account_id' => '01TEST',
                'product' => 'logres',
                'entitlement' => 'logres.access',
                'evaluated_at' => '2026-08-27T12:00:00Z',
                'grant_id' => '01GRANT',
            ]),
        ]);

        $client = new AccountsClient($http, 'https://accounts.example', 'service-token');
        $decision = $client->entitlement('01TEST', 'logres', 'logres.access');

        $this->assertTrue($decision->allowed);
        $this->assertSame('01GRANT', $decision->grantId);
    }
}
