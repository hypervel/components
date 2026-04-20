Auth for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/auth)

<!-- @TODO: Move to 0.4 documentation -->

## User Lookup Cache

Optional cross-request cache for `EloquentUserProvider::retrieveById()`. Disabled by default. When enabled, each authenticated request can hit the cache instead of re-querying the database for the current user — a large win under Swoole where workers are long-lived and request volume is high.

Only `retrieveById()` is cached. Credential and token lookups (`retrieveByCredentials`, `retrieveByToken`) are never cached for security — they must always see fresh data.

### Enabling it

Per-provider config in `config/auth.php`:

```php
'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class,
        'cache' => [
            'enabled' => env('AUTH_USERS_CACHE_ENABLED', false),
            'store' => env('AUTH_USERS_CACHE_STORE'),   // null = default cache store
            'ttl' => env('AUTH_USERS_CACHE_TTL', 300),
            'prefix' => env('AUTH_USERS_CACHE_PREFIX', 'auth_users'),
        ],
    ],
],
```

Minimum env setup for single Redis node:

```env
AUTH_USERS_CACHE_ENABLED=true
AUTH_USERS_CACHE_STORE=redis
```

High-scale recommended setup (`stack` with Swoole L1 + Redis L2):

```env
AUTH_USERS_CACHE_ENABLED=true
AUTH_USERS_CACHE_STORE=stack
```

### Why microcaching helps at scale

At high request volume, every authenticated request hits the user store. Without this cache, that's one Redis `GET` per request per worker. Even at modest RPS this is thousands of Redis round-trips per second just to hydrate `Auth::user()`.

The recommended `stack = [swoole (3–5s) → redis]` topology ("microcaching") keeps hot lookups in each worker's Swoole Table for a few seconds. The same user making multiple requests in that window hits the L1 and skips the Redis round-trip entirely. L1 hit rates of 90%+ are typical for authenticated traffic with even a 3-second TTL, which adds up to:

- Lower p99 latency — L1 reads are nanoseconds, Redis is hundreds of microseconds
- Smaller Redis tier — most of the load never reaches it
- Less network bandwidth — serialized user models stay inside the worker
- Brief Redis outage tolerance — L1 keeps serving authed requests for a few seconds if Redis goes down

### Invalidation model

Four layers, most-automatic to most-manual:

1. **Provider writes** — `updateRememberToken()` and `rehashPasswordIfRequired()` both call `$user->save()`, which fires the `saved` model event. Invalidation is handled by the listener (layer 2), not by an explicit clear inside those methods.

2. **Model events** — when caching is enabled for a provider, the provider registers `saved` and `deleted` listeners on the user model class. Any code path that modifies the user through Eloquent — `$user->save()`, `$user->update(...)`, `$user->delete()` — triggers cache invalidation. This covers controller updates, profile edits, admin changes.

3. **Manual** — for writes that bypass Eloquent events (pivot table changes for roles/permissions, raw DB queries, mass `update()`, external processes), clear explicitly via `Auth::clearUserCache(...)` — see "Manual invalidation API" below.

4. **TTL expiry** — even if active invalidation is missed, entries expire on their TTL and the next request fetches fresh data.

**Within a node:** `SwooleStore` uses a Swoole Table in shared memory, so one `forget()` from any worker clears it for every worker on that node.

**Across nodes:** only the shared tiers (`redis`, `database`) propagate. If you use `stack = [swoole, redis]`, invalidation clears the origin node's L1 + the shared Redis — but other nodes' Swoole L1s keep serving stale entries until their own L1 TTL expires. That bounded staleness window (a few seconds) is the microcaching trade-off. Cross-node pub/sub invalidation is out of scope for this feature; apps that need strict global consistency should skip the L1 tier.

### Manual invalidation API

```php
Auth::clearUserCache(mixed $identifier, ?string $guard = null): void
```

Call this after any write path that doesn't fire Eloquent model events — typical scenarios:

- Pivot table writes for roles/permissions (`$user->roles()->attach(...)`, `detach`, `sync`)
- Raw query builder or PDO writes (`DB::table('users')->update(...)`)
- Mass updates (`User::query()->where(...)->update(...)` — Laravel's `Builder::update()` does not fire model events)
- Queue jobs, scheduled commands, or external services modifying users through non-Eloquent paths

**Parameters:**

- **`$identifier`** — the user's auth identifier (what `retrieveById()` expects). For the default Eloquent-based guard this is the user's primary key. Use the same value you'd pass to `Auth::loginUsingId()`.
- **`$guard`** — the guard name to clear against, or `null` to use the application's default guard. The method resolves that guard, finds its provider, and clears the cache entry for **that provider's model**.

**How the model is chosen:**

The cache key includes the provider's model FQCN, so `Auth::clearUserCache(42, 'web')` only clears `App\Models\User:42`, not `App\Models\Landlord:42`. The guard determines the provider; the provider determines the model.

**Multi-guard / multi-model apps:**

| Setup | Behaviour |
|---|---|
| One provider shared by multiple guards (e.g. `web`, `api`, `sanctum`, `jwt` all point at `users`) | One call with any of those guard names clears the single shared cache keyspace. Calling for each guard is redundant. |
| Different guards with different models (e.g. `web → User`, `admin → Admin`, `landlord → Landlord`) | You must call once per guard/model you want to invalidate. `Auth::clearUserCache(42)` with no guard name clears *only* the default guard's model — a landlord update that hits `Landlord:42` needs `Auth::clearUserCache(42, 'landlord')`. |
| Default guard omitted in a multi-guard setup | Clears for the default guard *only*, not all guards. In non-trivial deployments, always pass the guard name explicitly to avoid surprises. |

**Tenant-aware resolver interaction:**

If you've registered `EloquentUserProvider::resolveUserCacheKeyUsing(...)`, `clearUserCache()` uses the same resolver — so it clears the entry for the **current** tenant context, not every tenant's copy. To clear the same user across multiple tenants, call `clearUserCache()` once per tenant context.

**No-ops:**

- If the guard's provider is not an `EloquentUserProvider` (e.g. a custom `RequestGuard`), the call is silently ignored.
- If caching is disabled for the provider, the call is a no-op.

### Bulk invalidation

The auth cache does not include a built-in method for flushing all cached users at once. If you need to invalidate everything - for example after a deploy that changes the User model, or during an incident - there are two supported approaches.

**1. Use a dedicated cache store**

Give the auth cache its own dedicated store, separate from the rest of your application's caching. Any supported driver works:

```php
// config/cache.php
'stores' => [
    'auth' => [
        'driver' => 'redis',
        'connection' => 'auth',
    ],
    // ...
],
```

```env
AUTH_USERS_CACHE_STORE=auth
```

Flush the store via the cache API:

```php
Cache::store('auth')->flush();
```

This clears everything held in the dedicated store.

**2. Use Redis in any-mode with tags**

Configure a Redis cache store in `any` tag mode and set tags on the auth provider's cache block:

```php
// config/cache.php
'stores' => [
    'auth' => [
        'driver' => 'redis',
        'connection' => 'auth',
        'tag_mode' => 'any',
    ],
    // ...
],
```

```php
// config/auth.php
'providers' => [
    'users' => [
        // ...
        'cache' => [
            // ...
            'store' => 'auth',
            'tags' => ['auth_users'],
        ],
    ],
],
```

Every cached user is then indexed under the configured tags and can be flushed collectively:

```php
Cache::store('auth')->tags(['auth_users'])->flush();
```

Tags are additive — per-user reads, writes, and the automatic invalidation listener keep working as before.

**Tag mode requirement.** The configured store must implement `TaggableStore` and be in `TagMode::Any` — Redis is the only stock driver that supports configurable tag modes, via its `tag_mode` config key. `enableCache()` throws at boot if these conditions aren't met. All-mode is rejected because its tag-namespaced storage keys would force every read and forget to carry tag context, which doesn't fit the auth-cache access pattern.

For per-request tag scoping (e.g. tagging each cached user with their tenant), see **Dynamic tag resolvers** below.

### TTL guidance

| Scenario | Guidance |
|---|---|
| Profile updates (name, avatar, preferences) | Default 300s is fine. Model events clear on save. |
| Password change | Irrelevant — session invalidation logs the user out. The cache miss on their next login is one-off. |
| Permission revocation (direct on user model) | Model events clear on save. |
| Permission revocation (via pivot table / bulk query) | Model events don't fire. Either call `Auth::clearUserCache($id)` explicitly, or accept the TTL staleness window. |
| High-security providers (financial/admin) | Use a tight L1 TTL (1–2s), skip the L1 tier, or disable caching entirely for that provider. |

### Store selection guide

| Store | Multi-node | Notes |
|---|---|---|
| `redis` | ✓ | Standard choice. Shared invalidation, fast, well-understood. |
| `database` | ✓ | Shared. Slower than Redis but still a major win over per-request hydration, especially with in-memory/unlogged Postgres tables. |
| `file` | ✗ | Node-local. Single-instance deployments only. |
| `swoole` | ✗ | Node-local, shared memory. Fastest single-node option; also the ideal L1 tier inside a `stack`. |
| `stack` | partial | Eventually consistent if a node-local tier (swoole/file) is layered above a shared tier (redis/database). See "Invalidation model" above. |

Rejected drivers (throw on `enableCache`):

- `session` — scoped to the current user's session; would cache user data inside one user's session.
- `array` — coroutine-local after the upcoming rewrite; nothing persists across requests.
- `null` — discards writes.
- `failover` — ambiguous fallback semantics; silently degrades onto an unsafe tier when the primary is down.

Stack composition caveat: only the outer store is validated. A stack built with an unsupported inner tier (e.g. `[array, redis]`) won't be caught — pick sensible tiers yourself.

### Tenant-aware cache keys

Default cache key format is `{prefix}:{fqcn}:{identifier}` — e.g. `auth_users:App\Models\User:42`. The fully-qualified model class name is always included so providers using different user models never collide.

For multi-tenant apps where the same user ID resolves to different rows per tenant (tenant global scopes, shared user tables), register a global resolver in a service provider's `boot()`:

```php
use Hypervel\Auth\EloquentUserProvider;

public function boot(): void
{
    EloquentUserProvider::resolveUserCacheKeyUsing(
        fn (mixed $identifier) => tenantId() . ':' . $identifier,
    );
}
```

Produces keys like `auth_users:App\Models\User:5:42` (prefix, FQCN, tenant 5, user 42).

**Why a static callback, not a config closure?** Config files are evaluated once at boot in Swoole. A closure calling `tenantId()` in the config would capture the boot-time tenant (likely null), not the per-request tenant. The static resolver callback runs fresh on each `retrieveById()`, reading the current coroutine's context.

### Dynamic tag resolvers

Static tags in `config/auth.php` apply provider-wide — every cached user gets the same set. For per-request scoping (e.g. tagging each cached user with their tenant so the app can flush "all users for tenant 5"), register a global resolver alongside the static config:

```php
use Hypervel\Auth\EloquentUserProvider;

public function boot(): void
{
    EloquentUserProvider::resolveUserCacheTagsUsing(
        fn () => ['tenant:' . tenantId()],
    );
}
```

The resolver returns a list of tag names and runs fresh on every cache write, reading the current coroutine's context. Effective tags applied to each write are the union of the static config tags and the resolver's return value:

```
static ['auth_users']  +  resolver → ['tenant:5']  =  write tagged ['auth_users', 'tenant:5']
```

Apps can then flush broadly or narrowly depending on which tag they target:

```php
Cache::store('auth')->tags(['auth_users'])->flush();  // every cached user
Cache::store('auth')->tags(['tenant:5'])->flush();    // just tenant 5's users
```

**Static tags are the feature gate.** If no static tags are configured, the resolver is ignored and writes go through the untagged cache. Apps that want dynamic tagging must also configure at least one static tag (typically a provider-level grouping tag like `['auth_users']`).

### Gotchas

- **`withQuery()` caches the first-seen shape.** If the provider has a `withQuery()` callback that eager-loads relations, the first uncached call caches the result including those relations. Every subsequent hit returns the same loaded relations. This is usually what you want for auth.
- **Bulk updates bypass Eloquent events.** `User::query()->update([...])`, raw `DB::update(...)`, pivot inserts/deletes via `attach/detach` — none of these fire model events. Use `Auth::clearUserCache($id)` after such writes or accept TTL staleness.
- **The whitelist only checks the outer store.** `stack = [array, redis]` passes the check because the outer class is `StackStore`. Responsibility for sensible tier selection is yours.

### Threat model

For auth-sensitive contexts (admin panels, financial actions), consider:

- Shorter L1 TTL (1–2s) — still absorbs bursts, narrower staleness window
- Skip L1 entirely — use plain `redis` instead of `stack`
- Disable caching for that provider — set `enabled => false` for the specific guard's provider

Password changes and session revocation are not staleness-sensitive — session invalidation already logs the user out, so the auth cache's state becomes moot on the user's next request.