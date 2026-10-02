Passkeys for Hypervel
===

Documentation: https://hypervel.org/docs/fortify

## Differences From Laravel

- Passkeys use a polymorphic `user` owner relation so multiple authenticatable model classes can share the same passkeys table.
- Standalone Passkeys follows Hypervel's current default guard selected by `Auth::shouldUse()` or `auth.defaults.guard`, with optional `passkeys.guard` route-group selection for built-in standalone routes.
- Passkeys omit Laravel's `relyingPartyName()` because `web-auth/webauthn-lib` deprecates non-empty relying party names.
- Passkeys include explicit orphan cleanup for polymorphic owners.
- Passkeys support boot-time request-aware callbacks for redirects and WebAuthn relying party / origin settings, such as for custom domains, multi-guard apps, or multi-tenant apps.
- Passkeys keep Laravel's directly writable `$passkeyModel` static property private so worker-lifetime state remains typed and resettable. Use `usePasskeyModel()` instead.
- Passkeys omit Laravel's protected `StorePasskey::ensureCredentialIsUnique()` pre-check because the unique `credential_id` index rejects duplicates without an extra query. Override `createPasskey()` to customize duplicate handling.

Ported from: https://github.com/laravel/passkeys-server
