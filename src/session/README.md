Session for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/session)

## Differences From Laravel

- `Store::passwordConfirmed(?string $guard = null)` stamps a guard-scoped key (`auth.password_confirmed_at_{guard}`) instead of Laravel's single shared key, resolving the current guard when none is given.
- Password-hash session artifacts are HMAC-only. Laravel's raw-hash fallback for legacy sessions is intentionally omitted because Hypervel 0.4 has no released legacy sessions.
- Laravel's `apc`, `memcached`, and `dynamodb` session drivers and the shared `SessionManager::createCacheBased()` wrapper are intentionally omitted because Hypervel ships no matching cache stores. Register cache-backed handlers with `Session::extend()`.
