<?php

namespace Sifrious\AccountsClient\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Sifrious\AccountsClient\AccountsClient;
use Sifrious\AccountsClient\Contracts\LoginDriver;
use Sifrious\AccountsClient\LoginManager;
use Sifrious\AccountsClient\ProductAuthenticator;
use Sifrious\AccountsClient\WorkOs\WorkOsAuthKitConfig;
use Sifrious\AccountsClient\WorkOs\WorkOsAuthKitDriver;

/**
 * Auto-discovered wiring, so adopting the seam is configuration rather than a
 * container-binding exercise repeated in every product.
 *
 * Everything resolves lazily and validates its configuration on first use. A
 * product with an incomplete `.env` fails loudly the first time someone tries
 * to sign in, rather than quietly constructing a client pointed at nothing.
 */
final class AccountsClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/zahir.php', 'zahir');

        $this->app->singleton(AccountsClient::class, fn (): AccountsClient => new AccountsClient(
            $this->app->make(Factory::class),
            $this->requiredString('zahir.base_url'),
            $this->requiredString('zahir.service_token'),
        ));

        $this->app->singleton(LoginDriver::class, fn (): LoginDriver => new WorkOsAuthKitDriver(
            $this->app->make(Factory::class),
            new WorkOsAuthKitConfig(
                clientId: $this->requiredString('zahir.workos.client_id'),
                clientSecret: $this->requiredString('zahir.workos.client_secret'),
                issuer: $this->requiredString('zahir.workos.issuer'),
                callbackUrls: $this->requiredUrlList('zahir.workos.callback_urls'),
                postLogoutUrls: $this->requiredUrlList('zahir.workos.post_logout_urls'),
            ),
        ));

        $this->app->singleton(LoginManager::class, fn (): LoginManager => new LoginManager(
            $this->app->make(LoginDriver::class),
            $this->app->make(AccountsClient::class),
        ));

        $this->app->singleton(ProductAuthenticator::class, fn (): ProductAuthenticator => new ProductAuthenticator(
            $this->app->make(LoginDriver::class),
            $this->app->make(AccountsClient::class),
            $this->requiredString('zahir.product'),
            $this->requiredString('zahir.access_entitlement'),
        ));

        $this->app->singleton(RequireProductEntitlement::class, fn (): RequireProductEntitlement => new RequireProductEntitlement(
            $this->app->make(AccountsClient::class),
            $this->requiredString('zahir.product'),
            $this->requiredString('zahir.access_entitlement'),
            $this->decisionMaxAgeSeconds(),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__.'/../../config/zahir.php' => $this->app->configPath('zahir.php')],
                'zahir-config',
            );
        }
    }

    private function config(): Repository
    {
        return $this->app->make(Repository::class);
    }

    private function requiredString(string $key): string
    {
        $value = $this->config()->get($key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Zahir configuration [{$key}] is missing.");
        }

        return $value;
    }

    /** @return list<string> */
    private function requiredUrlList(string $key): array
    {
        $value = $this->config()->get($key);

        if (! is_array($value) || $value === []) {
            throw new RuntimeException("Zahir configuration [{$key}] must list at least one exact URL.");
        }

        $urls = [];
        foreach ($value as $url) {
            if (! is_string($url) || $url === '') {
                throw new RuntimeException("Zahir configuration [{$key}] must contain only non-empty URLs.");
            }
            $urls[] = $url;
        }

        return $urls;
    }

    private function decisionMaxAgeSeconds(): int
    {
        $value = $this->config()->get('zahir.entitlement_decision_max_age_seconds');

        if (! is_int($value) || $value <= 0) {
            throw new RuntimeException('Zahir configuration [zahir.entitlement_decision_max_age_seconds] must be a positive integer.');
        }

        return $value;
    }
}
