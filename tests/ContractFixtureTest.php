<?php

namespace Sifrious\AccountsClient\Tests;

use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sifrious\AccountsClient\AccountsClient;
use Sifrious\AccountsClient\Data\VerifiedExternal;

class ContractFixtureTest extends TestCase
{
    public function test_client_consumes_v1_resolution_and_entitlement_fixtures(): void
    {
        $resolve = $this->case('account.resolve.success');
        $allow = $this->case('entitlement.allow');
        $resolveBody = $this->at($resolve, 'response.body');
        $resolveStatus = $this->at($resolve, 'response.status');
        $allowBody = $this->at($allow, 'response.body');
        $allowStatus = $this->at($allow, 'response.status');
        self::assertIsArray($resolveBody);
        self::assertIsInt($resolveStatus);
        self::assertIsArray($allowBody);
        self::assertIsInt($allowStatus);

        $http = new Factory;
        $http->fake([
            'https://zahir.example/api/v1/accounts/resolve' => $http->response($resolveBody, $resolveStatus),
            'https://zahir.example/api/v1/entitlements/decide' => $http->response($allowBody, $allowStatus),
        ]);
        $client = new AccountsClient($http, 'https://zahir.example', 'fixture-token');
        $account = $client->resolve(new VerifiedExternal(
            provider: 'workos',
            providerSubject: 'user_fixture_123',
            claims: ['email' => 'fixture@example.test', 'email_verified' => true, 'name' => 'Fixture Person'],
            provenance: [
                'issuer' => 'https://api.workos.com/', 'audience' => 'client_fixture',
                'asserted_at' => '2026-08-29T12:00:00Z', 'assertion_id' => 'assertion_fixture',
            ],
            authenticatedAt: '2026-08-29T12:00:00Z',
        ));
        $decision = $client->entitlement('acc_fixture', 'logres', 'access');

        $this->assertSame('active', $account->status);
        $this->assertTrue($account->created);
        $this->assertTrue($decision->allowed);
        $this->assertSame('active', $decision->accountStatus);
    }

    /** @return array<string, mixed> */
    private function case(string $name): array
    {
        $contents = file_get_contents(dirname(__DIR__).'/contracts/v1/fixtures.json');
        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read Zahir contract fixtures.');
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $case = is_array($decoded) && isset($decoded['cases']) && is_array($decoded['cases'])
            ? ($decoded['cases'][$name] ?? null)
            : null;

        if (! is_array($case)) {
            throw new RuntimeException("Missing Zahir fixture case [{$name}].");
        }

        $normalized = [];
        foreach ($case as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException("Fixture case [{$name}] has a non-string key.");
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $data */
    private function at(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                throw new RuntimeException("Missing fixture path [{$path}].");
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
