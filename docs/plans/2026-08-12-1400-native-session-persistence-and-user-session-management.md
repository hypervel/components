# Native Session Persistence and User Session Management Plan

## Plan status

This is the implementation plan for replacing the cache-backed Redis session driver with native session persistence and adding a driver-neutral API for listing and invalidating a user's sessions. It targets Hypervel `0.4` and deliberately optimizes the final codebase rather than preserving unreleased Hypervel-specific internals.

Implement the whole plan as one coherent change. Remove every superseded cache-backed path, configuration key, test, comment, and documentation statement. Do not leave compatibility aliases, deprecated wrappers, duplicate implementations, or TODOs for work defined here. The completed source should read as though database and Redis session management were designed together from the beginning.

This plan supersedes the cache-backed Redis decisions in `docs/plans/2026-07-27-0530-session-lifecycle-persistence-and-current-laravel-parity.md`; that completed plan remains useful history, but the current tree and this plan are authoritative.

## Desired outcome

- Session persistence does not depend on `hypervel/cache`. File, cookie, array, null, database, and Redis handlers own their storage directly.
- The Redis session hot path is native and minimal: one `GET`, `SETEX`, or `DEL` when tracking is disabled.
- Database and Redis expose one Laravel-style, capability-gated API for active user sessions.
- Managed ownership is qualified by the selected guard's auth provider, so unrelated providers may safely reuse scalar user IDs.
- Redis user-session tracking is opt-in so ordinary Redis sessions do not acquire a Redis 8 / Valkey 9 requirement.
- Each tracking-enabled standalone Redis handler mutation is atomic and uses one client round trip once the script is cached on that Redis node; a bulk repository call uses a preliminary ownership-scoped current-session mutation when needed. Healthy listing uses one `HGETALL` and never performs per-session payload reads.
- Redis Cluster keeps the session-ID-keyed payload layout and uses the minimum safe cross-slot operations. Session payloads remain authoritative; owner observations are coroutine-local, and partial failures may create only safe, TTL-bounded index drift.
- Database listing and individual invalidation use one query. Bulk invalidation uses one query without a started current session and at most two when it must first prove whether the current record belongs to the scoped user.
- Deleting the current session cannot be undone by response-end persistence and cannot create an indexed but unauthenticated replacement session.
- Cache remains a required package for the first-class session blocking and `$session->cache()` APIs, but no persistence handler routes storage through cache.

## Research and decisions

### Local references

- The old package at `examples/sonicstack/laravel-packages-old/enhanced-redis-session` proved the value of per-user indexes, metadata, ownership-aware deletion, and an opt-in flag. Its per-session pipelines and cache wrapper are historical constraints, not APIs to preserve.
- The locally cloned Jetstream 5.x tree at `examples/laravel/jetstream` uses “Browser Sessions” and “Log Out Other Browser Sessions”, maps `agent`, `ip_address`, `is_current_device`, and `last_active` for presentation, and queries/deletes database rows directly. It supplies useful UI vocabulary but no reusable driver-neutral abstraction.
- Hypervel's standalone `src/rate-limiter` package is the architectural precedent: storage-specific database/Redis drivers, SHA-cached Lua, raw Redis wire formats, and an explicit cluster branch after cache abstractions proved restrictive.
- Hypervel cache any-tag operations already use `HSETEX`, `HEXPIRE`, and `HTTL` through phpredis 6.3.0+ and carry the Redis 8 / Valkey 9 infrastructure needed by tracking tests.
- `RedisProxy::withoutSerializationOrCompression()` and `RedisConnection::evalWithShaCache()` provide coroutine-safe pooled connection pinning, raw byte ownership, option restoration, and steady-state `EVALSHA`.

### Laravel lineage and inherited lifecycle defect

Laravel and current Hypervel database sessions derive `user_id` from the selected guard at handler write time. `Store::invalidate()` clears attributes and rotates the ID, but `SessionGuard::id()` may still return its request/coroutine-cached user. Response-end persistence can therefore create a new row associated with a user even though the new payload contains no authentication key.

Today a later unauthenticated database write sets `user_id` to null, so the artifact self-heals after a request. The required multi-guard rule below must preserve ownership on an identity-less write, which would make that artifact permanent and reproduce it in Redis. This plan fixes the root lifecycle rather than documenting the artifact.

### Selected guard semantics

“Current user” means the identity exposed by the currently selected guard when the session is persisted:

- `Authenticate` calls `AuthManager::shouldUse()`, and the `auth.driver` binding resolves the coroutine-selected guard on every call. A non-default guard selected by middleware is therefore tracked correctly.
- A secondary guard that merely has a user but was not selected is not separately indexed.
- One session records one identity. The session subsystem must not enumerate all guards or pin tracking to the statically configured default guard.
- The selected guard's declared provider and normalized user ID form the ownership identity. Unrelated providers may reuse an ID without exposing or invalidating each other's sessions; guards sharing one provider deliberately share the same account namespace.
- Random session IDs isolate payloads but cannot isolate per-user management indexes. Separate prefixes or connections per guard are not an available workaround because every session guard uses the same `session.store`.

### Identity transitions on write

Handlers must distinguish three states:

| State | Meaning | Existing association | Result |
| --- | --- | --- | --- |
| resolved `P/A` | selected guard identifies user A under provider P | none/P/A/Q/B | associate or refresh P/A; remove a different owner if reassigned |
| unresolved | no selected identity is available, so ownership is unknown | none/P/A | remain raw when none; preserve P/A and refresh its metadata/TTL |
| unowned | an invalidated replacement is suppression-marked, or an authenticated custom guard declares no provider, so ownership is known absent | none/P/A | explicitly write unowned; remove a proven P/A association |

Unresolved writes preserve ownership because public routes in a multi-guard application can fall back to a different unauthenticated guard. Clearing ownership there makes session lists flicker and lets the index expire while the session remains active.

Unowned is deliberately not an alias for unresolved. Current-session invalidation must clear ownership even while the selected guard still caches the invalidated user. A providerless authenticated custom guard must also clear any previous owner rather than leave that account able to manage a session now selected under another identity system.

### Auth boundary

Session invalidation is a storage operation, not authentication logout:

- It must not call `StatefulGuard::logout()`: logout can update the users table by cycling a remember token and dispatch application-visible audit events.
- It must not call `SessionGuard::forgetUser()`: a later guard lookup can repopulate the cached user from a remember-me cookie.
- A remember-me cookie can authenticate the device again on the next request and create a new, correctly tracked session. Applications intending to end the current authenticated device must capture `UserSessions`, call `Auth::logout()`, then invalidate storage.
- Conversely, `Auth::logout()` without session invalidation is not a storage revocation. The unresolved-write rule preserves that record's last proven owner; identity-less activity may continue refreshing it until the session is explicitly destroyed or finally expires.

## Public API

Add manager methods exposed through the `Session` facade:

```php
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Session\UserSessions;
use UnitEnum;

public function supportsUserSessionManagement(): bool;

public function forUser(
    Authenticatable|int|string $user,
    UnitEnum|string|null $guard = null,
): UserSessions;
```

`forUser()` follows the familiar `Gate::forUser()` shape while accepting an optional Laravel-style guard selector. A null guard uses the coroutine-selected default guard without changing it; an explicit string or enum addresses that guard without mutating the selection. The selected guard's configured provider qualifies the ownership namespace. The method extracts an `Authenticatable` identifier, accepts integer or string identifiers, rejects any other model identifier and an empty string with `InvalidArgumentException`, and normalizes the result to a string. Integer `42` and string `'42'` intentionally address the same user within one provider. Integer zero remains valid.

Put scalar normalization in `UserSessionIdentity::normalize(int|string $userId): string` so the manager and both handlers share the zero/empty-string rules. Model extraction remains in the manager because handlers never receive models. If an `Authenticatable` is supplied and the chosen provider's driver is `eloquent`, require the object to be an instance of its configured model so a caller cannot accidentally address the current guard's provider with another provider's model. Database and custom providers are not inferred from model classes.

The chosen guard must declare a non-empty provider for `forUser()`; otherwise throw an `InvalidArgumentException` naming `auth.guards.{guard}.provider`. A provider name without a matching `auth.providers` entry remains an opaque namespace for custom guards. Only the optional Eloquent model check depends on provider configuration.

Generalize Auth's existing provider accessor at its owning boundary:

```php
public function getUserProviderName(
    UnitEnum|string|null $guard = null,
): ?string;

public function getDefaultUserProvider(): ?string
{
    return $this->getUserProviderName();
}
```

`getUserProviderName()` normalizes enums, defaults null to `getDefaultDriver()`, and returns the named guard's non-empty provider or null. Preserve `getDefaultUserProvider()` because it is a current Laravel API; its delegation retains Hypervel's deliberate selected-guard semantics and normalizes an empty configured value to null. Do not add provider lookup to the Auth `Factory` contract: provider configuration is concrete `AuthManager` behavior. Session resolves the canonical `'auth'` service instead of duplicating knowledge of `auth.guards.*`.

Unsupported handlers make the probe return `false`; `forUser()` throws:

```php
throw new BadMethodCallException(
    'This session driver does not support user session management.'
);
```

Do not add these manager operations to `Hypervel\Contracts\Session\Session`: they manage persisted records across sessions, not the current session attribute store.

### Scoped repository

Add `Hypervel\Session\UserSessions`:

```php
class UserSessions
{
    /** @return Collection<int, UserSession> */
    public function all(): Collection;

    public function invalidate(string $sessionId): bool;

    public function invalidateOthers(string $except): int;

    public function invalidateAll(): int;
}
```

The class captures only the normalized auth provider and target user ID, handler, and current `Store`. Provider names scope persistence and are not exposed on `UserSession`, just as the already-scoped user ID is not exposed there. It does not capture a guard object, resolved Auth identity, or session ID: whether the current persisted record was invalidated is a fact proved by storage, and the Store ID can rotate after repository construction. `SessionManager::forUser()` constructs it directly. Never bind or auto-resolve it: those captured references are request-specific and Hypervel auto-singletons unbound concrete classes.

Semantics:

- `all()` returns active sessions newest first.
- `invalidate()` returns `true` only when that handler deleted a session proven to belong to the scoped user.
- `invalidateOthers()` preserves exactly the supplied ID and returns the number of stored records deleted.
- `invalidateAll()` returns the number of active stored records deleted. Expired database rows are already invalid and remain garbage-collection work, matching Redis where expired payloads/index fields are no longer addressable.
- The methods do not call Auth logout.
- Bulk management of another user never mutates the administrator's current Store. An explicit `invalidate($currentId)` still rotates after the handler proves that exact stored session belongs to the scoped user.

Validate every public session-ID argument through `SessionId::validate()`, the shared throwing entry point backed by `SessionId::isValid()`. Throw `InvalidArgumentException` for an invalid `invalidate()` ID or `invalidateOthers()` exception instead of deriving a Redis key from arbitrary input or accidentally turning an invalid exception into “preserve nothing”. Handler methods still validate defensively because custom callers can invoke the capability contract directly.

Add a small session-internal `final SessionId` utility as the single PHP source for the framework's fixed identifier format:

```php
final class SessionId
{
    public const int LENGTH = 40;

    public static function generate(): string
    {
        return Str::random(self::LENGTH);
    }

    public static function isValid(?string $id): bool
    {
        return is_string($id)
            && strlen($id) === self::LENGTH
            && ctype_alnum($id);
    }

    public static function validate(string $id): void
    {
        if (! self::isValid($id)) {
            throw new InvalidArgumentException('The session identifier is invalid.');
        }
    }
}
```

`Store::generateSessionId()` and `Store::isValidId()` delegate to it, preserving the existing Store API. Give CSRF tokens their own named length constant rather than using a session-ID constant by coincidence. Redis PHP handlers call the utility; Lua's 40-byte/alphanumeric check is the unavoidable mirror and must name `SessionId` in its adjacent source comment.

### Value object

Add an immutable value object with storage-neutral fields only:

```php
final readonly class UserSession
{
    public function __construct(
        public string $id,
        public ?string $ipAddress,
        public ?string $userAgent,
        public CarbonImmutable $lastActivity,
        public CarbonImmutable $expiresAt,
    ) {
    }
}
```

`final readonly` protects value immutability. Do not add user ID (the repository already scopes it), parsed browser/device data, geolocation, humanized dates, or `isCurrentDevice`; those are presentation concerns, and callers can compare `$session->id` with `Session::id()`.

Both drivers derive `expiresAt` as `lastActivity + configured lifetime`. Do not persist a duplicate expiration timestamp.

### Capability contract

Add `Hypervel\Session\Contracts\CanManageUserSessions` inside the session package because it returns session-owned value objects:

```php
interface CanManageUserSessions
{
    public function supportsUserSessionManagement(): bool;

    /** @return Collection<int, UserSession> */
    public function userSessions(
        string $authProvider,
        int|string $userId,
    ): Collection;

    public function destroyUserSession(
        string $authProvider,
        int|string $userId,
        string $sessionId,
    ): bool;

    /** @param list<string> $except */
    public function destroyUserSessions(
        string $authProvider,
        int|string $userId,
        array $except = [],
    ): int;
}
```

- `DatabaseSessionHandler` implements it and always reports support.
- `RedisSessionHandler` implements it and reports the `track_user_sessions` configuration value.
- File, cookie, array, and null handlers do not implement it.
- Custom handlers may implement it.
- The explicit probe is required because Redis implements the contract structurally but enables the feature dynamically.
- Built-in handlers require a non-empty provider, normalize the user ID, and validate/deduplicate every exception ID; invalid values throw `InvalidArgumentException`. Share provider and exception-list validation through `Concerns\ValidatesUserSessionArguments`, while `SessionId::validate()` remains the one owner of session-ID validation. The repository already supplies canonical values, while defensive validation protects direct custom calls.

Use `invalidate` in the public repository and `destroy` at the handler boundary, matching `Store::invalidate()` and `SessionHandlerInterface::destroy()` respectively.

## Current-session correctness

Every mutating repository method reads the Store's current ID once at method entry, without fabricating an ID for an unstarted Store:

```php
$currentId = $this->store->isStarted()
    ? $this->store->getId()
    : null;
```

After a successful `invalidate($sessionId)`, `UserSessions` rotates only when `$sessionId` equals that operation-entry `$currentId`; the handler has proved ownership. Bulk operations use the same operation-entry snapshot and storage proof instead of comparing the target to the currently selected guard:

```php
$deletedCurrent = false;
$currentId = $this->store->isStarted()
    ? $this->store->getId()
    : null;

if ($currentId !== null && $currentId !== $except) {
    $deletedCurrent = $this->handler->destroyUserSession(
        $this->authProvider,
        $this->userId,
        $currentId,
    );

    if ($deletedCurrent) {
        $this->rotateCurrentSession();
    }
}

$count = $this->handler->destroyUserSessions(
    $this->authProvider,
    $this->userId,
    array_values(array_unique(array_filter(
        [$except, $currentId],
        static fn (?string $id): bool => $id !== null,
    ))),
);

return $count + (int) $deletedCurrent;
```

The handler bulk method accepts an internal exception list because the caller-supplied exception and the operation-entry current ID may differ. Always exclude that old current ID from the second mutation: this makes the preliminary ownership result authoritative even if another request changes the record concurrently. Rotate immediately after a proven current deletion, before attempting the remaining bulk mutation, so a later storage failure cannot let response-end persistence resurrect the deleted current record. Do not retain a session-ID property on the repository; a second call on the same repository snapshots the Store's then-current ID afresh.

Managing another user leaves the current Store untouched because the ownership-scoped delete returns `false`. Calling after `Auth::logout()` is also safe: storage ownership, not the now-null guard identity, controls rotation. A current session that has never been persisted cannot be proven by the handler and therefore does not rotate through a bulk call; callers ending that newly established in-memory authentication must use Auth/current-session lifecycle APIs. Do not reintroduce an identity fallback, a bulk-result DTO, or database-specific `RETURNING`: the extra scoped mutation is the portable, explicit proof.

The shared rotation helper is:

```php
$store->flush();
$store->regenerate();

UserSessionIdentity::suppress($store->getId());
```

`regenerate()` uses its default `false` destroy argument because the handler operation already deleted the old record. It rotates the cookie ID and regenerates the CSRF token without a duplicate delete. The response-end write targets a fresh ID, so it cannot resurrect the deleted record.

### Internal identity resolver

Add a session-internal `final readonly UserSessionIdentity` whose private construction enforces exactly the three states. It also owns one coroutine-local set of replacement session IDs:

```php
private const string UNOWNED_CONTEXT_KEY =
    '__session.user_sessions.unowned';

// array<string sessionId, true>
public static function suppress(string $sessionId): void;

public static function normalize(int|string $userId): string;

public static function resolve(
    ?Container $container,
    string $sessionId,
): self;
```

The value can represent the invariant compactly:

```php
private function __construct(
    public ?string $authProvider,
    public ?string $userId,
    private bool $unowned,
) {
}

public function isResolved(): bool;
public function isUnowned(): bool;
```

Resolution rules:

1. Return unowned immediately when the session ID is in the unowned set. Do not resolve a guard in this branch: `Guard::id()` can lazily load a provider or consume a remember-me cookie, while an invalidated, flushed replacement must remain explicitly unowned. The `suppress()` docblock must state that marking an ID makes its next writes resolve as unowned.
2. Otherwise resolve the canonical `'auth'` service as `AuthManager`, read the coroutine-selected guard and its ID, and return unresolved when Auth or an identity is unavailable. Unresolved means ownership is unknown, so handlers preserve a live proven owner.
3. When an identity exists, call `AuthManager::getUserProviderName()` for that selected guard. Return unowned when it returns null: an authenticated providerless custom guard has known-absent managed ownership and must clear a previous owner without breaking ordinary persistence.
4. Return resolved with the non-empty provider and normalized user ID otherwise.

Use concise docblocks on `isUnowned()` and the unresolved path to preserve the important known-none versus unknown distinction. Rename the context key/set, Redis/Lua intent string, and surrounding state comments from `suppressed` to `unowned`; `suppress()` remains the invalidation action, while unowned names the resulting storage state.

Do not include a user ID or handler object ID. `Store::invalidate()` flushed every guard's session attributes, so no identity may own that replacement ID; a legitimate `SessionGuard::login()` always regenerates again. Object IDs are also reusable. Do not clear the set after a successful write: applications may call `Store::save()` before middleware saves again, and failed-session retry callbacks run in the same coroutine. Coroutine teardown is the exact cleanup boundary.

A later login does not need special handling. `SessionGuard::login()` calls `regenerate(true)`, producing another session ID that does not match the suppression-marked replacement; the newly authenticated session is tracked normally for the same or a different user.

### Fix ordinary Store invalidation too

The inherited defect is reachable through the existing `$request->session()->invalidate()` API, not only through `UserSessions`. After `Store::invalidate()` flushes and its `migrate(true)` call completes the rotation, call:

```php
UserSessionIdentity::suppress($this->getId());
```

This is a local context write: it does not resolve Auth, query a user provider, consume a remember-me cookie, or add a storage/network call. It is harmless for handlers that do not use managed identities. A later login rotates again and therefore escapes suppression normally.

Do not put suppression in `migrate()` or `regenerate()`: authentication deliberately calls `regenerate(true)` while establishing a new authenticated session. Suppression belongs to `invalidate()` because that operation flushes the authentication attributes.

## Standalone persistence and package boundaries

### Remove cache-backed Redis persistence

- Delete `src/session/src/CacheBasedSessionHandler.php` and `tests/Session/CacheBasedSessionHandlerTest.php`.
- Remove `SessionManager::createCacheHandler()` and the cache-store validation/mutation branch.
- Remove the `session.store` configuration option, `SESSION_STORE`, and every config test/comment/doc reference to that obsolete cache-store selector.
- Replace the README statement inviting cache-backed handlers with the final native-driver difference.
- Add `hypervel/redis: ^0.4` to `require`.
- Keep `hypervel/cache: ^0.4` in `require`: session blocking and `$session->cache()` are unconditional public features, and installing the split session package must not leave either feature broken. Cache does not require session, so this creates no dependency cycle.

The package dependency does not weaken the persistence boundary. `$session->cache()` uses cache's `SessionStore` adapter over the current session's in-memory `_cache` attribute, so it adds no storage call or separate cache key; session blocking deliberately uses cache locks. Ordinary file, database, Redis, array, null, and cookie persistence never resolve or delegate through a cache store. Native Redis still performs its direct one-command or one-script paths.

Do not remove or rename the container service key `'session.store'`. Auth, routing, testing, Sentry, and cache's session-scoped store resolve the current `Store` through that established service. The obsolete configuration key and the live container service happen to share text but have unrelated responsibilities.

### Cache-backed session features

Keep `StartSession`'s existing required cache factory and constructor order:

```php
public function __construct(
    protected SessionManager $manager,
    protected CacheFactoryContract $cache,
    protected ExceptionHandlerContract $exceptionHandler,
) {
}
```

Do not add nullable injection, missing-package branches, or optional-feature documentation. Laravel 13's split `illuminate/session` metadata omits `illuminate/cache` even though `Store::cache()` calls `Cache::store('session')`; full Laravel applications mask that incomplete split boundary because the monolithic framework supplies both components. Hypervel should keep the cleaner standalone package contract rather than copy that weakness.

## Database driver

### Writes and selected identity

Keep the coroutine-local existence state nullable: `null` means unknown on fresh construction or cloning, `false` means a read or Store rotation proved the ID missing, and `true` means a row was found. `getExists(): bool` remains the compatibility view and returns false for unknown. `write()` reads only when the raw state is unknown, then builds the payload. A normal started or regenerated Store therefore performs no response-end re-read, while a direct handler write still resolves unknown state before choosing insert or update.

Use `UserSessionIdentity::resolve($container, $sessionId)` in `getDefaultPayload()`:

- resolved: write `auth_provider` and `user_id` as an explicit non-null pair;
- unresolved with a live existing row: omit both ownership fields, preserving its last proven association;
- unresolved with a missing or expired row: explicitly put both fields null, because an expired authenticated session must not lend ownership to its empty replacement;
- unowned: explicitly put both fields null.

Add one short comment at payload construction explaining that `auth_provider` and `user_id` are one ownership value and must always be written, omitted, or cleared together. This prevents a future edit from silently pairing one provider with another provider's user ID.

Keep request metadata behavior: when `RequestContext` exists, refresh IP address and the UTF-8-normalized, 500-character user agent. Do not infer device information.

Track the read row's expired state alongside existence and keep both slots handler-instance-local under the established object-ID key. Every `read()` outcome sets both deterministically: live is `exists=true, expired=false`, expired is `true, true`, and missing is `false, false`. Construction and cloning use `CoroutineContext::forget()` on both slots so reused object IDs cannot inherit either state. `setExists(false)` and a successful write clear the expired marker. This is persistence bookkeeping, unlike the cross-handler session-ID suppression set.

`performInsert()` builds a separate insert payload containing the handler-supplied ID, leaving the fallback update payload unchanged. Catch only `UniqueConstraintViolationException`; every other insert failure must propagate. On a genuine concurrent duplicate insert, update the payload and identity together: an unresolved known-missing write replaces the concurrent payload with its own unauthenticated payload and explicitly clears both ownership fields, while resolved and unowned states retain their explicit pair result.

### Management queries

Use `getQuery()` for every operation so reads use the write PDO and cannot observe replication lag after invalidation.

`userSessions()` captures one `$now`, calculates `$cutoff = $now - ($minutes * 60)`, and performs exactly one query:

```php
$rows = $this->getQuery()
    ->select(['id', 'ip_address', 'user_agent', 'last_activity'])
    ->where('auth_provider', $authProvider)
    ->where('user_id', (string) $userId)
    ->where('last_activity', '>', $cutoff)
    ->orderByDesc('last_activity')
    ->orderBy('id')
    ->get();
```

Map rows in PHP to `UserSession`; there are no payload reads and no per-row queries.

Ownership-aware deletion is one SQL statement:

```php
return $this->getQuery()
    ->where('auth_provider', $authProvider)
    ->where('user_id', (string) $userId)
    ->where('id', $sessionId)
    ->where('last_activity', '>', $cutoff)
    ->delete() > 0;
```

Bulk deletion is one provider-and-user-qualified `DELETE` over `last_activity > $cutoff`, adding `whereNotIn('id', $except)` when the validated exception list is non-empty, and returns the affected count. Capture one current timestamp per operation so listing and deletion use the same active boundary. Keep only the existing standalone `user_id` index: provider collisions leave a tiny candidate set, while widening the hottest table's index would add write and storage cost to every request.

Align the expiration boundary while touching the handler:

- a row is expired when `last_activity <= cutoff`;
- listing and managed deletion select only `last_activity > cutoff`;
- garbage collection already deletes `<= cutoff`.

### Session table

Update `src/session/src/Console/stubs/database.stub`:

```php
$table->string('user_id')->nullable()->index();
$table->string('auth_provider')->nullable();
$table->ipAddress('ip_address')->nullable();
```

String user IDs support integer, UUID, ULID, and application-defined identifiers through one schema. The nullable provider is populated only with an owned session and is deliberately not separately or compositely indexed. The documentation must explicitly call out both changes from Laravel's table and tell existing applications to alter their session table when adopting this API. Existing rows with a null provider fail closed and remain absent from managed listings; active sessions gain their selected provider on their next ordinary response-end write, while idle rows remain unmanaged until expiry. Null must never act as a provider wildcard.

Apply the same column definitions to `src/testbench/hypervel/migrations/0001_01_01_000002_testbench_create_sessions_table.php`; otherwise integration tests exercise a stale bigint schema. Strengthen `tests/Integration/Generators/SessionTableCommandTest.php` to assert the generated migration contains both semantic definitions.

## Redis driver

### Construction and raw connection helper

Add `RedisSessionHandler` and construct it from `SessionManager::createRedisDriver()` with:

- `Hypervel\Contracts\Redis\Factory`;
- `session.connection`, falling back to the existing `session` Redis connection;
- the configured session prefix;
- lifetime minutes;
- `session.track_user_sessions`;
- the container for request and selected-guard metadata.

Every Redis operation, including plain reads/writes, index listing, direct HFE commands, and Lua, must own raw bytes:

```php
protected function withConnection(Closure $callback): mixed
{
    return $this->redis->connection($this->connection)->withConnection(
        static fn (RedisConnection $connection): mixed =>
            $connection->withoutSerializationOrCompression(
                static fn (): mixed => $callback($connection),
            ),
        transform: false,
    );
}
```

The helper pins one pool connection, locally inspects/disables serializer and compression options, restores them in `finally`, and releases it. This adds no Redis command or network round trip. Session payloads are already serialized/encrypted by `Store`; phpredis transformation must never wrap their wire representation.

Implement ordinary `SessionHandlerInterface` lifecycle methods directly. Redis expiry makes `gc()` return zero.

### Physical keys and hashing

Logical keys contain no literal Redis Cluster hash tags:

```text
payload:    <session.prefix><40-character session ID>
user hash:  <session.prefix>_users:<owner digest>:sessions
```

The owner digest is a lowercase `xxh128` over a length-prefixed auth provider and normalized user ID. This makes unrelated providers distinct even when their user IDs overlap, while bounding user-controlled key material; it is not presented as secrecy. The length prefix makes the input injective even when either string contains delimiters. Do not seed the digest: the logical session prefix is already part of the physical index key, unlike the rate limiter where the digest is the whole key, so seeding would add no namespace separation.

```php
$ownerDigest = hash(
    'xxh128',
    strlen($authProvider) . ':' . $authProvider . ':' . $userId,
);
```

Maintain two prefix concepts:

- logical prefix: supplied to direct commands and `KEYS[]`; phpredis applies `OPT_PREFIX`;
- physical prefix: `OPT_PREFIX . session.prefix`, supplied only through `ARGV` when Lua constructs dynamic keys.

Never pass a physical key to a normal phpredis key argument, which would double-prefix it. Never derive a dynamic Lua key from the session prefix alone, which would omit `OPT_PREFIX`. The generated key layout introduces no cluster hash tag; an application-supplied connection or session prefix remains application-owned and may itself contain one.

### Single-key payload envelope

Tracking must not add an owner key or change the payload Redis type. Store either a raw payload or this versioned envelope in the existing string key:

```text
family prefix: "\0HVS"                  4 bytes
version 1:     "\0HVS1"                 5 bytes total
owner digest:  32 lowercase hex bytes
payload:       remaining opaque bytes
overhead:      37 bytes
```

PHP and Lua parsers must implement the same strict contract:

- a valid V1 record requires the exact magic plus exactly 32 lowercase hexadecimal owner bytes;
- a value without the family prefix is a raw payload;
- any value beginning with the family prefix but carrying an unknown version, short header, uppercase/invalid digest, or malformed V1 is corrupt/unsupported and reads as an empty session;
- arbitrary bytes after a valid header are opaque payload, including an empty payload;
- a tracking write may replace corrupt state but cannot infer its prior owner;
- ownership-scoped invalidation never deletes a corrupt/unknown payload because ownership is unproven, but it may prune the requested user's stale index field;
- unscoped `destroy()` still deletes it.

This framing is safe for JSON, PHP serialization, and encrypted sessions: none of their valid raw formats begin with NUL. Reading always understands the envelope, irrespective of the current flag. Enabling tracking wraps a raw session on its next write; disabling tracking reads an envelope and writes raw on its next write. Old index fields expire naturally. Flag changes therefore cause neither `WRONGTYPE` nor forced login loss.

### User index

Each user has one Redis hash. The hash field is the session ID and the value is compact JSON:

```json
{"ip_address":"203.0.113.5","user_agent":"Browser/1.0","last_activity":1786543200}
```

Nullable metadata remains JSON `null`. Write each field with `HSETEX ... EXAT <expires-at> FIELDS 1 ...`; tracked payload writes use the same absolute deadline. Redis removes the hash after its last field expires. `expiresAt` is derived from `last_activity`, so listing requires no `HTTL` command. Tests may use `HTTL` to prove synchronization.

Capture IP address and user agent with the same semantics as the database handler: nullable IP, UTF-8-normalized user agent truncated to 500 characters, and one current timestamp per write. When no `RequestContext` exists, preserve valid existing IP/user-agent metadata while advancing `last_activity`. The standalone mutation already has local access to the index field; the cluster branch uses one index-local Lua update in place of its ordinary direct `HSETEX`, so neither path adds a payload read or another client call. If no valid prior metadata exists, use null IP/user-agent values. A normal request always supplies fresh values. A subsequent valid write repairs corrupt metadata rather than perpetuating it.

Decode strictly: require a `SessionId::isValid()` hash field, an object/associative-array value with nullable strings for IP/user agent, and a non-negative integer timestamp. Skip malformed fields or values, collect their raw field names, and issue one `HDEL` after decoding to prune them from that user's index. Do not construct a DTO with an invalid ID, derive a payload key while listing, silently manufacture metadata, or fail the entire management page because one field is corrupt. Healthy listing remains exactly one `HGETALL`; corruption recovery adds at most one cleanup call and propagates a cleanup transport/command failure. Valid-metadata fields are not payload-verified during listing and may reflect TTL-bounded cluster drift until a scoped deletion or later write repairs them.

### Tracking-disabled performance

The disabled path performs exactly:

- `read`: one raw `GET`, followed by local envelope stripping;
- `write`: one raw `SETEX` with the opaque payload;
- `destroy`: one raw `DEL`;
- `gc`: no Redis command.

It does not invoke Lua, HFE commands, identity resolution, metadata encoding, or index maintenance. This path works without Redis 8 / Valkey 9.

### Standalone and Sentinel tracked algorithms

Use SHA-cached Lua for every mutation. Reads remain one `GET`; listing remains one `HGETALL`.

Tracked write is one atomic script:

1. `GET` the payload and parse any proven old owner.
2. Apply resolved/preserve/unowned intent.
3. When PHP supplied no request metadata, `HGET` the resulting owner's existing field and strictly preserve valid IP/user-agent values locally.
4. When there is a resulting owner, `HSETEX` its field with fresh or locally preserved metadata and the write's absolute expiry.
5. Store the raw or enveloped authoritative payload with that same absolute expiry.
6. Remove a different old owner's index field, or the unowned old owner's field, last.

Pass the current timestamp, an explicit fresh-request-metadata flag, and the encoded metadata from PHP; do not issue Redis `TIME`. The explicit flag distinguishes “no request context, preserve valid prior metadata” from fresh metadata containing nullable values. Dynamic old-owner index keys use the full physical prefix through `ARGV`.

“Atomic” here means one non-interleaved Redis script, not transactional rollback: a runtime command error does not undo earlier writes. The ordering above is therefore deliberate. A resulting owner's index is written before the authoritative envelope, so an early failure can create only harmless, ownership-rechecked drift; an old index is cleaned after the payload changes, so a late cleanup failure cannot authorize deletion under the old owner. Suppression has no resulting index, so it writes the raw unowned payload before attempting old-index cleanup. Keep these failure invariants without adding preflight commands or round trips.

The write script returns integer `1` only after the payload and all required index changes succeed. `RedisSessionHandler::write()` maps exactly that result to `true`; a legitimate false/nil result maps to `false`, while Redis/script/transport errors propagate. In the cluster branch, validate every required payload/index result and return `true` only after all steps succeed; a command-level failure result returns `false`, and an exception propagates. Zero affected by `DEL`/`HDEL` is a successful no-op, not a write failure. This preserves `Store::save()`'s retry contract: false makes it throw without publishing aged live attributes, and a subsequent save can restore the authoritative payload/current-owner index. If a reassignment or suppression had already replaced the envelope before obsolete-owner cleanup failed, that old field is no longer derivable on retry; it is harmless because scoped deletion rechecks the new envelope and it remains TTL-bounded.

Unscoped `destroy()` is one script: parse a valid owner, delete the payload regardless of format, and remove the proven index field.

`destroyUserSession()` is one script: read and strictly parse the payload, compare the envelope digest with the requested user digest, always remove the field from the requested user's index, and then delete the payload only when ownership is proven. Return one only for a proven payload deletion and zero for index-only cleanup. Performing the requested-index type-sensitive operation before `DEL` prevents a script error there from reporting failure after the authoritative session was already removed; `DEL` itself is type-agnostic. Put a short comment beside this ordering in the Lua source: same-slot script execution makes index-first safe and ensures a type error cannot delete the current payload before repository rotation.

`destroyUserSessions()` is one script rooted at the requested user's index:

1. read index fields internally;
2. preserve every explicit exception;
3. reject any field that is not a 40-character alphanumeric session ID before deriving a key;
4. read each candidate payload and delete it only when its valid envelope proves the requested owner;
5. remove stale, invalid, wrong-owner, missing, and successfully deleted fields from the requested index;
6. return the number of payloads actually deleted.

The loop is server-local and remains one atomic client round trip after that node has cached the script. Do not add PHP pipelines or per-session commands.

`userSessions()` performs one `HGETALL`, decodes all fields in PHP, prunes invalid-ID or malformed-value fields with at most one exceptional-path `HDEL`, maps DTOs, and sorts by `lastActivity` descending then session ID ascending, matching the database's deterministic tie-break. It deliberately does not verify payload existence per row because that would create N+1 calls.

### Per-user cardinality trade-off

The framework does not silently cap or evict a user's sessions: choosing a maximum device count and eviction policy is an authentication/product decision, and imposing one only on Redis would break driver-neutral semantics. The intended cardinality is ordinary human device/browser usage—normally tens and expected to remain comfortably below roughly one thousand active sessions per user. Listing and bulk invalidation are intentionally `O(n)` in that per-user count.

Above that operational range, `HGETALL` allocates proportionally in Redis/PHP and the atomic standalone bulk script runs proportionally longer while Redis is blocked. The cache any-tag implementation can switch to `HSCAN` above 1000 because it exposes streaming work; this API promises an eager `Collection` and atomic standalone bulk invalidation, so adopting its scan threshold would either add round trips without bounding the final collection or discard the atomicity requirement. Do not add pretend chunking here. Document the bound and require applications exposed to automated session creation to rate-limit authentication and enforce their chosen device/session policy before pathological cardinality. No result is silently truncated above the expected range.

### Redis Cluster branch

Cross-slot atomicity is impossible with session-ID-keyed payloads and user-keyed indexes. Moving payloads into user hash slots would make ordinary reads require an owner lookup and an extra round trip, and sessions exist before login. Keep the optimal payload layout and branch explicitly when `RedisProxy::isCluster()` is true. Retain each tracking-enabled Cluster read's proven envelope owner in `CoroutineContext` under `__session.redis.owner.` plus the handler object ID. The per-handler value maps session IDs to either a digest or `''`; an empty string means proven ownerless, while an absent entry means unobserved. Clear the slot on construction, cloning, and Cluster bulk deletion so PHP object-ID reuse or same-coroutine bulk invalidation cannot leave stale ownership state.

Cluster mutation ordering:

- A session-local Lua script operates only on the declared payload key, parses/stores/deletes it atomically, and returns a validated status plus proven old/resulting ownership.
- A resolved write knows its target owner before Redis access: update that owner's index first, mutate the payload, then remove a different old-owner field.
- An unresolved write uses the owner observed by the normal session read. If no observation exists, issue one plain `GET`, parse it through the same strict PHP envelope parser as `read()`, and remember the result. Update a non-empty observed owner's index first, then let the payload-local script write only when its actual owner—including no owner—still exactly matches the observation. A mismatch performs no payload mutation, records the actual owner, and returns false so the existing Store retry can pre-index the correct owner. The pre-indexed expected-owner field deliberately remains: its field TTL bounds the drift, and an ownership-rechecked scoped operation prunes it without touching the reassigned payload. This prevents a concurrent reassignment from committing a payload whose actual owner was not indexed by that operation.
- An unowned write has no resulting index, so mutate the authoritative payload first and remove its proven old-owner field afterward.
- Compute one absolute expiry from the write timestamp and configured lifetime. Every tracked field and payload mutation uses that deadline, so cross-slot call ordering cannot create relative-TTL skew.
- After a successful payload mutation, update the coroutine observation before fallible old-index cleanup. If cleanup fails, a stale old field cannot delete the reassigned session because all scoped deletion rechecks the authoritative envelope.
- On deletion, delete/verify the authoritative payload first, then clean its index field. Cleanup failure may leave a stale field but never a supposedly invalidated live payload. Put a short comment at this cluster call site explaining that separate cross-slot calls require payload-first ordering; do not make it match the standalone same-slot script.
- HFE bounds every stale field, and a subsequent write repairs a missing/current field.
- If a tracked Cluster session's current owner index cannot be updated, fail the write before refreshing its authoritative payload. The first save failure remains the request failure; an exception-rendering retry cannot replace that primary failure.

Operation counts:

| Operation | Cluster calls |
| --- | --- |
| read | one `GET` |
| list | one `HGETALL` |
| tracked write | one index-local command/script when owned, one payload Lua call, then at most one obsolete-index cleanup; an unobserved unresolved write first performs one `GET` |
| unscoped delete | one payload Lua call, then at most one `HDEL` |
| scoped single delete | one payload Lua call, then one requested-index `HDEL` |
| bulk delete | one `HKEYS`, one session-local ownership/delete script per valid non-except candidate, and at most one final `HDEL` for confirmed/stale/invalid fields |

Bulk deletion preserves every exception without touching it. It counts only payloads actually deleted. If a payload operation fails, propagate the error; never hide a possibly live session by preemptively deleting its index field. Attempt one final cleanup for already confirmed/stale fields before propagating a later payload failure. If that cleanup also throws, do not discard either diagnostic: throw a `RuntimeException` whose primary message retains the payload failure's class/message and whose `previous:` is the cleanup failure. If cleanup succeeds, rethrow the original payload failure unchanged. Unit tests must assert the dual-failure exception message and previous chain. Index cleanup failures after authoritative deletion propagate with drift remaining TTL-bounded.

Do not attempt a cluster pipeline: phpredis Redis Cluster does not provide a portable atomic/pipelined cross-node equivalent for this layout.

`RedisConnection::evalWithShaCache()` first sends `EVALSHA`; on `NOSCRIPT` it falls back to `EVAL` on the same pinned connection. The first use of each script on each node therefore takes two commands, while warmed steady-state mutations take one. Tests must cover the fallback and measure the advertised steady-state path only after warming it.

## Configuration and platform requirements

Replace the obsolete “Session Cache Store” block in `src/foundation/config/session.php` with:

```php
/*
|--------------------------------------------------------------------------
| User Session Tracking
|--------------------------------------------------------------------------
|
| When using the Redis session driver, this option maintains the metadata
| required to list and invalidate all sessions belonging to a user.
|
*/

'track_user_sessions' => (bool) env('SESSION_TRACK_USER_SESSIONS', false),
```

Keep `session.prefix`; it now belongs directly to `RedisSessionHandler`. Rename the surrounding “Session Database Connection” configuration heading to “Session Connection” and say that `session.connection` names either a database or Redis connection, instead of incorrectly directing both drivers to the database configuration. Add `SESSION_TRACK_USER_SESSIONS=false` to the testbench application `.env.example`.

Requirements:

- plain Redis sessions: the framework's existing Redis/phpredis floor;
- tracking-enabled Redis sessions: phpredis 6.3.0+, Redis 8.0+ or Valkey 9.0+;
- database management: every supported database using the updated string user-ID and nullable auth-provider schema.

Do not probe server versions on the hot path or add a network call to the capability probe. Enabling the config asserts the documented HFE platform requirement; unsupported servers fail on their native command error.

## Shared HFE test capability

Rename the overly cache-specific test concern:

```text
RequiresAnyTagModeRedis
    -> RequiresHashFieldExpiration

skipIfAnyTagModeUnsupported()
    -> skipIfHashFieldExpirationUnsupported()
```

Rename its constants/cache fields/messages to generic HFE terminology and update:

- its unit test file/class;
- cache any-tag integration tests;
- auth tagged-cache integration tests;
- the new Redis session integration tests.

Use `mv` for file renames, then verify zero references to the old symbol. This is test infrastructure, not a runtime dependency between session and cache.

## Documentation

### `src/docs/session.md`

Keep this as the user-facing source of truth and add:

- native Redis wording and removal of `SESSION_STORE`;
- the distinction between native persistence and the required cache-backed session cache/blocking features;
- a “Managing User Sessions” section with the capability probe, `forUser()`, DTO fields, list/invalidation examples, supported drivers, return counts, and current-ID comparison;
- the Redis tracking flag and HFE platform requirements;
- selected-guard, optional explicit-guard, one-identity-per-session, provider-qualified ownership, shared-provider, providerless-custom-guard, and overlapping-ID semantics;
- the database `user_id` string / nullable `auth_provider` / semantic IP schema change;
- active/newest-first and derived-expiry behavior;
- Redis Cluster's authoritative payload and TTL-bounded index drift;
- the uncapped `O(n)` per-user cardinality contract, expected operational range, lack of silent truncation/eviction, and authentication-rate/device-policy guidance;
- logout separation and remember-me behavior.

Show the safe current-device/all-device pattern:

```php
$user = Auth::user();

Auth::logout();

Session::forUser($user)->invalidateAll();
```

The handler proves whether it deleted the current persisted record even though the guard is logged out by the time `forUser()` is called. For Jetstream/Fortify-style “other browser sessions”, password-confirmation and `logoutOtherDevices()` remain auth concerns; storage invalidation is then:

```php
Auth::logoutOtherDevices($password);

Session::forUser($request->user())
    ->invalidateOthers($request->session()->getId());
```

### `src/docs/upgrade.md`

Add a focused 0.4 session-storage upgrade entry under “What Changed” and link the session docs under “Migration References”. State all three application actions explicitly:

- remove `SESSION_STORE`; Redis sessions now use the direct `SESSION_CONNECTION` and `SESSION_PREFIX` configuration;
- alter `sessions.user_id` from Laravel-style unsigned bigint to nullable indexed string before using managed sessions with string/UUID/ULID identifiers;
- add nullable `sessions.auth_provider`; existing null-provider rows deliberately remain unmanaged until active sessions rewrite them with their selected provider or idle rows expire;
- alter `sessions.ip_address` to the framework's semantic IP type, noting that this is `inet` on PostgreSQL and requires a driver-appropriate conversion for existing textual data.

Recommend comparing an application's migration with the current `make:session-table` output and writing an explicit driver-appropriate alter migration; do not imply that republishing a stub changes an existing table.

### Package README and facade metadata

- Add `Documentation: https://hypervel.org/docs/session` to the minimal session README.
- Retain only genuine lasting Laravel differences. Update the driver difference to state that Hypervel's Redis session driver is direct/native and Laravel's cache-backed APC, Memcached, DynamoDB, and shared cache wrapper are not provided.
- Add the lasting schema difference: Hypervel's generated `sessions.user_id` is a nullable indexed string (supporting integer, UUID, ULID, and application-defined IDs) rather than Laravel's `foreignId`; managed ownership also uses a nullable `auth_provider`, and `ipAddress()` gives PostgreSQL its native semantic type.
- Do not duplicate configuration or API documentation in the README.
- After adding the manager methods, regenerate the Session facade with `composer facade -- 'Hypervel\Support\Facades\Session'`, commit the generated annotations, and verify them with `composer facade -- --lint 'Hypervel\Support\Facades\Session'`. Do not hand-edit generated facade method tags.

## Test plan

### Unit and package tests

#### `tests/Auth/AuthManagerTest.php`

- `getUserProviderName()` resolves the coroutine-selected guard by default, accepts explicit string and enum guards without mutating the selection, and normalizes a missing or empty provider to null;
- `getDefaultUserProvider()` delegates with the same selected-guard behavior and remains Laravel-compatible;
- concurrent coroutines selecting different guards resolve their own provider without cross-request leakage.

#### `tests/Session/SessionManagerTest.php`

- Redis driver resolves `RedisFactory`, defaults to the `session` connection, honors an explicit connection, prefix, lifetime, and tracking flag.
- It never resolves/mutates a cache store.
- Capability probe and `forUser()` work for database, enabled/disabled Redis, unsupported built-ins, and a custom capable handler.
- `forUser()` defaults to the selected guard's provider, accepts an explicit string or enum guard without changing the selected guard, and still resolves the selected provider after logout.
- Different providers may reuse the same scalar ID, while two guards sharing one provider deliberately address the same namespace.
- Eloquent users must match the selected provider's configured model; database/custom providers are not guessed. A missing/empty provider fails clearly on explicit management.
- User model, integer zero, numeric string, UUID/ULID, empty, and invalid identifiers normalize/fail correctly regardless of configured provider count.
- Encrypted stores expose the same handler capability.
- Remove all `session.store`, `RedisStore`, cache-backed fallback, and non-Redis-cache validation tests.

#### `tests/Session/UserSessionsTest.php`

Use an in-memory capable handler fake and real `Store` to cover:

- typed collection passthrough and all four repository methods, with the captured provider forwarded on every handler call;
- ownership failure does not rotate;
- successful current individual invalidation flushes, rotates, regenerates CSRF, and suppresses the new ID;
- each mutating call snapshots the then-current Store ID at method entry; constructing the repository before a Store rotation and reusing it after a repository-driven rotation never probes a stale construction-time ID;
- bulk methods attempt the ownership-scoped current delete first, rotate immediately only on proof, add its boolean to the returned count, and exclude both the caller's exception and operation-entry current ID from the second mutation;
- bulk invalidation after `Auth::logout()` still rotates when storage proves current ownership, while a never-persisted current session does not rotate;
- a later bulk exception cannot resurrect an already deleted current record;
- another user's management never changes current Store;
- an unstarted Store never fabricates/rotates an ID;
- repeated `Store::save()` calls retain suppression;
- same-user and different-user login ID changes no longer match suppression;
- invalid session IDs and invalid `invalidateOthers()` exceptions fail before reaching a handler;
- ordinary `Store::invalidate()` records ID-only suppression, while `regenerate()` does not.

#### `tests/Session/UserSessionIdentityTest.php`

- resolved, unresolved, and unowned states are distinct, with docblocks and tests pinning unknown versus known-none behavior;
- integer/string IDs normalize identically and zero is preserved;
- suppression survives repeated resolution for the coroutine and produces the unowned state;
- a suppression-marked ID short-circuits before guard resolution regardless of cached or remembered identity;
- a selected authenticated guard with a declared provider resolves both provider and user ID, while a providerless custom guard becomes unowned and clears stale ownership without making Store saving throw;
- sets do not leak between concurrent coroutines;
- no user or handler object identity participates.

#### `tests/Session/RedisSessionHandlerTest.php`

Mock `RedisFactory`, `RedisProxy`, and `RedisConnection`; assert behavior and normalized arguments, not literal Lua source:

- tracking-off command counts and no identity/metadata/HFE work;
- raw/enveloped/malformed/unknown-version PHP parsing boundaries;
- tracked standalone write transition matrix and atomic one-script calls;
- unscoped, scoped single, and bulk invalidation ownership/counts, including index-only cleanup when scoped single ownership is missing/corrupt/wrong;
- list decode, sort, derived expiry, invalid-ID/malformed-value skipping, and one-call exceptional-path index pruning;
- logical versus physical prefix arguments with non-empty `OPT_PREFIX`;
- deterministic injective provider/user digest, isolation for equal IDs under different providers, shared ownership for guards using one provider, and no framework-generated literal hash tags;
- cluster authoritative ordering, owner observation/probing, minimum normal-lifecycle calls, stale cleanup, exception propagation, and bulk exception handling, including resolved and unresolved index failures before payload mutation, full equality for a proven-ownerless observation, concurrent owner mismatch and retry state, failed obsolete/requested-index cleanup, failed malformed-list pruning, invalid status/owner result shapes, observation isolation/reset, and retention of the cleanup failure as `previous` when payload and final cleanup both fail;
- raw wrapper restores serializer/compression after success and exception;
- tracked write returns true only for the script's explicit success result, returns false for a false/nil result, and preserves Store live state for retry after failure;
- transport-mock call counts prove one healthy `HGETALL`, one additional cleanup `HDEL` only for malformed listing metadata, and one `evalWithShaCache()` delegation per standalone handler mutation; transport mocks own these handler assertions because pinned raw `RedisConnection` calls deliberately bypass `RedisProxy` command events, while `UserSessionsTest` owns the repository's optional current-single-plus-bulk call sequence;
- retain the Redis connection helper's existing direct tests proving cold-node `EVALSHA`/`EVAL` fallback and warmed one-command behavior.

#### Existing tests to update

- Delete `CacheBasedSessionHandlerTest` with its source.
- `StartSessionTest`: retain the required cache factory, constructor order, lock behavior, and persistence retry coverage; native persistence does not change middleware's cache-backed blocking feature.
- `SessionConfigTest`: new flag/default and removed store.
- `PackageMetadataTest`: Redis and cache are both required; cache remains for session cache/blocking rather than persistence.
- `tests/Foundation/Testing/Concerns/InteractsWithSessionTest.php`: remove the cache-backed custom-driver regression and its dead import; the ordinary array-driver coverage already exercises `withSession()`, while custom-driver construction remains covered by `SessionManagerTest`.
- `SessionStoreTest` / encrypted tests: shared `SessionId` generation/validation behavior, a separately named CSRF-token length, no regressions to serialization or request handling, and proof that the existing `invalidate()` API cannot create an associated unauthenticated replacement while login-time regeneration is not suppressed.
- Auth README/facade metadata: retain `getDefaultUserProvider()`, add guard-aware `getUserProviderName()`, and regenerate/lint the Auth facade rather than hand-editing it.

### Database integration matrix

Move the current database handler integration body into a shared abstract fixture and run it through the existing convention:

```text
tests/Integration/Session/Database/DatabaseSessionHandlerTestCase.php
tests/Integration/Session/Database/MariaDb/DatabaseSessionHandlerTest.php
tests/Integration/Session/Database/MySql/DatabaseSessionHandlerTest.php
tests/Integration/Session/Database/Postgres/DatabaseSessionHandlerTest.php
tests/Integration/Session/Database/Sqlite/DatabaseSessionHandlerTest.php
```

Each concrete class uses `#[RequiresDatabase(...)]` and `#[WithMigration('session')]`; use `mv` for the existing test before restructuring. Cover on every driver:

Keep all four driver directories deliberately. The current standalone rate-limiter matrix also has MariaDB, MySQL, PostgreSQL, and SQLite fixtures, and the database workflow runs MariaDB independently. MariaDB coverage is not treated as a MySQL alias: it verifies that migration type mapping, indexed string identifiers, expiration predicates, and affected-row counts behave on its separate server/driver path.

- existing read/write/update/expiry/gc/destroy/existence behavior;
- nullable existence-state behavior: normal known-missing writes issue only the insert after the start read, direct unknown writes read before updating, and construction/cloning forget both existence and expired slots;
- concurrent row appearance after a known-missing read reaches the unique-violation fallback, stores the current unauthenticated payload with both ownership columns null, and never includes `id` in the update payload;
- non-unique insert failures propagate instead of falling through to a zero-row update and false success;
- string normalization for integer, numeric string, UUID, and ULID identifiers;
- PostgreSQL specifically proves an integer auth ID is explicitly bound against varchar storage;
- resolved/unresolved/unowned write transitions set, preserve, or clear `auth_provider` and `user_id` together, including the inherited post-invalidation ghost regression, providerless custom guards, and an unresolved rewrite of an expired row clearing its old owner;
- same-ID sessions under different providers are isolated for one-query active/newest-first listing, individual deletion, and bulk deletion; exact DTO metadata/expiry remains unchanged;
- exact expiration boundary;
- ownership-aware active single delete and one-query handler bulk delete with/without an exception list; repository-level bulk uses two statements only when it separately probes a started current record;
- expired unswept rows are absent from lists, cannot make scoped deletion report success, and remain eligible for garbage collection;
- repeated save and failed-write retry do not re-associate a suppression-marked replacement;
- migration column types are portable, with PostgreSQL `inet` behavior through `ipAddress()`.
- the testbench migration and generated session-table migration match the string/provider/semantic-IP schema while retaining only the standalone `user_id` index.

Use query listeners/logging to assert one statement for listing/individual/handler-bulk operations, one repository-bulk statement without a started Store, at most two with a started current record, and absence of payload/N+1 reads. A normal known-missing write executes one insert after its initial read; a direct write with unknown state against an existing row executes one read and one update without attempting an insert.

### Redis/Valkey integration

Add `tests/Integration/Session/Redis/RedisSessionHandlerTest.php` using both `InteractsWithRedis` and `RequiresHashFieldExpiration`; add `tests/Integration/Session/Redis` to both jobs in `.github/workflows/redis.yml`.

Call the HFE gate only from tracking-enabled tests. Tracking-disabled GET/SETEX/DEL coverage must still run against an older Redis server when available because that path intentionally has no HFE floor.

Cover against Redis 8 and Valkey 9:

- plain JSON, PHP-serialized, and encrypted session round trips;
- enabled tracking write/list/reassignment/preserve/unowned/destroy/bulk flows;
- selected non-default guard and non-selected secondary guard behavior, same-ID isolation across providers, and deliberate sharing across guards with one provider;
- identity-less public-route write preserves owner while refreshing metadata, payload TTL, and field `HTTL`;
- logout without invalidation preserves the last proven association, including across later identity-less writes, until explicit destruction or eventual inactivity expiry;
- remember-me self-invalidation authenticates into a fresh tracked session, while logout-first storage invalidation does not;
- observable server state after every operation: raw/enveloped payload contents, user-index field presence/removal, payload TTL, field `HTTL` synchronization, malformed-field pruning, and current-single-plus-bulk outcomes;
- positive connection `OPT_PREFIX` plus session prefix with neither omission nor duplication;
- phpredis PHP serializer for plain and tracked read/write/list/destroy, plus LZF compression when the extension exposes `Redis::COMPRESSION_LZF`; skip only the compression combination when unavailable, and verify option restoration after an exception;
- tracking flag enable/disable transitions and old index natural expiration;
- raw, corrupt family, malformed V1, and unknown-version records;
- HFE removes fields and the empty user hash after lifetime;
- stale/wrong-owner index fields cannot delete authoritative payloads;
- metadata corruption skips only the bad record and prunes its index field without hiding valid sessions;
- deliberate index `WRONGTYPE` failures prove standalone Lua's partial-commit ordering: a resulting-index failure leaves the old payload authoritative, while a late obsolete-index cleanup failure propagates after the new/unowned payload has already made the old field harmless and TTL-bounded;
- tracked writes give payloads and index fields one absolute expiry, including metadata-preserving identity-less writes.

Cluster behavior is unit-tested with ordered transport mocks because the Redis workflow does not provide a cluster. Do not pretend standalone integration proves cluster routing or add a single-node test that merely forces the Cluster flag. Real Cluster infrastructure and cross-slot integration coverage require their own reviewed CI change.

### Auth and end-to-end lifecycle

Add `tests/Integration/Session/Database/Sqlite/UserSessionLifecycleTest.php` with focused request-level coverage for:

- current invalidation followed by normal response-end save cannot resurrect the deleted ID;
- the replacement is unowned despite the selected guard's cached user;
- exception rendering's same-coroutine retry remains unowned;
- application-called save plus middleware save remains unowned;
- a fresh login regenerates away from suppression and tracks normally;
- two guards backed by different providers may authenticate the same scalar ID without cross-listing or cross-invalidation, and an explicit guard management call does not mutate the selected guard;
- admin management of another user leaves admin auth/session untouched;
- password/remember-token changes occur only when the application calls Auth APIs.
- direct `Store::invalidate()` and repository-driven current invalidation share the same unowned replacement invariant.

### Verification commands

Run narrow tests immediately after each source file change, then the affected suites:

```shell
vendor/bin/phpunit tests/Auth/AuthManagerTest.php
vendor/bin/phpunit tests/Session/RedisSessionHandlerTest.php
vendor/bin/phpunit tests/Session/UserSessionIdentityTest.php
vendor/bin/phpunit tests/Session/UserSessionsTest.php
vendor/bin/phpunit tests/Session/SessionManagerTest.php
vendor/bin/phpunit tests/Session/Middleware/StartSessionTest.php
vendor/bin/phpunit tests/Session

bin/run-database-tests.sh sqlite --filter=DatabaseSessionHandlerTest
bin/run-database-tests.sh mysql --filter=DatabaseSessionHandlerTest
bin/run-database-tests.sh mariadb --filter=DatabaseSessionHandlerTest
bin/run-database-tests.sh pgsql --filter=DatabaseSessionHandlerTest
bin/run-database-tests.sh sqlite --filter=UserSessionLifecycleTest

vendor/bin/phpunit tests/Integration/Session/Redis/RedisSessionHandlerTest.php
vendor/bin/phpunit tests/Foundation/Testing/Concerns/RequiresHashFieldExpirationTest.php

composer facade -- 'Hypervel\Support\Facades\Session'
composer facade -- --lint 'Hypervel\Support\Facades\Session'
composer facade -- 'Hypervel\Support\Facades\Auth'
composer facade -- --lint 'Hypervel\Support\Facades\Auth'
```

External database/Redis commands require their documented services. The implementation must still run every locally available path and leave CI wiring complete.

Finish with:

```shell
composer validate --strict
composer fix
git diff --check
```

After package metadata changes, run `composer update`/`composer install` as appropriate to validate dependency resolution; `composer.lock` remains untracked.

## Implementation sequence and file checklist

Work one file at a time and run the narrowest relevant test after each behavioral source edit.

1. Rename `RequiresAnyTagModeRedis` and its test with `mv`; update all auth/cache consumers and prove zero old references.
2. Add `Contracts/CanManageUserSessions.php`, `SessionId.php`, `UserSession.php`, `UserSessionIdentity.php`, and `UserSessions.php`, with their unit tests; provider-qualify every managed owner and delegate Store ID generation/validation to the shared utility.
3. Integrate identity suppression with `Store::invalidate()` and cover the inherited lifecycle regression.
4. Extend `DatabaseSessionHandler.php`; persist and query the provider/user ownership pair; restructure its database integration test into the four-driver matrix; update the published and testbench migrations plus generator tests.
5. Add `RedisSessionHandler.php`, its unit test, and Redis/Valkey integration test, using the provider-qualified owner digest without changing command counts.
6. Generalize Auth's provider-name accessor while preserving `getDefaultUserProvider()`; replace `SessionManager` Redis construction and add guard/provider API, capability, and coroutine-isolation tests.
7. Delete `CacheBasedSessionHandler` and its test; grep the entire source/tests tree for stale references.
8. Keep `StartSession`'s required cache dependency and verify existing middleware construction/blocking tests remain green.
9. Add Redis while retaining cache in session Composer metadata; update package metadata tests and validate the split dependency direction.
10. Replace configuration/env entries and update config tests.
11. Update the Auth and Session package READMEs, `src/docs/session.md`, and `src/docs/upgrade.md`; regenerate and lint the Auth and Session facades rather than editing generated annotations.
12. Add the Redis workflow directory and run all targeted/integration suites.
13. Run formatter/static analysis/full tests, inspect the complete diff, and remove any stale comments, imports, fixtures, config keys, or docs.

Files expected to be added:

```text
src/session/src/Contracts/CanManageUserSessions.php
src/session/src/Concerns/ValidatesUserSessionArguments.php
src/session/src/RedisSessionHandler.php
src/session/src/SessionId.php
src/session/src/UserSession.php
src/session/src/UserSessionIdentity.php
src/session/src/UserSessions.php
tests/Session/RedisSessionHandlerTest.php
tests/Session/UserSessionIdentityTest.php
tests/Session/UserSessionsTest.php
tests/Integration/Session/Redis/RedisSessionHandlerTest.php
tests/Integration/Session/Database/DatabaseSessionHandlerTestCase.php
tests/Integration/Session/Database/{MariaDb,MySql,Postgres,Sqlite}/DatabaseSessionHandlerTest.php
tests/Integration/Session/Database/Sqlite/UserSessionLifecycleTest.php
src/foundation/src/Testing/Concerns/RequiresHashFieldExpiration.php
tests/Foundation/Testing/Concerns/RequiresHashFieldExpirationTest.php
```

Files expected to be removed:

```text
src/session/src/CacheBasedSessionHandler.php
tests/Session/CacheBasedSessionHandlerTest.php
tests/Integration/Session/DatabaseSessionHandlerTest.php
src/foundation/src/Testing/Concerns/RequiresAnyTagModeRedis.php
tests/Foundation/Testing/Concerns/RequiresAnyTagModeRedisTest.php
```

Key existing files expected to be modified:

```text
.github/workflows/redis.yml
src/docs/session.md
src/docs/upgrade.md
src/auth/README.md
src/auth/src/CreatesUserProviders.php
src/foundation/config/session.php
src/session/README.md
src/session/composer.json
src/session/src/Console/stubs/database.stub
src/session/src/DatabaseSessionHandler.php
src/session/src/SessionManager.php
src/session/src/Store.php
src/support/src/CarbonImmutable.php
src/support/src/Facades/Auth.php
src/support/src/Facades/Session.php
src/testbench/hypervel/.env.example
src/testbench/hypervel/migrations/0001_01_01_000002_testbench_create_sessions_table.php
tests/Foundation/Testing/Concerns/InteractsWithSessionTest.php
tests/Integration/Auth/Redis/EloquentUserProviderCacheTagsTest.php
tests/Integration/Cache/Redis/ClusterFallbackIntegrationTest.php
tests/Integration/Cache/Redis/PrefixHandlingIntegrationTest.php
tests/Integration/Cache/Redis/RedisCacheIntegrationTestCase.php
tests/Integration/Generators/SessionTableCommandTest.php
tests/Session/PackageMetadataTest.php
tests/Session/SessionConfigTest.php
tests/Session/SessionManagerTest.php
tests/Session/SessionStoreTest.php
tests/Auth/AuthManagerTest.php
```

The last database path is removed only after its content is moved into the shared matrix fixture. Trait/test rename paths likewise replace, rather than duplicate, their old files.

## Completion criteria

- No cache-backed persistence remains in the session package. `hypervel/cache` remains required only for the first-class session blocking and `$session->cache()` APIs; persistence handlers never delegate through it.
- No `CacheBasedSessionHandler`, `session.store` configuration read, `SESSION_STORE`, or old HFE trait name remains anywhere outside historical plans/archive docs. The `'session.store'` container service remains intact.
- The public API is exactly the guard-aware Auth provider accessor, manager probe, guard-aware `forUser()`, scoped repository, and immutable DTO defined above; Laravel's `getDefaultUserProvider()` remains supported.
- Database and enabled Redis implement the same observable management semantics.
- Provider and user ID jointly scope every managed owner. Equal IDs under unrelated providers cannot cross-list or cross-invalidate; guards sharing one provider deliberately share the namespace; providerless authenticated custom guards remain operational but explicitly unowned.
- Unsupported/disabled drivers fail through the capability boundary, not driver-name conditionals.
- Tracking-off Redis performs one native command per read/write/delete.
- Each warmed standalone tracked handler mutation and healthy listing meet their one-round-trip contracts; malformed-list cleanup and repository-level current-plus-bulk invalidation have the explicitly documented extra call. Cold SHA-cache fallback is tested and documented. Database handler operations meet their one-query contracts, while repository bulk uses the documented ownership-proof statement when a started current record may be included.
- Redis serializer/compression and both prefix layers are correct.
- Current-session invalidation cannot resurrect the deleted ID or track its unauthenticated replacement.
- Existing `Store::invalidate()` and the new repository path both enforce that invariant without suppressing login-time regeneration.
- Multi-guard unresolved writes preserve the correct owner; unowned writes explicitly clear it; Auth provider selection remains coroutine-isolated.
- Cluster failure ordering never commits an owner-tagged payload unless that operation first updated the expected owner's index, and never lets a stale index delete an unproven/live session.
- User docs, README, config, Composer metadata, migration stub, facade annotations, workflows, and tests all describe the final design only.
- Targeted tests, database matrix, Redis/Valkey integration, `composer fix`, full tests, and `git diff --check` pass in their available environments.
