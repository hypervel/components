# Hypervel pool API and lifecycle redesign

## Status

Implementation and verification are complete. Integration with the latest `0.4` and verification of the combined code remain.

## Outcome and boundaries

Make the two packages immediately distinguishable: reusable objects belong to `hypervel/object-pool`; protocol connections belong to `hypervel/connection-pool`. Use consistent names for equivalent operations while preserving their different lifecycles. Combine the naming migration with the verified correctness fixes and removal of repeated checkout work.

Hypervel 0.4 is greenfield: remove obsolete Hypervel names instead of providing aliases. Preserve supported Laravel APIs, including named parameters and protected extension points. Pool-specific Hypervel APIs may change as specified here. Laravel conventions take precedence; ordinary pooling vocabulary fills gaps where Laravel has no matching API.

Keep useful capabilities even without a present first-party consumer. No shared generic pool superclass, strategy registry, class-string configuration, replacement options interface, generic maintenance framework, clock service, or extra maintained occupancy counter. Benchmark only when workload behavior, regression uncertainty, or added complexity could change the design decision.

## Final names and contracts

Paths below are relative to the components repository. Old identifiers in the mapping describe migration work; they are not compatibility APIs.

| Existing | Final |
|---|---|
| `hypervel/pool`, `src/pool`, `Hypervel\Pool`, `tests/Pool` | `hypervel/connection-pool`, `src/connection-pool`, `Hypervel\ConnectionPool`, `tests/ConnectionPool` |
| Abstract `Pool` | `ConnectionPool` |
| `PoolOption` | `PoolOptions` |
| `SimpleObjectPool` | `CallbackObjectPool` |
| `Database\Pool\DbPool` | `Database\Pool\DatabasePool` |
| Database and Redis `Pool\PoolFactory` | Each subsystem's `Pool\PoolManager` |
| `Sentry\Transport\Pool` | `Sentry\Transport\HttpTransportPool` |
| `ObjectPool\Traits\HasPoolProxy` | `ObjectPool\Concerns\HasPoolProxy` |
| `Pool\Events\ReleaseConnection` | `ConnectionPool\Events\ConnectionReleasing` |
| `Frequency` | `BorrowRateTracker` |
| `ConstantFrequency` | Explicitly owned `IdleConnectionMonitor` |

Keep `Connection`, `KeepaliveConnection`, `ObjectPool`, `PoolDefinition`, `PoolFingerprint`, `PoolProxy`, and contextual `PoolOptions` names. Keep `HttpPoolTransport`, which implements Sentry's asynchronous transport behavior, distinct from `HttpTransportPool`, which creates and pools SDK transports.

Move the contracts with `mv`, retaining strict native signatures and necessary generics:

- `Hypervel\Contracts\ConnectionPool\ConnectionPool`, `Connection`, and `UsageTracker`.
- `Hypervel\Contracts\ObjectPool\ObjectPool`, `Factory`, `Recycler`, and `InvalidatesPool`.

Remove `PoolOptionInterface`, `FrequencyInterface`, `LowFrequencyInterface`, and `ClearableFrequencyInterface`. `UsageTracker` replaces the recording/decision contract split; periodic maintenance no longer pretends to be a frequency strategy. Options and the monitor are concrete collaborators. Central contract signatures may reference optional package types lazily; do not introduce reverse Composer dependencies or cycles solely for those type names. `Factory` remains the object-pool manager contract, consistent with Laravel's factory/manager convention.

### Pool and manager APIs

| Operation | Final API and meaning |
|---|---|
| Acquire ownership | `borrow()` instead of pool `get()` |
| Return or dispose | `release()` and `discard()`; preserve ownership validation |
| Terminal teardown | `close()` and `isClosed()` |
| Options | `getOptions()` and protected `$options` |
| Instantiated resources owned by pool | `getManagedCount()` |
| Application checkouts | `getBorrowedCount()` |
| Available resources | `getIdleCount()` |
| Coroutines waiting for capacity | `getWaitingCount()` |
| Snapshot | `getStats(): array{managed: int, borrowed: int, idle: int, waiting: int, closed: bool}` |
| Connection excess-idle trimming | `trimExcessIdle()` replaces connection `flush()` |
| Object age maintenance | Keep `trimIdle()` and `sweepExpired()` |
| Whole object-pool inactivity | `isIdleExpired()` replaces `isIdle()` |
| Manager resolve/create | `pool()` replaces connection managers' `getPool()` |
| Registry inspection | `getPools()` replaces `pools()`; object `getDefinition()` replaces `definition()` |
| Detach and close one | `purge()` replaces object `remove()` and connection `flushPool()` |
| Detach and close all | `purgeAll()` replaces manager `flush()`/`flushAll()` |
| All physical variants of a DB connection | `purgeForConnection()` replaces `flushPoolsForConnection()` |
| Object proxy pool name | Keep `getPoolName()`, returning the fully qualified registry name consistently |

Both pool contracts expose the count and snapshot APIs. Managed counts exclude in-flight creation reservations but include owned resources undergoing maintenance or destruction. Therefore managed need not equal borrowed plus idle during a yield. Capacity enforcement still includes creation reservations. Counts remain O(1) reads of existing state.

`trimExcessIdle()` destroys only idle connections while the managed count exceeds `minRetainedConnections`, independent of idle age. The retention floor is not a target idle count, eager creation policy or guarantee after failures. Object `trimIdle()` additionally requires the individual idle-age threshold; `sweepExpired()` applies absolute lifetime regardless of that floor. Do not collapse these distinct operations just to match names.

Use “Close excess idle connections without checking their age.” as the connection method's contract/concrete docblock title, with the retained-minimum condition beneath it. Keep this documented user operation on the contract. `checkIdleConnection()` stays concrete: it is the supplied monitor's maintenance primitive, not a required operation for every contract-only pool.

Keep `Lease::get()` as access to an already-held object. Keep connection-level `getConnection()` and keepalive `call()`; these expose different underlying-resource models. Keep pool and configured connection `getName()` accessors and database physical-name resolution; do not rename every occurrence of `getPoolName` blindly.

Preserve object `getOrCreate($definition, $createCallback)`, required existing-only `get($identity)`, and `has($identity)`. A missing required lookup keeps its existing framework/native exception; do not add a nullable synonym or `PoolNotFoundException`. Document that `has()` does not reserve registry membership across a yield. Rename database pool manager `hasPool()` to `has()`, retaining its physical-name resolution; do not add new presence/lookup methods to other managers merely for symmetry.

Keep optional expected-instance comparison and existing boolean returns on targeted purge. Detach registry entries and definitions before yielding cleanup. Do not reset outstanding reservations during manager purge while borrowed resources still own them.

Rename creation callback parameters/properties to `$createCallback` in `CallbackObjectPool`, object manager, proxy and concern where they create a resource. Preserve distinct `$releaseCallback` and `$destroyCallback`. Rename numeric `$creating`/`$acquiring` to `$creatingCount`/`$acquiringCount`. Rename concrete pool-manager dependencies/accessors to `$poolManager`/`poolManager()`; retain object `Factory` contract terminology where it actually identifies that contract.

Across mail, queue, filesystem and broadcasting, use `$poolableDrivers`, `addPoolableDriver()`, `removePoolableDriver()`, `getPoolableDrivers()`, and `setPoolableDrivers()`. Update facade annotations and preserve boot-only mutation warnings and existing driver-selection behavior.

Document that `HasPoolProxy` requires the host's protected array `$poolableDrivers`. Keep differing defaults on the managers; do not add a conflicting trait property or accessor machinery solely to declare that requirement.

## Immutable options and duration semantics

Both packages use `final readonly PoolOptions` with public camelCase properties, a private normalized constructor, and `fromArray()`. Callers configure values before constructing the pool and read `$pool->getOptions()->maxConnections` or `->maxObjects`. Remove redundant per-field getters/setters; do not add a live-resizing mechanism. Retain `equals()` and `toArray()` only on object options, where definitions and mismatch diagnostics use them.

| Option | Connection default | Object default |
|---|---:|---:|
| `min_retained_connections` / `min_retained_objects` | `1` | `1` |
| `max_connections` / `max_objects` | `10` | `10` |
| `connect_timeout` | `10.0` | — |
| `wait_timeout` | `3.0` | `3.0` |
| `heartbeat_interval` | `null` | — |
| `heartbeat_timeout` | `1.0` | — |
| `idle_check_interval` | `null` | — |
| `max_idle_time` | `60.0` | `null` |
| `max_lifetime` | `null` | `60.0` |
| `pool_idle_timeout` | — | `300.0` |
| `events` | `[]` | — |

Rename `min_connections`, `heartbeat`, and object `idle_ttl` to their corresponding keys above. Rename `DEFAULT_IDLE_TTL` to `DEFAULT_POOL_IDLE_TIMEOUT`. Optional duration values are null to disable or finite positive integers/floats; reject zero, negative sentinels, strings, booleans, and non-finite numbers. `connect_timeout`, `wait_timeout`, and `heartbeat_timeout` remain finite positive non-null durations. Counts are integers, minimum at least zero, maximum at least one, minimum no greater than maximum.

Retain distinct defaults deliberately. Generic objects have no standard health/reconnect protocol, so lifetime rotation supplies age control. Protocol connections have health/reconnect behavior and can enable absolute lifetime rotation when needed. Matching defaults mechanically would change useful behavior without a corresponding benefit. Neither pool force-kills an application-owned resource simply because it has aged.

Normalize once with explicit presence checks: omission selects the documented default; explicit null disables a nullable option instead of falling through `??` to an enabled default. Reject unknown keys and invalid list shapes. Cast environment-backed numbers/booleans at config boundaries, preserving null before casting. Retain object definition equivalence/fingerprint rules and strict option comparisons.

Align Hypervel-owned environment names with the renamed keys: `*_MIN_CONNECTIONS` becomes `*_MIN_RETAINED_CONNECTIONS`, and interval settings `*_HEARTBEAT` become `*_HEARTBEAT_INTERVAL`. Preserve the owning DB/DB_POOLED/REDIS/REDIS_CACHE/REDIS_SESSION/REDIS_QUEUE/REDIS_REVERB prefixes and existing inheritance. Update config examples and any matching local environment keys without printing their values. Do not rename `*_HEARTBEAT_TIMEOUT` or unrelated protocol settings. Keep `idle_check_interval` discoverable in config/docs without inventing a new family of unnecessary environment switches.

Use this null-preserving shape for an inherited duration in a returned config array, evaluating its environment expression once:

```php
'heartbeat_interval' => ($duration = env(
    'REDIS_CACHE_HEARTBEAT_INTERVAL',
    env('REDIS_HEARTBEAT_INTERVAL', null),
)) === null ? null : (float) $duration,
```

Apply the same shape to all 18 heartbeat/lifetime negative-sentinel sites in foundation database config, retaining every nested fallback, and to its nine `max_idle_time` entries, whose enabled default remains 60. In `src/foundation/config/filesystems.php` and `src/foundation/config/queue.php`, change all four literal `'max_idle_time' => 0.0` entries to null and rename their `idle_ttl` keys to `pool_idle_timeout`. Reuse the local `$duration` name in nullable environment expressions; each value is consumed within its array element. Explicit environment `null` (or `(null)`) disables the setting even when its inherited value is enabled; an absent variable selects the fallback. `Env::getOption()` preserves this distinction through `Option::fromValue()->map()`. Do not cast null to zero, discard inheritance, or add a general duration-conversion service. Test omission, inherited numeric values, explicit outer null, inherited null, and positive overrides against loaded config.

Connection `events` is a list of event class names. Validate strings and `class_exists()` once in `fromArray()`, allowing third-party classes without an allowlist, reflection layer or marker interface. Keep `hasListeners()` before constructing `ConnectionReleasing`; dispatch remains before the connection is returned, with existing exception/cancellation cleanup.

Concrete pools preprocess raw config before base normalization. Database removes `testing_enabled`; for in-memory SQLite, clamp valid raw retained/max values to a maximum of one while retaining current invalid-input rejection. Preserve DB driver connect-timeout precedence and Redis's native timeout fallback semantics.

In `src/sentry/src/SentryServiceProvider.php`, update `sentryPoolOptions()` explicitly: its final normalization must force `'max_idle_time' => null` and `'pool_idle_timeout' => null`, replacing zero and the old key. Retain its supported input allowlist (`max_objects`, `wait_timeout`, `max_lifetime`); none of those names change. Exercise the provider normalization through its existing config tests, not only the options value object.

### Jitter and disabled deadlines

On connection `PoolOptions`, change the jitter helper to an instance method:

```php
public function jitteredLifetimeDeadline(float $createdAt): ?float
{
    if ($this->maxLifetime === null) {
        return null;
    }

    $factor = random_int(self::MIN_LIFETIME_JITTER_BASIS, self::LIFETIME_JITTER_SCALE)
        / self::LIFETIME_JITTER_SCALE;

    return $createdAt + $this->maxLifetime * $factor;
}
```

Keep the existing public 9000/10000 jitter constants. Reading validated instance state removes repeated lifetime validation without allowing arbitrary unchecked durations. Keep this method on options: database `PooledConnection` implements the contract directly, whereas Redis extends the base connection, so moving it to that base would strand the database caller.

Database/Redis `$lifetimeExpiresAt` becomes `?float = null`. Apply null-disable behavior to base connection checks, keepalive idle timeout, database/Redis expiry and heartbeat scheduling. Keep actual activity/initialization timestamps numeric: zero means not recorded, not a disabled option. In particular retain the last-release initialization checks and never refresh user activity during maintenance.

## Maintenance without redundant machinery

### Usage-triggered shrinking

`Hypervel\Contracts\ConnectionPool\UsageTracker` contains:

```php
public function recordBorrow(): void;
public function shouldTrimExcessIdle(): bool;
```

`BorrowRateTracker` implements it and exposes concrete `getBorrowRate(): float`. The protected `ConnectionPool::createUsageTracker(): ?UsageTracker` returns null by default; database and Redis override it to create a fresh tracker. Custom connection pools acquire no new default tracker. A custom non-rate policy can implement the contract without inheriting bucket machinery. Preserve customization through protected factory/subclass construction, not global singleton resolution or class-string config.

Initialize lazily on the first successful acquisition in `borrow()`, immediately before recording, inside the existing maintenance exception/cancellation boundary. Never call the overridable factory from the base constructor. Cache a successful result, including null, using an initialization flag beside the nullable tracker so opted-out pools do not invoke the factory on every borrow. Set the flag only after the factory returns. The factory constructs a lightweight per-pool collaborator synchronously; it does not borrow from the pool or perform yielding I/O. No locking or general initialization framework is needed. Test a subclass whose factory reads state assigned after `parent::__construct()`, one-time null initialization, and ordinary/cancellation factory failure cleanup.

The protected factory lets subclasses select or disable a policy using normalized configuration and completed subclass state without replacing the database/Redis constructor. Null means disabled, not “construct the default tracker.”

The built-in tracker's sample window and initial cooldown begin at first acquisition; time before first use is not usage history.

Replace repeated `Frequency::flush()` and `array_sum()` on checkout with once-per-second pruning/backfilling and a running count. Each borrow increments the current bucket and running count; expiration subtracts removed buckets. Rate is the running count divided by the current number of samples, returning zero before any samples exist. Preserve the 10-second default window, threshold 5 and 60-second cooldown, including the existing strict cooldown boundary. Keep window, threshold and cooldown customizable using clearly named protected settings. Bound pruning/backfill work and retained state by the configured window, including after long idle periods.

Use `intdiv(hrtime(true), 1_000_000_000)` in protected `currentTime()` for monotonic sampling seconds. Wall-clock corrections must not retain future buckets or distort cooldowns. Keep the existing deterministic clock override and sampling tests; verify the default clock against monotonic bounds captured immediately before and after its call. No clock service or wall-clock repair logic is needed.

Do not memoize the rate for a second: multiple borrows in that second must immediately change it, including after cooldown eligibility when high traffic prevents trimming. The extra running count is justified because otherwise that path repeatedly sums buckets. Use one captured second per operation so a clock rollover cannot split pruning and recording across different seconds. Preserve warmup and sample-boundary behavior, not just steady-state averages.

When migrating the seeded `FrequencyStub`, synchronize its aggregate and invalidate its prune memo whenever tests replace buckets or beginning time. Do not add a production state-rebuilding API. Prefer deterministic public-behavior tests; a small protected time method is sufficient if required to replace real-time races and long sleeps.

`borrow()` records successful ownership acquisition and asks the tracker whether to call `trimExcessIdle()`. Preserve its existing cancellation cleanup: a cancellation during maintenance disposes the just-acquired resource and rethrows the original; ordinary maintenance failures remain reported. Keep nullable direct dispatch instead of the old union property and `instanceof` branches.

### Optional periodic idle checks

`IdleConnectionMonitor` replaces the useful timer capability of `ConstantFrequency`; it is not merely a rename of an unused class. It is an owned concrete collaborator, with idempotent `start()`/`stop()`, enabled through `idle_check_interval`. Construct it per pool with an owned `Timer` and allow constructor timer injection for testing/custom construction. Do not resolve an unbound stateful timer/monitor as a worker-wide auto-singleton.

Concrete `ConnectionPool::start()` prepares the configured idle monitor after accepted construction. Before activation, borrowing does not create or start it. An empty activated pool starts its prepared monitor on the first successful borrow; an already-warmed pool starts it during activation. Keep the nullable monitor as this feature's activation state, without another flag. Its existing closed-state guard handles closure during yielding usage trimming. No generic idle timer exists for a pool that never acquires a connection.

Set an in-progress/started guard before calling `Timer::tick()`. `Coroutine::afterCreated()` hooks run synchronously before the timer ID is returned and can reenter or close the owner without suspending. Reset startup state if creation fails. If close occurs during creation, clear the returned timer instead of publishing it. Keep this a direct lifecycle guard; do not add locks, generations, a registry, or a second pending interval field. Tests must not use suspending after-created hooks, which violate that API's contract.

Pool close marks terminal state, stops the monitor, then closes/drains its channel. A check already in flight destroys its connection rather than requeueing after closure. The timer callback respects worker exit. Restart/start calls must not produce duplicate timers or revive a closed pool.

Check one FIFO idle connection per tick through `checkIdleConnection()`, with no extra maintenance queue. For N continuously idle connections, a pass takes roughly N intervals plus check time and scheduling; this is not a strict reclamation deadline. Custom `check()` can perform protocol I/O. It must not refresh activity timestamps. The monitor checks health/idle validity even below the retention floor; it is distinct from trimming healthy excess capacity.

Keep database/Redis protocol heartbeat sweeps and `KeepaliveConnection` socket heartbeat. They have protocol-specific timeouts, lifetime/idle policy and cleanup. Both generic monitoring and a driver's heartbeat may be enabled; document overlapping checks instead of silently giving one precedence. Custom drivers whose health checks require an active checkout must reject a non-null `idle_check_interval`.

### Object recycler

The `Recycler` contract contains only `start()` and `stop()`. Its start title is “Start periodic pool maintenance.” Concrete `PoolRecycler` keeps constructor interval configuration, finite-positive validation and `getInterval()`, with optional `?Timer` injection and a fresh owned default. Remove public timer/id access and mutable timer/interval setters. Document constructor/binding customization, retaining contract/concrete service identity and worker-start/pre-fork wiring.

Retain whole-pool idle eviction, lifetime sweeping, idle trimming, exact-instance purge, protection for acquiring/borrowed resources, and ordinary per-pool failure isolation. Do not introduce a maintenance service framework or replace these operations with the connection monitor.

The current recycler catches `Throwable` both around an individual pool and around the scheduled maintenance call. A custom contract pool or yielding destruction can propagate cancellation, which these catches convert into an ordinary report and further maintenance. Add typed cancellation rethrows at both boundaries so the existing Timer loop can stop; keep ordinary failure isolation and do not drain other pools after a canceled maintenance operation. Verify original exception identity at the direct maintenance call and the captured timer callback. Keep the existing simple timer-ID start/stop idempotence: recycler startup is a worker-lifecycle operation, without the monitor's demonstrated borrow-path reentry. Do not add a publication guard for a hook that would have to call recycler lifecycle methods deliberately.

## Shared channel and preserved ownership invariants

Move the matching pool channel implementation into `Hypervel\Coroutine\PoolChannel`, with `@template T of object`, `SplQueue<T>`, `push(object $data): bool`, and `pop(): object|false` carrying the generic return annotation. Each pool retains its typed object/connection boundaries. Move one channel with `mv` and reconcile the second before deleting its duplicate; do the same for channel tests, merging all distinct coverage into `tests/Coroutine/PoolChannelTest.php`.

Keep the queue independent of execution mode and the native channel solely for wake signals. Preserve signal coalescing, nonblocking queue ownership, canceled-wait classification, waiter decrement in finally, close retaining queued resources for draining, non-coroutine/coroutine transitions, and the final state pass after a deadline or lost wake signal. The existing `Coroutine\Channel\Pool` caches native channels and remains a different feature.

Both pools retain: reservation before yielding creation; maximum including creation slots; a single capacity-wait deadline; fresh-instance checks; strict foreign/double-release/discard rejection; destruction when creation finishes after close; release-after-close cleanup; no maintenance activity refresh; and exactly-once deferred leases. Keep bounded inspection of the idle population present at a sweep's start. Do not unify connection reconnection and object lifetime policy behind a shared pool abstraction.

## Correctness repairs at their owning boundaries

### Database and Redis pool publication

Both managers construct named pools through contextual container resolution. Custom initialization and resolving callbacks can yield before the candidate is registered, allowing competing candidates and orphaned heartbeat timers. Heartbeat child startup alone does not suspend the constructing caller.

Use a local lookup loop in each `pool()` method:

1. Return an open cached pool; remove a closed entry without closing it again. Database applies this to its exact-name fast path and then its resolved physical key, preserving base/read/write mapping.
2. Construct the candidate outside the registry, call its concrete `start()` after container resolution returns, then recheck its physical key.
3. If no open entry exists, publish and return the candidate. If the registered open entry is the candidate itself, return it directly.
4. Close a distinct losing candidate, then restart lookup. Cleanup can yield while the winner closes or is replaced, so do not return a saved winner afterward. Propagate cleanup errors and cancellation unchanged without altering the registered winner.

The loop repeats only after competing publication; it does not retry construction failures. Keep `has()` as registry membership and `getPools()` as existing-only inspection. No shared registry abstraction, lock, generation counter or new manager API is needed. Outstanding borrowed resources remain owned by the closed old pool until their release.

For each manager, test direct-close replacement/reuse, concurrent resolution with exactly-once loser closure and no timer left after purge, a closed entry appearing during construction, candidate/winner identity, and original cleanup failure/cancellation with the winner preserved. Exercise yielding loser cleanup while the winner closes or is replaced to verify lookup returns the current open entry. Database also covers physical read/write aliases. Use boot-registered resolving callbacks and bounded coroutine coordination, never suspending `afterCreated` hooks. Close every candidate in `finally` and reuse existing test setup where practical.

### Accepted construction and background activation

Constructors must not start DB/Redis heartbeats. A later resolving callback can fail before the manager receives the candidate; an automatically started timer would retain that rejected pool. Warming through borrow/release can create the same root through the generic idle monitor, so both background tasks use one explicit activation boundary.

Add concrete `ConnectionPool::start()` without changing the pool contract. Return when closed or generic monitoring is disabled; otherwise initialize the owned monitor with `??=` and start it only when managed resources exist. In `borrow()`, replace monitor creation with `$this->idleMonitor?->start()` inside the existing maintenance failure boundary. This preserves deferred scheduling for empty accepted pools and supports warming before activation without another state flag.

DB/Redis constructors configure their owned Timer but do not schedule it. Their public `start()` calls `parent::start()` and protected `startHeartbeat()`. Heartbeat startup returns when closed, disabled or already starting/started, retaining the database shared-SQLite exemption. Set a boolean startup guard before `tick()`, reset it on failure, and hold the returned ID locally. If stopped or closed during synchronous coroutine-start hooks, clear the returned timer instead of publishing it. `clearHeartbeat()` resets the guard and stored ID before clearing the captured ID. Explicit repeated starts are idempotent and a failed timer creation permits an explicit retry. Keep the guards local; no new heartbeat service, weak-reference ownership or destructor scheme.

Managers own activation failures after `make()` returns: close that exact candidate without changing another registered winner, then propagate failure. Activation cancellation stays primary; cleanup cancellation replaces an ordinary activation failure; otherwise preserve the original activation failure. Do not retry failed activation or add exception aggregation/reporting machinery.

Directly constructed pools call `start()` to enable background maintenance. Update direct-construction consumers, fixtures and canonical documentation; do not keep constructor auto-start through a flag. Initializers that explicitly call `start()`, spawn children or retain resources own cleanup for that explicit work. Merely borrowing/releasing before accepted construction must not root a rejected pool through framework timers.

Test failed resolving callbacks before/after warming with heartbeat and generic monitoring enabled: no registered pool, timer or live weak reference after collection. Cover empty/warmed activation, first-borrow scheduling, repeated start, close/no-restart, timer creation failure/retry and close during timer publication. Test manager activation failures with exact exception identity/precedence and candidate cleanup. Concurrent publication expects no timer on the candidate blocked in resolution, then one accepted winner timer and none after complete cleanup.

### Connection acquisition and release

Base `Connection::getConnection()` delegates once to `getActiveConnection()` and preserves its result or original failure. Do not retry arbitrary throwables: programming errors, invalid configuration, authentication failures and completed deadlines are not a generic pool recovery policy. Redis retains its existing check/reconnect paths and native retry settings. Database's independent wrapper is unchanged. Replace the blanket-retry test with success and one-attempt/original-exception coverage for ordinary errors, `TypeError` and cancellation; remove the failure-once fixture behavior.

Simplify `Connection::release()` into event handling followed by one pool release. A private `dispatchReleasingEvent()` owns timestamp/event dispatch and ordinary listener-error logging, with cancellation passed through. Capture any escaping throwable, attempt pool release once, then apply these rules:

- Ordinary listener failure is logged and swallowed when logging succeeds.
- Listener or logger cancellation stays primary over cleanup failure; ordinary secondary cleanup errors are reported without replacing that cancellation.
- An ordinary logger failure propagates when cleanup succeeds; cleanup failure takes precedence otherwise. Do not retry an already-failing logger to report its own failure.
- Cleanup failure propagates when no earlier failure exists.

Preserve `hasListeners()` and event-before-return ordering. Test ordinary logger failure with successful and failing cleanup alongside existing cancellation and exactly-once return coverage. Do not introduce a general exception or cleanup service.

### Shared SQLite resource closure

In `DatabasePool::close()`, clear `$sharedInMemorySqlitePdo` in a `finally` around `parent::close()`, keeping heartbeat shutdown first. Parent closure can propagate cancellation after marking the pool closed; a retained closed pool must not keep exposing its shared PDO. Preserve the original exception. Add a focused test using a real shared SQLite pool and a controlled cancellation from its owned connection's close, asserting cancellation identity and the cleared reference. Base drain/count/idempotence matrices need not be duplicated.

### Complete detached-manager drains

Object `PoolManager::flush()` detaches everything and then uses a bare close loop. A thrown close abandons later detached pools. Make every relevant manager's `purgeAll()` attempt the complete finite set, keeping the first ordinary failure and first cancellation separately and preferring cancellation afterward. Database already supplies the direct `closePools()` pattern; Redis has equivalent behavior that can use the clearer typed catches. Do not introduce a shared helper service.

```php
$firstException = null;
$firstCancellation = null;

foreach ($pools as $pool) {
    try {
        $pool->close();
    } catch (CanceledException $exception) {
        $firstCancellation ??= $exception;
    } catch (Throwable $exception) {
        $firstException ??= $exception;
    }
}

if ($firstCancellation !== null) {
    throw $firstCancellation;
}

if ($firstException !== null) {
    throw $firstException;
}
```

Detach the entire selected set before entering this loop. A concurrent replacement remains registered and open. Retain object definition cleanup and database read/write variant selection.

### Custom connection cleanup

`destroyConnection()` unsets ownership and signals capacity in finally, then propagates cancellation. Expose protected `ConnectionPool::ensureManaged(ConnectionContract $connection): int`, returning the validated object ID, so driver overrides can establish ownership before cleanup. Preserve the existing error and distinct channel/borrowed-ownership diagnostics. Overrides must not release reservations for rejected foreign or duplicate destruction; preserve primary cancellation if secondary cleanup fails.

### Generic checks and database/Redis heartbeat disposal

`ConnectionPool::checkIdleConnection()` currently catches cancellation as an ordinary failed check. Database and Redis `heartbeatConnection()` also put destruction inside the same catch region as health evaluation: cancellation from destruction is caught and causes a second destruction of an already-unmanaged connection.

Guard only expiry/health evaluation. On evaluation cancellation, dispose the currently owned idle connection exactly once and rethrow the original cancellation over secondary cleanup failures. Ordinary evaluation failures are reported and select disposal. Perform normal requeue/destruction outside those catches; cancellation during that disposal propagates directly and cannot trigger another disposal. Preserve database's open-transaction diagnostic and each driver's timeout/late-completion cleanup.

```php
try {
    $healthy = $connection->check();
} catch (CanceledException $cancellation) {
    try {
        $this->destroyConnection($connection);
    } catch (CanceledException) {
    } catch (Throwable $exception) {
        $this->report($exception);
    }

    throw $cancellation;
} catch (Throwable $exception) {
    $this->report($exception);
    $healthy = false;
}

if ($healthy && ! $this->closed) {
    $this->requeueConnection($connection);
} else {
    $this->destroyConnection($connection);
}
```

Adapt the evaluation to each driver's lifetime, retained-floor and health rules; use its existing disposal routine for protocol diagnostics. A small named decision method is acceptable where it makes those branches clearer. Do not add a heartbeat strategy or an ownership guard masking double disposal. A canceled sweep stops after its current resource is cleaned up; it does not drain every other idle connection. `Timer` already contains callback cancellation at its loop boundary.

### Keepalive socket ownership

Each reconnect publishes a new native channel. Use that channel object as the socket identity; no generation counter, state wrapper or lock. In `call()`, capture the channel being popped and inspect canceled status on that instance immediately after a false return. Cancellation becomes `CanceledException`; timeout or closed-channel failure remains `SocketPopException`. A failed ordinary waiter owns no socket and must not clear another caller's connection.

Only refresh activity after successful callback execution and requeue in finally when the captured channel is still current and connected. Otherwise drop the socket without protocol work that could replace a primary exception. Document that subclasses must return resources whose release/destructor closes the underlying socket.

`isTimeout()` returns false when disconnected, including before the first connection and after close, before reading its channel. Retain the non-nullable channel and the nullable previous-channel reads in close/reconnect; do not allocate an eager channel or spread nullable fallbacks through connected paths. Test the public predicate's initial, connected, closed and disabled-expiry states.

`close()` captures its channel. Retain the inner finally clearing state before requeue, and add an outer finally for acquisition failure; each clears only if the captured channel is still current. Keep the existing connected check before `sendClose()` on the socket actually held. Remove the duplicate clear from `closeAfterFailure()`; it suppresses secondary cleanup failure only. Preserve public/protected signatures.

The heartbeat callback captures its channel before protocol work. Guard failure cleanup against a replacement, not ordinary logging. Catch cancellation before `Throwable`, clean up only its own connection, then rethrow the original without ordinary error logging. `Timer::tick()` contains cancellation. Publish a locally returned heartbeat timer ID only if its channel remains current and connected; otherwise clear it. This handles non-suspending `afterCreated` hooks that close the ready connection before timer creation returns.

Concurrent reconnects can create multiple sockets and overwrite a live timer ID. After `getActiveConnection()` returns, check `isConnected()`: if another attempt supplied a live connection, protocol-close the unused socket without touching shared state or starting a timer. Report ordinary close failure through the existing logger/error-log behavior; propagate cancellation. If the other connection has already closed, publish the newly created socket normally.

Capture the previous channel immediately before publishing its replacement. Establish replacement connection/heartbeat state before closing the previous channel, including on heartbeat-startup failure, so awakened waiters see settled state and do not wait on an abandoned channel until timeout. The startup failure catch cleans up only its own candidate channel. Do not retain a pre-creation snapshot as the loser predicate or close target: another connection can be published and closed during creation.

Keepalive tests use creation/heartbeat/close callbacks on the existing fixture for bounded interleavings while retaining explicitly supplied protocol objects. Cover stale holder requeue/activity, late close/heartbeat failure, concurrent reconnect winner/loser cleanup, replacement after a winner closes, prompt old-channel waiter failure, and close during timer publication. Include heartbeat enabled/disabled explicit-close cases and primary cancellation identity.

### Precise exhaustion handling in Sentry

Add `PoolExhaustedException` and `PoolClosedException` directly under each pool package's `Exceptions`, extending `RuntimeException`. Throw them only at the capacity-wait exhaustion and closed-borrow boundaries, including closure during creation. Keep ownership/factory errors distinct, with their current native/framework failures.

`Sentry\Transport\HttpPoolTransport::send()` currently catches any `RuntimeException` from pool acquisition and labels it skipped. Catch only the object-pool exhausted/closed exceptions. Preserve asynchronous send, lease/release/discard, rate-limit state and shutdown behavior. Unexpected factory/ownership errors must reach the SDK's existing error handling; do not add another wrapper hierarchy.

## Observability

Use Hypervel's Laravel-derived Sentry integration and its own OpenTelemetry implementation as the implementation references.

In Sentry `Features/RedisFeature.php`, replace the misleading `db.redis.pool.using` mapping of total managed resources with explicit `managed`, `borrowed`, `idle`, and `waiting` attributes. Keep max/idle-time observations and retain `db.redis.pool.max_idle_time` with a null value when disabled; the Sentry SDK accepts null span data. Update the impossible idle 5/managed 2 fixture to realistic managed 7/idle 5/borrowed 2. Preserve sampling and feature guards before observation work.

Make Redis span observation existing-only: use the renamed manager's `getPools()[$event->connectionName] ?? null`, never its create-capable `pool()` method. The current `getPool()` call can create/cache a replacement and start a heartbeat timer after a concurrent purge or an event from a separately constructed connection. Record the command span regardless of registry presence; add pool name/options/count fields only when a currently registered pool exists. Do not introduce a new manager lookup API for this single observer. Cover absent and detached pools, asserting the command remains traced and no pool or timer is created.

For both connection and object OpenTelemetry counts, `used = managed - idle`; maintenance/disposal can occupy resources without an application borrower. Keep the existing OpenTelemetry instrument/attribute names and idle/used states. The object observer currently emits borrowed as used and loses this occupied capacity during yielding destruction; update it and its test. Explicit pool `getBorrowedCount()` remains the application ownership count. Do not introduce another maintained state counter.

Observe live manager registries in O(number of pools), with O(1) per-pool count reads. Keep disabled-instrument guards and avoid allocating stats when only a maximum is requested. Do not create pools, run health checks or perform network work solely to collect metrics. Do not add a timer per observed pool that captures obsolete instances. Retain metric identity/cardinality guidance in `src/docs/opentelemetry.md`.

## Source and research anchors

These references explain decisions; they do not make upstream structure a porting target.

| Evidence | Consequence |
|---|---|
| Laravel `src/Illuminate/Contracts`, database `DatabaseManager::purge()`/`getConnections()`, Redis manager and existing subsystem managers | Central contracts, clear manager responsibilities and lifecycle names; preserve actual Laravel APIs instead of globally renaming similarly spelled methods. |
| Hyperf `docs/en/pool.md`, `src/pool/src/{Frequency,ConstantFrequency}.php`; db-connection/db/redis/json-rpc pool construction; `CHANGELOG-3.0.md` PR 6099 | Usage shrinking and periodic checking are distinct useful features; preserve both and customizable policies. The [Hyperf pool guide](https://github.com/hyperf/hyperf/blob/master/docs/en/pool.md) documents replacement policies. |
| Hypervel `src/pool/src/Pool.php`, `Connection.php`, and `tests/Pool/ConnectionTest.php` | Preserve ownership bookkeeping and the existing regression that health checks do not refresh activity. |
| `src/coordinator/src/Timer.php`, `src/coroutine/src/Coroutine.php`, `src/queue/src/CoroutineQueue.php` | Existing owned timer injection/cancellation support; synchronous non-suspending startup hooks require a small publication guard. |
| Database `Pool/PoolFactory::closePools()` and `Pool/PooledConnection::close()` | Existing direct drain/error-precedence and finally-cleanup patterns. |
| [FriendsOfHyperf pool watcher](https://github.com/friendsofhyperf/sentry/blob/main/src/Metrics/Listener/PoolWatcher.php), adjacent DB/Redis watchers, [tracing listener](https://github.com/friendsofhyperf/sentry/blob/main/src/Tracing/Listener/EventHandleListener.php), and `Transport/CoHttpTransport.php` | Counts/options suffice for external instrumentation. Its managed-as-in-use and idle-as-waiting labels must not be copied. Its transport architecture is not Hypervel's target. |
| `src/opentelemetry/src/Instrumentation/PoolInstrumentation.php`, installed sem-conv DB state constants, [OpenTelemetry database metrics](https://opentelemetry.io/docs/specs/semconv/db/database-metrics/) | Idle/used metric states remain distinct from application borrowed ownership; derive occupied capacity from existing state. |

Hypervel's connection pool becomes independently maintained. Update its minimal README/package identity and remove the upstream-tracking line while retaining historical credit in canonical `src/docs/pools.md`.

## Package integration

Update root/split metadata, autoloading, facade annotations, CI configuration and active documentation. Object-pool no longer directly requires Engine; connection-pool still does. `bin/split.sh` derives repository names from source directories, so publishing requires a `hypervel/connection-pool` destination.

### Coordinated application-skeleton migration

The separate `hypervel/framework` wrapper requires `hypervel/pool`. Replace that requirement with `hypervel/connection-pool` and validate its dependency graph with the updated split packages.

The `hypervel/hypervel` application skeleton requires a companion configuration migration: otherwise generated applications supply removed keys/sentinels and fail as soon as the corresponding pool is constructed.

Update its `config/database.php` with the same connection option/environment names, nullable heartbeat/lifetime/idle values, retained defaults and inherited fallbacks specified above. In `config/filesystems.php` and `config/queue.php`, replace `idle_ttl` with `pool_idle_timeout` and disabled `max_idle_time: 0.0` with null; keep the existing retained-object and positive lifetime defaults. Check its config comments, examples and environment templates for matching active references. The committed components testbench application skeleton has no matching pool keys to migrate; its separate testing fixtures still need the inventory already specified.

Validate loaded default configs through the new option factories, null/inheritance cases and application bootstrap with the paired framework dependency. Coordinate release sequencing so the framework/package rename ships with a compatible application skeleton.

Preserve the application skeleton's Laravel-style `//` placeholders and set `no_empty_comment` to false in its formatter rules. Keep normalized migration imports and same-line anonymous-class braces, matching Hypervel migrations. Do not change the components formatter policy. Verify the configuration loads and a second skeleton formatter run makes no changes.

## Testing and acceptance

Mechanical renames migrate existing tests; behavioral changes receive focused regression coverage. Preserve existing assertions about supported behavior and replace tests of removed mutable APIs with tests of their approved immutable construction behavior. Do not weaken ownership checks or introduce production-only test hooks. New tests extend the Hypervel bases, use realistic resources/fixtures and bounded deterministic synchronization, and clean up owned children/timers in finally.

| Area / current test anchors | Required verification |
|---|---|
| `tests/Pool/{PoolTest,PoolNonCoroutineTest,ConnectionTest,PoolOptionTest}.php` → `tests/ConnectionPool` with corresponding class names | Defaults/unknown keys/counts/types; every optional null-disable case and required-positive rejection; event list/class validation including third-party class; jitter bounds/null; capacity reservations versus managed count; borrowed count/stats; closed/exhausted exception distinctions; one deadline, cancellation, strict ownership, release/creation after close and no timestamp refresh. |
| Both current `ChannelTest.php` files → `tests/Coroutine/PoolChannelTest.php` | Merge distinct cases: FIFO, queue survives signal close for drain, wait cancellation and waiter cleanup, coalescing/cross-mode signals, non-coroutine use and final-state retry after deadline. Keep both pool-level non-coroutine suites. |
| `tests/Pool/FrequencyTest.php` → `BorrowRateTrackerTest.php`; monitor cases → `IdleConnectionMonitorTest.php` | Rate initially zero, warmup/sample divisor, expired boundary, immediate same-second increments, cooldown boundary, high-traffic eligible cooldown, long idle, custom settings and running-count invariant. Pool-level tracker tests cover post-construction subclass state, first-borrow timing, cached null opt-out and factory failure cleanup. Monitor opt-in/default off, no constructor start, first successful borrow, fresh per-pool ownership, FIFO health checks, no activity refresh, start reentry/failure, close during startup/check, shutdown and no retained timer. |
| `tests/ObjectPool/{ObjectPoolTest,ObjectPoolNonCoroutineTest,PoolOptionsTest,PoolManagerTest,PoolRecyclerTest,PoolProxyTest,HasPoolProxyTest,LeaseTest,SimpleObjectPoolTest,PoolDefinitionTest,PoolFingerprintTest,ObjectPoolServiceProviderTest}.php` | Updated names/options and immutable equivalence; new exception types; all-pool close despite ordinary error/cancellation; cancellation priority; expected-instance replacement; acquiring/borrowed idle-eviction prevention; recycler constructor injection, service alias identity, cancellation through both catches and ordinary start/stop idempotence; complete lifecycle/lease/reset/deferred coverage. Rename callback-pool test and migrate rather than lose timer/setter capability assertions. |
| `tests/ObjectPool/PoolErrorReporterTest.php` | Retain its existing behavior coverage unchanged: the reporter's API/semantics are not being redesigned. Include it in the affected object-pool suite; the recycler fixes must propagate cancellation before calling the reporter, rather than changing its deliberately non-throwing reporting contract. |
| `tests/Pool/HeartbeatConnectionTest.php` → `tests/ConnectionPool/KeepaliveConnectionTest.php` | Canceled false pop versus timeout; typed heartbeat cancellation/no ordinary log; original exception survives close failure; ordinary canceled waiter preserves active owner's socket; explicit close timeout/cancellation clears state and timer with heartbeat enabled/disabled; active holder cannot requeue afterward; normal successful close/send failure behavior. |
| `tests/Database/PoolFactoryTest.php`, `tests/Redis/PoolFactoryTest.php`, corresponding lifecycle tests | Manager naming and physical lookup; detach before yielding close; complete drain and exception precedence; registry replacement survives old closure. Rename test files/classes to PoolManager. |
| `tests/Integration/Database/Sqlite/DbPoolHeartbeatTest.php`, `tests/Redis/RedisPoolHeartbeatTest.php`, teardown lifecycle tests | Typed/native cancellation through actual health path, one destruction, original cancellation survives secondary failure, disposal cancellation never causes second destruction, canceled sweep leaves later resources untouched, closed-during-check disposal, timeout/late completion, retention/lifetime, transaction diagnostic, disabled heartbeat, no extra application instrumentation. Rename DatabasePool test paths/classes coherently. |
| DB `PooledConnectionTest`, SQLite shared-PDO/pool tests, Redis connection/cancellation/event/proxy suites | Null idle/lifetime semantics, reconnect generation jitter, no borrowed-expiry interruption, SQLite one-owner/raw-invalid-input behavior, native timeout settings and existing coroutine pinning/reset/error semantics. |
| `tests/Sentry/{PoolTest,HttpPoolTransportTest,Features/RedisIntegrationTest,Features/StorageIntegrationTest,ConfigTest}.php`, OTel `Instrumentation/PoolInstrumentationTest.php` | New transport-pool name; expected exhausted/closed is skipped, arbitrary factory error escapes; SDK rate-limit state preserved; real readonly options and explicit null idle timeout in span data; realistic counts; absent/detached Redis registry still records the command without creating pools/timers; used includes yielding object cleanup and connection maintenance; explicit borrowed distinct; disabled/max-only observations avoid unnecessary work; replaced pools disappear from collected registries. |
| Filesystem/mail/queue/broadcast manager/proxy suites and service integrations | Poolable driver methods/facades; identity access; purge versus forget; resource equivalence; deferred streams/job leases; SDK/client reuse and supported Laravel signatures unchanged. Include test-support consumers in Foundation/Testbench and observability storage wrappers. |

For tests requiring new support types, use existing `Fixtures` directories or test-local helpers. Prefer regression cases through the existing concrete hooks instead of new monitoring registries, clocks or synthetic invalid coroutine lifecycle hooks. Cover real yields with controlled children/barriers, not unbounded polling or long timing sleeps.

Run framework tests from the repository root:

```sh
./vendor/bin/phpunit --no-progress tests/ConnectionPool/PoolOptionsTest.php
```

Run `composer lint:fix`, `composer analyse`, then the targeted suites. Complete the full framework suite with `composer test:parallel`, the Testbench package-mode suite and dogfood checks. Choose an explicit worker count that fits available memory and Redis database allocation. Use existing service traits and CI configuration; configured-but-unreachable services are failures.

Finish with Composer metadata/autoload checks, `git diff --check`, and searches for renamed symbols/keys, old getters/setters and inaccurate metric labels. Inspect matches: generic `get`, `flush`, `heartbeat`, real connection names, Laravel methods, attribution and historical plans can be legitimate. No stale active API examples, duplicate channels, obsolete frequency contracts or unused fixtures should remain.
