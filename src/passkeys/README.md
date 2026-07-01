Passkeys for Hypervel
===

Passwordless authentication using WebAuthn passkeys for Hypervel.

Ported from: https://github.com/laravel/passkeys

See `src/boost/docs/fortify.md` for the canonical Fortify and Passkeys documentation.

## Differences From Laravel

- Passkeys use a polymorphic `user` owner relation so multiple authenticatable model classes can share the same passkeys table.
- Standalone Passkeys follows Hypervel's current default guard selected by `Auth::shouldUse()` or `auth.defaults.guard`, with optional `passkeys.guard` route-group selection for built-in standalone routes.
- Passkeys use current non-deprecated `web-auth/webauthn-lib` APIs, including `CredentialRecord`.
- Passkeys omit Laravel's `relyingPartyName()` because `web-auth/webauthn-lib` deprecates non-empty relying party names.
- Passkeys include explicit orphan cleanup for polymorphic owners.
- Passkeys support boot-time request-aware redirect callbacks for multi-tenant and multi-guard post-login destinations.
- Passkey registration responses do not store request data on singleton response instances.
