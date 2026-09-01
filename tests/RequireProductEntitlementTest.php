<?php

namespace Sifrious\AccountsClient\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use PHPUnit\Framework\TestCase;
use Sifrious\AccountsClient\AccountsClient;
use Sifrious\AccountsClient\Contracts\AccountProjection;
use Sifrious\AccountsClient\Exceptions\ProductAccessDenied;
use Sifrious\AccountsClient\Laravel\RequireProductEntitlement;
use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;

final class RequireProductEntitlementTest extends TestCase
{
    public function test_a_current_allowed_decision_lets_the_request_through(): void
    {
        $response = $this->handle($this->zahir($this->decision(allowed: true)));

        $this->assertSame('reached', $response->getContent());
    }

    public function test_a_revoked_grant_fails_closed_on_the_very_next_request(): void
    {
        $this->assertDenied(
            $this->zahir($this->decision(allowed: false, grantId: null)),
            AuthenticationOutcome::UnauthorizedProduct,
        );
    }

    public function test_a_suspended_account_fails_closed(): void
    {
        $this->assertDenied(
            $this->zahir($this->decision(allowed: false, accountStatus: 'suspended')),
            AuthenticationOutcome::Suspended,
        );
    }

    /**
     * A stale decision is refused rather than trusted. Without this, a cached or
     * replayed answer would keep an account alive indefinitely after its grant
     * was pulled — which would make the whole re-check ceremonial.
     */
    public function test_a_stale_decision_is_refused_rather_than_trusted(): void
    {
        $this->assertDenied(
            $this->zahir($this->decision(allowed: true, evaluatedAt: gmdate('Y-m-d\TH:i:s\Z', time() - 3600))),
            AuthenticationOutcome::ZahirUnavailable,
        );
    }

    public function test_a_future_dated_decision_is_refused(): void
    {
        $this->assertDenied(
            $this->zahir($this->decision(allowed: true, evaluatedAt: gmdate('Y-m-d\TH:i:s\Z', time() + 3600))),
            AuthenticationOutcome::ZahirUnavailable,
        );
    }

    public function test_an_outage_denies_with_a_dependency_failure_not_a_refusal(): void
    {
        $http = new Factory;
        $http->fake(['https://accounts.example/*' => $http->response([], 500)]);

        $denial = $this->assertDenied($http, AuthenticationOutcome::ZahirUnavailable);

        $this->assertTrue($denial->isDependencyFailure());
        $this->assertSame(503, $denial->render($this->jsonRequest())?->getStatusCode());
    }

    public function test_a_decision_naming_another_product_is_refused(): void
    {
        $this->assertDenied(
            $this->zahir($this->decision(allowed: true, product: 'logres')),
            AuthenticationOutcome::UnauthorizedProduct,
        );
    }

    public function test_a_request_without_a_projected_account_never_reaches_zahir(): void
    {
        $http = new Factory;
        $http->fake();

        $middleware = new RequireProductEntitlement(
            new AccountsClient($http, 'https://accounts.example', 'service-token'),
            'burdgen',
            'access',
            30,
        );

        try {
            $middleware->handle(Request::create('/app'), fn (): Response => new Response('reached'));
            $this->fail('An unprojected request must be refused.');
        } catch (ProductAccessDenied $denied) {
            $this->assertSame(AuthenticationOutcome::UnauthorizedProduct, $denied->outcome);
        }

        $http->assertNothingSent();
    }

    public function test_a_refusal_renders_403_and_names_the_stable_outcome(): void
    {
        $denial = new ProductAccessDenied(AuthenticationOutcome::UnauthorizedProduct);
        $rendered = $denial->render($this->jsonRequest());

        $this->assertNotNull($rendered);
        $this->assertSame(403, $rendered->getStatusCode());
        $this->assertStringContainsString('unauthorized_product', (string) $rendered->getContent());
    }

    /**
     * A browser request gets nothing from the package, so the application's own
     * handler can render its words instead of losing to a bare 403.
     */
    public function test_a_browser_request_is_left_to_the_application(): void
    {
        $denial = new ProductAccessDenied(AuthenticationOutcome::Suspended);

        $this->assertNull($denial->render(Request::create('/app')));
    }

    private function jsonRequest(): Request
    {
        $request = Request::create('/app');
        $request->headers->set('Accept', 'application/json');

        return $request;
    }

    private function assertDenied(Factory $http, AuthenticationOutcome $expected): ProductAccessDenied
    {
        try {
            $this->handle($http);
        } catch (ProductAccessDenied $denied) {
            $this->assertSame($expected, $denied->outcome);

            return $denied;
        }

        $this->fail("Expected a {$expected->value} refusal.");
    }

    private function handle(Factory $http): HttpResponse
    {
        $middleware = new RequireProductEntitlement(
            new AccountsClient($http, 'https://accounts.example', 'service-token'),
            'burdgen',
            'access',
            30,
        );

        $request = Request::create('/app');
        $request->setUserResolver(fn (): AccountProjection => new class implements AccountProjection
        {
            public function zahirAccountId(): string
            {
                return 'acc_01test';
            }
        });

        return $middleware->handle($request, fn (): Response => new Response('reached'));
    }

    /** @param array<string, mixed> $decision */
    private function zahir(array $decision): Factory
    {
        $http = new Factory;
        $http->fake([
            'https://accounts.example/api/v1/entitlements/decide' => $http->response($decision),
        ]);

        return $http;
    }

    /** @return array<string, mixed> */
    private function decision(
        bool $allowed,
        string $product = 'burdgen',
        string $accountStatus = 'active',
        ?string $grantId = 'grant_1',
        ?string $evaluatedAt = null,
    ): array {
        return [
            'allowed' => $allowed,
            'account_id' => 'acc_01test',
            'account_status' => $accountStatus,
            'product' => $product,
            'entitlement' => 'access',
            'evaluated_at' => $evaluatedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
            'grant_id' => $grantId,
        ];
    }
}
