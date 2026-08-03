Auth for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/auth)

Documentation: https://hypervel.org/docs/authentication

## Differences From Laravel

- `Auth::routes()` is intentionally omitted because Hypervel does not integrate `laravel/ui`. Register authentication routes explicitly or use Fortify.
- Password brokers are guard-declared via `auth.guards.{guard}.passwords`; `auth.defaults.passwords` and `AUTH_PASSWORD_BROKER` do not exist, and bare `Password::` calls resolve through the current guard or throw.
- `auth.defaults.provider` does not exist; `getDefaultUserProvider()` returns the provider declared by the current default guard, and `createUserProvider(null)` means no provider.
- `guest:{guard}` selects the first named guard as the request's default guard on pass-through, mirroring how `auth:{guard}` selects on success.
- Password confirmation is scoped to the current guard, and each guard may define its own `password_timeout`.

Ported from: https://github.com/laravel/framework
