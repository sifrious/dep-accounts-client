<?php

namespace Sifrious\AccountsClient\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Sifrious\AccountsClient\AccountsClient;
use Sifrious\AccountsClient\Exceptions\LoginVerificationFailed;
use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;
use Sifrious\AccountsClient\ProductAuthenticator;
use Sifrious\AccountsClient\Tests\Doubles\StubLoginDriver;

final class ProductAuthenticatorTest extends TestCase
{
    public function test_an_entitled_active_account_is_authenticated(): void
    {
        $http = $this->zahir(
            account: ['id' => 'acc_01test', 'status' => 'active', 'created' => true],
            decision: $this->decision(allowed: true),
        );

        $result = $this->authenticator($http)->complete(Request::create('/auth/callback'));

        $this->assertSame(AuthenticationOutcome::Authenticated, $result->outcome);
        $this->assertTrue($result->grantsAccess());
        $this->assertSame('acc_01test', $result->accountId());
        $this->assertNotNull($result->access);
        $this->assertSame('grant_1', $result->access->grantId);
    }

    public function test_a_denied_entitlement_never_grants_access(): void
    {
        $http = $this->zahir(
            account: ['id' => 'acc_01test', 'status' => 'active', 'created' => false],
            decision: $this->decision(allowed: false, grantId: null),
        );

        $result = $this->authenticator($http)->complete(Request::create('/auth/callback'));

        $this->assertSame(AuthenticationOutcome::UnauthorizedProduct, $result->outcome);
        $this->assertFalse($result->grantsAccess());
        // The account still resolved; the product may name the person it refused.
        $this->assertSame('acc_01test', $result->accountId());
    }

    public function test_a_decision_for_another_product_is_not_a_grant(): void
    {
        $http = $this->zahir(
            account: ['id' => 'acc_01test', 'status' => 'active', 'created' => false],
            decision: $this->decision(allowed: true, product: 'logres'),
        );

        $result = $this->authenticator($http)->complete(Request::create('/auth/callback'));

        $this->assertSame(AuthenticationOutcome::UnauthorizedProduct, $result->outcome);
        $this->assertFalse($result->grantsAccess());
    }

    public function test_a_decision_for_another_account_is_not_a_grant(): void
    {
        $http = $this->zahir(
            account: ['id' => 'acc_01test', 'status' => 'active', 'created' => false],
            decision: $this->decision(allowed: true, accountId: 'acc_01someone_else'),
        );

        $result = $this->authenticator($http)->complete(Request::create('/auth/callback'));

        $this->assertSame(AuthenticationOutcome::UnauthorizedProduct, $result->outcome);
    }

    public function test_a_suspended_account_is_distinct_from_an_unentitled_one(): void
    {
        $http = new Factory;
        $http->fake([
            'https://accounts.example/api/v1/accounts/resolve' => $http->response([
                'account' => ['id' => 'acc_01test', 'status' => 'suspended', 'created' => false],
            ]),
        ]);

        $result = $this->authenticator($http)->complete(Request::create('/auth/callback'));

        $this->assertSame(AuthenticationOutcome::Suspended, $result->outcome);
        $this->assertFalse($result->grantsAccess());
        $this->assertTrue($result->outcome->endsSession());
        // Suspension is decided before the entitlement is even asked for.
        $http->assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'entitlements/decide'));
    }

    public function test_a_zahir_outage_is_never_reported_as_a_denial(): void
    {
        $http = new Factory;
        $http->fake([
            'https://accounts.example/api/v1/accounts/resolve' => $http->response(['message' => 'oops'], 503),
        ]);

        $result = $this->authenticator($http)->complete(Request::create('/auth/callback'));

        $this->assertSame(AuthenticationOutcome::ZahirUnavailable, $result->outcome);
        $this->assertTrue($result->outcome->isRetryable());
        $this->assertFalse($result->outcome->endsSession());
    }

    public function test_our_own_expired_service_credential_is_not_the_persons_fault(): void
    {
        $http = new Factory;
        $http->fake([
            'https://accounts.example/api/v1/accounts/resolve' => $http->response(['message' => 'Unauthenticated.'], 401),
        ]);

        $result = $this->authenticator($http)->complete(Request::create('/auth/callback'));

        $this->assertSame(AuthenticationOutcome::ZahirUnavailable, $result->outcome);
    }

    /**
     * Every protocol failure the driver can raise must arrive as its own
     * outcome. Collapsing them would leave a product unable to tell a person
     * who cancelled from a person under attack.
     */
    public function test_driver_failures_keep_their_distinct_outcomes(): void
    {
        foreach (AuthenticationOutcome::cases() as $outcome) {
            if ($outcome->grantsAccess()) {
                continue;
            }

            $driver = StubLoginDriver::failingWith(
                LoginVerificationFailed::withOutcome($outcome, 'verification failed'),
            );
            $authenticator = new ProductAuthenticator(
                $driver,
                new AccountsClient(new Factory, 'https://accounts.example', 'service-token'),
                'burdgen',
                'access',
            );

            $result = $authenticator->complete(Request::create('/auth/callback'));

            $this->assertSame($outcome, $result->outcome);
            $this->assertFalse($result->grantsAccess());
            $this->assertNull($result->accountId());
        }
    }

    public function test_completing_never_throws_and_never_reaches_zahir_on_a_failed_callback(): void
    {
        $http = new Factory;
        $http->fake();

        $authenticator = new ProductAuthenticator(
            StubLoginDriver::failingWith(LoginVerificationFailed::withOutcome(
                AuthenticationOutcome::ReplayRejected,
                'Login transaction is missing or already consumed.',
            )),
            new AccountsClient($http, 'https://accounts.example', 'service-token'),
            'burdgen',
            'access',
        );

        $result = $authenticator->complete(Request::create('/auth/callback'));

        $this->assertSame(AuthenticationOutcome::ReplayRejected, $result->outcome);
        $http->assertNothingSent();
    }

    public function test_logout_reports_a_logged_out_outcome(): void
    {
        $result = $this->authenticator(new Factory)
            ->logout(Request::create('/auth/logout'), 'https://product.example/goodbye');

        $this->assertSame(AuthenticationOutcome::LoggedOut, $result->outcome);
        $this->assertTrue($result->outcome->endsSession());
        $this->assertSame('https://product.example/goodbye', $result->response->getTargetUrl());
    }

    public function test_the_seam_reports_the_product_it_admits_to(): void
    {
        $authenticator = $this->authenticator(new Factory);

        $this->assertSame('burdgen', $authenticator->product());
        $this->assertSame('access', $authenticator->entitlement());
    }

    private function authenticator(Factory $http): ProductAuthenticator
    {
        return new ProductAuthenticator(
            StubLoginDriver::verifying(),
            new AccountsClient($http, 'https://accounts.example', 'service-token'),
            'burdgen',
            'access',
        );
    }

    /**
     * @param  array<string, mixed>  $account
     * @param  array<string, mixed>  $decision
     */
    private function zahir(array $account, array $decision): Factory
    {
        $http = new Factory;
        $http->fake([
            'https://accounts.example/api/v1/accounts/resolve' => $http->response(['account' => $account]),
            'https://accounts.example/api/v1/entitlements/decide' => $http->response($decision),
        ]);

        return $http;
    }

    /** @return array<string, mixed> */
    private function decision(
        bool $allowed,
        string $accountId = 'acc_01test',
        string $product = 'burdgen',
        ?string $grantId = 'grant_1',
    ): array {
        return [
            'allowed' => $allowed,
            'account_id' => $accountId,
            'account_status' => 'active',
            'product' => $product,
            'entitlement' => 'access',
            'evaluated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'grant_id' => $grantId,
        ];
    }
}
