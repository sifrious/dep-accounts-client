# Adopting shared login in an existing Laravel application

Worked end to end by `logres-site` and `burdgen`. Read `ownership.md` first.

## 1. Depend on the package

```json
{ "require": { "sifrious/accounts-client": "^0.1" } }
```

The service provider is auto-discovered. Publish the config to see every knob:

```bash
php artisan vendor:publish --tag=zahir-config
```

## 2. Get a service credential

Zahir issues one per calling product. On the Zahir host:

```bash
php artisan zahir:caller-credential:issue <product-key>
```

The token is shown once and stored only as a hash. It goes in the deployment
secret store, never in source control. Account-lifecycle authority is a separate
capability and stays off for a product caller.

## 3. Configure

```dotenv
ZAHIR_BASE_URL=https://zahir.example
ZAHIR_SERVICE_TOKEN=zhr....
ZAHIR_PRODUCT=<your product key>
ZAHIR_ACCESS_ENTITLEMENT=access

WORKOS_CLIENT_ID=...
WORKOS_CLIENT_SECRET=...
WORKOS_ISSUER=https://api.workos.com/
WORKOS_CALLBACK_URLS=https://app.example/auth/callback
WORKOS_POST_LOGOUT_URLS=https://app.example/
```

`ZAHIR_PRODUCT` is your own product key. Never point it at another product's:
entitlements are per product precisely so one product's access is not another's.

Callback and post-logout URLs are matched by exact string equality. A trailing
slash or an extra query parameter is a different URL and will be refused.

## 4. Add the account ID to the local user

```php
$table->string('zahir_account_id', 30)->nullable()->unique();
```

Unique is not optional — it is the constraint that makes repeated login
idempotent even if application code slips.

Implement the contract:

```php
class User extends Authenticatable implements AccountProjection
{
    public function zahirAccountId(): string
    {
        return (string) $this->getAttribute('zahir_account_id');
    }
}
```

If the table has a legacy unique email or a non-null password, relax those:
external identities do not supply a password, and two accounts may legitimately
share an email address.

## 5. Own three routes

```php
Route::get('/auth/login',    LoginRedirectController::class)->middleware('guest');
Route::get('/auth/callback', LoginCallbackController::class)->middleware(['guest', 'throttle:10,1']);
Route::post('/auth/logout',  LogoutController::class)->middleware('auth');
```

Throttle the callback. It is the one unauthenticated endpoint that does real work.

The callback controller is the whole integration:

```php
$result = $authenticator->complete($request);

if (! $result->grantsAccess()) {
    return $this->render($result->outcome);   // your words, per outcome
}

$user = User::query()->updateOrCreate(
    ['zahir_account_id' => $result->accountId()],
    ['name' => $result->identity->claims['name'] ?? null,
     'email' => $result->identity->claims['email'] ?? null],
);

Auth::login($user);
$request->session()->regenerate();

return redirect()->intended($this->firstIncompleteStepFor($user));
```

`updateOrCreate` keyed on the account ID is what makes returning login
idempotent. `session()->regenerate()` after login is what prevents session
fixation — the callback is reached by a guest, so the pre-login session ID is
attacker-influencable.

## 6. Register the entitlement middleware

```php
$middleware->alias(['zahir.entitlement' => RequireProductEntitlement::class]);
$middleware->appendToPriorityList(Authenticate::class, RequireProductEntitlement::class);
```

The second line is not optional. Laravel re-sorts middleware by its priority
list at runtime, and `Authenticate` is on that list while this is not — so
`['auth', 'zahir.entitlement']` on a route group does **not** guarantee that
order. Without it, a signed-out visitor is refused by the entitlement gate
before `auth` ever runs, and gets a flat denial instead of being sent to sign in.

Apply it to every authorized route group, not just the sensitive ones — a route
without it is a route where a revoked grant still works.

## 6a. Render denials for browsers

`ProductAccessDenied` answers API-shaped requests itself and returns nothing for
anyone else, so the application decides what a person sees:

```php
$exceptions->render(function (ProductAccessDenied $denied, Request $request) {
    return $request->expectsJson()
        ? null
        : redirect()->route('auth.problem', ['state' => $denied->outcome->value]);
});
```

## 7. Render every outcome

Each outcome needs its own words and its own next action. Collapsing them into
"login failed" is the failure mode this vocabulary exists to prevent: somebody
who cancelled, somebody whose access was revoked, and somebody hitting an outage
need three different sentences.

Do not render provider error strings. Map the outcome to your own copy.

## 8. Run the conformance suite

See `authentication-conformance.md`. Extend the shipped case, point it at your
adapter, and run it as your auth release gate.

## Checklist

- [ ] `sifrious/accounts-client` required; config published
- [ ] Service credential issued and stored as a deployment secret
- [ ] `ZAHIR_PRODUCT` set to this product's own key
- [ ] Callback and post-logout URLs registered exactly, both sides
- [ ] `zahir_account_id` column added with a unique index
- [ ] Local user implements `AccountProjection`
- [ ] Login, callback, and logout routes owned by the application
- [ ] Callback throttled; session regenerated after login
- [ ] `zahir.entitlement` applied to every authorized route group
- [ ] Entitlement middleware appended to the priority list after `Authenticate`
- [ ] Browser rendering registered for `ProductAccessDenied`
- [ ] All eleven outcomes rendered with distinct copy
- [ ] Conformance suite passing as the release gate
- [ ] No provider credential stored on the user or in the session
