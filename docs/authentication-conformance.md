# The authentication release gate

One deterministic suite, run identically by every product that signs people in
through Zahir. No network, no provider credentials, no database of its own.

## Running it

In this package:

```bash
composer conformance     # the suite alone
composer check           # the full gate: tests, static analysis, contract digest
```

In a consuming application, once wired up (below):

```bash
php artisan test --filter AuthenticationConformance
```

That command is the product's auth release gate. It passes or the product does
not ship an authentication change.

## What it proves

Eighteen cases across four areas.

**Identity.** First login creates exactly one local projection keyed by the
stable account ID. Repeated login reuses it — asserted against a *stateful* fake
Zahir, so the same subject really does resolve to the same account rather than a
canned response saying so. Two projections for one account would split a
person's history in half at their second sign-in.

**Entitlement.** An account without this product's grant is refused and gets no
session. Another product's entitlement grants nothing here — the assertion that
makes a second consumer worth having. Login always consults Zahir, and an
unentitled account cannot reach a protected surface.

**Failures.** Cancellation, expired callback, malformed callback, provider
failure, replay, and Zahir outage stay six distinct outcomes. Replay is proven
rather than scripted: the callback is presented twice against one begun
transaction. Every failure is then retried in sequence and must still leave
exactly one account and one projection, because retry storms are how duplicate
accounts get made.

**Lifecycle.** Suspension and revocation fail closed on the next protected
request and delete nothing — projection and durable state both survive, and
reinstating access returns the same projection. Logout and session expiry clear
the session while preserving durable product state, and signing back in resumes
it.

Plus one secrets check: the product session may carry the opaque account ID and
local state, never a token, verifier, or service credential.

## Wiring a product in

Implement `ConsumerUnderTest` — twelve methods, all of them things only your
product knows: where its routes are, what its session looks like, what durable
state it owns. None of them is an assertion.

```php
final class BurdgenConformanceTest extends TestCase
{
    use AuthenticationConformance;

    protected function consumerUnderTest(): ConsumerUnderTest
    {
        return $this->consumer ??= new BurdgenConsumer($this);
    }
}
```

`tests/Conformance/InMemoryConsumer.php` in this package is the worked example
and the reference implementation.

Two hooks deserve care:

- **`reachProtectedSurface()`** must go through the real entitlement middleware
  on a real protected route. Stubbing it turns the revocation and suspension
  cases into theatre.
- **`durableStateFingerprint()`** returns an opaque string covering whatever
  your product must not lose — onboarding progress, preferences, domain records.
  The suite never interprets it; it only checks the value survives logout,
  expiry, suspension, and revocation.

## What it deliberately does not cover

**Product onboarding, wording, and navigation.** Those belong in the product's
own tests. `durableStateFingerprint()` is the only hook, and it is opaque on
purpose: the shared standard should not grow opinions about one product's setup
flow.

**Protocol cryptography.** State, nonce, PKCE, JWKS signatures, issuer, audience,
and time claims are the driver's job, proven against locally signed fixtures by
`WorkOsAuthKitDriverTest`. `FakeIdentityProvider` does not reimplement them — a
second implementation of a security check is a second place for it to be subtly
wrong. It does model single use, because that is the one protocol property a
consumer can break through its own wiring.

**Anything live.** No WorkOS, no deployed Zahir, no production credentials.
Passing this suite says the boundary is wired correctly; it does not say a
deployment works. That proof is MME-2100's and cannot be satisfied here.
