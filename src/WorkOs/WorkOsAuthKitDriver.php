<?php

namespace Sifrious\AccountsClient\WorkOs;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Sifrious\AccountsClient\Contracts\LoginDriver;
use Sifrious\AccountsClient\Data\VerifiedExternal;
use Sifrious\AccountsClient\Exceptions\LoginVerificationFailed;
use Throwable;

final class WorkOsAuthKitDriver implements LoginDriver
{
    private const TRANSACTION_KEY = 'zahir.workos.transaction';

    /** @var array<string, mixed>|null */
    private ?array $jwks = null;

    /** @param Closure(): int|null $clock */
    public function __construct(
        private readonly Factory $http,
        private readonly WorkOsAuthKitConfig $config,
        private readonly ?Closure $clock = null,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $callbackUrl = $this->exactAllowlistedUrl($request->query('redirect_uri'), $this->config->callbackUrls, 'callback');
        $state = $this->randomToken32();
        $nonce = $this->randomToken32();
        $verifier = $this->randomToken64();

        $request->session()->put(self::TRANSACTION_KEY, [
            'state_hash' => hash('sha256', $state),
            'nonce_hash' => hash('sha256', $nonce),
            'pkce_verifier' => $verifier,
            'callback_url' => $callbackUrl,
            'expires_at' => $this->now() + $this->config->transactionTtlSeconds,
        ]);

        $query = http_build_query([
            'client_id' => $this->config->clientId,
            'redirect_uri' => $callbackUrl,
            'response_type' => 'code',
            'provider' => 'authkit',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return new RedirectResponse($this->config->authorizationEndpoint.'?'.$query);
    }

    public function verifiedExternalFromCallback(Request $request): VerifiedExternal
    {
        $transaction = $request->session()->pull(self::TRANSACTION_KEY);
        if (! is_array($transaction)) {
            throw new LoginVerificationFailed('Login transaction is missing or already consumed.');
        }

        $state = $request->query('state');
        $code = $request->query('code');
        if (! is_string($state) || ! is_string($code) || $code === '') {
            throw new LoginVerificationFailed('Authorization callback is incomplete.');
        }

        $expiresAt = $transaction['expires_at'] ?? null;
        $stateHash = $transaction['state_hash'] ?? null;
        $nonceHash = $transaction['nonce_hash'] ?? null;
        $verifier = $transaction['pkce_verifier'] ?? null;
        $callbackUrl = $transaction['callback_url'] ?? null;
        if (! is_int($expiresAt) || ! is_string($stateHash) || ! is_string($nonceHash)
            || ! is_string($verifier) || ! is_string($callbackUrl)) {
            throw new LoginVerificationFailed('Login transaction is invalid.');
        }

        if ($expiresAt < $this->now()) {
            throw new LoginVerificationFailed('Login transaction has expired.');
        }

        if (! hash_equals($stateHash, hash('sha256', $state))) {
            throw new LoginVerificationFailed('Authorization state is invalid.');
        }

        $this->exactAllowlistedUrl($callbackUrl, $this->config->callbackUrls, 'callback');

        try {
            $response = $this->http->asForm()->post($this->config->tokenEndpoint, [
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $callbackUrl,
                'code_verifier' => $verifier,
            ])->throw()->json();
        } catch (Throwable $exception) {
            throw new LoginVerificationFailed('Authorization code exchange failed.', previous: $exception);
        }

        if (! is_array($response) || ! is_string($response['access_token'] ?? null)) {
            throw new LoginVerificationFailed('Authorization response did not contain a token.');
        }

        $claims = $this->verifiedClaims($response['access_token']);
        $nonce = $claims['nonce'] ?? null;
        if (! is_string($nonce) || ! hash_equals($nonceHash, hash('sha256', $nonce))) {
            throw new LoginVerificationFailed('Authorization nonce is invalid.');
        }

        $subject = $claims['sub'] ?? null;
        if (! is_string($subject) || $subject === '') {
            throw new LoginVerificationFailed('Verified token has no subject.');
        }

        $user = $response['user'] ?? null;
        if (is_array($user) && isset($user['id']) && $user['id'] !== $subject) {
            throw new LoginVerificationFailed('Token and user subjects do not match.');
        }

        if (is_string($claims['sid'] ?? null)) {
            $request->session()->put('zahir.workos.session_id', $claims['sid']);
        }

        $safeClaims = [];
        foreach (['email', 'name'] as $claim) {
            if (is_string($claims[$claim] ?? null)) {
                $safeClaims[$claim] = $claims[$claim];
            }
        }
        if (is_bool($claims['email_verified'] ?? null)) {
            $safeClaims['email_verified'] = $claims['email_verified'];
        }

        $issuer = $claims['iss'];
        $issuedAt = $claims['iat'];
        $authenticatedAt = $claims['auth_time'] ?? $issuedAt;
        if (! is_string($issuer) || ! is_int($issuedAt) || ! is_int($authenticatedAt)) {
            throw new LoginVerificationFailed('Verified token is missing required provenance.');
        }

        return new VerifiedExternal(
            provider: 'workos',
            providerSubject: $subject,
            claims: $safeClaims,
            provenance: [
                'issuer' => $issuer,
                'audience' => $this->config->clientId,
                'asserted_at' => gmdate('Y-m-d\TH:i:s\Z', $issuedAt),
                ...is_string($claims['jti'] ?? null) ? ['assertion_id' => $claims['jti']] : [],
            ],
            authenticatedAt: gmdate('Y-m-d\TH:i:s\Z', $authenticatedAt),
        );
    }

    public function logout(Request $request, string $postLogoutRedirect): RedirectResponse
    {
        $redirect = $this->exactAllowlistedUrl($postLogoutRedirect, $this->config->postLogoutUrls, 'post-logout');
        $sessionId = $request->session()->pull('zahir.workos.session_id');

        if (! is_string($sessionId) || $sessionId === '') {
            return new RedirectResponse($redirect);
        }

        return new RedirectResponse($this->config->logoutEndpoint.'?'.http_build_query([
            'session_id' => $sessionId,
            'return_to' => $redirect,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    /** @return array<string, mixed> */
    private function verifiedClaims(string $token): array
    {
        try {
            JWT::$leeway = $this->config->clockToleranceSeconds;
            JWT::$timestamp = $this->now();
            $decoded = get_object_vars(JWT::decode($token, JWK::parseKeySet($this->jwks())));
        } catch (Throwable $exception) {
            throw new LoginVerificationFailed('Token signature or time claims are invalid.', previous: $exception);
        } finally {
            JWT::$timestamp = null;
            JWT::$leeway = 0;
        }

        if (($decoded['iss'] ?? null) !== $this->config->issuer) {
            throw new LoginVerificationFailed('Token issuer is invalid.');
        }

        $audience = $decoded['aud'] ?? null;
        if ($audience !== $this->config->clientId && (! is_array($audience) || ! in_array($this->config->clientId, $audience, true))) {
            throw new LoginVerificationFailed('Token audience is invalid.');
        }

        if (! is_int($decoded['iat'] ?? null) || $decoded['iat'] > $this->now() + $this->config->clockToleranceSeconds) {
            throw new LoginVerificationFailed('Token issued-at time is invalid.');
        }

        return $this->stringKeyed($decoded);
    }

    /** @return array<string, mixed> */
    private function jwks(): array
    {
        if ($this->jwks !== null) {
            return $this->jwks;
        }

        try {
            $json = $this->http->get($this->config->resolvedJwksEndpoint())->throw()->json();
        } catch (Throwable $exception) {
            throw new LoginVerificationFailed('Unable to load signing keys.', previous: $exception);
        }

        if (! is_array($json) || ! is_array($json['keys'] ?? null)) {
            throw new LoginVerificationFailed('Signing key response is invalid.');
        }

        return $this->jwks = $this->stringKeyed($json);
    }

    /** @param list<string> $allowlist */
    private function exactAllowlistedUrl(mixed $url, array $allowlist, string $kind): string
    {
        if (! is_string($url) || ! in_array($url, $allowlist, true)) {
            throw new LoginVerificationFailed("The {$kind} URL is not allowlisted.");
        }

        return $url;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    private function randomToken32(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    private function randomToken64(): string
    {
        return $this->base64Url(random_bytes(64));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @param array<mixed> $values
     *  @return array<string, mixed>
     */
    private function stringKeyed(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
