Sanctum for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/sanctum)

Documentation: https://hypervel.org/docs/sanctum

## Differences From Laravel

- Hypervel only supports the `id|token` format returned by `createToken()`. Laravel's legacy plain-token lookup is omitted because Hypervel's token cache and invalidation paths are keyed by token ID.
- Hypervel includes optional token and tokenable lookup caching for Swoole workers. Missing token IDs are cached as `null` for the configured TTL. Missing tokenable models are not cached because their visibility may depend on the current query context.
- When caching is enabled, the selected personal-access-token model, configured Sanctum guard provider models, and stock Eloquent graph containers are added to the cache class policy automatically. Applications declare custom-provider morph targets, nested relations, custom containers, and other application-owned objects with `Cache::allowSerializableClassesUsing()` during provider boot.
- Sanctum validates its cache store during process startup. Redis, database, file, storage, Swoole, and supported-only stacks are accepted; array, worker-array, null, session, failover, and type-destroying Redis serializer modes are rejected. Accepted native Redis serializers preserve model types but bypass the PHP class policy.
- Cached token entries never embed `tokenable`. Successful model creation, update, and deletion, plus cache-enabled deletion through the token relation, invalidate affected entries after commit. Sanctum's internal `last_used_at` write refreshes only the token entry.
- `HasApiTokens::tokens()` uses the protected `newTokenRelation()` factory instead of the model-wide `newMorphMany()` hook.
- Hypervel ships an explicit default `sanctum` guard. Laravel's global `sanctum.guard` accept-list is omitted; each Sanctum guard declares trusted session guards with `auth.guards.{guard}.session_guards`. An empty list means bearer tokens only, a missing key is a configuration error, and stateful users must match the Sanctum guard's provider instead of accepting any listed guard's user unchecked.
- Sanctum middleware priority is enabled by calling `Middleware::statefulApi()` in `bootstrap/app.php`; the package provider does not mutate global middleware priority.
- `sanctum.stateful` is renamed `sanctum.stateful_domains`, matching the `SANCTUM_STATEFUL_DOMAINS` environment variable and the key's actual contents.
- Sanctum's session password-hash artifacts are HMAC-only. Laravel's raw-hash fallback for legacy sessions is intentionally omitted because Hypervel 0.4 has no released legacy sessions.

Ported from: https://github.com/laravel/sanctum
