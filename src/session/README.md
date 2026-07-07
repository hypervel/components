Session for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/session)

## Differences From Laravel

- `Store::passwordConfirmed(?string $guard = null)` stamps a guard-scoped key (`auth.password_confirmed_at_{guard}`) instead of Laravel's single shared key, resolving the current guard when none is given.
