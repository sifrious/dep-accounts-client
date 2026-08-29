<?php

namespace Sifrious\AccountsClient\Tests;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;
use Sifrious\AccountsClient\Exceptions\LoginVerificationFailed;
use Sifrious\AccountsClient\WorkOs\WorkOsAuthKitConfig;
use Sifrious\AccountsClient\WorkOs\WorkOsAuthKitDriver;

final class WorkOsAuthKitDriverTest extends TestCase
{
    private const NOW = 1_788_000_000;
    private const CALLBACK = 'https://product.example/auth/callback';
    private const LOGOUT = 'https://product.example/signed-out';

    public function test_it_completes_a_signed_provider_neutral_login_and_logs_out(): void
    {
        [$privateKey, $jwk] = $this->keyPair();
        $http = new Factory;
        $driver = $this->driver($http);
        $session = $this->session();

        $start = $this->request(['redirect_uri' => self::CALLBACK], $session);
        $authorization = $driver->redirect($start);
        parse_str((string) parse_url($authorization->getTargetUrl(), PHP_URL_QUERY), $parameters);

        $token = JWT::encode([
            'iss' => 'https://api.workos.test/',
            'aud' => 'client_test',
            'sub' => 'user_123',
            'sid' => 'session_123',
            'jti' => 'assertion_123',
            'nonce' => $this->parameter($parameters, 'nonce'),
            'iat' => self::NOW - 5,
            'nbf' => self::NOW - 5,
            'exp' => self::NOW + 300,
            'auth_time' => self::NOW - 10,
            'email' => 'person@example.test',
            'email_verified' => true,
            'name' => 'Person',
            'unsafe_role' => 'admin',
        ], $privateKey, 'RS256', 'test-key');

        $http->fake([
            'https://api.workos.test/authenticate' => $http->response([
                'access_token' => $token,
                'user' => ['id' => 'user_123'],
            ]),
            'https://api.workos.test/jwks' => $http->response(['keys' => [$jwk]]),
        ]);

        $verified = $driver->verifiedExternalFromCallback($this->request([
            'state' => $this->parameter($parameters, 'state'),
            'code' => 'authorization_code',
        ], $session));

        self::assertSame('workos', $verified->provider);
        self::assertSame('user_123', $verified->providerSubject);
        self::assertSame(['email' => 'person@example.test', 'name' => 'Person', 'email_verified' => true], $verified->claims);
        $assertionId = $verified->provenance['assertion_id'] ?? null;
        self::assertSame('assertion_123', $assertionId);
        self::assertStringContainsString('code_challenge_method=S256', $authorization->getTargetUrl());
        self::assertStringContainsString('session_id=session_123', $driver->logout($this->request([], $session), self::LOGOUT)->getTargetUrl());
    }

    public function test_callback_state_is_single_use_even_when_invalid(): void
    {
        $http = new Factory;
        $driver = $this->driver($http);
        $session = $this->session();
        $driver->redirect($this->request(['redirect_uri' => self::CALLBACK], $session));

        foreach (['wrong', 'wrong-again'] as $state) {
            try {
                $driver->verifiedExternalFromCallback($this->request(['state' => $state, 'code' => 'code'], $session));
                self::fail('Invalid or replayed state was accepted.');
            } catch (LoginVerificationFailed $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }

        $http->assertNothingSent();
    }

    public function test_expired_transaction_fails_before_token_exchange(): void
    {
        $now = self::NOW;
        $http = new Factory;
        $driver = $this->driver($http, static function () use (&$now): int { return $now; });
        $session = $this->session();
        $authorization = $driver->redirect($this->request(['redirect_uri' => self::CALLBACK], $session));
        parse_str((string) parse_url($authorization->getTargetUrl(), PHP_URL_QUERY), $parameters);
        $now += 601;

        $this->expectException(LoginVerificationFailed::class);
        $this->expectExceptionMessage('expired');
        $driver->verifiedExternalFromCallback($this->request(['state' => $this->parameter($parameters, 'state'), 'code' => 'code'], $session));
    }

    public function test_nonce_issuer_audience_signature_and_time_fail_closed(): void
    {
        foreach (['nonce', 'issuer', 'audience', 'signature', 'expired'] as $failure) {
            $this->assertTokenFailure($failure);
        }
    }

    public function test_callback_and_logout_redirects_require_exact_allowlist_matches(): void
    {
        $driver = $this->driver(new Factory);
        $session = $this->session();

        $rejected = 0;
        foreach (['https://evil.example', self::CALLBACK.'?next=evil'] as $url) {
            try {
                $driver->redirect($this->request(['redirect_uri' => $url], $session));
                self::fail('Non-allowlisted callback was accepted.');
            } catch (LoginVerificationFailed) {
                $rejected++;
            }
        }
        self::assertSame(2, $rejected);

        $this->expectException(LoginVerificationFailed::class);
        $driver->logout($this->request([], $session), self::LOGOUT.'/extra');
    }

    private function assertTokenFailure(string $failure): void
    {
        [$privateKey, $jwk] = $this->keyPair();
        [$otherPrivateKey] = $this->keyPair();
        $http = new Factory;
        $driver = $this->driver($http);
        $session = $this->session();
        $authorization = $driver->redirect($this->request(['redirect_uri' => self::CALLBACK], $session));
        parse_str((string) parse_url($authorization->getTargetUrl(), PHP_URL_QUERY), $parameters);

        $claims = [
            'iss' => $failure === 'issuer' ? 'https://attacker.example/' : 'https://api.workos.test/',
            'aud' => $failure === 'audience' ? 'other-client' : 'client_test',
            'sub' => 'user_123',
            'nonce' => $failure === 'nonce' ? 'wrong' : $this->parameter($parameters, 'nonce'),
            'iat' => self::NOW - 5,
            'nbf' => self::NOW - 5,
            'exp' => $failure === 'expired' ? self::NOW - 120 : self::NOW + 300,
        ];
        $token = JWT::encode($claims, $failure === 'signature' ? $otherPrivateKey : $privateKey, 'RS256', 'test-key');

        $http->fake([
            'https://api.workos.test/authenticate' => $http->response(['access_token' => $token]),
            'https://api.workos.test/jwks' => $http->response(['keys' => [$jwk]]),
        ]);

        try {
            $driver->verifiedExternalFromCallback($this->request(['state' => $this->parameter($parameters, 'state'), 'code' => 'code'], $session));
            self::fail("{$failure} token was accepted.");
        } catch (LoginVerificationFailed $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    private function driver(Factory $http, ?\Closure $clock = null): WorkOsAuthKitDriver
    {
        return new WorkOsAuthKitDriver($http, new WorkOsAuthKitConfig(
            clientId: 'client_test',
            clientSecret: 'secret_test',
            issuer: 'https://api.workos.test/',
            callbackUrls: [self::CALLBACK],
            postLogoutUrls: [self::LOGOUT],
            authorizationEndpoint: 'https://api.workos.test/authorize',
            tokenEndpoint: 'https://api.workos.test/authenticate',
            jwksEndpoint: 'https://api.workos.test/jwks',
            logoutEndpoint: 'https://api.workos.test/logout',
        ), $clock ?? static fn (): int => self::NOW);
    }

    /** @param array<string, string> $query */
    private function request(array $query, Store $session): Request
    {
        $request = Request::create('https://product.example/auth', 'GET', $query);
        $request->setLaravelSession($session);

        return $request;
    }

    private function session(): Store
    {
        return new Store('test', new ArraySessionHandler(10));
    }

    /** @return array{string, array<string, string>} */
    private function keyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);
        $privateKey = '';
        self::assertTrue(openssl_pkey_export($resource, $privateKey));
        self::assertIsString($privateKey);
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        self::assertIsArray($details['rsa']);
        self::assertIsString($details['rsa']['n']);
        self::assertIsString($details['rsa']['e']);

        return [$privateKey, [
            'kty' => 'RSA',
            'kid' => 'test-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $this->base64Url($details['rsa']['n']),
            'e' => $this->base64Url($details['rsa']['e']),
        ]];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @param array<mixed> $parameters */
    private function parameter(array $parameters, string $key): string
    {
        $value = $parameters[$key] ?? null;
        self::assertIsString($value);

        return $value;
    }
}
