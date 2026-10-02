Fortify for Hypervel
===

Documentation: https://hypervel.org/docs/fortify

## Differences From Laravel

- Fortify follows Hypervel's current default guard selected by `Auth::shouldUse()` or `auth.defaults.guard`, with optional `fortify.guard` route-group selection for built-in routes.
- Fortify uses the password reset broker declared by the selected guard's `passwords` key instead of using a separate Fortify broker setting.
- Fortify integrates with the standalone `hypervel/passkeys` package and keeps passkeys polymorphic across authenticatable model classes.
- Fortify supports boot-time request-aware redirect callbacks for dynamic post-login destinations, such as for custom domains, multi-guard apps, or multi-tenant apps.
- Fortify throttles two-factor challenge submissions by default.
- Fortify scopes login throttling per guard (`guard|username|ip`), so a lockout in one actor silo never blocks logins in another.
- Fortify password confirmation follows the current guard: guard-scoped session key, optional per-guard `password_timeout`, and the confirmed-password status endpoint uses the same resolution. This also unifies Laravel's mismatched 900/10800 fallback defaults.
- Fortify's two-factor provider uses OTPHP instead of Google2FA and generates 32-character secrets by default.
- Two-factor user models implement the `TwoFactorAuthenticationUser` contract as well as using the `TwoFactorAuthenticatable` trait. Recovery codes are consumed atomically through the user's `consumeRecoveryCode()` method; `TwoFactorLoginRequest::validRecoveryCode()` only checks a code and leaves the login challenge in the session.
- Fortify omits Laravel's deprecated `Rules\Password`.
- Fortify keeps Laravel's directly writable static configuration properties private so worker-lifetime state remains typed and resettable. Use `authenticateThrough()`, `authenticateUsing()`, `confirmPasswordsUsing()`, `encryptUsing()`, and `ignoreRoutes()` instead.

Ported from: https://github.com/laravel/fortify
