# Broadcasting Correctness, Direct SDK Lifecycles, and Current Parity

## Goal and boundary

Complete the Broadcasting package audit by correcting the verified defects, closing every carried revalidation, restoring current Laravel-facing behavior, and simplifying built-in SDK ownership without adding request-scoped machinery. Package-wide discovery was completed before this plan; implementation, any same-family findings exposed during it, final validation, self-review, and code review complete the audit and its checklist.

## Audit principles

### 1. Verify before changing

A suspicious pattern is not an actionable finding until the audit establishes:

- the exact file and symbol;
- every relevant caller and callee across `src/` and `tests/`;
- the state or resource owner;
- the initialization, commit, use, and cleanup boundaries;
- a realistic production or test failure schedule;
- why current guards and tests do not prevent it;
- sibling implementations and same-family sites;
- relevant upstream behavior;
- the lowest correct fix boundary;
- a regression strategy;
- the performance and complexity effect of the proposed fix.

Use a focused probe when source reasoning cannot settle native or scheduler behavior. Do not repeatedly run the full suite hoping to reproduce a rare flake.

### 2. Fix the lowest inconsistent contract

Do not add local compensation when a shared lower-level contract is wrong. A caller catch is not enough when a typed filesystem method can return `false`; a per-consumer spawn catch is not enough when Engine exposes an ambiguous spawn contract; a proxy workaround is not enough when pool ownership is undefined.

After changing a lower-level contract, re-audit every affected caller and revisit completed packages that depend on it. Record cross-references in both the owning package and each affected package ledger entry.

### 3. Make ownership explicit

The component that acquires or registers a resource records the exact handle and releases that exact handle. Cleanup must not reconstruct identity from mutable state when the original handle can be retained.

Examples include coroutine IDs, timer IDs, process IDs plus incarnation checks, listener callbacks, pool leases, subscriber objects, stream handles, temporary filenames, signal watcher IDs, and channel tokens.

### 4. Make creation transactional

If code reserves capacity or publishes state before a later operation can fail, it must either finish creation or roll back every earlier change. Do not expose half-initialized objects, registered-but-dead pools, leaked wait-group counts, or published runtime paths without their cleanup owner.

### 5. Make cleanup exhaustive

Independent cleanup steps run even when an earlier step fails. The earliest operation or cleanup failure remains primary. Cleanup failures must not corrupt bookkeeping, skip unrelated cleanup, or turn a successful ownership transfer into a reported failure.

### 6. Bound only external progress

Use deadlines where progress depends on a process, socket peer, lock owner, IPC child, or external service that can disappear. Do not add arbitrary timeouts to ordinary internal coroutine joins once successful creation and ownership guarantee completion.

### 7. Preserve hot-path quality

For every fix, inspect:

- additional allocations;
- container or facade resolutions;
- locking and atomics;
- hashing and serialization;
- new yields or sleeps;
- retries and polling;
- logging or exception construction;
- retained worker memory;
- cache invalidation and eviction.

A correctness guard on a cold failure path has a different cost from a new lock or resolver on every request. State the difference explicitly.

Any proposed change with a measured or source-proven hot-path regression requires explicit owner approval before implementation, even when it fixes a defect. Present the expected frequency and magnitude, the evidence, and the viable alternatives. Do not hide an unavoidable tradeoff inside a general correctness claim.

Performance improvements must provide a meaningful practical benefit after accounting for code complexity and divergence from upstream. Measure representative behavior where practical. Always surface an evidence-backed opportunity to the owner, but do not implement it without approval; a micro-optimization within measurement noise is neither a reason to diverge nor an actionable finding.

### 8. Remove superseded design completely

When a fix changes the owning model, delete obsolete helpers, callbacks, properties, config keys, comments, tests, and documentation. Do not leave a compatibility path or comment describing behavior that no longer exists. Preserve intentional upstream comments unless the new design makes them incorrect.

### 9. Treat remediation patterns as candidates

The established patterns later in this plan are a vocabulary, not a lookup table. Choose among per-call parameters, immutable values, scoped bindings, cloning, CoroutineContext, factories, explicit ownership, static reset, or resource teardown only after proving the real lifetime and owner.

### 10. Reject speculative complexity

Record low-confidence concerns under rejected or unresolved analysis. Do not implement them. Surface every evidence-backed, meaningful non-defect improvement to the owner with its benefit, cost, and alternatives, then stop for explicit approval. This requirement exists to keep worthwhile opportunities visible, not to discourage finding them.

## Evidence and references

- The completed package investigation covered every Broadcasting source and test file, split/root metadata, Foundation broadcasting configuration, Boost/package documentation, Redis/Object Pool ownership, current Laravel source and originating PR surfaces, installed SDK internals, and every routed cross-package finding.
- Hypervel source and tests in this worktree, including Redis configuration ownership in `src/redis/src/RedisConfig.php` and object-pool behavior in `src/object-pool`.
- Current Laravel Framework `13.x` at `2c410561c21452de2f164caea64ab0fcac692a5d`.
- Laravel PR surfaces, followed by current source as the implementation reference:
  - PR #60483 / `c69bf137e3`: enum broadcast names (`BroadcastEvent` and tests).
  - PR #58971 / `4a92d0d9fe`: explicit Pusher JSONP enablement.
  - PR #56538 / `ad1d5e316b`: anonymous `toOthers` regression.
  - PRs #59470 / #59614 (`3f651af035`, `76025599b3`): static-closure and invokable custom creators.
- Installed SDKs used for source tracing: Ably 1.1.12, Pusher 7.2.8, Guzzle 7.15.2.
- Ably 1.0.0 tagged source confirms the entire admitted `^1.0` range has `Channels::release()`, `Channel::getCipherParams()`, and the sole `ChannelOptions::$cipher` option.
- Live phpredis 6.3.0 probe and extension source confirm native `publish()` applies `OPT_PREFIX`, while Lua arguments passed as `ARGV` with `num_keys = 0` do not. The behavior is identical for `Redis` and `RedisCluster` native publish.

## Architecture and ownership

`BroadcastManager` is a worker-lifetime singleton through Hypervel's automatic concrete resolution. It caches one broadcaster per named connection. Pusher, Reverb, and Ably SDK clients are concurrency-safe for the reached operations and own no borrow-scoped request state, so built-in pooling only adds proxy/borrow overhead, retains extra client graphs, caps concurrency, and hides concrete Laravel APIs.

After this work:

- built-in Pusher, Reverb, and Ably connections resolve directly and remain manager-cached;
- explicit custom poolable drivers retain the complete existing proxy, callback, purge, and pool-definition API;
- ordinary Ably channel objects are released after each publication because the SDK otherwise caches every dynamic name for the worker lifetime;
- application-configured encrypted Ably channels remain cached because their channel object owns the cipher options used by later publications;
- Redis prefix precedence is resolved once when the broadcaster is constructed, with no per-publication config work.

## Final finding set

| ID | Category | Severity | Decision |
|---|---|---:|---|
| `broadcasting-01` | Defect | Major | Use canonical Redis connection prefix precedence and prevent cluster double-prefixing. |
| `broadcasting-02` | Defect | Major | Remove exactly one leading Redis prefix during both authorization response paths. |
| `broadcasting-03` | Defect | Major | Normalize string and enum broadcast names to the strict string transport contract. |
| `broadcasting-04` | Security defect | Major | Require explicit per-connection opt-in before honoring Pusher JSONP callbacks. |
| `broadcasting-05` | Defect | Major | Do not clone enum cases at immediate, queued, unique, or wrapper clone boundaries. |
| `broadcasting-06` | Improvement | Minor | Construct only the selected ordinary/unique wrapper once, removing redundant cloning and metadata reads. |
| `broadcasting-07` | Architecture/API defect | Major | Resolve built-in SDK drivers directly while preserving explicit custom pooling. |
| `broadcasting-08` | Lifecycle defect | Major | Release ordinary Ably channels after success/failure while retaining configured encrypted channels. |
| `broadcasting-09` | Contract defect | Major | Accept Laravel-documented single-string `ShouldBroadcast::broadcastOn()` results. |
| `broadcasting-10` | Defect | Major | Throw on invalid JSON at Redis, Ably, and Log transport boundaries. |
| `broadcasting-11` | Test defect/parity | Minor | Correct the vacuous anonymous assertion and cover current creator shapes. |
| `broadcasting-12` | Metadata defect | Minor | Declare actual package dependencies and remove stale direct dependencies. |
| `broadcasting-13` | Container cleanup | Minor | Remove the redundant manager self-binding while preserving contract identities. |
| `broadcasting-14` | Type/lifecycle cleanup | Minor | Complete bounded native types and worker-lifetime mutator warnings. |
| `broadcasting-15` | Documentation defect | Minor | Add provenance and concise intentional differences; update public usage docs. |
| `broadcasting-16` | Dead code | Minor | Remove the unreachable fallback inside the protected `rescue()` extension point. |

## Implementation design

### 1. Redis prefix ownership and publication

In `BroadcastManager::createRedisDriver()`, derive the selected connection once through the Redis-owned helper rather than duplicating or partially reading Redis config:

```php
$connectionName = $config['connection'] ?? 'default';
$redisConfig = $this->app->make(RedisConfig::class)->connectionConfig($connectionName);

return new RedisBroadcaster(
    $this->app,
    $redis,
    $connectionName,
    (string) ($redisConfig['options']['prefix'] ?? ''),
);
```

This keeps the Redis package optional/lazy: its concrete helper is resolved only when the Redis broadcasting driver is selected. Do not widen the generic Redis Factory contract or duplicate merge rules. An invalid connection now fails during broadcaster construction instead of first publication. The manager wraps the helper's `InvalidArgumentException` in its established driver-construction `RuntimeException`, preserving the original message and exception as the cause.

Keep the existing protected `RedisBroadcaster::formatChannels()` semantics for Lua and subclasses. Split publication deliberately:

```php
if ($connection->isCluster()) {
    // formatChannels() is Laravel's protected manual-prefix extension point.
    // Native phpredis publish owns the prefix here, so retain only the parent formatter/cast.
    foreach (parent::formatChannels($channels) as $channel) {
        $connection->publish($channel, $payload);
    }
} else {
    // Lua receives channels as ARGV, which phpredis does not prefix.
    $connection->eval($script, 0, $payload, ...$this->formatChannels($channels));
}
```

Correct the existing cluster expectation from `redis.application.orders` to `application.orders`; it currently encodes the double-prefix defect. Cover shared options, connection options, top-level connection prefix precedence, empty prefix, and scalar-to-string normalization.

### 2. Redis authorization normalization

Replace global substring removal with one private literal-boundary helper:

```php
private function removeLeadingPrefix(string $channel): string
{
    return $this->prefix !== '' && str_starts_with($channel, $this->prefix)
        ? substr($channel, strlen($this->prefix))
        : $channel;
}
```

In `auth()`, preserve the existing empty-input guard before calling this helper. `Request::input()` returns `null` for a missing key, while Hypervel's helper and channel normalizer require strings, so Laravel's untyped normalize-first order is unsafe here. Reassign the stripped logical name before both Pusher-convention normalization and guard selection. Public `validAuthenticationResponse()` keeps its existing unguarded contract and only applies the helper before normalization. Do not use regex or a generic parser. Tests must cover a missing `null` channel, a leading prefix, a later identical byte sequence, an absent prefix, an empty prefix, and configured guard selection after logical-name normalization.

### 3. Event names and clone boundaries

Normalize custom names at the transport boundary:

```php
$name = method_exists($this->event, 'broadcastAs')
    ? (string) enum_value($this->event->broadcastAs())
    : get_class($this->event);
```

At each exact clone boundary, preserve enum cases and clone ordinary objects:

```php
$event instanceof UnitEnum ? $event : clone $event
```

Apply that expression to immediate dispatch, ordinary queued dispatch, unique queued dispatch, and `BroadcastEvent::__clone()`. Do not introduce reflection, a clone service, or a public helper.

Construct only the chosen wrapper:

```php
$broadcastEvent = $event instanceof ShouldBeUnique
    ? new UniqueBroadcastEvent($event instanceof UnitEnum ? $event : clone $event)
    : new BroadcastEvent($event instanceof UnitEnum ? $event : clone $event);
```

This removes the unique path's discarded ordinary wrapper, extra clone, and repeated cached attribute lookups. It does not alter public APIs or queue payloads.

### 4. Explicit Pusher JSONP

Add an optional third constructor parameter after Hypervel's existing dependencies:

```php
public function __construct(
    protected Container $container,
    protected Pusher $pusher,
    protected bool $allowJsonp = false,
) {}
```

Pass `(bool) ($config['jsonp'] ?? false)` from `createPusherDriver()`. `decodePusherResponse()` uses JSONP only when both the request callback and the connection opt-in are present. Add `'jsonp' => false` to the shipped Foundation Reverb and Pusher connection entries. Keep the constructor/config fallback because named connection arrays may replace framework defaults. Existing two-argument construction remains valid.

```php
if (! $request->input('callback', false) || ! $this->allowJsonp) {
    return json_decode($response, true);
}
```

### 5. Direct built-in SDK ownership and explicit custom pools

Change the manager default only:

```php
protected array $poolables = [];
```

Delete shipped Pusher and Ably `pool` blocks and the completed pooling todo. Preserve `HasPoolProxy`, `BroadcastPoolProxy`, `poolDefinition()`, `poolFactory()`, `purge()`, `addPoolable()`, `removePoolable()`, `setPoolables()`, and callback transfer. Stale application `pool` keys on a non-poolable connection remain ignored like other presentation-only config. Named connections intentionally own separate manager-cached clients, matching Laravel's manager model.

Rename and correct the existing `testReverbResolvesDirectlyWhileExistingPoolableDriversRemainUnchanged()` regression: it currently asserts the built-in `pusher`/`ably` defaults this work removes. The replacement must assert an empty default poolable list, no registered pool, concrete Pusher broadcasters for Reverb/Pusher, a concrete Ably broadcaster for Ably, and manager-reachable `getPusher()` / `setPusher()` / `getAbly()` / `setAbly()` methods. This is the counterfactual API proof: direct broadcaster unit construction cannot detect that the old manager returned a proxy with no `__call()`.

Retain and extend the existing custom-pool regressions rather than duplicating them:

- `testAuthenticatedUserResolverWorksThroughPooledManagerDriver()` proves explicit `addPoolable()`, borrow, and `configureBorrowed()`;
- `testEquivalentConnectionsConvergeAndCustomCreatorNeverReceivesPoolMetadata()` proves identity convergence and construction-config stripping;
- `testPurgeInvalidatesCachedAndUncachedBroadcasterPoolsWhileForgetIsCacheOnly()` proves release/recreation, purge, and cache-only forgetting.

Update `purge()`'s stale docblock:

```text
Disconnect the given driver and remove it from the local cache.

Boot or tests only, plus operational recovery for explicitly pooled drivers.
Direct drivers are only removed from the manager cache; an explicitly pooled
driver also invalidates its shared pool.
```

Add concise warnings to `setPusher()` and `setAbly()`:

```text
Boot or tests only. Replaces the SDK client on this worker-cached broadcaster;
per-request mutation races across coroutines.
```

Update both Broadcasting references in `src/boost/docs/pools.md`: the introduction must not imply built-in broadcasters are pooled, and Consumer Integration must state that Broadcasting builds pool definitions only for drivers explicitly marked poolable. Keep the broader Filesystem, Mail, and Queue guidance unchanged.

### 6. Ably channel cache lifecycle

Use the already-formatted name, get the channel once, publish in `try`, and release only ordinary/default channels in `finally`:

```php
foreach ($this->formatChannels($channels) as $name) {
    $channel = $this->ably->channels->get($name);

    try {
        $channel->publish($this->buildAblyMessage($event, $payload));
    } finally {
        if ($channel->getCipherParams() === null) {
            $this->ably->channels->release($name);
        }
    }
}
```

Keep the `publish()` invocation on one line below one identifier-scoped `@phpstan-ignore arguments.count, argument.type`: Ably declares two same-name `@method publish()` signatures, PHPStan retains only the last string/data declaration, and the real variadic implementation accepts a `Message` as its sole argument. The one-line placement lets the single local suppression cover both diagnostics without a stub, wrapper, or runtime change.

Do not release an encrypted channel: application code may configure it through `getAbly()->channels->get($formattedName, ['cipher' => ...])`, and releasing it would silently remove encryption from later broadcasts. Ably 1.x has no other channel option. The intentionally retained set is therefore only explicitly configured encrypted channels using Ably's formatted names (`public:`, `private:`, or `presence:`), not ordinary dynamic names.

Use a real `AblyRest` configured with the SDK's `httpClass` fake seam. Do not reflect into the SDK or make live network calls.

### 7. Single-string channel contract

Widen the existing Laravel-facing contract without an adapter:

```php
/**
 * Get the channels the event should broadcast on.
 *
 * @return Channel|Channel[]|string|string[]
 */
public function broadcastOn(): array|Channel|string;
```

Runtime already uses `Arr::wrap()`. Cover a real queued event returning a single string and update the two guide passages that currently describe only a channel or array.

### 8. Throwing JSON boundaries

Use `JSON_THROW_ON_ERROR` only where arbitrary user data is encoded:

```php
json_encode($value, JSON_THROW_ON_ERROR);
json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
```

Apply it to Redis presence `channel_data`, Redis event payloads, Ably presence user data and signature input, and Log payloads. Keep Redis's boolean encode unchanged because it cannot fail. Pusher already delegates to a throwing SDK encoder. Let `JsonException` propagate without prewalking data, double encoding, helper services, or exception translation.

### 9. Current creator behavior and anonymous regression

Change the sole assignment inside the `toOthers()` dispatch assertion to strict comparison:

```php
Event::assertDispatched(AnonymousEvent::class, function ($event) {
    return $event->socket === '12345';
});
```

Port current static-closure and invokable-object custom creator coverage. `RebindsCallbacksToSelf` is already the correct shared owner; do not change source unless a counterfactual test proves a defect.

### 10. Container, dead code, metadata, and bounded types

Remove the redundant `BroadcastManager::class` singleton declaration. Preserve the Factory alias and Broadcaster contract singleton; test that concrete and Factory resolve the same worker singleton and that the Broadcaster contract resolves the selected connection.

Replace the complete four-site array-offset surface in the substantially edited manager with the canonical APIs:

- `routes()` and `userRoutes()` resolve `router` through `make()`;
- `socket()` resolves the bound request through `make()`;
- `setDefaultDriver()` resolves config through `make()` and writes through `set()`.

Add `use Hypervel\Routing\Router;` for the router narrowing. Keep `getDefaultDriver()`'s existing typed `string()` getter and `getConfig()`'s nullable `get()` call. Add a local `@var` only if a string service key otherwise infers the wrong type. These are four one-line ownership-preserving substitutions with identical resolution frequency, not a repository-wide syntax sweep.

```php
/** @var Router $router */
$router = $this->app->make('router');

/** @var Request $request */
$request = $request ?: $this->app->make('request');

$this->app->make('config')->set('broadcasting.default', $name);
```

Keep the protected Laravel `rescue()` extension point, but remove its unreachable fallback:

```php
protected function rescue(Closure $callback): mixed
{
    return rescue($callback);
}
```

Complete only these native types:

- `BroadcastEvent::__clone(): void`;
- `Broadcaster::normalizeChannelHandlerToCallable(callable|string): callable`.

For `normalizeChannelHandlerToCallable()`, retain the method-title docblock but delete the now-redundant and contradictory `@param mixed` plus restating `@return callable` annotations.

Both destructors keep their existing title-only docblocks. No `@return void` is added: PHP forbids return types on `__destruct()`, while `__clone(): void` is legal and remains required.

Manually add `: void` to the 65 real untyped PHPUnit `test*` methods in already-touched Ably, Broadcaster, BroadcastEvent, Pusher, Redis, and anonymous-event test files. Constructors and other magic methods are not part of this set. Do not change `FakeBroadcasterUsingPusherChannelsNames::testChannelNameMatchesPattern(...): bool`; it is a callable probe, not a PHPUnit test.

Update `src/broadcasting/composer.json`:

```json
"psr/log": "^3.0",
"hypervel/routing": "^0.4"
```

Remove stale direct `hypervel/auth` and `hypervel/cache`; Bus owns unique locking and remains direct. Keep Contracts direct. Add a focused metadata test for required dependencies, SDK suggestions, provider metadata, and removed false dependencies.

The metadata regression must also assert that `hypervel/redis` remains an optional suggestion with its existing wording. The new lazy `RedisConfig::class` reference is valid only when the Redis driver is selected and must not accidentally promote Redis to a hard dependency.

### 11. Documentation and records

Keep user documentation concise:

- Broadcasting guide: enum broadcast names, single-string channels, and explicit `jsonp` opt-in/default.
- Pools guide: both Broadcasting references describe pooling only for explicitly marked drivers.
- Package README: title, Boost docs link, current Laravel provenance, and only intentional differences:
  - worker-wide static channel/auth-option registry;
  - worker-wide outgoing formatter and incoming authorizer;
  - explicit custom poolable drivers;
  - no `DeferrableProvider` marker because Hypervel has no matching provider mechanism.

Do not document internal bug fixes or duplicate the Boost guide.

Add a complete Broadcasting package block to the companion ledger. Its **Status and inspected surface** must say the audit is complete and name the package source, tests, configuration, metadata, documentation, Laravel PR/current-source references, Redis/Object Pool consumers, SDK ownership, and all carried findings inspected. Follow it with the findings table, **Important rejected concerns**, **Implementation and boundaries**, **Cross-package revalidation**, **Regression tests**, **Performance and compatibility**, **Laravel-facing result**, **Validation and review**, and **Assessment**. Link this detailed plan and check off Broadcasting only after implementation, the complete gate, fresh self-review, and code-review signoff.

Under **Important rejected concerns**, record that no destructor return type was added because PHP forbids any return type on `__destruct()`; this is a language constraint, not an untyped Laravel or Hypervel API choice.

Dispose of every dependency-index row that names Broadcasting:

- `events-05`: revalidated as already satisfied by `BroadcastEvent::$backoff` being `array|int|null`; mark Broadcasting complete in the index/Events record.
- `support-02`: complete for Broadcasting after `broadcasting-03`; every other enum boundary already uses the established string normalization and `BroadcastEvent::handle()` is the last gap.
- `redis-13`: complete after canonical prefix ownership and cluster publication correction.
- `contracts-09`: complete while concrete/proxy/facade/command `getChannels()` support remains and the core Broadcaster contract correctly omits it.
- `queue-11`: complete for Broadcasting. `BroadcastEvent` reads Queue's variadic/array-aware `Backoff` attribute through `ReadsQueueAttributes`, its property is `array|int|null`, and existing method/array/variadic regressions remain green.
- `queue-12`: complete for Broadcasting. Unique broadcast acquisition delegates to Bus's canonical `UniqueLock`, and existing manager regressions pin the exact `xxh128` display-name key for plain, property-ID, and method-ID unique events.

Update the corresponding owning-ledger prose as well as the dependency-index rows so no completed marker conflicts with stale text:

- `events-05` prose currently saying the later full Broadcasting audit must retain the boundary;
- `redis-13` prose currently saying Broadcasting remains routed to its full audit;
- both `contracts-09` prose locations currently naming Broadcasting as affected/remain pending.

`support-02` has no second prose marker. Update the `queue-11` and `queue-12` dependency-index rows to mark Broadcasting revalidation complete; their owning implementation is unchanged.

Update the audit routing index's three active-work lines exactly. At implementation start they name the Broadcasting audit, its new ledger heading plus the six carried IDs, and all six pending revalidations. At completion reset them to no active work, no required ledger entries, and no revalidation carried into active work. Every dependency-index row naming Broadcasting must then say revalidation is complete, and the Broadcasting package checklist must be checked.

The ledger must also say that the prior Redis cluster expectation encoded duplicate prefixing and the prior built-in-pool assertions encoded the default deliberately removed here. Correcting those assertions is part of the accepted design, not an unexplained regression.

## Test plan

| Surface | Required counterfactual coverage |
|---|---|
| `tests/Integration/Broadcasting/BroadcastManagerTest.php` / Redis config fixtures | canonical prefix precedence and scalar normalization at construction; immediate/queued/unique enum dispatch; ordinary object isolation; single selected wrapper construction; exact unique broadcast lock keys; corrected direct-built-in default; no default pool; manager-reachable concrete SDK getters/setters; extend existing explicit custom-pool regressions; creator shapes; provider/alias identity |
| `RedisBroadcasterTest` | Lua manual prefix; cluster native prefix ownership; formatter/cast preservation; leading-only auth prefix removal; guard selection; throwing payload/presence JSON |
| `AblyBroadcasterTest` | throwing presence JSON; ordinary channel release on success and failure; encrypted channel identity/options retained; repeated dynamic names do not accumulate |
| `PusherBroadcasterTest` | JSON default despite callback; configured JSONP; opt-in without callback remains JSON |
| `BroadcastEventTest` | string, string-backed enum, int-backed zero, unit enum names; single-string `broadcastOn()`; wrapper clone for enum and ordinary object; existing method/array/variadic Backoff behavior remains green |
| `tests/Integration/Broadcasting/SendingBroadcastsViaAnonymousEventTest.php` | strict socket comparison makes the `toOthers()` assertion counterfactual |
| `BroadcasterTest` / `LogBroadcasterTest` | callable/string normalization contract; throwing log JSON |
| `tests/Foundation/FoundationConfigTest.php` | Pusher and Reverb ship `jsonp => false`; existing Reverb `path` remains |
| `tests/Broadcasting/PackageMetadataTest.php` | mirror the HTTP metadata-test shape; decode package/root metadata with `JSON_THROW_ON_ERROR`; assert exact required/suggested/provider edges, optional Redis wording, and removed stale dependencies |

For tests involving static registries, manager caches, config, or SDK substitution, restore exact prior state in `finally`/teardown. No live Pusher, Ably, or additional Redis round trips are required beyond existing integration facilities.

## Implementation order

Work one file at a time and run its focused test immediately:

1. Redis manager/config ownership, broadcaster behavior, and Redis tests.
2. Ably lifecycle/JSON behavior and tests.
3. Pusher JSONP/client lifetime behavior, Foundation config, and tests.
4. event name/channel/clone contracts and tests;
5. direct built-in ownership, retained custom pools, provider identities, rescue cleanup, and integration tests;
6. remaining JSON/type/test hygiene;
7. metadata test and Composer metadata;
8. Boost docs, README, todo removal, plan/ledger/routing records.

## Validation and final review

1. Run each changed test file immediately after its owning source file.
2. Run all `tests/Broadcasting`, `tests/Integration/Broadcasting`, and affected Foundation config tests.
3. Run package metadata validation and `git diff --check`.
4. Run `composer fix` as the final fixer/PHPStan/parallel-test gate.
5. Inspect skips and warnings normally; do not weaken assertions to get green.
6. Freshly trace every changed caller/callee, clone boundary, config precedence path, pool branch, SDK cache owner, auth normalization path, JSON failure, static state/reset, and public/protected API.
7. Confirm no stale built-in pool config/docs/todo, duplicate prefixing, non-throwing arbitrary-data encode, unbounded ordinary Ably cache, false dependency, or superseded comment remains.
8. Reassess allocations, container/config resolution frequency, yields, network calls, worker-retained memory, and custom extension compatibility.
9. Request independent review of the complete diff and continue until sign-off before the owner pre-commit checkpoint.

## Completion invariants

- Redis logical and physical channel names agree for standalone and cluster connections under every supported prefix precedence layer.
- Authorization removes one physical prefix without corrupting the logical channel or guard selection.
- All documented broadcast name/channel forms reach strict internal contracts safely.
- Built-in clients are direct, concurrent, and manager-cached; custom pooling remains fully usable only when explicitly selected.
- Ordinary Ably dynamic channels cannot accumulate worker-wide; configured encryption is never silently discarded.
- Invalid transport JSON fails loudly before publication/signing/logging.
- No Laravel public API or protected extension point is broken. JSONP only changes from unsafe implicit behavior to current explicit opt-in.
- No added request-time I/O, serialization layer, lock, registry, retry, or meaningful hot-path overhead exists; direct SDK resolution removes proxy and pool overhead.
- The ledger, routing index, and package checklist truthfully record a completed Broadcasting audit with every carried revalidation closed.
