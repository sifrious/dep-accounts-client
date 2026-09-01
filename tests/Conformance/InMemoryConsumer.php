<?php

namespace Sifrious\AccountsClient\Tests\Conformance;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Sifrious\AccountsClient\AccountsClient;
use Sifrious\AccountsClient\Contracts\AccountProjection;
use Sifrious\AccountsClient\Exceptions\ProductAccessDenied;
use Sifrious\AccountsClient\Laravel\RequireProductEntitlement;
use Sifrious\AccountsClient\Outcome\AuthenticationOutcome;
use Sifrious\AccountsClient\ProductAuthenticator;
use Sifrious\AccountsClient\Testing\ConsumerUnderTest;
use Sifrious\AccountsClient\Testing\FakeIdentityProvider;
use Sifrious\AccountsClient\Testing\FakeZahir;

/**
 * The reference consumer: the smallest thing that can pass the conformance
 * suite, standing in for a real application's routes, session, and user table.
 *
 * It exists so the kit is proven against something before any product adopts
 * it — a conformance suite nobody has run is just a wish list. It is also the
 * worked example an adopter reads.
 */
final class InMemoryConsumer implements ConsumerUnderTest
{
    private const PRODUCT = 'reference-product';

    private FakeIdentityProvider $provider;

    private FakeZahir $zahir;

    private ProductAuthenticator $authenticator;

    private RequireProductEntitlement $entitlement;

    /** @var array<string, LocalUser> the product's "users" table, keyed by account ID */
    private array $projections = [];

    /** @var array<string, string> the product's "session" */
    private array $session = [];

    /** @var array<string, string> durable product state that must survive everything */
    private array $durable = [];

    public function __construct()
    {
        $this->provider = new FakeIdentityProvider;
        $this->zahir = new FakeZahir;

        $client = new AccountsClient($this->zahir->httpFactory(), FakeZahir::BASE_URL, 'zhr.reference.token');
        $this->authenticator = new ProductAuthenticator($this->provider, $client, self::PRODUCT, 'access');
        $this->entitlement = new RequireProductEntitlement($client, self::PRODUCT, 'access', 30);
    }

    public function provider(): FakeIdentityProvider
    {
        return $this->provider;
    }

    public function zahir(): FakeZahir
    {
        return $this->zahir;
    }

    public function productKey(): string
    {
        return self::PRODUCT;
    }

    public function beginLogin(): void
    {
        $this->authenticator->begin(Request::create('/auth/login'));
    }

    public function completeLogin(): AuthenticationOutcome
    {
        $result = $this->authenticator->complete(Request::create('/auth/callback'));

        if (! $result->grantsAccess()) {
            // A refused login leaves no session behind. Everything already
            // stored stays put; refusal is not deletion.
            $this->session = [];

            return $result->outcome;
        }

        $accountId = (string) $result->accountId();

        // Keyed on the stable account ID, which is what makes a returning login
        // land on the same record instead of minting a second one.
        $this->projections[$accountId] ??= new LocalUser($accountId);
        $this->durable[$accountId] ??= 'onboarding:step-one';

        $this->session = ['account_id' => $accountId];

        return $result->outcome;
    }

    public function projectionCount(string $accountId): int
    {
        return isset($this->projections[$accountId]) ? 1 : 0;
    }

    public function signedInAccountId(): ?string
    {
        return $this->session['account_id'] ?? null;
    }

    public function sessionPayload(): string
    {
        return json_encode($this->session, JSON_THROW_ON_ERROR);
    }

    public function signOut(): void
    {
        $this->authenticator->logout(Request::create('/auth/logout'), 'https://product.test/');
        $this->session = [];
    }

    public function expireSession(): void
    {
        // Only the session goes; projections and durable state are untouched.
        $this->session = [];
    }

    public function reachProtectedSurface(): bool
    {
        $accountId = $this->signedInAccountId();

        if ($accountId === null) {
            return false;
        }

        $request = Request::create('/app');
        $request->setUserResolver(fn (): AccountProjection => $this->projections[$accountId]);

        try {
            $this->entitlement->handle($request, fn (): Response => new Response('ok'));
        } catch (ProductAccessDenied) {
            return false;
        }

        return true;
    }

    public function durableStateFingerprint(): string
    {
        ksort($this->durable);

        return json_encode($this->durable, JSON_THROW_ON_ERROR);
    }
}
