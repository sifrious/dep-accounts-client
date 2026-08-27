# Accounts client project instructions

- Keep identity-provider objects behind `LoginDriver`.
- Keep service transport behind `AccountsClient` public methods.
- Do not own product sessions, product profiles, credentials, or payment state.
- Do not add provider dependencies until the provider decision is accepted.
- Do not add product-specific behavior to this package.
