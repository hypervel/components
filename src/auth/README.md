Auth for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/auth)

<!-- @TODO: Move to 0.4 documentation -->

## Differences From Laravel

- `Auth::routes()` is intentionally omitted because Hypervel does not integrate `laravel/ui`. Register authentication routes explicitly or use Fortify.
- Password brokers are guard-declared via `auth.guards.{guard}.passwords`; `auth.defaults.passwords` and `AUTH_PASSWORD_BROKER` do not exist, and bare `Password::` calls resolve through the current guard or throw.
- `auth.defaults.provider` does not exist; `getDefaultUserProvider()` returns the provider declared by the current default guard, and `createUserProvider(null)` means no provider.
- `guest:{guard}` selects the first named guard as the request's default guard on pass-through, mirroring how `auth:{guard}` selects on success.
- Password confirmation is guard-scoped (`auth.password_confirmed_at_{guard}`) with an optional per-guard `password_timeout`; `RequirePassword` resolves the guard and timeout at handle time.

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

Cache configuration is read during process startup and must not be changed while a worker is serving requests.

Enabled Eloquent provider models and Hypervel's standard Eloquent collection and pivot classes are added to the cache class policy automatically. Declare application-owned relations, custom collections or pivots, and other nested objects from a service provider:

```php
use App\Models\Organization;
use App\Models\Team;
use Hypervel\Support\Facades\Cache;

public function boot(): void
{
    Cache::allowSerializableClassesUsing(fn (): array => [
        Organization::class,
        Team::class,
    ]);
}
```

Providers constructed directly and not represented in `auth.providers` must also declare their root model. These declarations apply to PHP-policy serialization paths. Accepted native Redis serializers preserve model types but bypass the class policy. See [Serializable Cached Objects](https://hypervel.org/docs/cache#serializable-cached-objects) for denied nested-class behavior and remedies.

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

At high request volume, every authenticated request otherwise reads and hydrates the user from its provider.

The `stack = [swoole → redis]` topology can keep hot lookups in each node's Swoole table for a short period. Repeated requests for the same user can then skip the shared Redis round trip. This reduces shared-cache traffic and latency, with bounded L1 staleness as the trade-off.

Choose the L1 TTL from the application's consistency requirements and measure the real workload rather than assuming a fixed hit rate or latency.

### Invalidation model

Four layers, most-automatic to most-manual:

1. **Provider writes** — `updateRememberToken()` and `rehashPasswordIfRequired()` both call `$user->save()`, which fires the `saved` model event. Invalidation is handled by the listener (layer 2), not by an explicit clear inside those methods.

2. **Model events** — when caching is enabled for a provider, the provider registers `saved` and `deleted` listeners on the user model class. Any code path that modifies the user through Eloquent — `$user->save()`, `$user->update(...)`, `$user->delete()` — triggers cache invalidation. This covers controller updates, profile edits, admin changes.

3. **Manual** — for writes that bypass Eloquent events (pivot table changes for roles/permissions, raw DB queries, mass `update()`, external processes), clear explicitly via `Auth::clearUserCache(...)` — see "Manual invalidation API" below.

4. **TTL expiry** — even if active invalidation is missed, entries expire on their TTL and the next request fetches fresh data.

**Within a node:** `SwooleStore` uses a Swoole Table in shared memory, so one `forget()` from any worker clears it for every worker on that node.

**Across nodes:** only shared tiers propagate invalidation. If you use `stack = [swoole, redis]`, invalidation clears the origin node's L1 and shared Redis, while other nodes' Swoole L1s can serve their entry until its TTL expires. Applications that require strict global consistency should skip the node-local L1 tier.

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

| Setup | Behavior |
|---|---|
| One provider shared by multiple guards (e.g. `web`, `api`, `sanctum`, `jwt` all point at `users`) | One call with any of those guard names clears the single shared cache keyspace. Calling for each guard is redundant. |
| Different guards with different models (e.g. `web → User`, `admin → Admin`, `landlord → Landlord`) | You must call once per guard/model you want to invalidate. `Auth::clearUserCache(42)` with no guard name clears *only* the default guard's model — a landlord update that hits `Landlord:42` needs `Auth::clearUserCache(42, 'landlord')`. |
| Default guard omitted in a multi-guard setup | Clears for the default guard *only*, not all guards. In non-trivial deployments, always pass the guard name explicitly to avoid surprises. |

**Tenant-aware resolver interaction:**

If you've registered `EloquentUserProvider::resolveUserCacheKeyUsing(...)`, `clearUserCache()` passes a null user model to the same resolver — so it clears the entry for the **current** tenant context, not every tenant's copy. When a user is saved or deleted, automatic invalidation passes that model so the resolver can derive its owning tenant without ambient context. To clear the same user across multiple tenants manually, call `clearUserCache()` once per tenant context.

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

**Tag mode requirement.** The configured store must implement `TaggableStore` and be in `TagMode::Any` — Redis is the only stock driver that supports configurable tag modes, via its `tag_mode` config key. Tag validation runs when the user provider is created. All-mode is rejected because its tag-namespaced storage keys would force every read and forget to carry tag context, which doesn't fit the auth-cache access pattern.

For per-request tag scoping (e.g. tagging each cached user with their tenant), see **Dynamic tag resolvers** below.

### TTL guidance

| Scenario | Guidance |
|---|---|
| Profile updates (name, avatar, preferences) | Default 300s is fine. Model events clear on save. |
| Password or other direct model change | Eloquent model writes clear the cached user. Eventless writes require explicit invalidation or acceptance of the TTL bound. |
| Permission revocation (direct on user model) | Model events clear on save. |
| Permission revocation (via pivot table / bulk query) | Model events don't fire. Either call `Auth::clearUserCache($id)` explicitly, or accept the TTL staleness window. |
| High-security providers (financial/admin) | Use a tight L1 TTL (1–2s), skip the L1 tier, or disable caching entirely for that provider. |

### Store selection guide

| Store | Multi-node | Notes |
|---|---|---|
| `redis` | ✓ | Standard choice. Shared invalidation, fast, well-understood. |
| `database` | ✓ | Shared. Slower than Redis but still a major win over per-request hydration, especially with in-memory/unlogged Postgres tables. |
| `file` | ✗ | Node-local. Single-instance deployments only. |
| `storage` | depends | Shared only when the configured filesystem disk is shared by every node. |
| `swoole` | ✗ | Node-local, shared memory. Fastest single-node option; also the ideal L1 tier inside a `stack`. |
| `stack` | partial | Eventually consistent if a node-local tier (swoole/file) is layered above a shared tier (redis/database). See "Invalidation model" above. |

Rejected drivers (throw on `enableCache`):

- `session` — scoped to the current user's session; would cache user data inside one user's session.
- `array` — coroutine-local; nothing persists across requests.
- `worker-array` — worker-local copies diverge across workers and nodes.
- `null` — discards writes.
- `failover` — an unavailable primary can retain a stale identity and serve it after recovery.

Stack layers are validated recursively, including nested stacks.

For Redis, `SERIALIZER_NONE`, native PHP, and available igbinary serializers preserve model types. Msgpack is accepted only with `msgpack.php_only=1`. JSON, non-PHP msgpack, and unknown serializer modes are rejected because they can return arrays instead of models. Native serializers bypass `cache.serializable_classes`; use `SERIALIZER_NONE` when class-policy enforcement is required.

### Tenant-aware cache keys

Default cache key format is `{prefix}:{fqcn}:{identifier}` — e.g. `auth_users:App\Models\User:42`. The fully-qualified model class name is always included so providers using different user models never collide.

For multi-tenant apps where the same user ID resolves to different rows per tenant (tenant global scopes, shared user tables), register a global resolver in a service provider's `boot()`:

```php
use App\Models\User;
use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Database\Eloquent\Model;

public function boot(): void
{
    EloquentUserProvider::resolveUserCacheKeyUsing(
        function (mixed $identifier, string $model, ?Model $user): string {
            if (! is_a($model, User::class, true)) {
                return (string) $identifier;
            }

            $tenantId = $user?->tenant_id ?? tenant()->getKey();

            return $tenantId . ':' . $identifier;
        },
    );
}
```

Produces keys like `auth_users:App\Models\User:5:42` (prefix, FQCN, tenant 5, user 42).

This example partitions the tenant-owned `User` model while leaving other provider models unpartitioned. The resolver receives the identifier and provider model class. When a user is saved or deleted, automatic invalidation also provides that user model. Lookups and manual invalidation provide null. This lets tenant-aware providers use ambient context for lookups and the row's owner for event-driven invalidation.

**Why a static callback, not a config closure?** Configuration files should contain serializable values so they can be cached. Registering the callback during provider boot keeps configuration cacheable, while the resolver itself runs fresh on each lookup or invalidation.

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

- **`withQuery()` caches the first-seen shape.** If the provider eager-loads relations, the first uncached call stores that graph. Declare every application relation and custom container class in the cache class policy so later hits can restore the full shape.
- **Bulk updates bypass Eloquent events.** `User::query()->update([...])`, raw `DB::update(...)`, pivot inserts/deletes via `attach/detach` — none of these fire model events. Use `Auth::clearUserCache($id)` after such writes or accept TTL staleness.

### Threat model

Configured provider models and stock Eloquent graph containers are allowed automatically. Keep manual declarations limited to the application-owned classes the cached graph needs. Broad allowlists expand PHP's unserialization surface. Native Redis serializers bypass this policy.

For auth-sensitive contexts (admin panels, financial actions), consider:

- Shorter L1 TTL (1–2s) — still absorbs bursts, narrower staleness window
- Skip L1 entirely — use plain `redis` instead of `stack`
- Disable caching for that provider — set `enabled => false` for the specific guard's provider

Eloquent user model saves and deletes clear the cached entry. Writes that suppress or bypass model events require explicit invalidation or acceptance of the configured TTL bound.
