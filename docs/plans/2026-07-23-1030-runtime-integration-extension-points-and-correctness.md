# Runtime Integration Extension Points and Correctness

## Status

Implementation plan for a coordinated set of Hypervel framework enhancements, package-upstream corrections, verified queue contract tests, and adjacent correctness fixes. The source audit and pre-plan second-opinion loop are complete. This document records the settled design.

Every production API below is general-purpose. The cache, Sanctum, queue, and Foundation fixes are independently correct even when no integration uses the new extension points.

Backward compatibility, minimizing churn, and preserving stale internal shapes are not design constraints. The implemented result must read as though these boundaries and semantics were designed this way from the start. This does not permit speculative machinery: every production change below fixes a source-verified defect, consolidates duplicated framework-owned behavior, or exposes one narrow lifecycle boundary with a concrete supported consumer.

## Goals

Implement all of the following as one coherent runtime-integration change:

- allow one boot-registered callback to prepare outgoing broadcast channel objects before driver formatting;
- allow the singleton URL generator to resolve an ambient application origin and route defaults lazily, while keeping asset origins on their existing dedicated path and adding a coroutine-local replacement API for non-HTTP work;
- move Sanctum's `last_used_at` write to the successful-authentication boundary, preserve read/write routing state, and port the current upstream tracking switch;
- centralize named rate-limiter key construction and allow one boot-registered scope resolver to participate before the existing hash;
- expose one protected gRPC metadata-preparation method shared by all four RPC shapes;
- pin the queue payload, processing, attempted, retry, and chain-order contracts already provided by the framework without adding another queue seam;
- correct the queue documentation to describe at-least-once delivery and non-transactional chain handoff honestly;
- make stack-cache compensation run when a lower layer throws, and stop a failed upper-layer read repair from hiding or overwriting a valid lower-layer value;
- make Meilisearch and Typesense integration-test cleanup prefixes safe by default, matching the existing Algolia behavior;
- complete the native return type of `Connection::setRecordModificationState()`;
- update user-facing documentation in the existing simple, human-friendly Hypervel style; and
- add deterministic tests for every new API, unchanged default, failure branch, state-lifetime rule, and verified regression.

## Non-goals

This plan does **not**:

- perform the larger Laravel Scout reconciliation or add Scout lifecycle hooks;
- add a callback registry, callback priorities, named callback ownership, or runtime callback removal beyond passing `null`;
- scope default-signature HTTP throttles, overlap locks, unique-job locks, or raw `RateLimiter` keys;
- add a queue wrapper, payload object, new queue event, boot-order abstraction, or retry subsystem;
- add a process-global gRPC callback or create clients per logical context;
- make `StackStore` an exception-suppressing failover store;
- add exception aggregation around failed cache compensation;
- cache URL resolver results or copy resolver state into coroutine context;
- add a lazy asset-origin resolver when `ASSET_URL` and `useAssetOrigin()` already own that policy;
- add test-only production APIs; or
- require an external service for the new tests.

## Finding summary

| Finding | Category | Severity | Evidence |
|---|---|---:|---|
| Broadcast drivers have a shared channel-formatting choke point, but no way for a package to prepare channel objects before stringification | Missing extension point | Major for cross-cutting wire naming | `Broadcaster::formatChannels()` stringifies directly; Redis delegates to it; Ably bypasses it |
| `UrlGenerator` has no lazy ambient origin/default resolver | Missing extension point | Major | `formatRoot()` and `getDefaultParameters()` only consult explicit, request, and stored default state |
| `URL::defaults()` outside an HTTP request writes worker-global state | Coroutine-safety gap | Critical for jobs and console work | `defaults()` falls through to the singleton `RouteUrlGenerator` when `RequestContext` is absent |
| `asset()` shares `formatRoot()` with application URLs when no asset root is configured | Extension-boundary hazard | Major for ambient origin policies | Putting the new resolver directly in `formatRoot()` would also rewrite asset URLs despite their separate `ASSET_URL` / `useAssetOrigin()` controls |
| Sanctum stamps a token immediately after hash verification | Audit/correctness defect | Major | `PersonalAccessToken::findToken()` writes before expiration, provider, custom-authentication, and tokenable checks |
| Sanctum's internal timestamp write makes sticky read routing choose the primary for later application reads | Performance defect | Major on split read/write connections | Eloquent save marks `Connection::$recordsModified`; the current code does not restore its prior value |
| Sanctum treats an event-cancelled timestamp save as a completed write | Correctness defect | Moderate | `Model::save()` may return `false` when `saving` or `updating` is cancelled; the current helper ignores the result |
| Current upstream Sanctum can disable `last_used_at` tracking, while Hypervel cannot | Upstream gap | Moderate | Local upstream commit `9526c2c`; Hypervel guard has no tracking flag |
| `Connection::setRecordModificationState()` returns `$this` without its native `static` return type | Type incompleteness | Minor | One declaration, no overrides, existing `$this` docblock and facade return |
| HTTP and queue named rate limiters duplicate key construction | Shared-ownership duplication | Major for cross-cutting scoping | `ThrottleRequests` and queue `RateLimited` separately concatenate and hash the same logical key |
| All four gRPC request methods duplicate default metadata merging | Missing subclass seam | Moderate | Four inline `defaultMetadata->merge()` calls in `BaseClient` |
| Queue behavior needed by integrations is present but incompletely pinned | Regression risk | Major | Payload hooks see live jobs, Context composes first, processing precedes unserialization, attempted runs in `finally`, retry preserves arbitrary payload fields |
| Queue documentation claims effectively exactly-once processing | Documentation defect | Major | The timeout section says jobs are “only successfully processed once,” then warns they may run twice |
| A chained job is dispatched before the current backend job is deleted | Delivery contract needing documentation | Major | `CallQueuedHandler::call()` dispatches the next chain member before `delete()` |
| A lower stack-cache write exception leaves already-written upper layers committed | Correctness defect | Major | `StackStore::callStores()` compensates only when a lower layer returns `false` |
| A failed upper read repair turns a valid lower hit into a miss | Correctness/data-loss defect | Critical for counters | `getOrRestoreRecord()` returns `null`; `increment()` and `incrementTagged()` then take their absent-key path |
| Meilisearch and Typesense cleanup can run with an empty prefix | Destructive test defect | Critical when external tests are enabled | Both traits define prefix computation but never invoke it; `str_starts_with($name, '')` matches every resource |
| Two Sanctum tests currently cover the same explicit `expires_at` branch | Test omission | Moderate | The configured `sanctum.expiration` branch lacks equivalent focused coverage |

## References checked

### Hypervel source

- `src/broadcasting/src/Broadcasters/Broadcaster.php`
- `src/broadcasting/src/Broadcasters/AblyBroadcaster.php`
- `src/broadcasting/src/Broadcasters/LogBroadcaster.php`
- `src/broadcasting/src/Broadcasters/PusherBroadcaster.php`
- `src/broadcasting/src/Broadcasters/RedisBroadcaster.php`
- `src/routing/src/UrlGenerator.php`
- `src/routing/src/RouteUrlGenerator.php`
- `src/routing/src/Middleware/ThrottleRequests.php`
- `src/cache/src/RateLimiter.php`
- `src/cache/src/RateLimiting/GlobalLimit.php`
- `src/cache/src/RateLimiting/Limit.php`
- `src/cache/src/RateLimiting/Unlimited.php`
- `src/cache/src/StackStore.php`
- `src/cache/src/StackStoreProxy.php`
- `src/cache/src/SwooleStore.php`
- `src/cache/src/SwooleTable.php`
- `src/sanctum/src/PersonalAccessToken.php`
- `src/sanctum/src/SanctumGuard.php`
- `src/sanctum/src/SanctumServiceProvider.php`
- `src/sanctum/config/sanctum.php`
- `src/database/src/Connection.php`
- `src/database/src/Eloquent/Model.php`
- `src/database/src/Eloquent/Concerns/HasAttributes.php`
- `src/queue/src/Queue.php`
- `src/queue/src/Worker.php`
- `src/queue/src/SyncQueue.php`
- `src/queue/src/CallQueuedHandler.php`
- `src/queue/src/Console/RetryCommand.php`
- `src/queue/src/Middleware/RateLimited.php`
- `src/log/src/Context/ContextServiceProvider.php`
- `src/grpc/src/Client/BaseClient.php`
- `src/grpc/src/Metadata.php`
- `src/foundation/src/Application.php`
- `src/foundation/src/Testing/Concerns/InteractsWithAlgolia.php`
- `src/foundation/src/Testing/Concerns/InteractsWithMeilisearch.php`
- `src/foundation/src/Testing/Concerns/InteractsWithTypesense.php`
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`
- the `Broadcast`, `URL`, `RateLimiter`, and `DB` facade annotations

### Hypervel tests and documentation

- broadcasting broadcaster/driver tests;
- routing URL-generator and throttle tests;
- Sanctum guard, token-cache, model, config, and static-state tests;
- cache rate-limiter and stack-store tests;
- queue worker, sync-queue, middleware, context, handler, and retry-related tests;
- gRPC base-client and retry tests;
- `ExternalServiceOptInTest`;
- `src/boost/docs/broadcasting.md`;
- `src/boost/docs/urls.md`;
- `src/boost/docs/sanctum.md`;
- `src/boost/docs/routing.md`;
- `src/boost/docs/grpc.md`;
- `src/boost/docs/queues.md`;
- `src/boost/docs/cache.md`; and
- `src/boost/docs/testing.md`.

### Local upstream and framework precedents

- Current local Laravel Sanctum checkout at `examples/laravel/sanctum`, including commit `9526c2c` for optional `last_used_at` tracking.
- Current upstream Sanctum's successful-authentication timestamp placement and preservation of the connection's prior modification state.
- Current local Laravel routing's falsy forced-origin fallback, which matches
  Hypervel's existing `useOrigin('')` behavior.
- `Queue::createPayloadUsing()` for a boot-only appending callback list where multiple independent payload contributions genuinely compose.
- Existing single-owner `...Using()` APIs throughout Hypervel for replaceable policy callbacks.
- `UrlGenerator::useOrigin()` and `useAssetOrigin()` for coroutine-local replacement-and-clear semantics.
- `Broadcaster::$channels` for state that must be shared by all broadcaster driver instances.
- `GlobalLimit` as the existing explicit definition-side signal that a named quota is intentionally shared globally.

No online-only fact is load-bearing. The relevant package upstream and framework sources are available in the monorepo.

## Final architecture

| Concern | Final owner and rule |
|---|---|
| Broadcast channel preparation | One worker-static callback on the base `Broadcaster`, applied once to raw channel values before stringification and driver-specific translation |
| URL ambient policy | Two boot-only callbacks stored on the singleton `UrlGenerator`, read lazily on each eligible application URL / route generation |
| URL asset policy | Existing configured or coroutine-local asset roots, then the explicit `useOrigin()` / request fallback; the new lazy origin resolver never participates |
| Explicit URL state | `useOrigin()` and new `useDefaults()` write only coroutine context and may be called during requests, jobs, scheduled work, or console operations |
| Sanctum timestamp ownership | `PersonalAccessToken::updateLastUsedAt()` owns caching/throttling; `SanctumGuard` chooses the successful-authentication lifecycle point |
| Sanctum read routing | Snapshot and restore the exact pre-save `recordsModified` state after a successful internal timestamp save |
| Named limiter keys | `RateLimiter::resolveNamedLimiterKey()` is the one key-construction owner for HTTP and queue middleware |
| Rate-limit scope | One worker-singleton callback receives the normalized named limiter; `GlobalLimit` bypasses it |
| gRPC metadata | `BaseClient::prepareMetadata()` owns default/per-call composition and is overridable by generated-style client bases |
| Queue integration | Existing Context/payload/events/retry contracts; tests and documentation only |
| Stack writes | Earlier successful layers compensate on a lower `false` or exception |
| Stack read repair | A valid lower hit is returned even when optional upper repair returns `false`; repair exceptions remain loud |
| External-search cleanup | Every search test trait computes a non-empty sequential or ParaTest prefix unless the test supplied one |

## 1. Broadcast channel preparation

### Files

- `src/broadcasting/src/Broadcasters/Broadcaster.php`
- `src/broadcasting/src/Broadcasters/AblyBroadcaster.php`
- `src/support/src/Facades/Broadcast.php`
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`
- broadcaster driver tests
- `src/boost/docs/broadcasting.md`

Redis already calls `parent::formatChannels()` before adding its configured prefix. Pusher and Log use the base method directly. Ably is the only driver that duplicates stringification and must be brought through the base boundary before translating `private-` / `presence-` into Ably namespaces.

### State and public API

Add one nullable static slot:

```php
/**
 * The callback used to format outgoing channel values.
 */
protected static ?Closure $channelFormatter = null;
```

Add one replaceable boot-only registrar:

```php
/**
 * Register the outgoing channel formatter.
 *
 * Boot-only. The callback persists in shared static state for the worker
 * lifetime and applies to every broadcaster across all coroutines.
 */
public static function formatChannelsUsing(?Closure $callback): void
{
    static::$channelFormatter = $callback;
}
```

The callback contract is `Closure(array $channels): array`. It receives the raw values supplied by the event, including channel wrapper objects, and is called exactly once per real formatting operation. Passing `null` restores stock behavior.

Do not append callbacks. Channel naming is one coherent wire policy, unlike payload contributions where several callbacks can independently add fields.

### Base and driver formatting

The base method becomes:

```php
/**
 * Format the channel array into an array of strings.
 */
protected function formatChannels(array $channels): array
{
    if (static::$channelFormatter !== null) {
        $channels = (static::$channelFormatter)($channels);
    }

    return array_map(
        static fn (mixed $channel): string => (string) $channel,
        $channels,
    );
}
```

Ably must transform the already prepared strings:

```php
/**
 * Format the channel array into an array of strings.
 */
protected function formatChannels(array $channels): array
{
    return array_map(function (string $channel): string {
        if (Str::startsWith($channel, ['private-', 'presence-'])) {
            return str_starts_with($channel, 'private-')
                ? Str::replaceFirst('private-', 'private:', $channel)
                : Str::replaceFirst('presence-', 'presence:', $channel);
        }

        return 'public:' . $channel;
    }, parent::formatChannels($channels));
}
```

Redis remains structurally unchanged because it already delegates to the base first.

### Static cleanup

Rename `flushChannels()` to the framework-wide `flushState()` and reset all three slots:

```php
/**
 * Flush all static state.
 */
public static function flushState(): void
{
    static::$channels = [];
    static::$channelOptions = [];
    static::$channelFormatter = null;
}
```

Update the global test subscriber and facade annotation. Do not retain a stale `flushChannels()` alias.

### Tests

Cover:

- no formatter produces byte-for-byte existing channel names;
- the callback receives wrapper objects before they become strings;
- callback output is stringified once;
- passing `null` unregisters the formatter;
- `flushState()` clears channels, channel options, and the formatter;
- Pusher and Log send the callback's formatted names through their real public broadcast paths;
- Redis applies the callback before its configured Redis prefix;
- Ably applies the callback before its `private:` / `presence:` / `public:` translation; and
- one broadcaster registration affects another driver instance, proving the intentional worker-static ownership.

Any touched broadcaster test that still extends raw `PHPUnit\Framework\TestCase` must be moved to `Hypervel\Tests\TestCase`, with redundant local framework-state cleanup removed.

### Documentation

Add a short “Formatting Outgoing Channels” section showing registration from a service provider:

```php
use Hypervel\Broadcasting\Broadcasters\Broadcaster;

Broadcaster::formatChannelsUsing(function (array $channels): array {
    return array_map(fn ($channel) => 'application.' . $channel, $channels);
});
```

State plainly that the callback is configured during boot, receives channel objects before driver formatting, applies to every broadcaster, and may be cleared with `null` in tests.

## 2. Lazy URL origin and default resolution

### Files

- `src/routing/src/UrlGenerator.php`
- `src/routing/src/RouteUrlGenerator.php`
- `src/support/src/Facades/URL.php`
- `tests/Routing/RoutingUrlGeneratorTest.php`
- `src/boost/docs/urls.md`

`UrlGenerator` is a worker-lifetime singleton. The resolver callbacks are therefore instance state configured once during boot. They read ambient coroutine state lazily; they must never capture request-specific values at registration.

The callbacks do not require static cleanup. The test subscriber resets the container and facades between tests, which discards the singleton instance. Adding a static `flushState()` would duplicate that lifecycle.

### State and registrars

Add:

```php
/**
 * The callback used to resolve the URL origin.
 */
protected ?Closure $originResolver = null;

/**
 * The callback used to resolve default route parameters.
 */
protected ?Closure $defaultsResolver = null;
```

And:

```php
/**
 * Register the URL origin resolver.
 *
 * Boot-only. The callback persists on the worker-shared URL generator and
 * is evaluated for subsequent URL generations across all coroutines.
 */
public function resolveOriginUsing(?Closure $resolver): void
{
    $this->originResolver = $resolver;
}

/**
 * Register the default route parameter resolver.
 *
 * Boot-only. The callback persists on the worker-shared URL generator and
 * is evaluated for subsequent route generations across all coroutines.
 */
public function resolveDefaultsUsing(?Closure $resolver): void
{
    $this->defaultsResolver = $resolver;
}
```

Both callbacks take no arguments. The origin callback returns an origin string
or `null`; an empty string is treated as no override, matching `useOrigin()`.
The defaults callback returns an array or `null`. `null` means “do not override
stock behavior.” Passing `null` to the registrar removes the callback.

Zero-argument callbacks are deliberate: these policies read ambient application/coroutine state, and no route object is needed to make the decision. Route-declared domains are intentionally outside the origin callback's eligible path.

### Origin precedence, asset boundary, and caching

For a null caller-supplied root, precedence is:

1. the current coroutine's explicit `useOrigin()` value;
2. the call-time origin resolver result;
3. the cached request/fallback root.

A non-null root supplied by `RouteUrlGenerator` for a route-declared domain remains authoritative. The resolver is not invoked in that path.

Extract the null-root selection into one protected helper. Explicit and resolved origins are never stored under `CACHED_ROOT_CONTEXT_KEY`; only the stable request fallback is cached:

```php
/**
 * Resolve the root URL for the current coroutine.
 */
protected function resolveRoot(bool $useOriginResolver = true): string
{
    $root = CoroutineContext::get(self::FORCED_ROOT_CONTEXT_KEY) ?: null;

    if ($root === null && $useOriginResolver && $this->originResolver !== null) {
        $resolvedRoot = ($this->originResolver)();
        $root = $resolvedRoot === null ? null : rtrim($resolvedRoot, '/');
    }

    return $root ?: CoroutineContext::getOrSet(
        self::CACHED_ROOT_CONTEXT_KEY,
        fn (): string => $this->getRequest()->root(),
    );
}

public function formatRoot(string $scheme, ?string $root = null): string
{
    if ($root === null) {
        $root = $this->resolveRoot();
    }

    $start = str_starts_with($root, 'http://') ? 'http://' : 'https://';

    return preg_replace('~' . $start . '~', $scheme, $root, 1);
}
```

Reading `FORCED_ROOT_CONTEXT_KEY` directly on every eligible generation is important. The current combined cache stores the forced-or-request choice; that shape would incorrectly cache a resolver result or prevent a changed ambient state from being observed.

Resolver output uses the same trailing-slash normalization as `useOrigin()`.
Falsy explicit or resolved roots fall through rather than becoming malformed
origins. This preserves the existing Hypervel and Laravel `useOrigin('')`
behavior.

`asset()` keeps its existing precedence:

1. the current coroutine's `useAssetOrigin()` value;
2. the constructor/configured asset root (`ASSET_URL`);
3. the explicit `useOrigin()` value or request root.

Only the new lazy origin resolver is excluded from step 3:

```php
$root = ($forcedAssetRoot ?? $this->assetRoot)
    ?: $this->formatRoot(
        $this->formatScheme($secure),
        $this->resolveRoot(useOriginResolver: false),
    );
```

This preserves existing Hypervel and Laravel behavior where an explicit `useOrigin()` affects the asset fallback, while preventing an ambient application-origin policy from silently moving static assets. `assetFrom()` already supplies an explicit root and remains unchanged. Do not add an asset resolver; `ASSET_URL` and `useAssetOrigin()` already cover worker-global and coroutine-local asset policy.

Once the cached root contains only the request-derived fallback, the final `CoroutineContext::forget(CACHED_ROOT_CONTEXT_KEY)` in `useOrigin()` is dead. Remove it. `setRequest()` must keep invalidating the cached root because it changes the fallback request itself.

### Default precedence

For default route parameters, precedence is:

1. worker-global defaults already stored on `RouteUrlGenerator`;
2. the resolver's call-time array;
3. explicit coroutine defaults;
4. explicit parameters passed to `route()`, which continue to win later in `RouteUrlGenerator`.

Implement:

```php
/**
 * Get the effective default named parameters used by the URL generator.
 */
public function getDefaultParameters(): array
{
    $defaults = $this->routeUrl()->defaultParameters;

    if ($this->defaultsResolver !== null
        && ($resolvedDefaults = ($this->defaultsResolver)()) !== null) {
        $defaults = array_merge($defaults, $resolvedDefaults);
    }

    return array_merge(
        $defaults,
        CoroutineContext::get(self::DEFAULT_PARAMETERS_CONTEXT_KEY, []),
    );
}
```

Do not cache the resolver result. The feature exists so a single URL generator can observe a changed job, request, or explicit execution context.

`RouteUrlGenerator` currently calls `getDefaultParameters()` twice while building one route URL: once while assigning positional parameters and again while replacing named placeholders. That is harmless for stored arrays, but a lazy callback would otherwise run twice and could produce two different snapshots if it yielded or consulted changing state.

Resolve one effective default array at the start of `RouteUrlGenerator::to()` and pass it through the protected formatting helpers:

```php
public function to(Route $route, mixed $parameters = [], bool $absolute = false): string
{
    $defaultParameters = $this->url->getDefaultParameters();
    $parameters = $this->formatParameters($route, $parameters, $defaultParameters);

    // ...

    $root = $this->replaceRootParameters(
        $route,
        $domain,
        $parameters,
        $defaultParameters,
    );

    $path = $this->replaceRouteParameters(
        $route->uri(),
        $parameters,
        $defaultParameters,
    );

    // ...
}
```

Update `formatParameters()`, `replaceRootParameters()`, `replaceRouteParameters()`, and `replaceNamedParameters()` to accept that array instead of reading it again. These methods are protected implementation details with no callers outside `RouteUrlGenerator`.

This is call-local data, not object state. Do not store the snapshot on the singleton generator, in a static, or in coroutine context. One `route()` call gets one internally consistent resolver result; a later `route()` call resolves again.

### Coroutine-local replacement

Add:

```php
/**
 * Replace the default route parameters for the current coroutine.
 */
public function useDefaults(?array $defaults): void
{
    if ($defaults === null) {
        CoroutineContext::forget(self::DEFAULT_PARAMETERS_CONTEXT_KEY);

        return;
    }

    CoroutineContext::set(self::DEFAULT_PARAMETERS_CONTEXT_KEY, $defaults);
}
```

`useDefaults()` is safe in requests, jobs, scheduled tasks, and console commands. A non-null array replaces the current coroutine map; `null` clears it. Existing `defaults()` keeps its current merge semantics:

- inside a request, merge into the current coroutine defaults;
- outside a request, configure worker-global defaults.

Update the `defaults()` docblock to direct non-HTTP operation-local callers to `useDefaults()`.

Do not add these concrete configuration methods to the small `Contracts\Routing\UrlGenerator` contract; existing concrete URL customization APIs such as `defaults()` and `useOrigin()` are not contract requirements either.

### Tests

Cover:

- stock output with both resolvers unset;
- origin resolver output and trailing-slash normalization;
- a `null` resolver result falling through to the request root;
- an empty resolver result falling through to the request root;
- explicit `useOrigin()` taking precedence without invoking the resolver;
- an empty explicit `useOrigin()` value being treated as absent and allowing
  the resolver to run;
- a route-declared domain taking precedence without invoking the resolver;
- `asset()` using the request root without invoking the resolver when no asset root is configured;
- explicit `useOrigin()` continuing to affect the asset fallback;
- `useAssetOrigin()` and the configured asset root continuing to take precedence over `useOrigin()`;
- resolver output changing between two calls in one coroutine;
- concurrent coroutines resolving different origins without leakage;
- clearing a registered resolver with `null`;
- worker-global defaults, resolved defaults, coroutine defaults, and explicit route parameters in the exact precedence order;
- `null` default resolution preserving stock behavior;
- exactly one defaults-resolver invocation per `route()` call, shared by domain and path substitution;
- resolver output changing between calls;
- route-domain placeholders receiving effective defaults;
- `useDefaults()` replacing an earlier map rather than merging;
- `useDefaults(null)` clearing the map;
- existing `defaults()` continuing to merge request defaults;
- non-HTTP `useDefaults()` remaining coroutine-local; and
- concurrent job-like coroutines not observing each other's defaults.

### Documentation

Update the origin and default-value sections with:

- boot-time lazy resolver examples;
- the precedence rules in user-facing terms;
- the fact that declared route domains are not rewritten;
- the fact that the lazy origin resolver does not apply to asset URLs, which continue using `ASSET_URL` / `useAssetOrigin()`;
- `useDefaults()` for one request/job/command;
- `defaults()` for request-local merging or deliberate boot-global defaults; and
- a warning not to call the boot-only registrars dynamically.

## 3. Sanctum successful-use tracking

### Files

- `src/sanctum/src/PersonalAccessToken.php`
- `src/sanctum/src/SanctumGuard.php`
- `src/sanctum/src/SanctumServiceProvider.php`
- `src/sanctum/config/sanctum.php`
- `src/database/src/Connection.php`
- Sanctum guard/cache/config tests
- `tests/Database/DatabaseConnectionTest.php`
- `src/boost/docs/sanctum.md`

### Correct lifecycle

`PersonalAccessToken::findToken()` must be a lookup and hash-verification operation only. Remove the timestamp write from it.

The guard updates the token only after all of the following have succeeded:

1. token lookup and hash verification;
2. configured expiration;
3. token `expires_at`;
4. provider compatibility;
5. `Sanctum::authenticateAccessTokensUsing()` callbacks;
6. tokenable lookup;
7. `HasApiTokens` support; and
8. every registered `TokenAuthenticated` listener.

The guard sequence becomes:

```php
if ($this->supportsTokens($tokenable)) {
    /** @var Authenticatable&\Hypervel\Sanctum\Contracts\HasApiTokens $tokenable */
    $user = $tokenable->withAccessToken($accessToken);

    if ($this->events?->hasListeners(TokenAuthenticated::class)) {
        $this->events->dispatch(new TokenAuthenticated($accessToken));
    }

    if ($this->trackLastUsedAt) {
        $accessToken->updateLastUsedAt();
    }

    CoroutineContext::set($contextKey, $user);

    return $user;
}
```

If an event listener throws, the request fails and the token is not stamped. This matches “last successful use” and the current upstream ordering.

### Model-owned update and caching

Replace the protected static helper with a public overridable instance method. This preserves Hypervel's existing design where the token model owns token-cache behavior and allows a custom token subclass to customize the write without replacing the guard:

```php
/**
 * Store the time the token was last used.
 */
public function updateLastUsedAt(): void
{
    $now = now();
    $cacheEnabled = (bool) config('sanctum.cache.enabled');

    if (
        $cacheEnabled
        && $this->last_used_at !== null
        && $this->last_used_at->diffInSeconds($now)
            < config('sanctum.cache.last_used_at_update_interval')
    ) {
        return;
    }

    $connection = $this->getConnection();
    $hasModifiedRecords = $connection->hasModifiedRecords();
    $previousLastUsedAt = $this->getRawOriginal('last_used_at');

    $saved = $this->forceFill(['last_used_at' => $now])->save();

    if (! $saved) {
        $this->setAttribute('last_used_at', $previousLastUsedAt);

        return;
    }

    $connection->setRecordModificationState($hasModifiedRecords);

    if ($cacheEnabled) {
        static::getCache()->put(
            static::getCacheKey($this->id),
            $this,
            config('sanctum.cache.ttl'),
        );
    }
}
```

Important details:

- capture one immutable `now()` value for both the throttle decision and saved timestamp;
- when caching is disabled, keep the existing immediate-write behavior;
- when caching is enabled, return before resolving the connection or issuing SQL if the interval has not elapsed;
- perform one save path rather than separate cached and uncached copies;
- snapshot `hasModifiedRecords()` before the internal write;
- restore the exact prior value only after a successful save;
- when a `saving` or `updating` listener cancels the save, restore the token
  model's in-memory `last_used_at` from its raw original value, leave the
  listener's connection modification state untouched, and do not refresh the
  cache;
- do **not** restore in `finally`: after a failed write, pretending the connection is still in its previous known routing state can hide a partial or ambiguous operation;
- let exceptions propagate without restoring the model, connection state, or
  cache;
- refresh the cached token only when caching is enabled and a write occurred; and
- let model events continue clearing stale token/tokenable entries during the save before the refreshed token is put back.

Accepted upstream-parity edge: a `saving` or `updating` listener could perform
another successful write during the timestamp save. Restoring the pre-save
modification-state snapshot afterward can then hide that listener write from
sticky read routing. Avoiding this rare, bounded replica-staleness case would
require connection write-generation tracking solely for model-listener
composition; do not add that machinery here.

### Tracking configuration

Add a declared package default:

```php
/*
|--------------------------------------------------------------------------
| Last Used Timestamp
|--------------------------------------------------------------------------
|
| When enabled, Sanctum records the time a personal access token last
| completed authentication successfully.
|
*/

'last_used_at' => env('SANCTUM_LAST_USED_AT', true),
```

Add a promoted guard property:

```php
protected bool $trackLastUsedAt = true,
```

Pass it from the provider using the typed repository getter:

```php
trackLastUsedAt: $app->make('config')->boolean('sanctum.last_used_at'),
```

The default remains enabled. This is current-upstream functionality adapted to Hypervel's typed configuration rule. Do not use a call-site fallback because the package config declares the default and is merged before guard creation.

### Connection typing

Complete the existing concrete fluent contract:

```php
public function setRecordModificationState(bool $value): static
{
    $this->recordsModified = $value;

    return $this;
}
```

There is one declaration, no override, and no corresponding `ConnectionInterface` requirement. Do not add it to the interface. The DB facade already advertises the concrete `Connection` return.

### Tests

Cover:

- `findToken()` performs no timestamp write;
- a bad hash does not stamp;
- configured-age expiration does not stamp;
- explicit `expires_at` expiration does not stamp;
- provider mismatch does not stamp;
- a custom authentication callback returning `false` does not stamp;
- a missing tokenable does not stamp;
- a tokenable without `HasApiTokens` does not stamp;
- successful token authentication stamps;
- a throwing `TokenAuthenticated` listener does not stamp;
- no listeners still allows stamping;
- tracking disabled performs no stamp;
- the default configuration tracks;
- cache disabled writes on each successful authentication;
- cache enabled skips a write within the interval;
- the throttled early return performs no query and does not touch connection modification state;
- cache enabled writes after the interval and refreshes the token cache;
- a cancelled timestamp save still authenticates, leaves the persisted and
  in-memory timestamp unchanged, does not refresh the cache, and preserves a
  listener-established `recordsModified === true` state;
- a successful timestamp write preserves `recordsModified === false`;
- a successful timestamp write preserves `recordsModified === true`;
- a custom token subclass's `updateLastUsedAt()` override is invoked;
- `setRecordModificationState()` returns the same concrete connection and sets either boolean exactly; and
- the two currently duplicated expiration tests are separated so one covers `sanctum.expiration` / `created_at` and the other covers token `expires_at`.

Use time travel rather than real sleeps.

### Documentation

Add a short “Last Used Timestamps” configuration section. Explain:

- tracking is enabled by default;
- `SANCTUM_LAST_USED_AT=false` disables the write;
- the timestamp is written only after successful API-token authentication;
- session authentication is unaffected; and
- when token caching is enabled, `last_used_at_update_interval` limits the write frequency.

Update the token-caching text so it never implies rejected credentials update the timestamp.

## 4. Named rate-limiter key scoping

### Files

- `src/cache/src/RateLimiter.php`
- `src/routing/src/Middleware/ThrottleRequests.php`
- `src/queue/src/Middleware/RateLimited.php`
- `src/support/src/Facades/RateLimiter.php`
- cache, routing, and queue rate-limiter tests
- `src/boost/docs/routing.md`
- the queue rate-limiting documentation where appropriate

`RateLimiter` is the worker-lifetime singleton already responsible for named limiter registration. Store one nullable resolver on that instance:

```php
/**
 * The callback used to resolve the scope for named limiter keys.
 */
protected ?Closure $keyScopeResolver = null;
```

### Registrar

```php
/**
 * Register the named limiter key scope resolver.
 *
 * Boot-only. The callback persists on the singleton rate limiter for the
 * worker lifetime and applies to subsequent named limits across coroutines.
 */
public function resolveKeyScopeUsing(?Closure $resolver): void
{
    $this->keyScopeResolver = $resolver;
}
```

The resolver receives the normalized limiter name and returns `?string`. `null` means stock behavior. An application that needs to decline selected limiters can do so by name without receiving or mutating `Limit`.

### One key-construction owner

Add:

```php
/**
 * Resolve the storage key for a named rate limit.
 */
public function resolveNamedLimiterKey(
    string $limiterName,
    Limit $limit,
    bool $shouldHashKeys = true,
): string {
    $scope = $limit instanceof GlobalLimit
        ? null
        : $this->keyScopeResolver?->__invoke($limiterName);

    // Length prefixes keep arbitrary segment values injective before hashing.
    $key = strlen($limiterName) . ':' . $limiterName
        . strlen($limit->key) . ':' . $limit->key;

    if ($scope !== null) {
        $key = strlen($scope) . ':' . $scope . $key;
    }

    return $shouldHashKeys
        ? hash('xxh128', $key)
        : $key;
}
```

Properties:

- absent scope produces the same canonical two-segment key regardless of whether a resolver is unset or returns `null`;
- length-prefixed segments keep limiter name, limit key, and optional scope boundaries injective;
- every hashed path performs one xxh128 operation, not nested hashes;
- `GlobalLimit` does not invoke the resolver and uses the canonical unscoped key;
- `Unlimited`, which extends `GlobalLimit`, is still handled before key construction by both middleware paths;
- the HTTP middleware passes its existing `self::$shouldHashKeys` flag;
- queue `RateLimited` uses the default `true`;
- limiter callback duplicate-key normalization still happens before this method;
- the default-signature `throttle:max,minutes` HTTP path remains stock and never invokes this named-limiter seam; and
- direct `RateLimiter::attempt()`, cache locks, overlap middleware, and unique-job keys remain untouched.

Replace both duplicated middleware expressions with this method. Add facade annotations for both public methods.

Do not add static cleanup. The resolver is instance state and disappears with the container-cached singleton during test cleanup.

### Tests

At the `RateLimiter` owner:

- exact hashed stock key;
- exact raw stock key;
- hashed scoped key with exactly the documented input;
- raw scoped key;
- resolver receives the normalized string name;
- `null` scope preserves stock behavior;
- passing `null` unregisters the resolver;
- `GlobalLimit` never invokes the resolver; and
- an empty `Limit` key and fallback key remain valid.

At each consumer:

- named HTTP limits use the centralized key in hashed and non-hashed modes;
- named queue limits use the same scoped hash;
- `Unlimited` still bypasses storage;
- ordinary default-signature HTTP throttling remains unscoped; and
- HTTP and queue limits with the same logical scope/name/key converge.

Change `tests/Queue/RateLimitedTest.php` from raw PHPUnit to `Hypervel\Tests\TestCase` while touching it, relying on the shared subscriber for container and Mockery cleanup.

### Documentation

Document the boot-time resolver beside named rate limiters:

```php
RateLimiter::resolveKeyScopeUsing(function (string $limiter): ?string {
    return Context::get('account_id');
});
```

Explain that it applies only to named limiters and that returning `null` keeps the normal key. Show `GlobalLimit` as the explicit way for a named quota to remain shared across all scopes. Avoid describing overlap or unique-job keys as rate limits.

## 5. gRPC metadata preparation

### Files

- `src/grpc/src/Client/BaseClient.php`
- `tests/Grpc/BaseClientTest.php`
- `src/boost/docs/grpc.md`

Extract the four identical default-metadata merges into one protected subclass boundary:

```php
/**
 * Prepare metadata for a new RPC.
 *
 * @param array<string, list<string>|string>|Metadata $metadata
 */
protected function prepareMetadata(array|Metadata $metadata): Metadata
{
    return $this->defaultMetadata->merge($metadata);
}
```

Each of `_simpleRequest()`, `_clientStreamRequest()`, `_serverStreamRequest()`, and `_bidiRequest()` calls it exactly once at RPC creation:

```php
$metadata = $this->prepareMetadata($metadata);
```

Unary and server-streaming retry factories continue capturing that prepared immutable `Metadata` object. They must not invoke the hook again. Client-streaming and bidirectional calls retain the same creation-time metadata for the lifetime of their stream.

This is a protected generated-client subclass seam, not a process-global callback. It naturally supports request IDs, service identity, tracing, logical-context propagation, and other per-client cross-cutting metadata without affecting unrelated clients.

### Tests

Extend the existing test client fixture with a controllable override and cover:

- stock default/per-call merge remains byte-for-byte unchanged;
- override invocation for unary, client-streaming, server-streaming, and bidirectional calls;
- exactly one invocation per RPC creation;
- the override may inspect and return an immutable `Metadata` replacement;
- per-call values still append after constructor defaults when the override delegates to `parent`;
- unary retry reuses the prepared snapshot without another invocation;
- server-streaming retry does the same; and
- a changed ambient test value after initial creation does not alter retry metadata.

No external gRPC server is required; the current engine client fixtures expose sent request metadata and retry attempts.

### Documentation

In “Generated-Style Clients,” show a client base overriding `prepareMetadata()` and delegating to `parent` before adding/replacing a header. State that the method runs once when the RPC is created and retries reuse the prepared metadata.

## 6. Pin existing queue integration contracts

### Production code

None.

The current framework already provides the required universal boundaries:

- the base `ContextServiceProvider` registers its payload callback before application providers boot;
- payload callbacks see object jobs while `data.commandName` and `data.command` still hold the live object;
- the queue replaces those fields with the class name and serialized clone only after callbacks complete;
- `JobProcessing` runs before `Job::fire()`, which is before `CallQueuedHandler` unserializes the command;
- `JobAttempted` is dispatched from `finally` in both `Worker` and `SyncQueue`; and
- `RetryCommand` decodes the existing payload, changes only attempts/retry timing, and republishes the remaining structure.

Do not add a provider-order test. The composed payload behavior test proves the useful contract end to end without pinning container registration mechanics separately.

### Tests

#### Composed Context payload

Expand `tests/Log/ContextQueueTest.php` so an application-registered later payload callback proves:

- it sees Context data already composed by `ContextServiceProvider`;
- it sees the live job object for marker inspection;
- it may update one Context field without deleting unrelated visible data;
- it preserves the complete hidden map, including Bus unique-job metadata; and
- the final payload contains a class-string `commandName` and serialized command after all callbacks.

#### Processing and attempted events

Add focused tests to `QueueWorkerTest` and `QueueSyncQueueTest` proving:

- `JobProcessing` is observable before the job handler/fire path;
- `JobAttempted` runs after success;
- `JobAttempted` also runs when handling throws; and
- the event carries the thrown exception where the event contract provides it.

These tests should use behavior/order recording, not broad event call counts.

While changing `QueueSyncQueueTest`, move it from raw PHPUnit to `Hypervel\Tests\TestCase` and remove its duplicate static queue/container cleanup. The global test subscriber is the authoritative owner.

#### Retry payload preservation

Add `tests/Queue/RetryCommandTest.php`. Use a failed-job fixture containing an arbitrary nested context field and assert `queue:retry` republishes it unchanged while resetting only the fields the command owns. Include a serialized command so the normal retry-until inspection path is exercised.

No integration-specific key belongs in this test. A generic nested field proves the transport contract for every integration.

## 7. Document at-least-once delivery and chain handoff

### Files

- `src/boost/docs/queues.md`
- `tests/Queue/CallQueuedHandlerTest.php`

### At-least-once section

Add an “At-Least-Once Delivery” subsection near the introduction / creating-jobs material and include it in the table of contents.

Use direct user-facing language:

- a worker can finish application side effects and stop before acknowledging/deleting the backend job;
- the queue may therefore deliver the same job again;
- timeout and visibility settings reduce bad overlap but cannot provide exactly-once execution; and
- jobs should be idempotent through unique database constraints, idempotency keys, state checks, or transactions around effects that can be committed together.

Replace the sentence claiming timeout settings ensure a job is only successfully processed once.

### Chain warning

Document the current deliberate ordering: Hypervel dispatches the next chain member before deleting the current backend job. This prefers a possible duplicate chain handoff over silently losing the rest of the chain if the worker stops in the gap. Both the current job and its downstream effects must therefore be idempotent.

### Ordering regression

Add one focused `CallQueuedHandlerTest` with an order-recording job fixture:

```php
$order = [];

// dispatchNextJobInChain records "chain"
// backend delete records "delete"

$this->assertSame(['chain', 'delete'], $order);
```

Assert the semantic order only. Do not simulate a process crash.

## 8. Correct stack-cache compensation and read repair

### Files

- `src/cache/src/StackStore.php`
- `tests/Cache/CacheStackStoreTest.php`
- `tests/Cache/CacheStackStoreTagsTest.php`
- `src/boost/docs/cache.md`

### Exception compensation

Import `Throwable` and catch only failures from the lower continuation:

```php
protected function callStores(Closure $handler, ?Closure $rollback = null, bool $force = false): bool
{
    return $this->callStoresStacked(
        function (StackStoreProxy $store, Closure $next) use ($handler, $rollback, $force): bool {
            if (! $handler($store) && ! $force) {
                return false;
            }

            try {
                $result = $next();
            } catch (Throwable $throwable) {
                if ($rollback !== null) {
                    $rollback($store);
                }

                throw $throwable;
            }

            if (! $result && $rollback !== null) {
                $rollback($store);
            }

            return $result;
        },
        static fn (): bool => true,
    );
}
```

The boundary is intentional:

- if the current layer's own handler throws, that layer did not report success, so this frame does not compensate it;
- every already-successful outer frame catches the lower failure and compensates itself;
- a successful compensation rethrows the exact original throwable object;
- if compensation itself throws, ordinary PHP unwinding exposes the compensation failure;
- do not introduce an aggregate exception or wrapper solely to retain both failures; and
- `force: true` is used only by rollback-less `forget()` and `flush()`, so its exception behavior remains a direct pass-through.

Tagged writes already supply the same per-layer `forget($key)` compensation and automatically gain the throw path.

### False read-repair result

Tighten the real return type and preserve a valid lower hit:

```php
protected function getOrRestoreRecord(string $key): ?array
{
    return $this->callStoresStacked(
        function (StackStoreProxy $store, Closure $next) use ($key): ?array {
            if (! is_null($record = $store->get($key))) {
                return (array) $record;
            }

            if (is_null($record = $next()) || ! array_key_exists('value', $record)) {
                return null;
            }

            $this->putToStore($store, $key, $record);

            return $record;
        },
        static fn (): null => null,
    );
}
```

Semantics:

- a valid lower record remains the authoritative read result even if an optional upper-layer backfill reports `false`;
- another missing outer layer may still attempt to backfill the same current record;
- a malformed lower record remains a miss;
- a current-layer hit remains treated exactly as it is today;
- a backfill exception still propagates;
- `StackStore` is not a failover/error-suppression driver; and
- an oversized Swoole value remains a loud configuration error on both direct write and read repair.

This prevents both counter paths from treating a live lower value as absent:

- `increment()` proceeds from the lower value;
- `incrementTagged()` does the same;
- if their later all-layer write returns `false`, the upper write is rolled back, the lower value remains intact, and the increment returns `false` rather than resetting the key.

### Tests

Cover:

- a lower-layer exception after an upper success invokes upper compensation;
- deeper layers are not called after the throw;
- successful compensation rethrows the exact original exception instance;
- an own-layer handler exception does not invoke compensation for that same layer;
- existing lower-`false` compensation remains unchanged;
- tagged writes retain their existing false compensation and share the exception helper;
- `get()` returns a valid lower value when upper repair returns `false`;
- malformed lower records remain misses;
- backfill exceptions propagate;
- `increment()` never calls its absent-key `forever()` path after failed repair;
- failed subsequent increment write leaves the lower value intact;
- `incrementTagged()` has the same protection; and
- a three-layer read allows another outer layer to repair even when an inner upper layer returned `false`.

Do not add wall-clock tests or a real external cache service; mock the layer results and call order deterministically.

### Documentation

Update the Swoole-table and stack sections in simple language:

- the `bytes` setting must fit the serialized stack record, not only the application value;
- oversized Swoole values throw instead of silently falling through;
- stack writes compensate earlier layers when a later layer rejects or throws;
- a failed optional backfill does not hide a valid lower hit; and
- exceptions during read repair remain visible so configuration and service failures are not masked.

## 9. Safe external-search test prefixes

### Files

- `src/foundation/src/Testing/Concerns/InteractsWithAlgolia.php`
- `src/foundation/src/Testing/Concerns/InteractsWithMeilisearch.php`
- `src/foundation/src/Testing/Concerns/InteractsWithTypesense.php`
- `tests/Foundation/Testing/Concerns/ExternalServiceOptInTest.php`
- `src/boost/docs/testing.md`

### Prefix initialization

Mirror Algolia in the other two setup methods after the opt-in environment check and before any client initialization or cleanup:

```php
if ($this->meilisearchTestPrefix === '') {
    $this->computeMeilisearchTestPrefix();
}
```

```php
if ($this->typesenseTestPrefix === '') {
    $this->computeTypesenseTestPrefix();
}
```

This preserves an explicit test-supplied prefix. Defaults remain:

- `test_` for sequential execution;
- `test_{TEST_TOKEN}_` for a ParaTest worker.

Do not compute before the opt-in check. An unconfigured external test must still skip without doing setup work.

### Trait docblocks

Replace the three class-level inventory docblocks with concise usage documentation. Keep:

- what service the trait supports;
- the `use InteractsWith...;` instruction;
- the environment variables that opt into the service; and
- the fact that resources are isolated by the test prefix.

Remove lists of trait members, auto-called methods, and “Features” bullets that merely inventory the implementation.

### Tests

Extend the existing harnesses so overridden client initialization records the prefix and throws a sentinel before any SDK call. Cover for both Meilisearch and Typesense:

- no host still skips before prefix/client work;
- host plus no `TEST_TOKEN` computes `test_`;
- host plus a token computes `test_{token}_`;
- an explicit prefix is preserved; and
- computation happens before client initialization.

Tests that deliberately change `TEST_TOKEN` must capture and restore all three environment sources (`getenv`, `$_SERVER`, and `$_ENV`) and flush `Env` afterward. Rename the existing host-only environment-key helper if necessary rather than leaving a misleading name.

No external Meilisearch, Typesense, or Algolia process is involved in these unit tests.

### Documentation

Clarify in the external-service testing section that search traits derive a worker-specific index/collection prefix from `TEST_TOKEN` and only clean resources matching that prefix. Keep the prose short and action-oriented.

## Documentation deliverables

Update documentation beside each public behavior:

| Document | Content |
|---|---|
| `broadcasting.md` | Boot-time outgoing channel formatter and raw-before-driver ordering |
| `urls.md` | Lazy origin/default resolvers, precedence, declared-domain boundary, `useDefaults()` |
| `sanctum.md` | Successful-use semantics, tracking switch, cache throttle |
| `routing.md` | Named limiter scope resolver, null behavior, `GlobalLimit` escape |
| queue rate-limiting section | Named queue middleware uses the same named-limiter key policy |
| `grpc.md` | Protected `prepareMetadata()` subclass example and retry snapshot |
| `queues.md` | At-least-once delivery, idempotency, chain-before-delete |
| `cache.md` | Swoole record sizing, stack compensation, false repair, fail-loud exceptions |
| `testing.md` | Search test prefix and cleanup isolation |

Match the language and structure of nearby Hypervel documentation. Do not copy this plan's internal vocabulary into user-facing docs where a simpler explanation is enough.

## Implementation order

Implement serially, one file at a time:

1. broadcaster state, registrar, base formatting, Ably composition, cleanup, facade, tests, and docs;
2. URL resolver state, precedence, `useDefaults()`, facade, tests, and docs;
3. Sanctum lookup purity, model update, guard timing/configuration, connection return type, tests, and docs;
4. centralized named-limiter keys, both middleware consumers, facade, tests, and docs;
5. gRPC metadata extraction, tests, and docs;
6. queue contract tests, delivery documentation, and chain-order regression;
7. stack-store exception/read-repair fixes, tests, and docs;
8. Foundation prefix initialization, concise trait docblocks, tests, and docs;
9. broad stale-symbol/documentation searches; and
10. full static analysis, formatting, and test verification.

After changing each test file, run that file immediately before moving to the next one.

## Test plan

### Focused unit and package tests

Run every changed/new file directly. At minimum this includes the relevant files under:

- `tests/Broadcasting`;
- `tests/Routing`;
- `tests/Sanctum`;
- `tests/Database`;
- `tests/Cache`;
- `tests/Queue`;
- `tests/Log`;
- `tests/Grpc`; and
- `tests/Foundation/Testing/Concerns`.

Then run focused package groups for Broadcasting, Routing, Sanctum, Cache, Queue, Log, gRPC, Database, and Foundation.

### External integration tests

No new external-service integration test is required:

- broadcast drivers are tested through mocks;
- URL/rate-limit behavior is in-process;
- Sanctum uses the normal Testbench database;
- gRPC uses the existing fake engine clients;
- queue contracts use sync/fake worker objects;
- stack stores use deterministic mocked layers; and
- external-search prefix tests stop before SDK initialization.

Therefore no workflow, `.env`, or `.env.example` change is needed.

### Full verification

From the components repository root:

```bash
./vendor/bin/phpstan
./vendor/bin/php-cs-fixer fix
composer test:parallel
```

`composer test:testbench` is not required unless implementation unexpectedly changes the Testbench package itself.

## Performance model

| Path | Unconfigured cost | Configured / corrected cost |
|---|---|---|
| Broadcast | One predictable nullable static check per formatted broadcast | One callback per formatting call, before existing string/driver maps |
| URL origin | One nullable instance check on eligible application-root generation; assets perform no resolver check | One callback per eligible generated application URL; no query and no retained result |
| URL defaults | One nullable instance check while defaults are already assembled | One callback and one call-local snapshot per route generation; array merge proportional to supplied defaults |
| Sanctum rejected auth | Removes the current timestamp write | No extra work |
| Sanctum successful auth | One configuration boolean, one connection-state read, and one raw-original attribute read | Same timestamp save as today when enabled; a successful save restores the routing snapshot, while a cancelled save restores the attribute |
| Named rate limits | One method call and nullable callback check per `Limit` | One callback and one string prefix; still exactly one hash |
| gRPC | A protected method call replaces an inline merge | No extra merge, allocation, or retry-time callback |
| Queue | No production change | No production change |
| Stack successful writes | `try` around the lower continuation, with no extra I/O | Compensation only on an existing failure path |
| Stack successful reads | One explicit well-formed lower-record check on a miss path | A `false` repair may allow another outer repair attempt instead of aborting a valid read |
| Foundation prefixes | Test-only | One short string computation per opted-in test setup |

These costs are either noise relative to the work already performed at that boundary or occur only when the new policy is configured. The Sanctum change is a practical performance improvement for applications using sticky read/write routing. Do not add benchmark thresholds to CI; use exact callback, hash, SQL, and transport-call counts.

## Rejected overengineering and workarounds

- **Callback registries and priority systems:** each new policy has one coherent owner; replacement with `null` is sufficient.
- **Static URL callbacks:** the URL generator is already a singleton, and instance state gets correct container-owned test cleanup.
- **Cached URL resolutions:** would make ambient context changes stale and defeat the purpose of lazy resolution.
- **A lazy asset-origin resolver:** assets already have deliberate worker-global and coroutine-local controls, and no concrete consumer needs a third policy source.
- **Writing URL defaults during operation transitions:** would duplicate state and risk coroutine leaks; `useDefaults()` is the explicit operation-local API.
- **Sanctum observers or consumer-side timestamp repair:** the guard owns authentication success and the model owns its cache/write behavior.
- **Restoring read-routing state in `finally`:** would claim a failed write left a known state.
- **Connection write-generation tracking for Sanctum model listeners:** would
  add framework-wide machinery for a rare upstream-parity composition whose
  consequence is bounded replica staleness; cancelled saves keep listener
  writes visible without it.
- **A separate limiter wrapper/store:** two framework middleware already share `RateLimiter`; key construction belongs there.
- **Passing full `Limit` objects to the scope resolver:** the normalized name is the only useful policy input, while `GlobalLimit` is the definition-side escape.
- **Scoping unnamed throttles, overlap locks, or unique-job locks:** those names may deliberately represent global coordination and have different public contracts.
- **A process-global gRPC hook:** would affect unrelated third-party clients; a protected client subclass seam is the precise boundary.
- **New queue lifecycle APIs:** current payload and event contracts already cover the supported behavior; tests are the missing piece.
- **A queue boot-order test:** composed-payload behavior proves the contract without coupling tests to provider mechanics.
- **Cache exception suppression/retry:** `StackStore` is tiered caching, not failover. Hiding Swoole size errors would make misconfiguration persistent and invisible.
- **Compensating the currently throwing cache layer:** it never reported success; only already-successful outer frames have a known action to reverse.
- **Cache exception aggregation:** no established framework convention justifies a wrapper solely for a rare double failure.
- **Foundation cleanup guards that silently skip an empty prefix:** computing the intended safe prefix fixes the root cause and preserves cleanup.
- **A shared external-test trait hierarchy:** three small service-specific traits do not justify another abstraction.
- **Scout changes in this plan:** the upstream reconciliation and external-engine lifecycle design remain a dedicated larger effort.

## Completion criteria

Implementation is complete only when:

- every production API and lifecycle rule above exists with the documented signature;
- no `flushChannels()` implementation, facade annotation, subscriber call, or test reference remains;
- Ably no longer performs an independent pre-parent stringification path;
- URL resolvers are lazy, only request fallback roots are cached, and `useOrigin()` contains no dead cached-root invalidation;
- empty explicit or resolved URL origins are treated as absent rather than
  becoming malformed roots;
- the lazy origin resolver never affects asset URLs, while explicit `useOrigin()`, configured asset roots, and `useAssetOrigin()` retain their existing precedence;
- non-HTTP operation-local defaults have no worker-global write path;
- `findToken()` contains no `last_used_at` write;
- every rejected Sanctum path leaves `last_used_at` unchanged;
- an event-cancelled Sanctum timestamp save leaves persistence, in-memory
  state, cache, and listener-established read routing coherent;
- successful Sanctum timestamp writes preserve either prior modification-state value;
- `Connection::setRecordModificationState()` has `: static`;
- HTTP and queue named limiters contain no duplicated key-construction expression;
- all four gRPC RPC creation methods call `prepareMetadata()` once;
- no new queue production seam exists;
- queue documentation contains no exactly-once claim;
- stack-cache lower exceptions compensate earlier writes;
- false upper read repair cannot turn a valid lower counter into an absent-key reset;
- Meilisearch and Typesense cleanup cannot run with the default empty prefix;
- all touched tests use a Hypervel test base;
- public facade annotations and documentation match the source;
- stale comments and duplicate/inaccurate documentation are removed rather than retained as history;
- PHPStan and CS Fixer are clean; and
- the full parallel suite passes.
