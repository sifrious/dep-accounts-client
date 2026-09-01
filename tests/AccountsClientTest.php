<?php

namespace Sifrious\AccountsClient\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use Sifrious\AccountsClient\AccountsClient;
use Sifrious\AccountsClient\Data\VerifiedExternal;
use Sifrious\AccountsClient\Exceptions\ZahirRejected;
use Sifrious\AccountsClient\Outcome\IdentityUnlinkOutcome;
use Sifrious\AccountsClient\Exceptions\ZahirUnavailable;

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
                'entitlement' => 'access',
                'evaluated_at' => '2026-08-27T12:00:00Z',
                'grant_id' => '01GRANT',
            ]),
        ]);

        $client = new AccountsClient($http, 'https://accounts.example', 'service-token');
        $decision = $client->entitlement('acc_01test', 'logres', 'access');

        $this->assertTrue($decision->allowed);
        $this->assertSame('active', $decision->accountStatus);
        $this->assertSame('01GRANT', $decision->grantId);
    }

    public function test_it_links_and_unlinks_identity_through_opaque_contracts(): void
    {
        $http = new Factory;
        $http->fake([
            'https://accounts.example/api/v1/accounts/acc_01test/identities/link' => $http->response([
                'account' => ['id' => 'acc_01test', 'status' => 'active'],
                'outcome' => 'linked',
            ]),
            'https://accounts.example/api/v1/accounts/acc_01test/identities' => $http->response([
                'account_id' => 'acc_01test', 'outcome' => 'unlinked',
            ]),
        ]);
        $client = new AccountsClient($http, 'https://accounts.example', 'service-token');
        $identity = new VerifiedExternal(
            provider: 'future-idp',
            providerSubject: 'future-subject',
            claims: [],
            provenance: ['issuer' => 'https://issuer.example/', 'audience' => 'client', 'asserted_at' => '2026-08-29T12:00:00Z'],
            authenticatedAt: '2026-08-29T12:00:00Z',
        );

        $linked = $client->linkIdentity('acc_01test', $identity);
        $unlinked = $client->unlinkIdentity('acc_01test', 'future-idp', 'future-subject');

        self::assertSame('acc_01test', $linked->id);
        self::assertSame(IdentityUnlinkOutcome::Unlinked, $unlinked->outcome);
        $http->assertSent(fn (Request $request): bool => $request->hasHeader('X-Zahir-Current-Account', 'acc_01test'));
    }

    public function test_it_requests_lifecycle_changes_without_exposing_storage(): void
    {
        $http = new Factory;
        $http->fake([
            'https://accounts.example/api/v1/accounts/acc_01test/suspension' => $http->sequence()
                ->push(['account' => ['id' => 'acc_01test', 'status' => 'suspended']])
                ->push(['account' => ['id' => 'acc_01test', 'status' => 'active']]),
        ]);
        $client = new AccountsClient($http, 'https://accounts.example', 'service-token');

        $suspended = $client->suspend('acc_01test', 'risk review');
        $active = $client->reactivate('acc_01test', 'review complete');

        self::assertSame('suspended', $suspended->status);
        self::assertSame('active', $active->status);
        $http->assertSentCount(2);
    }

    /**
     * @return list<array{int}>
     */
    public static function retryableStatuses(): array
    {
        return [[500], [502], [503], [504], [408], [429]];
    }

    /**
     * A product must be able to tell "ask again later" from "no". Every status
     * here means the former, and none of them may reach a consumer as a denial.
     *
     * @param int $status
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('retryableStatuses')]
    public function test_transient_failures_surface_as_unavailability(int $status): void
    {
        $http = new Factory;
        $http->fake(['https://accounts.example/*' => $http->response(['message' => 'nope'], $status)]);

        $client = new AccountsClient($http, 'https://accounts.example', 'service-token');

        $this->expectException(ZahirUnavailable::class);
        $client->entitlement('acc_01test', 'burdgen', 'access');
    }

    public function test_a_deliberate_refusal_surfaces_separately_and_carries_its_status(): void
    {
        $http = new Factory;
        $http->fake(['https://accounts.example/*' => $http->response(['message' => 'Not Found'], 404)]);

        $client = new AccountsClient($http, 'https://accounts.example', 'service-token');

        try {
            $client->entitlement('acc_01missing', 'burdgen', 'access');
            $this->fail('A 404 must be reported as a refusal.');
        } catch (ZahirRejected $rejected) {
            $this->assertSame(404, $rejected->status);
        }
    }

    /**
     * The message must stay free of the credential that produced it; a client
     * exception is routinely logged verbatim.
     */
    public function test_a_transport_failure_never_echoes_the_service_token(): void
    {
        $http = new Factory;
        $http->fake(['https://accounts.example/*' => $http->response([], 503)]);

        $client = new AccountsClient($http, 'https://accounts.example', 'super-secret-token');

        try {
            $client->entitlement('acc_01test', 'burdgen', 'access');
            $this->fail('Expected unavailability.');
        } catch (ZahirUnavailable $unavailable) {
            $this->assertStringNotContainsString('super-secret-token', $unavailable->getMessage());
        }
    }

    /**
     * Refusing to strand an account without a way back in is a recoverable
     * state, so it arrives as an outcome a product can offer a path out of
     * rather than an exception it has to guess the meaning of.
     */
    public function test_a_last_identity_refusal_arrives_as_a_recovery_outcome(): void
    {
        $http = new Factory;
        $http->fake(['https://accounts.example/*' => $http->response(
            ['message' => 'Identity unlinking failed.', 'reason' => 'recovery_required'],
            409,
        )]);

        $client = new AccountsClient($http, 'https://accounts.example', 'service-token');
        $result = $client->unlinkIdentity('acc_01test', 'workos', 'user_only');

        $this->assertSame(IdentityUnlinkOutcome::RecoveryRequired, $result->outcome);
        $this->assertFalse($result->outcome->removedAnIdentity());
        $this->assertSame('acc_01test', $result->accountId);
    }

    public function test_any_other_refusal_still_raises(): void
    {
        $http = new Factory;
        $http->fake(['https://accounts.example/*' => $http->response(['message' => 'Nope.'], 403)]);

        $client = new AccountsClient($http, 'https://accounts.example', 'service-token');

        $this->expectException(ZahirRejected::class);
        $client->unlinkIdentity('acc_01test', 'workos', 'user_only');
    }
}
