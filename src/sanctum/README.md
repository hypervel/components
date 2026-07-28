Sanctum for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/sanctum)

Ported from: https://github.com/laravel/sanctum

## Differences From Laravel

- Hypervel only supports the `id|token` format returned by `createToken()`. Laravel's legacy plain-token lookup is omitted because Hypervel's token cache and invalidation paths are keyed by token ID.
- Hypervel includes optional token and tokenable lookup caching for Swoole workers. Missing token IDs are cached as `null` for the configured TTL. Missing tokenable models are not cached because their visibility may depend on the current query context.
- When caching is enabled, the selected personal-access-token model, configured Sanctum guard provider models, and stock Eloquent graph containers are added to the cache class policy automatically. Applications declare custom-provider morph targets, nested relations, custom containers, and other application-owned objects with `Cache::allowSerializableClassesUsing()` during provider boot.
- Sanctum validates its cache store during process startup. Redis, database, file, storage, Swoole, and supported-only stacks are accepted; array, worker-array, null, session, failover, and type-destroying Redis serializer modes are rejected. Accepted native Redis serializers preserve model types but bypass the PHP class policy.
- Cached token entries never embed `tokenable`. The live token receives the exact resolved tokenable before callbacks and events. Deletion and application-visible token updates clear both entries, while Sanctum's internal `last_used_at` write clears only the token entry. The tokenable TTL is its maximum staleness bound.
- The global `sanctum.guard` accept-list is removed. Each sanctum-driver guard declares its trusted session guards with `auth.guards.{guard}.session_guards`; `[]` means bearer tokens only, and a missing key is a config error. Stateful session users must also match the sanctum guard's provider; Laravel returns any listed guard's user unchecked.
- `sanctum.stateful` is renamed `sanctum.stateful_domains`, matching the `SANCTUM_STATEFUL_DOMAINS` environment variable and the key's actual contents.
- Sanctum's session password-hash artifacts are HMAC-only. Laravel's raw-hash fallback for legacy sessions is intentionally omitted because Hypervel 0.4 has no released legacy sessions.
