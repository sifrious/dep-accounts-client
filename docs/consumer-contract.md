# The consumer contract

## The seam

`ProductAuthenticator` is the whole public entry point.

```php
$authenticator->begin($request);                       // → RedirectResponse
$authenticator->complete($request);                    // → LoginResult   (never throws)
$authenticator->logout($request, $postLogoutUrl);      // → LogoutResult
```

`complete()` performs the entitlement check itself and returns
`AuthenticationOutcome::Authenticated` only when Zahir allowed this product's own
entitlement for an active account. That is why the check lives inside the method
rather than beside it: there is no ordering a consumer can get wrong, and no path
that reaches `Auth::login()` on a verified identity alone.

Always branch on `LoginResult::grantsAccess()`. Comparing outcomes by hand means
a case added later could be read as permission by code that predates it.

## Outcomes

Stable string codes. Cases may be added; values are never renamed or repurposed.
`AuthenticationOutcomeTest` freezes the list.

| Code | Meaning | Ends session | Retryable |
|---|---|---|---|
| `authenticated` | Verified, active, entitled | – | – |
| `unauthorized_product` | Real active account, no grant for this product | yes | no |
| `suspended` | Account lifecycle forbids access | yes | no |
| `canceled` | Declined at the provider | no | yes |
| `callback_expired` | Transaction outlived its TTL | no | yes |
| `callback_invalid` | Malformed, or state/nonce/issuer/audience/signature failed | no | yes |
| `replay_rejected` | Single-use transaction presented twice | no | yes |
| `provider_failure` | The identity provider failed or answered unusably | no | yes |
| `zahir_unavailable` | Zahir unreachable or unable to answer | no | yes |
| `logged_out` | Deliberate sign-out | yes | – |
| `session_expired` | Product session aged out | yes | yes |

Two separations carry real weight:

- **`zahir_unavailable` is never a denial.** An outage that reported as "no
  access" would lock out every entitled person and, in the logs, would be
  indistinguishable from a mass revocation.
- **`canceled` is not a failure.** Somebody changed their mind. It gets the same
  door again, not an error page.

## The local projection

Implement `AccountProjection` on the local user model:

```php
public function zahirAccountId(): string;
```

Enforce uniqueness on that column in storage. Two projections for one account is
the specific bug this contract exists to prevent — it splits a person's product
history in half at their second sign-in.

The projection is a convenience copy, not an authority. Store the account ID plus
whatever local state the product owns. Never store provider credentials on it.

## Credentials

Products receive no provider credentials. The driver holds the PKCE verifier,
state, and nonce in the product's own session for the duration of one login and
consumes them on the callback. `VerifiedExternal` carries only `email`,
`email_verified`, `name`, and assertion provenance — its constructor rejects
anything else outright.

Two secrets are deployment configuration and never reach a session, a log, or a
rendered response: the WorkOS client secret and the Zahir service token.
