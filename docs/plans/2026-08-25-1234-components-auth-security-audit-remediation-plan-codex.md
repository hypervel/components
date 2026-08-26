# Auth and Security Audit Remediation Plan

Status: Complete

## Objective

Resolve audit findings 57–70 across Sanctum, Fortify, Socialite, JWT Auth, and Passkeys, together with the same root defects in `hypervel/auth`. Preserve current Laravel APIs and protected extension points unless the approved greenfield policy calls for removing legacy compatibility baggage. Optimize for Hypervel's long-lived, concurrent Swoole runtime without adding speculative machinery.

The completed code should have these properties:

- shared cache hits remain one ordinary cache read with no lock, database query, tag resolution, or coroutine-state check;
- cache fills and exact invalidations cannot reorder a committed revocation into a stale positive entry during the normal lock lease;
- transaction rollback never invalidates committed shared state;
- guard overrides are explicit per-execution state, independent of request token input;
- request-dependent Socialite redirects are never frozen into worker-lifetime providers;
- security-sensitive limiter, JWT, and passkey behavior fails closed through existing framework APIs;
- no aliases, registries, retries, background renewal, cache topology classifiers, or configuration options are introduced without a verified need.

## Scope and evidence

### Included findings

| Package | Findings | Result |
|---|---:|---|
| Sanctum | 57–58 | Race-safe token and tokenable caching; correct explicit guard-user semantics. |
| Fortify | 59–61 | Account-scoped two-factor throttling; safe empty recovery-code state; forced two-factor rotation resets confirmation. |
| Socialite | 62–65 | Execution-scoped redirect resolution; JWKS default algorithm support; remove the legacy X configuration alias only. |
| JWT Auth | 66–68 and same root as 58 | Correct explicit-user, refresh, logout, and invalidation state transitions; secret generation leaves the selected algorithm alone. |
| Passkeys | 69–70 | Owner-scoped route binding; integrated and standalone deletion routes receive their configured passkey throttle. |
| Auth | Same roots as 57–58 | Race-safe `EloquentUserProvider` fills and token-independent stateless-guard `setUser()` semantics. |

No `docs/todo.md` item matches these packages. Nothing discovered in this focused investigation is deferred.

### References checked

- Current Hypervel source, package READMEs, user documentation, and existing tests under `src/{auth,cache,sanctum,fortify,socialite,jwt,passkeys}` and their matching `tests/` directories.
- Local current Laravel sources under `examples/laravel/framework`, `examples/laravel/sanctum`, `examples/laravel/fortify`, and `examples/laravel/socialite` for public API, protected-extension, route, guard, and package semantics.
- Existing Hypervel cache lock contracts and implementations, transaction `afterCommit` behavior, Any-mode tags, coroutine context conventions, and HTTP authentication-context synchronization.

### Findings corrected during investigation

- Finding 58 is not an `actingAs` API gap. It is incorrect `Guard::setUser()` semantics: the explicit user is currently stored under whichever token happens to be in the request.
- Finding 59 does not apply to normal login. Fortify already provides an account-keyed fallback login limiter when `fortify.limiters.login` is null. Only the two-factor challenge falls back to IP-only generic throttle behavior.
- Finding 65 is not a missing driver. Hypervel already has the one modern OAuth 2 `x` driver; only the legacy `services.x-oauth-2` configuration fallback must be removed.
- Finding 69 must use the existing route binder. It already honors `Passkeys::usePasskeyModel()`; changing the controller parameter type would weaken the current extension surface.

## Architectural decisions

### Shared model-cache coordination

Add `Hypervel\Cache\ModelCacheCoordinator` beside `ModelCacheStoreValidator`. It is a stateless internal framework service with exactly two public operations:

```php
public function fill(
    CacheRepository $cache,
    string $key,
    int $ttl,
    Closure $read,
    bool $cacheNull = true,
    ?Closure $writeCache = null,
): mixed;

public function invalidate(CacheRepository $cache, string $key): void;
```

`fill()` follows this protocol:

1. Read the plain repository once. Return immediately when a presence envelope exists.
2. Only after a miss, require the repository store to implement `LockProvider`; throw the existing `UnsupportedModelCacheStoreException` if it does not.
3. Attempt a non-blocking per-key lock with a 10-second lease.
4. When acquired, re-read the plain repository. This avoids a duplicate primary-database read when another fill completed before the waiter acquired the lock.
5. If still absent, invoke `$read`, which each caller supplies as a write-connection query.
6. Publish a small private array presence envelope when the value is non-null or `$cacheNull` is true. Resolve optional `$writeCache` only at this point; otherwise write through `$cache`.
7. Run the critical section through `Lock::get()` so the existing lock implementation releases ownership on both success and failure while preserving the callback exception as primary.
8. When the lock is not acquired, invoke `$read` and return its result without publishing. This preserves availability without creating an unordered writer.

The implementation must use an explicit `$acquired` flag because `Lock::get()` returns `false` both when acquisition fails and when the callback legitimately returns `false`.

`invalidate()` acquires the same per-key lock with `Lock::block(11, ...)`, forgets the exact key, and lets `LockTimeoutException` propagate. Configure that lock to retry every 25 milliseconds through `betweenBlockedAttemptsSleepFor()`: the protected fill is one cache read, one indexed database read, and one cache write, so the general 250-millisecond lock cadence can add avoidable request latency after a brief collision. Eleven seconds is deliberate: `block()` throws when elapsed time reaches `waitMs - sleepMs`, and the shorter cadence still leaves more than the full 10-second lease before timeout. Invalidation must never fall back to an unlocked forget because that recreates the fill-vs-mutation race.

The lock key is separate from the data key and bounded:

```php
private function lockKey(string $key): string
{
    return 'model-cache:lock:' . hash('xxh128', $key);
}
```

This prevents a lock record from colliding with the cached value on stores that share one namespace, and prevents long model/key-resolver output from exceeding backend key limits.

The presence envelope must be an array, not an object, for the same reason as `NullSentinel`: restrictive `unserialize(allowed_classes)` policies do not alter arrays. Its marker is package-private and recognition is strict. Do not expose a new cache contract or public envelope type.

The honest concurrency bound is retained in the plan and tests: if a worker pauses for longer than the 10-second lease between its source read and publication, it can publish after a later invalidation. Closing that pathological pause requires lease renewal or fencing across every store, adding materially more machinery than the one indexed read and one cache put being protected. The normal race is fully ordered, and any overrun remains bounded by the configured cache TTL. Do not expose lock timing as user configuration or add a background renewal loop. A genuinely stuck lease may cause more acquisition attempts at the 25-millisecond cadence, but that exceptional cost is preferable to a routine 250-millisecond overshoot after a short fill.

### Supported stores

Tighten `ModelCacheStoreValidator::validate()` itself:

- accept only the known model-safe Redis, database, file, and Swoole store families, whose model serialization is verified and which all provide `LockProvider`; every other store, including `StorageStore`, is rejected by omission, and the coordinator retains its direct capability check at the use boundary;
- reject every `StackStore`, even though its bottom layer provides locks, because upper-layer values in other workers or nodes cannot be synchronously invalidated;
- delete recursive stack-layer validation, layer-path parameters, and `stackLocation()` as dead code;
- preserve Any-mode tag validation for the auth user cache.

The exception must name the real failure: a cache stack can retain an upper-layer identity copy in another worker or node after revocation. Do not build a request-scoped-layer classifier; rejecting stacks is simpler, deterministic, and honest.

Standalone file and Swoole stores remain valid for a single shared scope. User documentation must state that multi-node deployments need a selected store and lock namespace visible to every application node.

### Transaction visibility

The master plan's cross-cutting cache decision is corrected before implementation:

> Shared cache publication follows database transaction visibility. Committed mutations invalidate affected shared entries after commit, and rollback never invalidates them. Where a package must read its own uncommitted cache-affecting writes, that execution bypasses the affected shared entries while its transaction is dirty. Where fills can race with committed mutations, coordinate cache misses and exact invalidations with per-identity locks unless an existing atomic primitive provably orders every competing writer. Cache hits remain lock-free.

Auth and Sanctum deliberately cache committed shared state. They do not add dirty-key coroutine state to provide read-your-own-uncommitted-writes inside the same transaction that mutates an identity model. Normal authentication does not perform that sequence, and supporting it would add savepoint/rollback state and a check to every cache hit. Code inside such a transaction should use its model or query result directly. Permission caching retains its separate dirty bypass because grant/revoke-then-check is a normal package operation.

Use `Connection::afterCommitOrNow()` for this settlement rule. It delegates to the transaction manager when present, executes immediately only when no manager and no transaction exist, and remains fail-closed when an active transaction has no manager. Auth and Sanctum must not keep package-local copies of that database behavior.

### Performance budget

| Path | Required cost after the change |
|---|---|
| Model cache hit | One plain `get`; no lock, query, dynamic tag resolver, or extra context access. |
| Cold fill, lock won | Initial `get`, lock acquire, in-lock `get`, one primary read, one cache publication, one lock release. An untagged writer performs one ordinary put; a tagged auth writer also maintains its tag indexes within the tagged put. |
| Cold fill, lock lost | Initial `get`, lock attempt, one primary read, no write. |
| Committed invalidation | One blocking lock acquisition, one exact forget, and one lock release per affected key. |
| Rolled-back mutation | No cache operation. |
| Tokenable user save/delete | One identity-key invalidation regardless of how many tokens the user owns. |

Add operation-count assertions so later refactors cannot move locks, tag resolution, or database reads onto the hit path. Keep repository-call and backend-command assertions distinct: standalone Redis can maintain Any-mode tag indexes within one Lua round trip, while Redis Cluster uses the tagged operation's required multi-command path.

## Implementation

### 1. Cache package: coordinator and validator

Create `src/cache/src/ModelCacheCoordinator.php` using the protocol above. Keep helper methods private and limited to envelope recognition/wrapping, store narrowing, and lock-key generation. The class must not retain repositories, values, closures, or keys on the worker-lifetime auto-singleton.

Update `ModelCacheStoreValidator` to enforce the model-safe locking-store whitelist and reject stacks directly. Preserve Redis serializer validation, but remove the redundant post-whitelist lock check and stack-location plumbing that no longer have a consumer.

Add `tests/Cache/ModelCacheCoordinatorTest.php` covering:

- hot positive and cached-null envelopes return without touching the lock provider or read callback;
- a cold fill double-checks after acquiring and avoids the source read when another fill already published;
- positive and null source results obey `$cacheNull`;
- the lazy writer is never resolved on a hit or non-publishing lock-loss path and is resolved once on publication;
- false callback results remain distinguishable from failed lock acquisition;
- lock-loss reads but never publishes;
- exceptions release an acquired lock and propagate;
- invalidation blocks, forgets under the lock, and propagates timeout/failure;
- invalidation retries a brief lock collision after 25 milliseconds through the real `Lock::block()` loop;
- fill and invalidation share the same bounded lock identity while data and lock keys differ;
- one real `WorkerArrayStore` coroutine interleaving proves publication occurs while the shared lock is held, invalidation contends for that lock, and the final operation order is publish then forget;
- a non-locking store throws `UnsupportedModelCacheStoreException` only after a cache miss.

Update `tests/Cache/ModelCacheStoreValidatorTest.php` for the exact accepted/rejected matrix and simpler stack failure message. Delete tests that exist only for recursive stack-layer paths.

### 2. Auth: Eloquent user-cache races

Change `EloquentUserProvider::retrieveById()` to call `ModelCacheCoordinator::fill()`:

- use the provider's plain `$cache` for hit reads, double-checks, and locking;
- build the key once;
- query through a new focused write-connection fetch method so a fill after invalidation never republishes a replica-lagged user; leave uncached `retrieveById()` on its current read-routing path rather than moving every authentication lookup to the primary;
- pass `fn () => $this->resolveWriteCache()` as the lazy writer so Any-mode tags are applied only when a value is actually published;
- continue caching missing users through the presence envelope.

Change exact manual and model-event invalidation to `ModelCacheCoordinator::invalidate()`. `clearUserCache()` settles through `Connection::afterCommitOrNow()` on `$this->createModel()->getConnection()` before invalidating, so a documented raw or quiet write inside a transaction cannot forget early and let a concurrent fill republish pre-commit state. Saved and deleted events continue to schedule invalidation after the mutated model's outer commit and do nothing on rollback. Keep the current descriptor registry and dynamic key/tag semantics: it prevents provider references from accumulating across `forgetGuards()` cycles and lets event listeners rebuild every configured store/prefix key.

Build the identifier lookup once in a protected `newUserByIdQuery()` method. The ordinary path executes it through normal read routing, while the cache-fill path adds `useWritePdo()` before `first()`. This keeps the existing protected fetch signatures while preventing the two security-relevant lookups from drifting.

Key each descriptor by `serialize([$storeName, $prefix])`. The model class already owns the outer registry key, and the bounded in-memory pair needs neither a delimiter encoding nor a hash.

Do not coordinate bulk store or tag flushes. They do not identify a finite key set that can share the fill locks, and adding a key registry or global lock would be disproportionate machinery. Document that exact `clearUserCache()` invalidation follows its model connection's transaction, while bulk flush remains immediate.

Do not change identifier normalization. The default resolver already casts both lookup and model-event identifiers to string. A custom resolver owns its own type semantics.

Update unit and integration coverage in the existing auth cache files:

- deterministic fill-vs-save and fill-vs-delete barriers prove stale data cannot be republished after committed invalidation;
- source fill uses the write PDO;
- rollback performs no invalidation and commit performs exactly one per descriptor;
- `clearUserCache()` called inside a transaction waits for outer commit and does nothing on rollback;
- missing users are cached;
- multiple descriptors remain independent and duplicate descriptors collapse;
- real Any-mode tagged Redis fills and invalidations remain readable from the plain repository;
- the dynamic tag resolver is not called on cache hits;
- Swoole guard recreation does not retain provider instances or duplicate listeners;
- Stack, Storage, non-locking custom, and unsafe serializer stores fail during provider validation.

### 3. Sanctum: race-safe token and tokenable cache

#### Token entries

Replace `rememberNullable()` in `PersonalAccessToken::findTokenUsingCache()` with coordinator `fill(cacheNull: true)`. The source query uses the model's write connection and keeps `tokenable` unloaded. A missing token remains cached for the configured TTL to protect public endpoints from repeated unknown/revoked IDs.

Keep only the token model's existing `created`, `updated`, and `deleted` listeners, and make them invalidate only the token entry after commit. Those three listeners already cover restore and force-delete: restore calls `save()` and force-delete passes through ordinary `delete()`. Do not register `restored` or `forceDeleted` listeners that would take the same lock and forget twice. Remove mutation snapshot publication entirely. Remove the `last_used_at` special case and `wasOnlyLastUsedAtChanged()`; `updateLastUsedAt()` performs its existing throttled database write and lets the ordinary updated listener invalidate once after commit.

The request after a `last_used_at` write refills the invalidated token entry once, while the owner-keyed tokenable entry remains hot; subsequent requests stay hot until the next mutation or expiry. This costs at most one additional indexed token read per configured update interval. Do not skip invalidation for a last-used-only change: without publishing a refreshed snapshot, each request would deserialize the old timestamp and repeat the audit write for the full cache TTL. Publishing the mutation snapshot recreates the stale-writer race, while an authoritative locked re-read performs the same extra database read plus another cache write.

Build `HasApiTokens::newTokenRelation()` from a fresh configured token model rather than `newRelatedInstance()`. Eloquent's generic helper copies an owner's connection onto an otherwise unconfigured related model, while token authentication later queries through the configured token model class. That can write tokens to one database and look them up in another. The Sanctum relation must consistently use the token model's connection for reads, writes, authentication, and cache settlement; a token model with dynamic tenant-aware connection resolution continues to provide that connection itself.

`PersonalAccessTokenRelation::delete()` fixes the matching token IDs before deletion as it does today, then calls `clearTokenCache()` for each ID. The clear method owns transaction settlement on the same token-model connection, so the relation's duplicate settlement helper is removed. Multiple selected IDs register independent callbacks on the same transaction; commit invalidates each entry and rollback discards them all. It must not invalidate tokenable identity keys because deleting or moving a token does not mutate the owning user model.

Keep `clearTokenCache(int|string $tokenId)` as the explicit token-entry escape hatch, but narrow its behavior and documentation to that entry only. It settles through `Connection::afterCommitOrNow()` on the configured token model's connection, then calls `ModelCacheCoordinator::invalidate()` rather than performing a bare forget, so a documented raw-SQL/quiet-mutation cleanup cannot race with a fill and be overwritten or invalidate before that connection commits.

#### Tokenable entries

Re-key positive tokenable entries by morph identity rather than token ID. This removes N duplicate user objects for N tokens and makes user-model invalidation O(1).

Canonicalize the identity before hashing:

```php
$id = (string) $morphId;
$identity = strlen($morphType) . ':' . $morphType
    . '|' . strlen($id) . ':' . $id;

$key = $prefix . ':tokenable:' . hash('xxh128', $identity);
```

The length prefix avoids delimiter ambiguity. Casting the ID to string is required because PostgreSQL can hydrate the token row's morph ID as a string while the owner model key is cast to an integer. Morph types remain distinct; integer `5` and string `'5'` intentionally identify the same persisted key. The hash bounds long custom morph values.

Use coordinator `fill(cacheNull: false)` for tokenable lookup. Resolve the source explicitly through `$accessToken->tokenable()->useWritePdo()->getResults()`; the current `getAttribute('tokenable')` lazy-loading path provides no place to select the write connection. Missing tokenables remain uncached because global scopes and request-specific query context can make absence execution-dependent. The returned relation is still attached to the access-token instance once resolved.

Add `HasApiTokens::bootHasApiTokens()` with `saved` and `deleted` listeners. Each listener checks `sanctum.cache.enabled` when the event runs, resolves `Sanctum::personalAccessTokenModel()`, and invokes that model's new `clearTokenableCache(Model $tokenable)`. That method derives the morph identity and settles invalidation through `Connection::afterCommitOrNow()` on `$tokenable->getConnection()`, never the personal-access-token connection, before acquiring the coordinator lock. This is required when owners and tokens use different connections. `restored` is unnecessary because soft-delete restoration runs `save`; normal, soft, force deletes all dispatch `deleted`. Avoid capturing config at trait boot because workers may reboot configuration in tests and supported boot flows.

The explicit tokenable invalidation method accepts the model rather than exposed morph fragments, preventing callers from producing a key with inconsistent type normalization and providing the owning connection for transaction settlement. It calls `ModelCacheCoordinator::invalidate()` under the same identity lock as tokenable fills. It remains a Hypervel-specific API, with no requirement to preserve its pre-0.4 behavior.

Leave `sanctum:prune-expired` unchanged. It uses mass deletes and therefore fires no model events, but every selected token is already expired and a surviving cache snapshot still fails `SanctumGuard::isValidAccessToken()` before authentication. Preserve the current documentation rather than adding a token-ID query and N locked invalidations that provide no security benefit.

An orphaned live token whose owner remains invisible performs one primary tokenable read per authentication attempt. Preserve that correctness/performance tradeoff rather than negative-caching a query-context-dependent absence. Normal application rate limiting bounds hostile retries.

#### Sanctum tests

Extend `PersonalAccessTokenCacheTest`, `HasApiTokensTest`, and guard/integration coverage for:

- deterministic token fill racing with delete/update/last-used mutation and tokenable fill racing with owner save/delete;
- deterministic token and tokenable fill races against the two explicit clear APIs;
- a committed revocation cannot be followed by a positive cache resurrection;
- rollback leaves the old committed entry available; outer commit invalidates once;
- token and tokenable explicit clears inside transactions wait for their respective outer commits and do nothing on rollback;
- positive and negative token envelopes, while tokenable misses remain uncached;
- two tokens for one owner share one tokenable cache entry;
- token deletes, ownership changes, and relation bulk deletes invalidate token keys only;
- owner save, ordinary delete, soft delete, restore, and force delete invalidate one tokenable identity key;
- integer, string, UUID, ULID, long, and delimiter-containing morph identities; use a dedicated UUID/ULID test schema because Sanctum's shipped migration uses unsigned-bigint morphs;
- custom morph maps and custom tokenable model connections;
- a tokenable on a second connection invalidates only after that connection commits, independent of the token model's connection state;
- token creation and relation deletion use the configured token model's connection even when the owner model selects another connection, and static token authentication reads the row that creation wrote;
- both token and tokenable source fills use their write connections;
- exact cold-fill/mutation/hit cache and query operation counts;
- unsupported stores fail at boot; multi-node documentation requires a shared store.

### 4. Stateless guards: explicit users and request-local resolution

Add a dedicated explicit-user CoroutineContext key per guard to `TokenGuard`, `SanctumGuard`, `RequestGuard`, and `JwtGuard`.

- `user()` checks the explicit key before inspecting request input, bearer tokens, or session guards.
- `hasUser()` checks the explicit key before token-derived resolution state.
- `setUser()` writes only the explicit key; it must not depend on the current request token.
- internal successful token resolution caches under the token-specific key without accidentally turning it into an explicit override.
- `forgetUser()` clears the explicit override and the relevant token/default resolved state so normal request authentication can run again.
- `getAuthContextKeys()` returns only the explicit key. The HTTP testing bridge synchronizes state that must survive a request boundary, never transient request-resolution caches. Add the method where absent.

Rename `RequestGuard`'s internal resolver cache key to `__auth.guards.{name}.user.resolved`; its explicit key is the parallel `.user.explicit`. Request-driven `forgetUser()` must remove the parent test coroutine's explicit override, while resolver callbacks run once in every simulated request.

`JwtGuard` needs three additional rules:

- internal token resolution uses `cacheUser()` and then fires `Authenticated`; it must not call public `setUser()`;
- `cachedUser()` is explicit-first for `getUserId()`, `hasUser()`, `logout()`, and normal guard semantics;
- `login()` clears any prior explicit override, caches the login user under the newly minted token, and fires `Login` before `Authenticated`, matching Laravel's event order.

`refresh()` moves only token-bound state. Read the old token's exact user key, clear that key and payload in `finally`, and cache an authenticatable result under the new token after success. Never clear, consume, or downgrade the explicit override: it must survive successful and failed refreshes unchanged. `once()` and `onceUsingId()` continue using public `setUser()`; their explicit assignment matches Laravel guard-instance semantics in tests.

A request-driven JWT `login()` is deliberately not synchronized to the parent test coroutine. JWT is stateless: the response returns a token and later requests authenticate only when they present it. Request-driven `forgetUser()` still clears an existing explicit parent override.

Keep keys per guard and coroutine. Do not add a public `actingAs()` method; existing framework test helpers correctly use `setUser()`.

Tests cover an explicit user with an unrelated bearer/query token, restoration of normal token auth after `forgetUser()`, per-guard and sibling-coroutine isolation, null/default request state, `hasUser()`, and real HTTP auth-context synchronization. Each stateless guard gets a real two-request bridge regression proving transient resolution runs once per request and explicit state survives or is cleared as directed. Sanctum also proves `assertAuthenticated()` re-resolves from the completed bearer request. JWT covers internal resolution provenance, login event order, login superseding an explicit user, and explicit state surviving refresh failure.

### 5. Fortify

#### Finding 59: two-factor limiter

Register the package-owned named limiter `two-factor` in `FortifyServiceProvider::boot()` through the existing rate-limiter API. Set `fortify.limiters.two-factor` to `two-factor` in both package config and the published stub. This matches Laravel's published Fortify provider/config convention, so existing Laravel-shaped application registration naturally overrides the package default. The route keeps accepting another limiter name or throttle string from configuration.

The limiter allows five attempts per minute and keys by:

1. challenged guard name plus challenged account ID when both values are valid in the required login session state;
2. session ID when either identity component is unexpectedly absent.

Do not include IP. The challenge requires a session, and IP keying lets one attacker distribute attempts across addresses or one shared address couple unrelated accounts. Do not guard registration with “if absent”: application providers boot later and `RateLimiter::for()` naturally overwrites the package default.

Tests prove the same challenged account shares a limit across IPs, distinct accounts behind one IP do not, different guards do not collide, missing identity falls back per session, the package default is replaceable by a later application registration, and normal login limiting is unchanged.

#### Finding 60: absent recovery codes

Make `TwoFactorAuthenticatable::recoveryCodes()` return `[]` when the encrypted attribute is null or empty. Keep malformed non-empty ciphertext and non-array decoded JSON loud. This turns two-factor disablement between login and challenge into ordinary invalid challenge behavior without hiding data corruption.

Tests cover null, empty string, encrypted empty array, locked refetch, malformed ciphertext, and non-array JSON.

#### Finding 61: forced rotation

When `EnableTwoFactorAuthentication` rotates a secret because `$force` is true, clear `two_factor_confirmed_at` in the same `forceFill(...)->save()` when confirmation is enabled or the stored timestamp is non-null. Follow the existing `DisableTwoFactorAuthentication` confirmation idiom so disabling and later re-enabling confirmation cannot make a new secret inherit an old confirmation; do not add another branch or write.

Tests cover confirmed forced rotation, ordinary non-force enable, force with confirmation disabled, event behavior, and one atomic save payload.

Update Fortify user documentation and configuration comments so the named default limiter and account-scoped behavior are clear. These are security/correctness changes, not porting-guide entries.

### 6. Socialite

#### Findings 62–63: deferred redirect resolution

Keep the raw `Closure|string` redirect in the worker-cached provider baseline. Widen `Two\AbstractProvider`'s constructor property to `Closure|string`; string callers and named arguments remain compatible. Keep public `redirectUrl(string $url)` unchanged.

Add a fluent `withRedirectFormatter(Closure $formatter): static` method and a nullable formatter property to `Two\AbstractProvider`. Its public docblock must begin with `Boot-only.` and explain that the formatter persists on the worker-cached provider and affects subsequent requests; per-request redirect changes continue to use `setConfig()` or `redirectUrl()`. `SocialiteManager::buildOAuth2Provider()` chains that setter beside `withConfig()` with a closure that calls the manager's protected `formatRedirectUrl()` using the current config. This preserves the protected extension point for built-in and documented custom drivers even when a custom provider declares its own five-parameter constructor; do not pass a sixth constructor argument that PHP could silently discard. Keep widening the existing `$redirectUrl` constructor property to `Closure|string`, which preserves all string callers and named arguments.

`getRedirectUrl()`:

- returns an execution override when already memoized;
- otherwise constructs the current effective config with the raw redirect, calls the formatter when present, or evaluates a direct-provider Closure with `value()` and returns direct strings unchanged;
- memoizes the resolved string in this provider instance's CoroutineContext namespace for the current execution.

`setConfig()` stores raw `redirect` values and clears only the current execution's resolved redirect. `redirectUrl()` stores the literal override and clears/replaces the memo consistently. Base closures and relative paths are therefore evaluated against the current request once per execution, never at manager construction.

Do not add a resolver registry or mutate the worker-lifetime baseline after construction.

Tests cover absolute, relative, and Closure redirects through manager config, direct providers, `setConfig`, and `redirectUrl`; formatter overrides; one evaluation per execution; invalidation after an execution override; concurrent host/tenant isolation; and callback-request reuse.

#### Finding 64: JWKS default algorithm

Add `@phpstan-require-extends \Hypervel\Socialite\Two\AbstractProvider` to `InteractsWithJwks`, accurately limiting the concern to OAuth 2 providers, then read `id_token_alg` from provider configuration with default `RS256`. Pass it as the second argument to `JWK::parseKeySet()`.

Key both `$jwks` and `$jwksRefreshAttempt` identities by URL and algorithm. A configuration override that changes the algorithm must not reuse parsed keys or a refresh cooldown from the old algorithm. Keep OpenID discovery cache identity URL-only because the discovery document itself is not algorithm-specific. Let `firebase/php-jwt` validate supported algorithms, key compatibility, and signatures; do not maintain a second whitelist.

Tests cover keys with and without `alg`, the RS256 default, configured overrides, URL-stable algorithm changes, cache reuse only for matching identity, refresh cooldown isolation, key rotation, and library rejection of mismatches/unsupported algorithms. Document `services.{provider}.id_token_alg` for OpenID Connect providers.

#### Finding 65: modern X config only

Change `createXDriver()` to read only `services.x`. Keep the `x` OAuth 2 driver and the existing omission of OAuth 1/Twitter. Do not add `x-oauth-2` or `twitter` driver aliases.

Tests prove canonical configuration works, legacy-only configuration produces the normal missing-config error, and legacy driver names remain unsupported. Update the concise Socialite documentation and add one action-oriented entry to `porting-from-laravel.md`, because Laravel porters must rename this legacy configuration key. No other finding in this slice belongs in the porting guide.

### 7. JWT Auth

#### Findings 66–67: logout and invalidation

`JwtGuard::logout()` must always call manager invalidation when a token exists. Remove the `hasBlacklistEnabled()` guard. When the blacklist is disabled, the manager's existing exception must propagate before any user/token/payload context is cleared or a Logout event is fired. Logout without a token remains harmless and still clears local state consistently.

After `JwtGuard::invalidate()` succeeds:

- forget the cached user and decoded payload for that exact token;
- retain token identity in context, so subsequent decode/user access observes the blacklist and grace-period rules through `JwtManager`;
- leave sibling token entries untouched.

If manager invalidation throws, context remains unchanged. Tests cover enabled/disabled blacklist, no token, failure ordering, Logout event timing, immediate same-execution user/payload checks, grace period, and sibling tokens.

#### Finding 68: secret command

Make `jwt:secret` write only `JWT_SECRET`. Never create or overwrite `JWT_ALGO`; shipped config already defaults to HS256, while applications may deliberately select an asymmetric algorithm. Tests cover missing algorithm, existing HS/RS/ES algorithm values, force and confirmation paths, show mode, and secret replacement.

Update JWT docs only if current command prose incorrectly claims it writes the algorithm. No porting entry is needed.

### 8. Passkeys

#### Finding 69: owner-scoped binding

Update the existing global `passkey` binder in `PasskeysServiceProvider`:

1. resolve `AuthFactory` from the provider container and obtain the selected guard;
2. if the guard is not `StatefulGuard`, or it has no authenticated `PasskeyUser`, throw `ModelNotFoundException` for the configured passkey model and route value;
3. build the user's passkey relation once;
4. call `resolveRouteBindingQuery()` on that relation's configured related model, passing the relation and route value, then call `firstOrFail()`.

Do not catch broad runtime exceptions: invalid guard configuration and construction errors are server errors, not 404s. Hypervel's middleware priority already runs `UseGuard` and authentication before `SubstituteBindings`, so the selected guard and owner exist when route binding runs.

This preserves custom model classes, route keys, morph ownership, connections, scopes, and the existing controller signature without resolving a second model. Foreign, nonexistent, and unauthenticated passkey identifiers become indistinguishable 404s before `DeletePasskey` runs. The binder is global, so every application route parameter named `{passkey}` is owner-scoped; administrative cross-user routes should use a different parameter name and query explicitly. Document that deliberate security invariant in the Passkeys section of `fortify.md`, not in the Laravel porting guide.

Build `PasskeyAuthenticatable::passkeys()` from a fresh configured passkey model and `newMorphMany()` rather than the generic `morphMany()` helper. The generic helper copies the owner's connection onto a related model that has no explicit connection, while registration, verification, row locking, and pruning otherwise use the configured passkey model's connection. The relation must consistently use the passkey model's connection. Cover an owner on another connection registering a credential that the normal verification lookup can immediately read.

#### Finding 70: delete throttling

Apply the existing `$passkeyManageMiddleware` helper to the Fortify passkey destroy route. This gives it the same configured limiter as registration while continuing to omit throttling when `fortify.limiters.passkeys` is null.

Also apply standalone Passkeys' existing `$middleware(...$managementMiddleware)` helper to its destroy route instead of using bare `$managementMiddleware`. This gives standalone deletion the package's default/custom `passkeys.throttle` and continues to omit it when configured null. Do not duplicate middleware construction in either package.

Tests cover own/foreign/missing/unauthenticated binding, custom route keys and models, non-stateful/misconfigured guards, morph owners and connections, default/custom/null deletion throttle for both Fortify-integrated and standalone routes, and both route middleware snapshots.

## Documentation and cleanup

Update existing prose in place:

- `src/docs/sanctum.md`: supported stores, lock requirement, multi-node visibility, token/tokenable key ownership, model-event invalidation, positive-only tokenable misses, explicit clear APIs, transaction behavior, and invalidation lock failures surfacing after a committed write;
- `src/sanctum/README.md`: only deliberate lasting cache-surface differences, without duplicating the user guide;
- `src/docs/authentication.md`: cached user store constraints, committed-state behavior, transaction-aware exact invalidation, invalidation lock failures surfacing after a committed write, and immediate bulk-flush behavior where the existing cache section requires correction;
- `src/docs/fortify.md`: named account-scoped two-factor limiter, forced rotation confirmation, owner-scoped passkey binding, and passkey deletion throttling;
- `src/docs/socialite.md`: execution-time redirect values, `id_token_alg`, and canonical `services.x`;
- `src/docs/jwt.md`: only any stale command/logout wording;
- `src/docs/porting-from-laravel.md`: one concise `services.x-oauth-2` to `services.x` action.

Do not add README difference entries or porting-guide entries for race fixes, security corrections, performance work, internal coordinator details, or stricter invalid-input behavior.

After implementation and verification, remove findings 57–70 from the master remediation ledger. Keep the corrected cross-cutting decision. The focused plan becomes the durable implementation record; duplicate completed rows in the master list would be stale.

## Expected file map

The exact tests may be merged into existing files when that keeps ownership clearer, but production changes should remain within these boundaries:

- Cache: new `ModelCacheCoordinator`; simplify `ModelCacheStoreValidator`.
- Database: add the shared `Connection::afterCommitOrNow()` settlement primitive and focused contract tests.
- Support: expose the connection primitive in the existing `DB` facade method annotations.
- Auth: `EloquentUserProvider`, `TokenGuard`, `RequestGuard`, auth service/provider validation only if needed.
- Sanctum: `PersonalAccessToken`, `PersonalAccessTokenRelation`, `HasApiTokens`, `SanctumGuard`, service-provider validation.
- Fortify: service provider, config and stub, routes, `TwoFactorAuthenticatable`, `EnableTwoFactorAuthentication`.
- Socialite: manager, OAuth 2 abstract provider, JWKS concern.
- JWT: guard, HTTP guard-context coverage, and secret command.
- Passkeys: configured-model relation connection, service-provider binder, and standalone route throttle; Fortify owns the integrated route throttle.
- Docs and the two plan files listed above.

Do not add package dependencies: the owning packages already depend on cache, auth, database, or rate-limiter contracts required by this design.

## Verification

### Targeted cadence

Run each changed test file immediately after its corresponding source edit. At minimum, run focused suites for Cache, Auth cached providers and TokenGuard, Sanctum, Fortify, Socialite, JWT, and Passkeys before the full checkpoint.

Run Redis integration coverage for real shared locks and tagged auth-user writes. Run the applicable database matrix for transaction timing, PostgreSQL morph-ID type normalization, UUID/ULID owner identities, soft deletes, and route binding connections. Tests without configured external services must retain the repository's normal skip behavior.

Place driver-specific shared cases under `tests/Integration/Sanctum/Database/` and `tests/Integration/Auth/Database/`, with thin concrete suites in each package's `Database/{MariaDb,MySql,Postgres,Sqlite}/` directories. The database runner auto-discovers those exact driver directories; no workflow edit is needed. PostgreSQL must execute the morph-ID hydration/type-normalization proof, while MariaDB, MySQL, and SQLite exercise their own morph-column and transaction behavior. Keep shared assertions in one abstract package test case rather than duplicating them in the driver wrappers.

### Deterministic concurrency

Prove fill-vs-invalidation ordering once at `ModelCacheCoordinator`, the shared owning boundary. Use `WorkerArrayStore`, coroutine channels, and an instrumented real array lock to place execution at source-read, failed competing acquisition, locked publication, and invalidation boundaries. Assert that publication happens while the lock record exists, the invalidator observes contention, the operation order is publish then forget, and the final entry is absent. Package tests separately prove commit settlement, rollback behavior, connection selection, and operation counts; do not duplicate lock machinery in every consumer.

### Performance and resource checks

Measure or count rather than infer:

- one cache operation and no lock/query/tag callback on positive and negative hits;
- one shared tokenable object per owner identity rather than per token;
- no provider/model/key/closure retention added to worker-lifetime services;
- no additional coroutine state on model-cache hit paths;
- no extra Fortify/Socialite/JWT/Passkeys network or database work on unaffected paths;
- bounded lock and tokenable cache keys;
- all request-specific guard and redirect state disappears with CoroutineContext teardown.

### Final checks

1. Run `composer fix` once after all targeted suites are green.
2. Re-read every changed file and trace callers/callees, transaction boundaries, lock release, exception ordering, coroutine isolation, custom model/provider extension points, and docs against the final code.
3. Confirm no stale methods, stack-recursion helpers, last-used publisher branches, old cache-key wording, legacy X fallback, unused imports, or superseded plan rows remain.
4. Request full code review of the complete branch and resolve every confirmed finding before commit/PR handoff.

## Completion criteria

- Findings 57–70 and the same-root auth defects have regression tests and no duplicated master-plan rows.
- Cache-hit paths are lock-free and meet the operation-count budget.
- Supported cache fills read from the write connection and exact committed invalidations use the same identity lock.
- Stack and non-locking model-cache stores fail clearly during startup validation.
- Tokenable invalidation is O(1) per owner mutation and identity keys are type-stable across supported databases.
- Guard `setUser()` behavior is request-token independent and coroutine/guard isolated.
- Fortify, Socialite, JWT, and Passkeys retain modern Laravel-style public APIs and extension points.
- Only Laravel legacy baggage explicitly approved for removal is removed.
- Documentation is accurate, concise Laravel prose with no duplicate or internal-design exposition.
- No dead code, speculative mechanisms, compatibility workarounds, or meaningful performance/scalability regressions remain.
