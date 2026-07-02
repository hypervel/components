Fortify for Hypervel
===

Backend controllers and scaffolding for Hypervel authentication.

Ported from: https://github.com/laravel/fortify

See `src/boost/docs/fortify.md` for the canonical Fortify and Passkeys documentation.

## Differences From Laravel

- Fortify follows Hypervel's current default guard selected by `Auth::shouldUse()` or `auth.defaults.guard`, with optional `fortify.guard` route-group selection for built-in routes.
- Fortify derives its password reset broker from the selected guard provider instead of using a separate Fortify broker setting.
- Fortify integrates with the standalone `hypervel/passkeys` package and keeps passkeys polymorphic across authenticatable model classes.
- Fortify supports boot-time request-aware redirect callbacks for multi-tenant and multi-guard post-login destinations.
- Fortify throttles two-factor challenge submissions by default.
- Fortify fixes Laravel's two-factor response contract mismatch.
- Fortify's two-factor provider contract accepts the configured secret length, and the default is `32` for `pragmarx/google2fa` v9.
- Recovery code replacement operates on decoded JSON entries.
- Fortify omits Laravel's deprecated `Rules\Password`.
- Fortify tightens loose upstream comparisons and application-model event docs where Hypervel can express the real contract.
