# Zahir client

This package is the reusable product-side boundary for Zahir.

It provides:

- a provider-neutral login-driver contract;
- a `VerifiedExternal` value containing only allowlisted claims and assertion provenance;
- authenticated HTTP calls for account resolution and entitlement decisions;
- immutable responses with no ORM or provider-specific objects.

Products still own OAuth/OIDC transport, state, nonce, PKCE, callback allowlists, replay protection, product sessions, local user projections, and authorization. The package never stores credentials or connects to Zahir's database.

The first identity-provider adapter will implement WorkOS AuthKit behind `LoginDriver`; WorkOS types must not cross that interface.

## Verification

```bash
composer install
composer check
```
