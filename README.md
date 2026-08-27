# Accounts client

The Accounts client is the reusable integration boundary between product applications and the Accounts service.

It provides:

- a provider-independent login driver contract;
- a login coordinator that resolves verified identities into stable Accounts accounts;
- authenticated HTTP calls for account resolution and entitlement decisions;
- small immutable response values that do not expose provider-specific objects.

Applications still own their local session and product-specific account projection. The package never stores credentials and never connects directly to the Accounts database.

## Installation

```bash
composer require sifrious/accounts-client
```

## Verification

```bash
composer install
composer test
```

The concrete login driver and service credential are configured by each application after the identity and service-authentication decisions are accepted.
