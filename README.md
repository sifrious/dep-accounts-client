# Zahir client

> **License:** Copyright © 2026 Sifrious. All rights reserved. This is
> publicly viewable proprietary software, not open-source software. See
> [LICENSE.md](LICENSE.md).

This package is the reusable product-side boundary for Zahir.

It provides:

- a provider-neutral login-driver contract;
- a `VerifiedExternal` value containing only allowlisted claims and assertion provenance;
- authenticated HTTP calls for account resolution and entitlement decisions;
- immutable responses with no ORM or provider-specific objects.

Products own routes, product sessions, local user projections, onboarding, and authorization. Provider drivers in this package own OAuth/OIDC transport validation, while storing their short-lived transaction state only in the product-provided session. The package never stores credentials or connects to Zahir's database.

The WorkOS AuthKit driver is behind `LoginDriver`; WorkOS tokens, sessions, users, and SDK types never cross that interface. It uses authorization code + PKCE, single-use state, nonce and time validation, locally verified JWKS signatures, exact callback/logout allowlists, and produces only `VerifiedExternal`.

The client also exposes provider-neutral identity link/unlink and account suspend/reactivate calls. These operations use opaque account IDs and service contracts only; consumers never receive database models or storage identifiers.

Construct `WorkOsAuthKitConfig` from deployment secrets and exact URLs, inject Laravel's HTTP factory, then give the resulting `WorkOsAuthKitDriver` to `LoginManager`. Initiation expects the selected exact callback URL in the request's `redirect_uri` query value. Live credentials are only needed for a deployment smoke test; normal tests are deterministic and make no WorkOS calls.

## Verification

```bash
composer install
composer check
```
