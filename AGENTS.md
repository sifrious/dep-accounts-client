# Zahir client project instructions

- Keep identity-provider objects behind `LoginDriver`.
- `LoginDriver` may return only provider-neutral `VerifiedExternal` data after completing protocol verification.
- Keep service transport behind `AccountsClient` public methods.
- Never query Zahir storage or own product sessions, profiles, credentials, payment state, or authorization policy.
- Identify external identities by `(provider, provider_subject)`; never use email as an identity key.
- Do not add product-specific behavior or WorkOS types to the public package API.
