# Cache Serializable-Class Policy and Model-Cache Safety

## Status

The design is signed off.

## Objective

Make PHP object caching secure by default without requiring applications to repeat
framework-owned model classes in `cache.serializable_classes`.

The finished design must:

- preserve freshly reloaded package configuration in replacement Swoole workers;
- keep one global cache unserialization policy across every manager-built
  built-in direct PHP-serializing cache store;
- let framework and package providers contribute lazily resolved classes during boot;
- automatically support the ordinary Auth and Sanctum model classes;
- accept every type-preserving native Redis serializer for first-party model
  caches while rejecting type-destroying modes;
- correct the Sanctum token/tokenable cache flow;
- remove stale values from every reachable failover leaf on `forget()` and `flush()`;
- retain no per-store allowlist machinery, compatibility layer, duplicate validator,
  stale documentation, model-graph preprocessing, extra backend request, or
  additional serialization pass.

Hypervel-specific backwards compatibility and minimizing churn are not design
constraints. Laravel API parity remains a design constraint; every planned
difference has explicit approval and is described at its relevant section. The
final tree should look as though object caching, first-party identity caches,
composite stores, and long-lived workers were designed together.

## Verified Problem

### Hypervel cannot copy Laravel's static configuration model

Laravel reads cache configuration during a short-lived request and does not ship
Hypervel's Auth user cache or Sanctum token/tokenable cache. Hypervel:

- memoizes cache stores for the worker lifetime;
- can construct a serializing store during another provider's `boot()`;
- permits an application provider to select a custom Sanctum token model later in
  the same boot sequence;
- caches Eloquent models across requests in Auth and Sanctum.

Passing the current `cache.serializable_classes` value into a store constructor
therefore freezes an incomplete list. A per-store declaration API would not fix
this cleanly: `stack` and `failover` themselves do not unserialize, while their
transitive leaves do, and a `null` store name depends on `cache.default`. The
allowlist is consequently global and shared by all serializing cache stores.

Application boot is not the final configuration boundary for a Swoole worker.
The `serve` command boots providers in the master process, then
`ReloadDotenvAndConfig` rebuilds configuration during each worker's
`BeforeWorkerStart`. `LoadConfiguration` copies the rebuilt items into the same
configuration repository object, so provider-captured configuration repositories
can observe the worker values. Application `booted()` callbacks do not run again.

`ConfigMutationTracker` currently records the result of every boot-time
`Repository::set()`. Package `mergeConfigFrom()` and
`replaceConfigRecursivelyFrom()` calls therefore record complete arrays after
their `env()` expressions and application overrides have been evaluated in the
master. Worker reload briefly installs the fresh values, then mutation replay
writes those master snapshots over them. An unpublished package config is restored
only from the stale snapshot; a published package config is likewise overwritten.

The tracker must preserve two different semantics:

- replay an authoritative value when the mutation result itself must match master
  state, such as `server.servers` after Swoole listeners have been created;
- replay an operation when its result depends on configuration and environment
  state rebuilt for the worker, as both package config merge helpers do.

Package merge operations must consequently be re-evaluated against the fresh
worker repository without rerunning providers or changing ordinary boot-mutation
replay.

Policy finalization and configured model-store validation must consequently use
two explicit schedules:

- console and test processes finalize and validate from application `booted()`
  callbacks because they never dispatch worker-start events;
- Swoole processes finalize and validate from independent, unguarded
  `AfterWorkerStart` listeners in every ordinary worker and taskworker, after
  configuration reload and before the worker-start coordinator resumes.

The server master deliberately remains unfinalized and retains its small resolver
closures for the server lifetime. It serves no requests. Do not place finalization
or validation inside `CreateSwooleTimers`: that listener intentionally runs only
on worker zero and never on taskworkers.

The dual schedule is required because Auth and Sanctum cache enablement can change
with the worker environment. Finalizing from `booted()` in server mode would
freeze the master's contribution list and skip validation when freshly reloaded
worker configuration enables a feature.

Local Laravel references remain useful for public cache behavior, but not for this
worker-lifetime policy. These paths are relative to the monorepo root
(`../../../examples/laravel/framework` from this worktree):

- `examples/laravel/framework/src/Illuminate/Cache/CacheManager.php`;
- `examples/laravel/framework/src/Illuminate/Cache/Repository.php`;
- `examples/laravel/framework/src/Illuminate/Cache/SessionStore.php`;
- `examples/laravel/framework/src/Illuminate/Cache/FailoverStore.php`.

### Framework-known classes should not require application declarations

`cache.serializable_classes` defaults to `false`. Auth and Sanctum documentation
currently tells every application to add its root models manually. This has three
problems:

1. The framework already knows the configured Eloquent provider and Sanctum token
   model classes.
2. `EloquentUserProvider::withQuery()` and custom model `$with` properties can add
   nested classes that configuration cannot derive.
3. `Repository::handleIncompleteClass()` checks only a top-level
   `__PHP_Incomplete_Class`. A valid root model can contain denied relation objects
   and pass that check.

A local round-trip probe confirmed the classes in an ordinary to-many graph:

1. root model only: missing `Hypervel\Database\Eloquent\Collection`;
2. root plus collection: missing the related application model;
3. many-to-many relations can additionally contain the stock `Pivot` or
   `MorphPivot`.

`Model::__sleep()` merges cached casts and clears cast caches before returning the
serialized property list, so Carbon cast objects are not an additional automatic
allowlist requirement.

The cache write always contains a valid serialized payload. PHP creates incomplete
objects only when a later restricted read encounters a denied class. A denied root
reaches `Repository::handleIncompleteClass()`. A denied nested object follows PHP
and Laravel behavior: direct property or method use fails and names the class,
while Eloquent `toArray()` / `toJson()` omits an incomplete relation entirely
rather than returning it as `null`. The framework must document those signals and
the declaration remedies without adding model preprocessing to ordinary cache
operations.

### Sanctum's current cache flow defeats its tokenable cache

`SanctumGuard::user()` calls `isValidAccessToken()` before
`PersonalAccessToken::findTokenable()`. Provider validation inside
`isValidAccessToken()` reads `$accessToken->tokenable`, causing a database query.
`findTokenable()` then returns a second instance from its cache. The object used
for provider validation is not necessarily the one passed to `withAccessToken()`.

The existing cache suite uses a coroutine-local `array` store with
`serialize => false` without crossing a coroutine/request boundary. Repeated
lookups therefore return the same live token object and mask both the query and
object-identity defects. `withAccessToken()` also mutates the user model, so a
worker-shared store that retains live objects would be unsafe across coroutines.

Token refresh has two further defects:

- `updateLastUsedAt()` writes the live PAT back to cache after `tokenable` may have
  been loaded. The PAT entry can then carry a stale tokenable and repopulate the
  dedicated tokenable entry after it expires.
- the PAT `updating` listener clears both the PAT and tokenable entries before
  every update. With last-used tracking enabled, which is the default, a fresh
  token cannot take the interval early return because `last_used_at` is `null`.
  Its first successful authentication therefore populates the tokenable entry and
  deterministically deletes it during the audit write in the same request. The
  next request queries the user again.

The guard's last-used setting is fixed when the guard is constructed, so changing
`sanctum.last_used_at` at runtime does not alter an existing guard.

### Store suitability is semantic, not just serializability

Auth has a private outer-store whitelist; Sanctum has no equivalent. The correct
shared rules are:

| Store | Model cache decision | Reason |
|---|---|---|
| Redis | allow conditionally | Shared/persistent; the serializer must preserve PHP object types. |
| Database | allow | Shared/persistent and policy-aware. |
| File | allow | Persistent; node-local deployments must accept node-local invalidation. |
| Storage | allow | Persistent and policy-aware. |
| Swoole | allow | Node-local; valid for explicitly single-node use. |
| Stack | recurse | Every leaf must be suitable. |
| Array | reject | Coroutine-local, so it is not a cross-request cache. |
| Worker array | reject | Per-worker copies diverge; unserialized mode also shares mutable objects across coroutines. |
| Null | reject | Does not cache. |
| Session | reject | Per-session identity is not a cross-request shared identity cache. |
| Failover | reject | First-success writes and unreachable leaves can later expose stale identities. |

A failover `[redis, database]` can write only Redis, fall back to an old database
value during an outage, or fail to delete an unavailable Redis value and serve it
after recovery. Even an all-leaf best-effort delete cannot revoke an unreachable
primary. Nesting failover inside a stack must not bypass rejection.

For Redis model caches, a native phpredis serializer bypasses PHP's
`allowed_classes` policy, but bypass is not itself a functional failure:

- PHP and igbinary preserve the Eloquent runtime type;
- msgpack preserves PHP classes and references when `msgpack.php_only=1`;
- JSON and msgpack with `msgpack.php_only=0` return arrays instead of models.

The validator therefore rejects type-destroying and unknown modes while accepting
type-preserving native serializers. Compression does not affect the decision.

### The session cache belongs to the Session serialization boundary

`Hypervel\Cache\SessionStore` places live values into the session and does not
serialize or unserialize them itself. `Hypervel\Session\Store` serializes the whole
session later using the explicitly configured Session strategy.

Session serialization defaults to JSON and its JSON value semantics are
documented. `SessionStore` preserves literal dotted cache keys by storing the
`_cache` collection as one flat map. Inner PHP framing would contradict the
selected Session serializer, add permanent work, and break the literal-key
design. `SessionStore`, its tests, and the existing Session documentation are not
changed by this work.

Do not add a session-wide class-policy entry to `docs/todo.md`. JSON is the
deliberate default, and the PHP option already warns that object deserialization
increases gadget-chain risk when the application key is compromised. Session's
policy bypass is therefore a documented boundary, not an unfinished framework
gap.

### Failover invalidation currently stops after the first non-throwing leaf

`FailoverStore::forget()` and `flush()` use `attemptOnAllStores()`, whose name is
misleading: it returns on the first non-throwing call. Stale lower-priority values
therefore survive successful invalidation. Laravel currently has the same behavior;
Hypervel should deliberately diverge.

Leaf `forget()` booleans cannot be folded as success:

- Redis, file, array, session, and Swoole commonly return `false` when a key was
  absent;
- database returns `true` regardless of affected rows.

For `forget()`, reaching a leaf without an exception is successful invalidation
regardless of that boolean. `flush()` booleans do represent a real operation result
and remain foldable.

## Final Architecture

```text
cache.serializable_classes
          │
          ▼
 SerializableClassPolicy ◄── lazy provider contributions during boot
          │
          ├── shared by every built-in direct PHP-serializing cache store
          └── finalized once per console process or Swoole worker

 AuthServiceProvider ───────► configured cached provider models
 SanctumServiceProvider ────► PAT model + Sanctum guard provider models
 App/package providers ─────► application-specific relations and morph targets
```

The policy is an instance owned by `CacheManager`. It is not static, not in the
cache factory contract, not per store, and has no interface. Every built-in
direct PHP-serializing store created by that manager receives the same object.

### Performance invariants

- Package config merge operations are captured during master boot and re-evaluated
  once during each worker's existing config replay. This adds no request-time
  config or cache work.
- The serializable-class feature adds no additional serialization/deserialization
  pass, graph walk, provider resolution, backend request, or container lookup to
  Auth/Sanctum cache operations. Sanctum's correctness fixes add only an
  in-memory relation assignment after tokenable resolution, an in-place relation
  removal on a cold PAT miss, and one clone with that relation removed on the
  existing audit refresh write.
- Native Redis deserialization returns before the shared PHP policy. Policy-aware
  direct stores add one nullable-policy check and, after finalization, a method
  call plus two in-memory conditionals before the same `unserialize()`; a
  tiny-string microbenchmark measured roughly 70–80 ns, while real model
  deserialization and backend I/O dominate.
- Configured store/stack/serializer/msgpack-ini inspection runs once at process
  start. `EloquentUserProvider::enableCache()` repeats the same validation once
  when a provider is constructed so direct construction cannot bypass the
  invariant; cached guards do not repeat it on user lookups. Automatic
  contributed class names are resolved only at startup. Resolver closures are
  cleared when each console process or Swoole worker finalizes; the non-serving
  server master keeps its inherited copies.
- `SessionStore` gains no framing, policy call, or serialization work.
- Sanctum's audit-write predicate runs only when the existing last-used interval
  permits a database write; ordinary hot hits still return before model events.
  That write performs one small dirty-key comparison and one fewer cache delete.
- Failover's extra all-leaf work is limited to correctness-critical `forget()` and
  `flush()`; reads and writes remain first-success.

## 1. Preserve Fresh Package Configuration During Worker Reload

### Record derived package config as operations

Update `Hypervel\Foundation\Configuration\ConfigMutationTracker` without adding a
second tracker or changing the Config repository. Replace its inaccurate
class-level `@internal` annotation with prose that identifies it as
framework-internal boot-mutation infrastructure rather than a userland API.

Allow the existing ordered log to hold raw mutations and semantic operations:

```php
/**
 * @var list<array<array-key, mixed>|Closure(Repository): void>
 */
protected array $mutations = [];
```

Add one public method for the cross-package framework consumer:

```php
/**
 * Apply and record a configuration operation for worker replay.
 *
 * Boot-only. The operation is retained for worker-start replay and is
 * re-evaluated against the freshly rebuilt configuration repository.
 *
 * @param Closure(Repository): void $mutation
 */
public function applyAndRecord(Repository $config, Closure $mutation): void
{
    $wasRecording = $this->recording;
    $this->recording = false;

    try {
        $mutation($config);
    } finally {
        $this->recording = $wasRecording;
    }

    if ($wasRecording) {
        $this->mutations[] = $mutation;
    }
}
```

This applies the operation during master boot while suppressing the raw
`Repository::set()` notifications it produces. A failed operation restores the
prior recording state and exits before appending.

Keep one ordered replay loop. It must seal recording before invoking any entry so
semantic closures cannot append their internal `set()` calls to the log while it
is being replayed:

```php
public function replay(Repository $config): void
{
    $this->recording = false;

    foreach ($this->mutations as $mutation) {
        if ($mutation instanceof Closure) {
            $mutation($config);

            continue;
        }

        $config->set($mutation);
    }
}
```

Do not add a public contract, DTO, enum/mode discriminator, retry, rollback layer,
or second observer. Raw mutations keep exact value replay in their existing order.

### Re-evaluate the two package config merge helpers

In `Hypervel\Support\ServiceProvider`, import
`Hypervel\Config\Repository as ConcreteConfigRepository` and the Foundation
tracker. `hypervel/support` already declares its Foundation dependency, so no
package metadata changes are required.

After each existing cached-config early return, resolve the tracker through the
container so tests and framework boot retain normal container behavior. Keep one
concise WHY comment at the resolution: package config merges depend on worker
environment/config reload, so Foundation replays the operation rather than its
master-process result.

`mergeConfigFrom()` captures only its path, key, and provider-derived
`mergeableOptions()` list in a static closure:

```php
$config = $this->app->make('config');
$mergeableOptions = $this->mergeableOptions($key);

$this->app->make(ConfigMutationTracker::class)->applyAndRecord(
    $config,
    static function (ConcreteConfigRepository $config) use ($path, $key, $mergeableOptions): void {
        $packageDefaults = require $path;
        $appConfig = $config->array($key, []);
        $merged = array_merge($packageDefaults, $appConfig);

        foreach ($mergeableOptions as $option) {
            if (isset($packageDefaults[$option], $appConfig[$option])) {
                $merged[$option] = array_merge(
                    $packageDefaults[$option],
                    $appConfig[$option],
                );
            }
        }

        $config->set($key, $merged);
    },
);
```

Capturing the list instead of the provider avoids retaining service-provider
instances while preserving overridden `mergeableOptions()` declarations.

`replaceConfigRecursivelyFrom()` uses the same tracker API with its existing,
distinct merge rule:

```php
static function (ConcreteConfigRepository $config) use ($path, $key): void {
    $config->set($key, array_replace_recursive(
        require $path,
        $config->array($key, []),
    ));
}
```

Do not route ordinary provider `Config::set()` calls through semantic replay.
Their values may intentionally describe master-created resources.

## 2. Add the Shared Serializable-Class Policy

### Policy class

Add `src/cache/src/SerializableClassPolicy.php`.

`SerializableClassPolicy` is final because finalization is the security invariant
and there is no alternate implementation. Its normalized result is
`list<class-string>|false|null`, where `null` means unrestricted.

```php
final class SerializableClassPolicy
{
    /**
     * @var list<Closure(): array<array-key, class-string>>
     */
    private array $resolvers = [];

    /** @var list<class-string>|false|null */
    private array|false|null $resolvedClasses = null;

    private bool $finalized = false;

    /**
     * Create a serializable class policy.
     *
     * @param null|(Closure(): (null|array|bool)) $configuredClassesResolver
     */
    public function __construct(
        private ?Closure $configuredClassesResolver = null,
    ) {
    }

    /**
     * Register classes that PHP may unserialize.
     *
     * Boot-only. Contributions are held for the process-wide policy and cannot
     * change after finalization.
     *
     * @param Closure(): array<array-key, class-string> $resolver
     *
     * @throws LogicException
     */
    public function allowUsing(Closure $resolver): void
    {
        if ($this->finalized) {
            throw new LogicException(
                'Serializable cache classes must be declared from a service provider boot() method before the cache policy is finalized during process startup.'
            );
        }

        $this->resolvers[] = $resolver;
    }

    /**
     * Finalize the serializable class policy.
     *
     * Boot-only. The result is frozen for every subsequent cache read in the
     * process.
     *
     * @internal
     */
    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }

        $this->resolvedClasses = $this->resolve();
        $this->finalized = true;
        $this->configuredClassesResolver = null;
        $this->resolvers = [];
    }

    /**
     * Unserialize a cached value through the effective policy.
     */
    public function unserialize(string $value): mixed
    {
        $classes = $this->finalized
            ? $this->resolvedClasses
            : $this->resolve();

        return $classes === null
            ? unserialize($value)
            : unserialize($value, ['allowed_classes' => $classes]);
    }
}
```

`resolve()` must implement these exact semantics:

| Configured resolver/value | Effective result |
|---|---|
| no configured resolver | unrestricted `null`; do not execute any resolver |
| `null` or `true` | unrestricted `null`; do not execute any resolver |
| `false`, no declarations | `false` |
| `false`, declarations | declaration list |
| explicit array | configured list followed by declarations |

Normalize and validate each source before unioning it. PHP ignores allowlist keys
and does not autoload names merely because they appear in `allowed_classes`, so
accept arbitrary array keys and unknown class names from both sources. Discard
each source's keys before spreading it; otherwise matching string keys across
configuration and a resolver silently overwrite an explicitly allowed class:

```php
foreach ($classes as $key => $class) {
    if (! is_string($class)) {
        throw new InvalidArgumentException(/* identify source and actual key */);
    }
}

return array_values($classes);
```

Invalid config types, non-array resolver results, and non-string entries fail during
process-start finalization. Associative arrays are accepted and normalized per
source before the union. This prevents matching associative keys in independently
supported sources from silently discarding an operator-configured class and
producing an incomplete object on read. Invalid-entry messages name the source and
the entry's actual integer or string key. After all normalized sources have been
appended, stable dedupe with `array_values(array_unique($classes))`. Unknown names
are retained without eager autoloading and simply never match unless that class is
available when PHP unserializes a value. Keep the
`false`-with-no-contributions return before final normalization. Resolver
exceptions propagate unchanged. Clearing the closures after finalization avoids
retaining dead provider captures for the worker lifetime. The existing actionable
`LogicException` remains sufficient for late registration.

### CacheManager API and lifecycle

Construct one policy in `CacheManager::__construct()`. The configuration resolver
must read `$this->app` at evaluation time, not capture the constructor argument,
so the existing tests-only `setApplication()` behavior remains coherent before
finalization:

```php
$this->serializableClassPolicy = new SerializableClassPolicy(
    fn () => $this->app->make('config')->get('cache.serializable_classes'),
);
```

Add:

```php
/**
 * Register classes that cache stores may unserialize.
 *
 * Boot-only. The resolver contributes to the worker-lifetime cache policy and
 * is evaluated after every provider has booted: at application boot completion
 * in console processes or after configuration reload in each Swoole worker. An
 * earlier cache read evaluates the current contributions without memoizing them.
 *
 * @param Closure(): array<array-key, class-string> $resolver
 *
 * @throws LogicException
 */
public function allowSerializableClassesUsing(Closure $resolver): static
{
    $this->serializableClassPolicy->allowUsing($resolver);

    return $this;
}

/**
 * Finalize the worker-lifetime serializable class policy.
 *
 * Boot-only. The policy is frozen for the worker lifetime; later
 * contributions throw and every subsequent unserialize uses the frozen list.
 *
 * @internal
 */
public function finalizeSerializableClasses(): void
{
    $this->serializableClassPolicy->finalize();
}
```

Add only the extension API to the Cache facade metadata:

```php
 * @method static \Hypervel\Cache\CacheManager allowSerializableClassesUsing(\Closure $resolver)
```

Place it beside the manager-level
`handleUnserializableClassUsing()` entry in
`Hypervel\Support\Facades\Cache`, before the repository-forwarded methods.

Keep the method off `Hypervel\Contracts\Cache\Factory`: third-party factories do
not have to implement Hypervel's manager capability. Defining it directly on the
manager is mandatory because an unknown method would otherwise reach `__call()`,
resolve the default store, and silently invoke a repository method.

Delete `CacheManager::getSerializableClasses(array $config)`. This is an approved
Laravel API difference: the correct Hypervel policy is global and live, while the
protected Laravel hook is evaluated per store. Preserving that meaning would
require a shared contribution registry plus per-store policy views and
finalization; calling it with fabricated config would retain the signature while
silently breaking subclass behavior. Record the removal concisely in
`src/cache/README.md` and at the method's natural source location.

Keep `CacheServiceProvider::boot(): void` parameterless. Resolve
`CacheManager::class` only in the console branch, then schedule finalization
according to the process:

```php
if ($this->app->runningInConsole()) {
    $cache = $this->app->make(CacheManager::class);

    $this->app->booted(
        fn () => $cache->finalizeSerializableClasses(),
    );
} else {
    // Worker configuration is reloaded during BeforeWorkerStart.
    $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event): void {
        $this->app->make(CacheManager::class)->finalizeSerializableClasses();
    });
}
```

The console callback captures the boot-resolved worker-safe manager. The server
listener resolves the manager at event time, following the event-listener
rebinding rule.
Register it as a distinct listener after the existing timer listener, with no
worker-ID or taskworker guard. The timer listener registers lazy callbacks whose
store resolution happens after worker start. Any store another startup listener
resolves earlier already holds the shared policy object and observes finalization,
so listener order does not affect correctness; letting timer registration failures
surface first is clearer.

Do not add an injected parameter, including an optional one. PHP treats both as
incompatible with a downstream parameterless override and fails while declaring
the subclass. Boot-time container resolution reaches the same aliased singleton
and has no functional or hot-path cost, so changing this Hypervel-owned public
surface has no benefit.

No `AfterEachTestSubscriber` entry is needed. The policy, validator, and manager
hold instance state discarded with the test container, and
`Sanctum::flushState()` already resets its static model and callbacks.

All providers are registered before any provider boots. Auth may therefore declare
classes from its new `boot()` method even though `AuthServiceProvider` precedes
`CacheServiceProvider` in the alphabetical provider list. Do not reorder providers.
Auth/Sanctum structural store validation may run before Cache finalization because
it does not read the class policy and resolved stores share the policy object.

### Add the live policy without replacing Laravel store APIs

Preserve every Laravel-compatible
`array|bool|null $serializableClasses = null` constructor parameter, its name,
position, default, and protected property. Add a separate nullable trailing
`SerializableClassPolicy` parameter/property for manager-created stores. The
unserializer selects the live policy when injected and otherwise retains the
existing scalar path byte-for-byte:

```php
if ($this->serializableClassPolicy !== null) {
    return $this->serializableClassPolicy->unserialize($value);
}

if ($this->serializableClasses !== null) {
    return unserialize($value, ['allowed_classes' => $this->serializableClasses]);
}

return unserialize($value);
```

Apply this dual path to Laravel-compatible `ArrayStore`, `DatabaseStore`,
`FileStore`, `StorageStore`, and `RedisStore`. Keep the scalar property on
Hypervel's `AbstractArrayStore` so `ArrayStore` subclasses retain the inherited
Laravel extension surface; `WorkerArrayStore` inherits it. Both the scalar and
policy properties, constructor parameters, and dual-path `unserialize()` live on
`AbstractArrayStore`. `ArrayStore::__construct()` keeps Laravel's two parameters,
adds the trailing nullable policy, and forwards both serialization inputs to the
parent. Hypervel-only `SwooleStore` receives a promoted non-null policy directly,
with a fresh unrestricted policy as its constructor default. Do not add a trait,
mode flag, policy factory, or constructor union that exposes a policy object
through Laravel's protected scalar property.

`StorageStore::path()` uses stable, unseeded `xxh128` over
`$this->prefix . $key`. Keep the real prefix in the digest input so shared disks
preserve store namespace separation across workers, restarts, and nodes.

At every store-construction site, the manager passes its single policy instance
through the named trailing `serializableClassPolicy:` argument and never passes
the scalar argument. This prevents the policy from binding positionally to the
restored Laravel scalar parameter, especially in constructors with several
earlier arguments. Direct Laravel-style construction continues to accept
positional and named scalar arguments and follows PHP's native allowlist behavior
without policy resolution.

Redis keeps both inputs through its existing helper because
`Redis\Support\Serialization::phpUnserialize()` is the actual unserialization
boundary and `RedisStore` has no unserializer. Retain the helper's scalar
constructor parameter and add a separate trailing nullable policy:

```php
public function __construct(
    protected array|bool|null $serializableClasses = null,
    protected ?SerializableClassPolicy $serializableClassPolicy = null,
) {
}
```

`RedisStore::getSerialization()` passes both fields to the helper. Its
`phpUnserialize()` retains the numeric fast return, then selects the injected live
policy, the existing scalar allowlist, or unrestricted PHP unserialization in that
order. Do not wrap a scalar in a policy: an unfinalized wrapper would re-resolve it
on every direct-store read. The helper's native-serializer return remains before
`phpUnserialize()`, so native phpredis serializers bypass both paths exactly as
before and remain available for ordinary caches and first-party model caches when
they preserve object types.

Keep `FileStore::lock()` on Laravel's `new static(...)` extension path and pass
both serialization inputs positionally. The lock store can address the same
directory and key namespace as ordinary file-cache values, so it must not lose
the manager-injected live policy. The trailing positional policy remains
compatible with subclasses that declare Laravel's four-argument constructor,
because PHP accepts extra positional arguments for user-defined methods.

Do not inject the policy into `SessionStore`; Session owns that value boundary.
Do not route Swoole's internal lock arrays, interval indexes, metadata, or
`SerializableClosure` resolver payload through the user-value policy. The resolver
is framework-created internal metadata, signed by `laravel/serializable-closure`
when the application key is configured, and may legitimately capture arbitrary
application objects. It is not reachable through a public user cache key and
cannot use the user-value class list. Only the existing cached-user-value
unserializer changes. In particular, keep the increment path's internal
`unserialize($record['value'], ['allowed_classes' => false])` hardcoded deny-all;
it reads a numeric record before casting and is not the user-value boundary.

Retain direct-construction scalar tests and add live-policy tests separately.
Ordinary direct construction remains unrestricted by default.

## 3. Contribute Framework-Owned Model Classes Automatically

### Auth

Add `hypervel/cache: ^0.4` and `hypervel/core: ^0.4` to
`src/auth/composer.json`. Auth directly imports cache classes but does not declare
the dependency; its server lifecycle listener directly imports
`AfterWorkerStart`.

Add a parameterless `AuthServiceProvider::boot(): void`, importing
`Hypervel\Contracts\Config\Repository as ConfigRepository`. Resolve
`CacheManager::class` and `ConfigRepository::class` once at the top through the
provider's container. Capture these worker-safe, unconditionally needed
dependencies in its lazy resolver and console booted callback. The Swoole event
callback resolves its dependencies from the container at event time. Required or
optional injected parameters buy nothing and can make a downstream subclass that
already declares `boot(): void` fail at class declaration. Give the provider one
private `cachedEloquentProviders()` parser. Use that parser for both class
contribution and startup store validation so the two paths cannot drift:

```php
/**
 * @return list<array{
 *     name: string,
 *     model: class-string<Model&Authenticatable>,
 *     store: ?string
 * }>
 */
private function cachedEloquentProviders(ConfigRepository $config): array;
```

The list carries normalized provider names explicitly so a usable numeric
configuration key such as `0` is represented as `"0"` rather than being coerced
back to an integer array key. From `boot()`, call the manager extension with a
lazy resolver. It returns models only for Eloquent providers whose own cache is
enabled:

```php
$cache->allowSerializableClassesUsing(function () use ($config): array {
    $models = [];

    foreach ($this->cachedEloquentProviders($config) as $settings) {
        $models[] = $settings['model'];
    }

    return $models === [] ? [] : [
        ...$models,
        EloquentCollection::class,
        Pivot::class,
        MorphPivot::class,
    ];
});
```

Read providers with `$config->array('auth.providers')`. Ignore non-array entries
because they cannot select a cache-enabled Eloquent provider. For array entries,
selection exactly matches `CreatesUserProviders::createEloquentProvider()`: the
driver must be `eloquent`, caching is enabled with
`! empty($provider['cache']['enabled'])`, and the store is read with
`$provider['cache']['store'] ?? null`. Cast each selected PHP array key to its
string provider identifier, including usable numeric and empty-string keys. For
each selected provider, require a class-string implementing both Eloquent `Model`
and the Auth
`Authenticatable` contract, and require the store to be a string or `null`. Throw
an `InvalidArgumentException` naming the provider and invalid field. The boot
validator and runtime provider must select the same provider set. The three
framework graph containers cover ordinary Eloquent relation shapes. Custom
collection/pivot subclasses and every application relation model remain manual
declarations.

Keep one concise source comment that declaration happens in `boot()`: all providers
have registered the cache binding by then, while the resolver remains lazy until
policy finalization. Do not encode this dependency by reordering providers.

### Sanctum

Keep Laravel Sanctum's public parameterless
`SanctumServiceProvider::boot(): void`. Resolve `CacheManager` and
`Hypervel\Contracts\Config\Repository as ConfigRepository` once at the top through
the provider's container, then capture those worker-safe dependencies in the
resolver and console booted validator. Resolve dependencies from the container
inside the Swoole event callback, as required for event listeners. Retain the same
pattern for existing event/listener callbacks. Add `hypervel/core: ^0.4` to
`src/sanctum/composer.json` for the direct `AfterWorkerStart` import.

Register a lazy resolver that returns an empty list unless
`sanctum.cache.enabled` is true. When enabled, it contributes:

- `Sanctum::personalAccessTokenModel()`, evaluated only at policy finalization;
- Eloquent provider models referenced by guards whose driver is `sanctum`;
- the three stock Eloquent graph containers when at least one model was found.

```php
$cache->allowSerializableClassesUsing(function () use ($config): array {
    if (! $config->boolean('sanctum.cache.enabled')) {
        return [];
    }

    $models = [Sanctum::personalAccessTokenModel()];

    foreach ($config->array('auth.guards') as $guardName => $guard) {
        if (! is_array($guard) || ($guard['driver'] ?? null) !== 'sanctum') {
            continue;
        }

        $providerName = $guard['provider'] ?? null;

        if (! is_string($providerName)) {
            continue;
        }

        $provider = $config->get("auth.providers.{$providerName}");

        if (! is_array($provider) || ($provider['driver'] ?? null) !== 'eloquent') {
            continue;
        }

        $model = $provider['model'] ?? null;

        if (! is_string($model)
            || ! is_a($model, Model::class, true)
            || ! is_a($model, Authenticatable::class, true)) {
            throw new InvalidArgumentException(
                "Authentication provider [{$providerName}] model must be an Eloquent authenticatable class."
            );
        }

        $models[] = $model;
    }

    return [
        ...$models,
        EloquentCollection::class,
        Pivot::class,
        MorphPivot::class,
    ];
});
```

Ignore entries that cannot select a Sanctum guard backed by an Eloquent provider;
automatic discovery must not make unused malformed/custom Auth configuration fail
worker startup. Validate the model only after an Eloquent provider is selected.
Do not guess arbitrary morph targets or models produced by custom providers.
Applications declare those, nested `withQuery()` / `$with` relation models,
custom collections, custom pivots, other application-owned nested objects, and
Auth providers constructed directly rather than represented by
`auth.providers.*` through the same public API:

```php
Cache::allowSerializableClassesUsing(fn (): array => [
    Team::class,
    Organization::class,
]);
```

This is additive to an explicit configured array and dedupes across Auth, Sanctum,
and application/package providers.

## 4. Add One Shared Model-Cache Store Validator

Add:

- `src/cache/src/ModelCacheStoreValidator.php`;
- `src/cache/src/Exceptions/UnsupportedModelCacheStoreException.php`.

`UnsupportedModelCacheStoreException` extends `InvalidArgumentException`, matching
the configuration-error contract already exposed by
`EloquentUserProvider::enableCache()`.

The validator is an ordinary open service with no mutable state apart from its
injected `RedisConfig`. Applications can already replace container services, so
`final` would not protect the gate and would only prevent faithful typed test
doubles. It accepts a resolved cache repository and a feature description through
one public method:

```php
/**
 * Validate that the store can safely cache models.
 *
 * @throws UnsupportedModelCacheStoreException
 */
public function validate(
    CacheRepository $repository,
    string $feature,
): void
```

Import `Hypervel\Contracts\Cache\Repository as CacheRepository`; the validator
uses only the contract's `getStore()` method and must not narrow callers to
Hypervel's concrete repository.

Do not add a second traversal method, capability result, result object, or enum.
The recursive operation:

1. recursively validates every `StackStore` layer;
2. never short-circuits stack validation;
3. applies the Redis serializer/type-preservation rule to `RedisStore`;
4. accepts database, file, storage, and Swoole leaves;
5. rejects every other store, including failover.

Implement the recursion as one private store method carrying a zero-based layer
path for errors. Normalize `StackStore`'s constructor result with `array_values()`
so its existing ordered-layer invariant becomes an actual list. This also fixes
tag-composition errors assigning duplicate or incorrect layer numbers when
manager-built configuration contains string keys. Correct the constructor input
docblock to `array<array-key, StackStoreProxy|Store>` because those keyed inputs
are valid. A stack visits every proxy returned by `getStores()` and recurses through
`StackStoreProxy::getStore()`. Supported leaves return; a rejected leaf throws
`UnsupportedModelCacheStoreException` naming the feature, concrete store class,
and full stack path. Do not use reflection, unwrap repositories more than once,
or add accessors to stores that are always rejected.

Expose the stack layers without leaking its mutable property:

```php
/**
 * Get the underlying store layers.
 *
 * @return list<StackStoreProxy>
 *
 * @internal
 */
public function getStores(): array
{
    return $this->stores;
}
```

`StackStoreProxy::getStore()` already exists. No accessor is required on failover
because failover is always rejected.

### Redis serializer inspection

For Redis, obtain the connection name from:

```php
$store->getContext()->connectionName()
```

Read only:

```php
$options = $this->redisConfig
    ->connectionConfig($connectionName)['options'] ?? [];
```

`connectionConfig()` already merges shared and connection options with connection
options winning. `RedisConnection::CONNECTION_LEVEL_PHPREDIS_OPTIONS` excludes the
serializer, so there is no second location to reproduce.

Scan the merged array in iteration order. Recognize both:

- case-insensitive string key `serializer`;
- numeric key `Redis::OPT_SERIALIZER`.

Both keys can coexist because they are distinct PHP array keys.
`RedisConnection::setOptions()` applies them in array order, so the last recognized
entry is the effective serializer:

```php
$serializer = Redis::SERIALIZER_NONE;

foreach ($options as $option => $value) {
    if ((is_string($option) && strtolower($option) === 'serializer')
        || $option === Redis::OPT_SERIALIZER) {
        $serializer = (int) $value;
    }
}
```

Apply these exact decisions:

| Effective serializer | Accept | Class-policy behavior |
|---|---:|---|
| `SERIALIZER_NONE` | yes | PHP applies the shared policy. |
| `SERIALIZER_PHP` | yes | Native serializer bypasses the policy. |
| `SERIALIZER_IGBINARY` when defined | yes | Native serializer bypasses the policy. |
| `SERIALIZER_MSGPACK` when defined and `msgpack.php_only=1` | yes | Native serializer bypasses the policy. |
| `SERIALIZER_JSON` | no | Models become arrays. |
| msgpack with `msgpack.php_only=0` | no | Models become arrays. |
| unknown/future values | no | Type preservation is unverified. |

Conditionally inspect igbinary/msgpack constants with `defined()` / `constant()`;
these constants depend on the phpredis build. For msgpack, read
`ini_get('msgpack.php_only')` during boot validation and accept it only when
`filter_var($value, FILTER_VALIDATE_BOOL)` returns `true`. Document
`msgpack.php_only` as a `php.ini` worker-start setting: request-time `ini_set()`
mutates process-global behavior across Swoole coroutines. Do not add a behavioral
probe or connect to Redis. A rejected Redis store error names the connection,
effective serializer value, and the type-preservation reason; msgpack errors name
the required `msgpack.php_only=1` setting.

The msgpack decision was verified in an isolated PHP 8.4 / msgpack 3.0.1 /
phpredis 6.3.0 build through both phpredis helpers and an actual Redis round trip.
PHP-only mode preserved concrete Eloquent graphs, protected/private properties,
enums, dates, shared references, cycles, and serialization hooks; non-PHP mode
returned arrays. MessagePack does not deduplicate repeated strings: its PHP
extension emits every string/class name in full and tracks references only for
objects and arrays. Igbinary does intern repeated strings and class names, which
particularly suits Eloquent graphs with repeated attribute keys. User docs give
the structural distinction and say to benchmark the real workload; exact
environment-dependent multipliers remain non-contractual plan research only.

`SERIALIZER_PHP` preserves types but bypasses the class policy. Recommend
`SERIALIZER_NONE` when choosing only for cache serialization; native PHP remains
valid when uniform connection-wide native serialization is intentional.
Compression remains unrestricted. Comment that config inspection deliberately
avoids `RedisConnection::serialized()`, which would open a live connection at
boot.

This gate applies only to first-party Auth/Sanctum Eloquent identity caches.
Ordinary Redis cache usage remains unrestricted, and its existing documented
native-serializer policy bypass remains unchanged.

### Validation call sites and timing

Replace `EloquentUserProvider::SUPPORTED_AUTH_CACHE_STORES` and
`ensureSupportedAuthCacheStore()` with the shared validator. `enableCache()` calls
it before mutating provider state. Tag validation remains Auth-specific and follows
the structural validation:

```php
$container = Container::getInstance();
$cache = $container->make('cache')->store($storeName);

$container->make(ModelCacheStoreValidator::class)->validate(
    $cache,
    "Auth user cache for model [{$this->model}]",
);
```

Both feature providers validate configured stores at the same process-ready
boundary as policy finalization:

- Auth validates every cache-enabled Eloquent provider store;
- Sanctum validates its configured/default store when token caching is enabled.

Auth reuses `cachedEloquentProviders()` so its callback has the same configuration
selection rules as the runtime consumer. Extract one private validation method
that receives the manager and configuration repository. Resolve the validator
once inside it, but only after finding an enabled provider:

```php
private function validateCachedEloquentProviders(
    CacheManager $cache,
    ConfigRepository $config,
): void {
    $providers = $this->cachedEloquentProviders($config);

    if ($providers === []) {
        return;
    }

    $validator = $this->app->make(ModelCacheStoreValidator::class);

    foreach ($providers as $settings) {
        $validator->validate(
            $cache->store($settings['store']),
            "Auth user provider [{$settings['name']}]",
        );
    }
}
```

In console/test processes, an application `booted()` callback invokes this method
with the boot-resolved manager and configuration repository. In server mode, an
unguarded `AfterWorkerStart` listener invokes it with dependencies resolved from
the container at event time. Give both Auth and Sanctum scheduling branches the
same concise source comment: worker configuration is reloaded during
`BeforeWorkerStart`, so server validation must follow it. Do not introduce a
shared lifecycle helper or cache-owned readiness event for three small explicit
branches.

`EloquentUserProvider::enableCache()` calls the same method before enabling the
cache. Keep both validation sites: startup validation fails a bad deployment
before traffic, while `enableCache()` preserves the provider's invariant when it
is constructed or used directly. Tag validation remains subsequent and
Auth-specific.

Sanctum normalizes `null` and `''` to the default store exactly as `getCache()`
does. Its private validation method checks the supplied config repository first,
resolves the validator once, and validates the repository without retaining any
result. It resolves neither the validator nor a cache store when the feature is
disabled. The console callback captures the boot-resolved manager/config
repository; the server listener resolves both at event time.

This guarded resolution is deliberate: the callback fires only once, whereas the
lazy policy resolvers may run repeatedly before finalization and therefore capture
their dependencies. Hoisting the validator would eagerly construct it and its
`RedisConfig` dependency for every application even when both model caches retain
their disabled defaults.

This resolves configured stores even when no request authenticates. Redis and
database store construction opens no backend connection, and failing at boot is
intentional. The policy need not be finalized before this structural validation.
Every ordinary worker and taskworker validates after its own configuration reload;
no validation shares `CreateSwooleTimers`' worker-zero guard. All startup listeners
finish before `WorkerStartCallback` resumes the worker-start coordinator.

Configuration is boot-only. Sanctum currently re-reads its store name on every
operation, so the docs explicitly prohibit runtime changes. Do not add per-operation
revalidation or static capability state for unsupported runtime configuration
mutation.

Unit tests that construct a bare container bind a validator double. Integration
tests exercise the real validator and all recursive compositions.

## 5. Correct Sanctum Token and Tokenable Caching

### One tokenable instance per authentication

Preserve Laravel Sanctum's protected `isValidAccessToken()` name, signature,
aggregate semantics, short-circuiting, and authentication callback. Correct only
its tokenable resolution: when the temporal checks pass, call the cache-aware
`findTokenable()` instead of lazy-loading the relation directly.

```php
protected function isValidAccessToken(?PersonalAccessToken $accessToken): bool
{
    if (! $accessToken) {
        return false;
    }

    $model = Sanctum::$personalAccessTokenModel;
    $isValid
        = (! $this->expiration || $accessToken->getAttribute('created_at')->gt(now()->subMinutes($this->expiration)))
        && (! $accessToken->getAttribute('expires_at') || ! $accessToken->getAttribute('expires_at')->isPast())
        && $this->hasValidProvider($model::findTokenable($accessToken));

    if (is_callable(Sanctum::$accessTokenAuthenticationCallback)) {
        $isValid = (bool) (Sanctum::$accessTokenAuthenticationCallback)($accessToken, $isValid);
    }

    return $isValid;
}
```

`user()` continues to call this hook, then calls `findTokenable()` after a true
result. The default implementation has already loaded the exact relation in
memory, so that second method call performs no cache or database operation. A
subclass override that returns true without resolving the relation causes exactly
one resolution afterward. The existing `&&` chain still avoids tokenable
resolution for an expired token unless the callback overrides the failure.

A hash-verified token whose tokenable fails provider validation may populate the
tokenable cache before rejection. That is deliberate: only a holder of the valid
token reaches this path, and caching the resolved identity does not change the
rejection or authorization result.

`findTokenable()` first returns an already-loaded relation, including a loaded
`null`, then otherwise resolves and sets the relation on the passed live PAT on
both cache hits and misses:

```php
if ($accessToken->relationLoaded('tokenable')) {
    return $accessToken->getRelation('tokenable');
}

if (! config('sanctum.cache.enabled')) {
    return $accessToken->getAttribute('tokenable');
}

$cache = self::getCache();
$tokenable = $cache->rememberNullable(/* ... */);
$accessToken->setRelation('tokenable', $tokenable);

return $tokenable;
```

This keeps the live token's relation shape identical on hits and misses, lets
`Sanctum::$accessTokenAuthenticationCallback` read the already-resolved exact
tokenable without a second query, and preserves the current event-facing shape.
It does not put the relation into the PAT cache because every PAT write strips it.
When Sanctum caching is disabled and no relation is loaded, the method keeps its
existing direct `getAttribute('tokenable')` return; Eloquent lazy loading already
sets the relation, so do not add a redundant `setRelation()` to that branch.

### Never cache tokenable inside the PAT entry

Inside `findTokenUsingCache()`:

```php
return static::find($id)?->unsetRelation('tokenable');
```

The miss callback owns the freshly loaded model, so strip it in place rather than
cloning it. This ensures the first miss and later hits have the same PAT shape,
including when a custom PAT has `tokenable` in `$with`.

When a custom PAT eager-loads `tokenable` through `$with`, Eloquent has already
issued a relation query before the miss callback strips it, so the later
`findTokenable()` call issues a second tokenable query. Accept that nonstandard
cold-miss cost: the hot path is unchanged, while preserving the eager-loaded
instance would require special-case cache plumbing or a per-miss allocation.

After a successful `updateLastUsedAt()`, write a clone stripped of only
`tokenable`:

```php
$cache->put(
    $cacheKey,
    $this->withoutRelation('tokenable'),
    $ttl,
);
```

Preserve every other custom PAT relation. Those relations remain subject to graph
class declarations and may become incomplete on a restricted read when undeclared.
Never call `withoutRelations()`: it would silently change custom model shape.
The refresh clone is required because the original PAT is attached to the
authenticated user through `withAccessToken()` and remains request-visible through
`currentAccessToken()`. Mutating it would discard the exact resolved tokenable and
can cause a later lazy-load query.
The tokenable cache miss keeps its existing `rememberNullable()` behavior without
any model preprocessing.

### Preserve tokenable cache across internal audit writes

Keep full cache invalidation for deletion and every application-visible PAT
update. Narrow only Sanctum's own `last_used_at` audit write. Retain the existing
cache-enabled guard. The `updating` event fires before Eloquent adds `updated_at`,
so the listener can identify that write without adding state:

```php
static::updating(function (PersonalAccessToken $model): void {
    if (! config('sanctum.cache.enabled')) {
        return;
    }

    $dirty = $model->getDirty();

    // Eloquent fires updating before adding updated_at, so this exact dirty set
    // identifies Sanctum's internal audit write.
    if (array_keys($dirty) === ['last_used_at']) {
        self::forgetTokenEntry(self::getCache(), $model->id);

        return;
    }

    self::clearTokenCache($model->id);
});
```

Extract a protected static
`forgetTokenEntry(CacheRepository $cache, int|string $tokenId): void` helper for
the PAT entry. `clearTokenCache()` resolves its repository once, passes it to the
helper, then forgets the tokenable entry through the same repository. Its public
behavior is unchanged and no path gains a duplicate container/config lookup. The
`deleting` listener continues to call `clearTokenCache()`.

Do not generalize the exception to every update that leaves tokenable identity
fields unchanged. Abilities, expiry, and other application-visible mutations stay
on the conservative full-invalidation path; only the framework's proven
audit-write defect needs narrower behavior. Do not add listener-order machinery:
application event listeners that mutate the model after this listener are an
explicit Eloquent extension point, not an invariant this cache layer must
preserve.

This makes the configured tokenable TTL the real maximum staleness bound. Changes
to a tokenable model do not automatically evict token-id-keyed entries; immediate
reflection requires explicit `clearTokenCache()` calls for that model's tokens.
Document that contract instead of adding a reverse tokenable-to-token index.

### Normalize Sanctum's static model default

`Sanctum` repeats `PersonalAccessToken::class` in the static property initializer
and `flushState()`. Follow the repository invariant by adding one protected typed
`DEFAULT_PERSONAL_ACCESS_TOKEN_MODEL` constant and referencing it from both
places. Preserve the property's `class-string<TToken>` type; if PHPStan loses the
class-string refinement through the typed constant, add the correct class-string
docblock to the constant rather than widening the property.

## 6. Make Failover Invalidations Reach Every Leaf

Keep first-success behavior for reads, writes, increments, locks, touch, and prefix
resolution. Change only `forget()` and `flush()`.

Keep Laravel's protected `attemptOnAllStores()` name and last-exception behavior
for first-success operations. Correct its inaccurate docblock to state that it
returns after the first store call that does not throw; naming cleanup does not
justify breaking the protected extension point.

Add one focused helper that:

- invokes the named method on every configured repository;
- collects successful return values in order;
- retains the last thrown exception, matching Laravel's first-success primitive;
- dispatches `CacheFailedOver` for newly failing leaves using the existing
  per-coroutine dedupe;
- updates the existing failing-cache context with all leaves that threw;
- returns results plus the last exception.

Factor the existing event/dedupe check into one small failure-recording method used
by both first-success and every-store loops. Do not duplicate the
`CacheFailedOver` decision or create a generalized operation strategy:

```php
protected function recordStoreFailure(
    string $store,
    Throwable $exception,
    array $failingCaches,
): void {
    if (! in_array($store, $failingCaches, true)
        && $this->events->hasListeners(CacheFailedOver::class)) {
        $this->events->dispatch(new CacheFailedOver($store, $exception));
    }
}
```

Each catch block records the shared failure behavior before retaining the
exception and leaf in its own operation state:

```php
} catch (Throwable $exception) {
    $this->recordStoreFailure($store, $exception, $failingCaches);

    $lastException = $exception;
    $failedCaches[] = $store;
}
```

The caller's existing `finally` still replaces the per-coroutine failing-cache
set, so the healthy leaf is not retained as failing.

```php
/**
 * @return array{0: list<mixed>, 1: ?Throwable}
 */
protected function attemptOnEveryStore(string $method, array $arguments): array;
```

Do not add a boolean mode flag. Each caller maps the primitive to its own contract.

`forget()`:

```php
[$results, $exception] = $this->attemptOnEveryStore(__FUNCTION__, func_get_args());

if ($results === []) {
    throw $exception ?? new RuntimeException('All failover cache stores failed.');
}

return $exception === null;
```

Ignore completed leaf booleans. Return `true` when every leaf was reached without
an exception, including an absent key. Return `false` after partial exceptions.

`flush()`:

```php
[$results, $exception] = $this->attemptOnEveryStore(__FUNCTION__, func_get_args());

if ($results === []) {
    throw $exception ?? new RuntimeException('All failover cache stores failed.');
}

return $exception === null
    && ! in_array(false, $results, true);
```

Thus:

- all leaves throw: rethrow the last exception, matching Laravel failover;
- partial exception: both methods return `false` after trying all leaves;
- `forget()` ignores an absent-key `false`;
- `flush()` returns `false` for any completed false.

This improves generic failover invalidation but does not make failover suitable for
identity caches: an inaccessible primary can still retain and later serve stale
identity data.

Keep a concise source explanation on `forget()` / `flush()` that Hypervel
deliberately reaches every leaf because first-success invalidation resurrects stale
values. Do not add historical commentary to unrelated methods.

## 7. Configuration, Documentation, and Cleanup

### Configuration

Update `src/foundation/config/cache.php` to describe:

- the global policy;
- `false`, array, and unrestricted `null`/`true` semantics;
- why `false` is the secure default for forged cache payloads;
- provider-contributed framework classes;
- native Redis serializer bypass for general caches.

Update the matching Auth cache blocks in `src/foundation/config/auth.php` and the
committed Testbench skeleton at `src/testbench/hypervel/config/auth.php`:

- supported leaves include storage;
- stacks are recursively validated;
- array, worker-array, null, session, and failover are rejected;
- delete the stale "only the outer store is validated" caveat;
- retain honest node-local and L1 TTL behavior;
- state that storage invalidation is shared only when its configured disk is
  shared;
- correct the touched comments to the repository's American English spelling.

Keep Sanctum cache configuration concise. State that the TTL is the maximum
staleness bound for cached tokenable identity. Put the fuller boot-only and
invalidation explanation in the Sanctum documentation rather than duplicating a
long config comment.

### Cache documentation

Update `src/boost/docs/cache.md`:

- explain automatic union and stable dedupe;
- document `Cache::allowSerializableClassesUsing()` as a boot-only lazy resolver;
- show application-owned nested object, relation, and morph declarations rather
  than root Auth/Sanctum declarations;
- document the existing optional
  `Cache::handleUnserializableClassUsing()` callback while preserving Laravel's
  default no-op behavior;
- explain that a denied nested class follows PHP/Laravel behavior: direct use
  reports an incomplete object and names the class, while Eloquent
  `toArray()` / `toJson()` omits an incomplete relation rather than returning it
  as `null`; identify the stable error phrase contained in both method-call and
  property-access failures and explain that it normally means the class was
  denied by this policy rather than missing from the autoloader, while removed
  classes require clearing stale entries;
- list the policy-aware direct serializing drivers and explicitly exclude
  `SessionStore`, whose values use the Session serializer;
- explain that native Redis serializers bypass the class policy;
- distinguish igbinary's repeated-string/class table from msgpack's compact
  binary encoding, require PHP-only msgpack for models, and recommend workload
  benchmarks without publishing environment-dependent multipliers;
- document all-leaf failover `forget()` / `flush()` behavior.

Do not change the Session cache documentation. It already states that the store
follows the configured Session serializer and documents the intentional JSON
object-fidelity semantics.

### Auth documentation

Update `src/auth/README.md` and
`src/boost/docs/authentication.md`:

- delete instructions to list the root user model manually;
- state that enabled configured provider models and stock Eloquent graph
  containers are automatic;
- explain manual nested relation/custom collection/custom pivot/other object
  declarations, including directly constructed providers not represented in
  `auth.providers.*`;
- make clear that those declarations apply to PHP-policy serialization paths;
  accepted native Redis serializers preserve types while bypassing the policy;
- link to the cache documentation's denied-nested-class behavior and remedies;
- state that Auth cache configuration is boot-only;
- document recursive store validation and storage support;
- explain why failover is rejected;
- document accepted type-preserving Redis modes and the policy bypass;
- require `msgpack.php_only=1` for msgpack and reject JSON/unknown modes;
- remove the stale outer-stack caveat;
- correct the `withQuery()` promise: its cached shape is retained only when all
  application relation classes are declared;
- describe node-local microcaching benefits without unsupported fixed hit-rate or
  nanosecond-latency claims, and state the bounded L1 staleness tradeoff directly
  without an “out of scope” label;
- replace the stale “array after the upcoming rewrite” wording with its current
  coroutine-local behavior;
- say Auth-specific tag-mode validation runs when the provider is created, not at
  application boot;
- replace the claim that password changes are irrelevant with the real
  invalidation rule: Eloquent model writes clear the user entry, while eventless
  writes require explicit invalidation or accept the TTL bound;
- describe config callbacks as worker-start configuration rather than request-time
  state;
- correct the touched README table heading to American English.

### Sanctum documentation

Update `src/sanctum/README.md` and `src/boost/docs/sanctum.md`:

- delete manual root PAT/user allowlist instructions;
- state automatic lazy PAT and configured Sanctum guard provider declarations;
- explain manual custom-provider morph targets, nested `$with` relations, custom
  Eloquent containers, and other application-owned nested objects;
- make clear that those declarations apply to PHP-policy serialization paths;
  accepted native Redis serializers preserve types while bypassing the policy;
- link to the cache documentation's denied-nested-class behavior and remedies;
- document recursive supported-store validation, failover rejection, and the same
  type-preserving Redis rules;
- state cache configuration, including `sanctum.last_used_at`, is boot-only;
- explain that the PAT cache never embeds `tokenable`, while the live token receives
  the exact resolved tokenable before callbacks/events;
- explain that deletion and application-visible PAT updates clear both entries,
  while Sanctum's internal `last_used_at` write clears only the PAT entry;
- state that the tokenable TTL is its actual maximum staleness bound, tokenable
  model changes do not automatically evict token-id-keyed entries, and immediate
  reflection requires explicit clearing;
- retain the guidance that cache TTL should be greater than or equal to the
  last-used update interval;
- keep custom PAT selection documented as a provider-boot operation.

### Stale-source cleanup

After implementation, whole-tree searches must find no live:

- class-level `@internal` annotation on `ConfigMutationTracker`;
- package config helper that records an evaluated merge result as a raw mutation;
- `getSerializableClasses()` method;
- `SUPPORTED_AUTH_CACHE_STORES`;
- docs telling users to add ordinary Auth/Sanctum root models manually;
- docs claiming only the outer stack store is validated;
- docs claiming every PAT update clears both PAT and tokenable entries;
- Sanctum cache test using `array` with `serialize => false`;
- runtime custom PAT selection in cache tests;
- cache policy injection or inner framing in `SessionStore`.

Include test configuration in this audit, not only documentation. In
`tests/Integration/Auth/EloquentUserProviderCacheTagsTest.php`, remove the manual
`[User::class]` config so the test proves the automatic provider declaration
works. Retain
`tests/Integration/Cache/Redis/BasicOperationsIntegrationTest.php`'s
`[stdClass::class]`: that is a genuine application-owned class and remains the
explicit configured-array path. Keep a separate Auth assertion for the additive
union with explicit application classes.

Do not edit local Laravel examples.

## 8. Testing Plan

### Worker configuration replay coverage

Add `tests/Foundation/Configuration/ConfigMutationTrackerTest.php`:

- raw and semantic entries replay in their exact shared order;
- a semantic operation sees the fresh repository state;
- internal `set()` calls are not recorded as duplicate raw mutations;
- recording is sealed before replaying closures, so the log cannot grow during
  iteration;
- a failed initial operation restores the prior recording state and is not
  appended;
- a raw child-key override recorded after a semantic root merge still wins;
- post-replay mutations retain the existing sealed behavior.

Extend `tests/Support/SupportServiceProviderTest.php` with a real tracker bound
through its existing application double. Preserve all current merge assertions and
add replay coverage for:

- fresh package defaults and application overrides;
- overridden `mergeableOptions()` nested merging without retaining the provider;
- `replaceConfigRecursivelyFrom()` using its distinct recursive rule;
- cached configuration resolving neither Config nor the tracker.

Extend `tests/Foundation/Listeners/ReloadDotenvAndConfigTest.php` with an isolated
temporary package config whose value uses the fixture environment. Register a
small inline provider after the master load, switch to `.env.testing`, run the
real reload listener, and prove the post-replay package value is
`testing_value`, not the master `default_value`. Also prove an unpublished package
config is restored by semantic replay. Existing Reverb/server tests remain the
regression for exact raw value replay.

### SerializableClassPolicy unit coverage

Add `tests/Cache/SerializableClassPolicyTest.php`:

- a policy with no configured resolver resolves to unrestricted;
- no configured resolver does not invoke declarations;
- `null` and `true` normalize to unrestricted and never invoke resolvers;
- `false` with no declarations remains `false`;
- `false` plus declarations becomes the declaration list;
- configured and declared classes are both accepted in one graph;
- multiple feature resolvers union their classes;
- pre-finalization `unserialize()` recomputes and does not memoize;
- finalization freezes once and clears resolver captures;
- post-finalization declaration throws the actionable `LogicException`;
- resolver exceptions propagate;
- invalid config type, non-array resolver result, and non-string entries fail with
  the source and actual entry key;
- associative configured and resolver arrays are accepted;
- colliding string keys in a configured array and contributed resolver preserve
  both values;
- unknown names are retained without autoloading and do not match unrelated
  serialized objects;
- duplicates across normalized sources still restore every distinct declared
  class;
- unrestricted `unserialize()` restores an object;
- `false` restores a denied object as `__PHP_Incomplete_Class`;
- configured and contributed classes restore their concrete objects.

### Manager/provider lifecycle coverage

Extend `tests/Cache/CacheManagerTest.php`; add focused provider lifecycle coverage
in `tests/Cache/CacheServiceProviderTest.php`,
`tests/Auth/AuthServiceProviderTest.php`, and
`tests/Sanctum/SanctumServiceProviderTest.php`:

- every direct serializing driver receives the same policy object;
- a store constructed before a later declaration sees the final policy;
- `build()` outside `$stores` also sees later declarations;
- `setApplication()` is read at evaluation time before finalization;
- providers booted before and after `CacheServiceProvider` can both contribute
  before process finalization;
- a custom PAT selected in an application provider's `boot()` is included;
- the Auth provider may declare before Cache's `boot()` without provider reordering;
- Cache, Auth, and Sanctum console callbacks capture their boot-resolved
  worker-safe manager/config repository, while server event callbacks resolve
  dependencies at event time;
- a Cache provider fixture overrides parameterless `boot()`, calls
  `parent::boot()`, and proves the existing server listeners and new lifecycle
  registration still occur;
- a Sanctum provider fixture overrides parameterless `boot()`, calls
  `parent::boot()`, and proves its real boot registrations still occur;
- console/test processes finalize and validate from application `booted()`;
- server application boot leaves the inherited master policy unfinalized until a
  worker-start event;
- server workers use config reloaded into the same captured repository object;
- policy finalization and Auth/Sanctum validation run for worker IDs beyond zero
  and taskworkers, independently of Swoole timer eligibility;
- disabled features never resolve the guarded validator;
- facade metadata reaches the manager method rather than resolving a store.

Do not add reflection-only signature assertions or an Auth provider subclass
fixture. The two behavior fixtures pin the existing non-empty parent boot methods
and fail loudly at class declaration if a parameter is added; Auth has no parent
boot behavior for such a fixture to test.

Leave
`tests/Cache/CacheFailoverStoreTest.php::testIncompleteClassHandlerRunsOnceAcrossFailoverRepositories()`
unchanged. Do not introduce a framework default handler or exception-specific
failover behavior.

Retain the existing scalar allowed-class suites for every Laravel-compatible
direct store, including positional and named `serializableClasses` construction.
Keep direct-store coverage proving that a store constructed with neither a scalar
nor a policy uses native unrestricted unserialization.
Add separate manager-injected live-policy coverage. Hypervel-only `SwooleStore`
takes a promoted non-null policy with a fresh unrestricted policy as its default;
the Redis-support helper keeps its scalar input and adds the named trailing
policy. On one direct store, construct both a scalar and a policy and prove the
policy takes precedence. Add or retain denied/allowed object round trips for:

- serializing array and worker-array;
- database;
- file, while its lock clone retains both the scalar Laravel constructor input
  and the manager-injected live policy;
- storage;
- Redis's PHP-owned serialization path when the native serializer is disabled;
- Swoole public values.

In `tests/Cache/Redis/Support/SerializationTest.php`, pass the live policy with the
named trailing `serializableClassPolicy:` argument. Keep separate scalar-constructor
coverage; do not let a policy test bind to the retained scalar parameter by
position.

Extend `tests/Cache/CacheStorageStoreTest.php` with an exact path assertion derived
from `hash('xxh128', $prefix . $key)` using a non-empty prefix.

Extend `tests/Cache/CacheStackStoreTest.php` with the regression that a
string-keyed layer configuration reports the correct zero-based layer index in
tag-composition errors.

### ModelCacheStoreValidator coverage

Add `tests/Cache/ModelCacheStoreValidatorTest.php`:

- accepts Redis/Database/File/Storage/Swoole and subclasses;
- rejects Array/WorkerArray/Null/Session/Failover;
- accepts an all-supported stack recursively;
- rejects unsupported and failover leaves at any nesting depth;
- reports the feature/store/layer clearly;
- scans merged Redis options without resolving a live connection;
- accepts an absent serializer and numeric-key or string/case-varied
  `serializer` entries set to `SERIALIZER_NONE`, regardless of compression;
- accepts native PHP, defined igbinary, and defined PHP-only msgpack while
  documenting their class-policy bypass;
- rejects JSON, msgpack with PHP-only disabled, and unknown/future modes;
- reports the Redis connection, effective serializer, and actionable reason for
  every rejection;
- conditionally covers build-dependent igbinary/msgpack constants;
- reads msgpack PHP-only configuration without probing behavior or resolving a
  live connection;
- validates every nested stack leaf, including a later unsupported leaf after
  earlier supported leaves;
- reports the full layer path for nested failover stores and rejected Redis
  serializers;
- honors shared-versus-connection option merge;
- honors last occurrence when string/case-varied and numeric serializer keys
  coexist.

Auth unit tests prove `enableCache()` validates before any state, descriptors, or
listeners mutate, while tag validation remains subsequent and Auth-specific.
Startup lifecycle integration tests prove every configured enabled provider store
is validated without resolving a guard, using the exact runtime `! empty()`
enablement and `store ?? null` normalization. They also cover malformed provider
entries being ignored, accepted numeric/empty provider names, and selected invalid
models/store values through the parser's named configuration exceptions.

Sanctum startup lifecycle tests prove disabled caching resolves no store and
enabled caching validates the configured/default store before requests without
retaining runtime state. With a restricted class policy, they also prove malformed
or unrelated guard/provider entries are ignored by automatic discovery, custom
providers remain manual, and an invalid selected Eloquent model fails with the
intended configuration exception rather than a subscript/type error.

### Auth cache coverage

Use a real serializing supported store in integration tests:

- `cache.serializable_classes = false` plus automatic provider declaration caches
  and returns the root user with zero second lookup query;
- explicit configured classes union with automatic classes;
- automatic contributions contain the configured root and stock Eloquent
  collection/pivot classes but not application relation or custom container
  classes;
- an undeclared to-one relation becomes incomplete on the cache hit, direct use
  names the denied class, and `toArray()` omits the relation key rather than
  returning `null`;
- a to-many relation restores the stock Eloquent collection while undeclared
  related application models remain incomplete;
- declaring relation classes through the facade makes the full graph round-trip;
- isolated real Redis integration in
  `tests/Integration/Auth/EloquentUserProviderRedisCacheTest.php` conditionally
  round-trips a cached user under serializer-none policy enforcement plus native
  PHP, igbinary, and PHP-only msgpack, proving each available accepted mode
  returns the concrete model and avoids a second database query. Use
  `InteractsWithRedis`; do not make the general Auth integration suite depend on
  Redis.

### Sanctum cache and guard coverage

Replace the `array`/unserialized fixture in
`tests/Sanctum/PersonalAccessTokenCacheTest.php` with an isolated real file store,
`cache.serializable_classes = false`, and a configured Eloquent provider for
`TestUser`.

Cover:

- automatic default PAT and provider model declarations;
- custom PAT chosen from provider boot is included;
- missing token and tokenable nullable sentinels;
- second token lookup performs no PAT query;
- second tokenable lookup performs no user query;
- guard authentication in two fresh coroutine/request contexts performs zero
  PAT/user queries in the second context;
- provider validation, callback relation, and `withAccessToken()` use the exact
  same tokenable instance;
- the authentication callback can read `$accessToken->tokenable` without another
  query;
- cached PAT copies never have `tokenable` loaded;
- miss and hit PAT shapes match even when custom PAT `$with` includes tokenable;
- `updateLastUsedAt()` refresh strips tokenable and cannot reseed stale user state;
- with last-used tracking enabled, a fresh token's first full guard authentication
  leaves its tokenable entry cached, and a second fresh request/coroutine performs
  zero PAT and user queries;
- other custom PAT `$with` relations are preserved;
- automatic declarations include the PAT and configured guard provider models but
  not custom-provider morph targets or application relation classes;
- declaring custom provider/morph targets and custom PAT relation classes restores
  the complete graph;
- cached users are independent objects, preventing cross-coroutine access-token
  mutation leakage;
- internal audit writes forget only the PAT entry;
- application-visible PAT updates and deletion still forget both PAT and
  tokenable entries;
- PAT updates with caching disabled resolve no cache repository and perform no
  invalidation;
- public `clearTokenCache()` still forgets both entries.

Rewrite tests that mutate `sanctum.cache.store`, cache enablement, or token model
after boot as separate boot configurations/providers. Do not bless runtime config
mutation.

### Failover coverage

Extend `tests/Integration/Cache/FailoverStoreTest.php`:

- `forget()` and `flush()` invoke every leaf;
- absent-key false from Redis-style leaves does not make `forget()` false;
- one throwing leaf plus one completion returns false without throwing;
- partial failures dispatch/dedupe `CacheFailedOver` and update context;
- all leaves throwing rethrows the last exception after trying all;
- first-success operations also rethrow the last exception when every leaf
  fails, preserving Laravel's existing failover behavior;
- `flush()` returns false for a completed false;
- `flush()` returns false for partial exception;
- all successful flushes return true;
- reads and writes remain first-success;
- the same partial-outage store can continue first-success writes after a failed
  all-leaf invalidation.

### Documentation/config tests

Update config defaults and package documentation assertions where present.
`tests/Testbench/DefaultConfigurationTest.php` continues to assert the default
`false` policy.

## 9. Verification and Review

Run focused tests immediately after each subsystem:

```bash
./vendor/bin/phpunit --no-progress tests/Foundation/Configuration/ConfigMutationTrackerTest.php
./vendor/bin/phpunit --no-progress tests/Foundation/Listeners/ReloadDotenvAndConfigTest.php
./vendor/bin/phpunit --no-progress tests/Support/SupportServiceProviderTest.php
./vendor/bin/phpunit --no-progress tests/Cache/SerializableClassPolicyTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheManagerTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheServiceProviderTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheArrayStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheWorkerArrayStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheDatabaseStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheFileStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheRepositoryTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheRedisStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/Redis/Support/SerializationTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheStorageStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheSwooleStoreIntervalTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheSwooleStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheStackStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/CacheFailoverStoreTest.php
./vendor/bin/phpunit --no-progress tests/Cache/ModelCacheStoreValidatorTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Cache/FailoverStoreTest.php
./vendor/bin/phpunit --no-progress tests/Auth/AuthEloquentUserProviderCacheTest.php
./vendor/bin/phpunit --no-progress tests/Auth/AuthServiceProviderTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Auth/EloquentUserProviderCacheTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Auth/EloquentUserProviderCacheTagsTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Auth/EloquentUserProviderRedisCacheTest.php
./vendor/bin/phpunit --no-progress tests/Sanctum/PersonalAccessTokenCacheTest.php
./vendor/bin/phpunit --no-progress tests/Sanctum/GuardTest.php
./vendor/bin/phpunit --no-progress tests/Sanctum/SanctumServiceProviderTest.php
```

Then run package/static checks and the required complete suite from repository root:

```bash
composer validate --strict src/auth/composer.json
composer validate --strict src/sanctum/composer.json
composer fix
git diff --check
```

`composer fix` runs formatting, both PHPStan configurations, the full parallel
suite, the scoped Testbench package suite, and the dogfood package suite in the
repository-defined order. Do not replace it with a partial check sequence.

After tests pass:

1. re-read `AGENTS.md` and this plan in full;
2. inspect the complete diff for config replay ordering, worker-lifetime state,
   provider timing, hot-read overhead, stale docs/comments, and duplicated policy
   logic;
3. run the stale-source searches from Section 7;
4. compare deliberate Laravel differences again against the local reference;
5. request independent source and test review;
6. resolve every finding and rerun affected focused tests plus the full required
   suite.
