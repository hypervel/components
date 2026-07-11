Fortify for Hypervel
===

Backend controllers and scaffolding for Hypervel authentication.

Ported from: https://github.com/laravel/fortify

See `src/boost/docs/fortify.md` for the canonical Fortify and Passkeys documentation.

## Differences From Laravel

- Fortify follows Hypervel's current default guard selected by `Auth::shouldUse()` or `auth.defaults.guard`, with optional `fortify.guard` route-group selection for built-in routes.
- Fortify uses the password reset broker declared by the selected guard's `passwords` key instead of using a separate Fortify broker setting.
- Fortify integrates with the standalone `hypervel/passkeys` package and keeps passkeys polymorphic across authenticatable model classes.
- Fortify supports boot-time request-aware redirect callbacks for dynamic post-login destinations, such as for custom domains, multi-guard apps, or multi-tenant apps.
- Fortify throttles two-factor challenge submissions by default.
- Fortify scopes login throttling per guard (`guard|username|ip`), so a lockout in one actor silo never blocks logins in another.
- Fortify password confirmation follows the current guard: guard-scoped session key, optional per-guard `password_timeout`, and the confirmed-password status endpoint uses the same resolution. This also unifies Laravel's mismatched 900/10800 fallback defaults.
- Fortify fixes Laravel's two-factor response contract mismatch.
- Fortify's two-factor provider uses OTPHP with mandatory PSR clock injection, fresh per-secret TOTP objects, and a default secret length of `32` characters.
- Fortify renders two-factor QR SVGs through a concrete internal chillerlan renderer.
- Fortify caches accepted TOTP codes for the full configured verification window to prevent replay for as long as the code remains acceptable.
- Recovery code replacement operates on decoded JSON entries.
- Fortify omits Laravel's deprecated `Rules\Password`.
- Fortify tightens loose upstream comparisons and application-model event docs where Hypervel can express the real contract.
