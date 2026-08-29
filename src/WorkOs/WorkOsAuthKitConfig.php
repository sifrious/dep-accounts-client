<?php

namespace Sifrious\AccountsClient\WorkOs;

use InvalidArgumentException;

final readonly class WorkOsAuthKitConfig
{
    /**
     * @param list<string> $callbackUrls
     * @param list<string> $postLogoutUrls
     */
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $issuer,
        public array $callbackUrls,
        public array $postLogoutUrls,
        public string $authorizationEndpoint = 'https://api.workos.com/user_management/authorize',
        public string $tokenEndpoint = 'https://api.workos.com/user_management/authenticate',
        public string $jwksEndpoint = '',
        public string $logoutEndpoint = 'https://api.workos.com/user_management/sessions/logout',
        public int $transactionTtlSeconds = 600,
        public int $clockToleranceSeconds = 60,
    ) {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new InvalidArgumentException('WorkOS client credentials are required.');
        }

        if ($this->callbackUrls === [] || $this->postLogoutUrls === []) {
            throw new InvalidArgumentException('Callback and post-logout allowlists are required.');
        }

        foreach ([...$this->callbackUrls, ...$this->postLogoutUrls] as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException("Invalid allowlisted URL [{$url}].");
            }
        }
    }

    public function resolvedJwksEndpoint(): string
    {
        return $this->jwksEndpoint !== ''
            ? $this->jwksEndpoint
            : 'https://api.workos.com/sso/jwks/'.rawurlencode($this->clientId);
    }
}
