# Who owns what

Four parties take part in a product login. Every recurring argument about this
system is really an argument about which of them owns a thing, so the boundaries
are written down once here.

| Concern | Owner |
|---|---|
| Passwords, passkeys, MFA, recovery, provider sessions, identity verification | External identity provider |
| Opaque `acc_*` account IDs, external identity links, lifecycle status, products, entitlement grants, resolution decisions, audit provenance | Zahir |
| Provider-neutral login contracts, protocol verification, the outcome vocabulary, the Zahir transport, conformance fixtures | This package |
| Routes, framework sessions, the local projection, policies, preferences, onboarding, navigation, and every rendered word | The consuming application |

## The rules that follow from it

**Identity is `(provider, provider_subject)`.** Never an email, never a GitHub
login, never a tenant, never a local user ID. Emails and display names are
mutable metadata; two of those identifiers can be reassigned to a different
human being outright.

**The account ID is opaque.** `acc_` plus a ULID, derived from nothing. Products
store it and compare it; they never parse it or infer anything from it.

**Products never touch Zahir storage.** Every call goes through `AccountsClient`
over an authenticated contract. A product that could reach the database would
become a second authority on identity, and the point of Zahir is that there is
exactly one.

**Zahir owns no sessions and no product authorization.** It answers "is this
account allowed to use this product?" and nothing more. Roles, workspaces,
per-object permissions, and onboarding stay in the product.

**No provider type crosses the seam.** WorkOS tokens, sessions, SDK users, and
raw assertions stop at `LoginDriver`. Only `VerifiedExternal` comes out, and it
carries just `email`, `email_verified`, and `name`.

**Entitlement is not presentation.** Hiding a link is not access control. The
only thing that grants access is an allowed decision from Zahir for this
product's own entitlement, re-checked on protected requests.

## Where session invalidation actually lives

Zahir holds no session state, so it cannot reach into a product and end a
session — deliberately, since a credential-free account service has no business
owning browser state.

Authority is re-established continuously instead. `RequireProductEntitlement`
re-asks Zahir on each protected request and refuses any decision older than
`zahir.entitlement_decision_max_age_seconds`. A suspension or a revoked grant
therefore takes effect within one decision window rather than at the end of a
session lifetime, and no product keeps access it was granted before the change.

That freshness bound is the contract. A cached decision without it would keep an
account alive indefinitely after its grant was pulled.
