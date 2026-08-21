# Audit Correctness and Worker-Lifetime Bounds

## Objective

Correct the confirmed non-watcher findings from the 0.4 audit without changing Laravel-compatible APIs, adding material hot-path machinery, or documenting behavior the repository does not ship. The finished code should have truthful Redis result contracts, reentrant Redis command events, connection-owned database tracking, correct secondary Swoole settings, event preparation keyed only by finite registrations, bounded-work reclamation of expired worker-local cache state, whole-second deadlines that never shorten requested lifetimes, self-reclaiming mutex channels, accurate scheduler timing, robust diagnostics and stream writes, and no premature Boost installation instructions.

This plan targets branch `fix/audit-correctness-follow-up` from 0.4 commit `88190e640498`. Research references are the local Laravel checkout at `a659f095965b`, phpredis at `777f7377674a`, Swoole at `8e8c49915ca5`, and the installed PHP 8.4.23 / phpredis 6.3.0 / Swoole 6.2.2 runtime.

## Scope decisions and invariants

- All `FindDriver` / `FindNewerDriver` work is excluded. The complete watcher batch is already recorded under `docs/todo.md` and belongs in its own coherent PR.
- The audit's `reply_literal` explanation is false for phpredis 6.3.0. `SET ... GET` and serializer decoding are the real reasons the transformed `SET` result must be `mixed`; no `reply_literal` test or special case will be added.
- The Swoole audit is narrower than written. Most malformed settings fatal, but malformed `ssl_sni_certs` on an SSL port warns and returns `false`. Separately, untouched secondary ports inherit the first port's merged settings from Swoole, so Hypervel must explicitly configure every secondary with global plus that secondary's local settings.
- `Swoole\Server::set()` masks the primary port's recoverable `false` result by returning `true`. Hypervel will not duplicate Swoole validation or apply primary settings twice. The upstream handoff is `_tmp/swoole/pr-ideas/server-port-set-return-contract.md`.
- No Redis `EXISTS` probe will be added. With a serializer, a stored `false` and a missing key are deliberately indistinguishable through phpredis `GET`; a second command would also be racy.
- Redis listener reentrancy will reuse the connection that owns the event. It will not release the wrapper before dispatch, add a recursion guard, reserve a second pool slot, or alter event payloads. As in Laravel, nested commands emit their own events; an unconditional same-command listener therefore recurses instead of timing out on an accidental second pool checkout.
- Event listener and observer preparation will be retained only under finite registration keys. Dispatched runtime names will never enter Dispatcher state; there will be no cap, eviction policy, timer, request cleanup, wildcard index, or class-name memo.
- `Hypervel\RateLimiter\WorkerArrayStore` is not a defect. Its tests-only scope, per-worker isolation, and retention of expired untouched keys are explicit in shipped config and documentation; Reverb owns and clears its per-connection keys. It will not gain production-oriented pruning machinery.
- Eloquent mutator keys remain semantic model/schema identifiers. Arbitrary growth requires arbitrary request strings to be used as model attributes, while replacing one `StrCache` call would leave the parallel negative mutator maps unchanged. No Eloquent metadata redesign will be added for application code that passes unfiltered input to an unguarded model.
- `Hypervel\Cache\WorkerArrayStore` deliberately retains live and forever values across requests, but abandoned TTL-expired values and locks are dead state. Reclamation will perform fixed work per explicitly requested record write, never work proportional to existing store size. There will be no whole-map sweep, cadence, cap, eviction, expiry index, timer, coroutine, lifecycle hook, or new configuration.
- A future instant stored with whole-second precision must round upward so it never occurs before the requested duration or absolute deadline. Immediate, past, and zero-duration values retain their current floor. This is one shared time conversion plus direct storage-boundary uses, not a clock service or per-package timing mechanism.
- Mutex release will reclaim only a quiescent channel that is still the exact channel published for the key. It will not add owner tracking, reference counts, a wrapper entry type, producer introspection, polling, or cleanup timers.
- Stream completion loops stop on `false` or zero progress. They will not poll readiness, sleep, spin, or add asynchronous buffering.
- Laravel port structure remains recognizable: inline JSON flags stay inline, the HTTP fake keeps its local sink branches, and the scheduler reuses the existing support trait. No shared codec, sink writer service, or clock API will be introduced.
- The only removed public-looking method is `RedisConnection::setDatabase()`: it is an undocumented Hypervel-only bookkeeping hook, absent from Laravel, all contracts, and the generated facade, and is replaced by automatic tracking at the command execution boundary. No Laravel API is removed or narrowed.

## Finding disposition

| Area | Disposition |
|---|---|
| Watcher misses/deletions and subsequent watcher discoveries | Deferred in full to the existing watcher TODO batch |
| Redis serializer `GET`, `ZADD INCR`, `SET GET`, and false sentinels | Fix as one native-contract pass |
| Redis command-listener pool deadlock | Fix by event-scoped reuse of the owning wrapper |
| Redis selected-database bookkeeping | Fix at `RedisConnection::__call()`; remove proxy-only setter plumbing |
| Empty Boost package advertised as working tooling | Gate installation docs; leave product implementation in TODO |
| Secondary Swoole configuration | Explicitly set every secondary and check the real false sentinel |
| Unbounded dispatcher caches | Replace runtime-name caches with lazy preparation keyed only by finite registrations |
| Rate-limiter `WorkerArrayStore` retention | Intentional documented tests-only behavior; no change |
| Eloquent mutator caches with arbitrary attribute input | Incorrect application usage and no coherent narrow fix; no change |
| Cache array-store expiry | Prevent expired-value revival and reclaim abandoned expired worker-local values/locks with bounded work |
| Whole-second future deadlines | Round up future cache, queue, credential, and client deadlines; preserve immediate/past behavior and precise monotonic Worker time |
| Coroutine mutex channel retention | Reclaim quiescent channels after successful release without disturbing waiter handoff |
| Scheduler seconds labelled as milliseconds | Reuse `InteractsWithTime` |
| Database assertion JSON failures | Use the two deliberate tolerant-encoding flags and restore Laravel's Unicode option |
| Fake HTTP sink writes/rewinds | Complete partial writes and rewind only seekable sinks |
| Log stream writes | Complete supported caller-resource writes without replaying a written prefix |

## 1. Make transformed Redis contracts match phpredis

### Source changes

Edit `src/redis/src/RedisConnection.php`:

| Wrapper | Final contract | Required behavior |
|---|---|---|
| `callGet()` | `mixed` | Return every decoded phpredis value unchanged; normalize native `false` to `null` as today. |
| `callSet()` | `mixed` | Accept `mixed $expireResolution`; preserve legacy `EX`/`PX` reshaping and native option arrays/integers. Return ordinary booleans or the previous decoded value from `SET ... GET`. |
| `callZadd()` | `false\|float\|int` | Preserve float scores from `INCR` and native failures. |
| `callMget()` | `array\|false` | Keep the empty-key fast return. Guard a whole-call `false` before mapping element-level missing values to `null`. The whole-call case is primarily Redis Cluster/transport behavior. |
| `callHmget()` | `array\|false` | Guard `false` before `array_values()`. |
| `callZrangebyscore()` / `callZrevrangebyscore()` | `array\|false` | Pass through documented native failures. |
| `callZinterstore()` / `callZunionstore()` | `false\|int` | Pass through documented native failures. |

`prepareSet()` already distinguishes the Laravel five-argument string form from native options by checking whether the third argument is a string. Widen only the parameter that is genuinely polymorphic; retain the existing TTL and flag types. Add one concise sentence to `callSet()` explaining that `GET` returns the previous decoded value and `false` can mean that no previous value existed, not that the write failed.

The whole-result guards must precede collection operations:

```php
$values = $this->connection->mGet($keys);

if ($values === false) {
    return false;
}

return array_map(
    static fn (mixed $value): mixed => $value !== false ? $value : null,
    $values,
);
```

Do not cast native values to satisfy annotations. Atomic result normalization remains separate from MULTI/PIPELINE: queueing mode must still reshape arguments but return the native `Redis`/`RedisCluster` queue object until `exec()`. Redis Cluster supports MULTI queueing only; it does not support PIPELINE.

Correct the full class-level `@method` queue-object metadata on `RedisConnection`, then regenerate `src/support/src/Facades/Redis.php` and inspect/lint the result. The metadata tracks the pinned current phpredis source rather than only the installed 6.3.0 release, matching the existing tag block's inclusion of newer commands. Of 204 distinct shared tags that explicitly name `Redis`, 194 exist on the pinned Cluster surface and 184 already declare `RedisCluster`. Add that queue object to those methods plus six declarations proven from the C implementation: `brpoplpush`, `getdel`, `ping`, `bzpopmax`, `bzpopmin`, and `msetex`. The first five use `CLUSTER_PROCESS_CMD` / `CLUSTER_PROCESS_KW_CMD` or an equivalent explicit MULTI branch, and current-source `msetex` uses the same macro; all enqueue the response and return `getThis()`. Standalone arginfo already carries its fluent `Redis` return for the same methods.

Keep the deliberate exclusions precise:

- `auth`, `debug`, `failover`, `function`, `migrate`, `move`, `replicaof`, `select`, `sunsubscribe`, and `swapdb` have no pinned-source `RedisCluster` method;
- `exec` ends queueing and returns the executed result array rather than a queue object;
- `unsubscribe` and `punsubscribe` send directly through the active subscription slot and are not MULTI commands;
- Cluster `unwatch()` appends a boolean response in MULTI but falls through with a `null` method result, so its truthful shared type is `null|bool|Redis`, not a `RedisCluster` union.

Cluster `unwatch()` also pollutes the later `exec()` response with that appended boolean, and its failure macro appends `false` but continues iterating watched nodes. Do not add a Hypervel workaround. Record that native defect in `_tmp/phpredis/pr-ideas/redis-cluster-arginfo-and-unwatch-multi.md` together with the wrong declarations for `bzpopmax`, `bzpopmin`, and `msetex`, the imprecise `mixed` declarations for `brpoplpush`, `getdel`, and `ping`, and the four related wrong-class declarations corrected upstream after 6.3.0.

For the transformed methods in this audit, `get` and `set` remain `mixed`; `mget`, `hmget`, `zadd`, both score-range methods, and both store methods include both native queue objects. Type score-range bounds as `float|int|string`, matching accepted numeric and infinity bounds. Keep `false`, not the overly broad `bool` from the Cluster stub, in the reverse-score-range result: Redis has no successful boolean reply for that command. Remove the completed Redis result-metadata TODOs after the source and generated facade are correct.

Keep case-insensitive duplicate command tags identical. Both lowercase and camelCase score-range declarations must use the same widened bounds. Update the exact `KEYS` and `LINSERT` metadata assertions to include the Cluster queue object rather than weakening those guards.

Keep the transformed score-range wrapper parameters aligned with those declarations: `callZrangebyscore()` and `callZrevrangebyscore()` accept `float|int|string` bounds, not `mixed`. Mirror the existing descriptions from the lowercase transformed command declarations onto their camelCase duplicates so case aliases generate equally useful facade documentation.

There is no configured live Redis Cluster in the copied `.env`, so Cluster metadata verification rests on installed 6.3.0 reflection plus the newer pinned stub and C source. Standalone Redis integration still runs in the full suite.

### Tests

Extend `tests/Redis/RedisConnectionTest.php` with focused native-result doubles:

- `GET` returns an array, object, integer, and `null` normalization for native `false` without a `TypeError`.
- `SET` accepts a native options array atomically, returns decoded scalar/array/object previous values for `GET`, preserves `true` for ordinary writes, and preserves `false` for absent/conditional results.
- The same options are reshaped correctly while queued and the queued native object is returned unchanged.
- `ZADD ... INCR` returns a float; `ZADD` and every listed wrapper preserve native `false`.
- `MGET` still maps false elements to `null`, returns `[]` without a native call for empty keys, and preserves a whole-call `false`.
- `HMGET` applies `array_values()` only to arrays.

Extend `tests/Integration/Redis/RedisProxyIntegrationTest.php` where a configured Redis service is available:

- Strengthen the existing PHP serializer case to round-trip arrays, objects, and integers, not only a string.
- Exercise `SET` with `['GET']` and `['GET', 'EX' => ...]`, including a decoded prior array/integer and the no-prior-value `false` result.
- Exercise `ZADD INCR` and assert its float result after the write.
- Keep unit coverage authoritative for Cluster-only whole-call `MGET === false`; do not require a Cluster service solely for that sentinel.

The cache package remains unaffected because `StoreContext::withConnection()` defaults to `transform: false`; retain a focused assertion or source trace rather than changing cache code.

## 2. Make Redis event dispatch reentrant without adding ordinary-command overhead

### Ownership design

Edit `src/redis/src/RedisProxy.php`. The existing command flow must remain:

1. borrow or reuse a wrapper;
2. execute the command;
3. synchronously dispatch the matching command event if listeners exist;
4. permanently hand off successful stateful commands or release the wrapper;
5. preserve current exception precedence: listener failure, then command failure, then cleanup failure.

When an event listener exists and the command did not begin with a context connection, temporarily publish the exact owned `RedisConnection` under this proxy's normal context key for dispatch only:

```php
CoroutineContext::set($contextKey, $connection);

try {
    $dispatcher->dispatch($event);
} finally {
    CoroutineContext::forget($contextKey);
}
```

Put this in one private event-dispatch helper used for both `CommandExecuted` and `CommandFailed`. If the command already began with a context connection, dispatch directly and leave that context untouched. Call the helper only after `hasListeners()` succeeds, so ordinary commands with no listeners gain no context operations or helper work beyond their existing guard.

The temporary publication is not a deferred-release owner. After it is removed, the existing cleanup block remains the sole owner of release or permanent handoff. Successful outer `multi`, `pipeline`, `select`, and `watch` commands are therefore stored durably only after dispatch. If the failed wrapper was invalidated, a nested listener command calls `getConnection()` on that same wrapper and reconnects it before reuse; the outer cleanup still releases it exactly once.

Add one short internal WHY comment at the temporary boundary: synchronous listeners must reuse the leased wrapper to avoid a reentrant pool checkout/deadlock, and nested commands deliberately retain Laravel's event/reentrancy semantics. This records the failure-mode change: an unconditional listener for the same command will recurse rather than eventually fail at the pool wait timeout. Do not add a public listener warning or recursion guard; listener code that reacts to the same command must use its normal condition to avoid recursion.

### Tests

Add to `tests/Integration/Redis/RedisProxyIntegrationTest.php`:

- a named connection with `max_connections = 1` and a short test `wait_timeout`;
- a `CommandExecuted` listener filtered to the outer command/connection that performs a nested command on the same proxy;
- assertions that the nested command completes, sees the outer write, and the pool remains reusable. The old code must fail by attempting a second checkout.

Add focused ownership tests to `tests/Redis/RedisProxyTest.php`:

- ordinary non-stateful commands with no listeners never publish context;
- success and failure events see the exact wrapper in context and remove only a temporary publication afterward;
- pre-existing context is preserved;
- a nested command during a failure event can reconnect an invalidated wrapper, `markReconnected()` makes it valid again, and outer cleanup returns that wrapper to the pool rather than discarding it;
- listener exceptions still clean temporary context, still allow stateful outer-command handoff, and retain existing exception precedence;
- a listener during outer MULTI/PIPELINE queues commands on the caller's already-open native transaction, matching Laravel's single-connection behavior.

Do not add `RedisManager::listen()` documentation for the MULTI/PIPELINE case: this is the normal consequence of executing a nested command on the current connection, not an unsupported or broken mode.

## 3. Track selected databases at the connection boundary

### Source changes

In `RedisConnection::__call()`, record the selected database only when an atomic `SELECT` was actually applied. Atomic phpredis `select()` returns `true`; while MULTI/PIPELINE is queueing it returns the native `Redis` queue object, so the exact result check prevents an unexecuted or later-discarded selection from becoming reconnect state:

```php
if ($name === 'select' && $result === true && array_key_exists(0, $arguments)) {
    $this->database = (int) $arguments[0];
}
```

Keep this beside the existing WATCH/EXEC state tracking. It covers proxy calls, calls made through `withConnection()`, and calls made through a pinned proxy. Its sole purpose is reconnect safety when the current native client is no longer available to inspect; release-time cleanup does not depend on inferred wrapper state.

Normalize the standalone `database` value to `int` in `RedisConfig::connectionConfig()` after URL parsing and standalone defaults are applied. `ConfigurationUrlParser` correctly returns URL path components as strings for its general database contract, while Redis accepts only numeric database indexes. This owning connection-config boundary must convert both URL-derived values and Laravel-style string environment values once so construction, reconnect tracking, and strict release comparisons all receive the declared integer shape. Cluster configurations continue to omit `database`.

The supported MULTI/PIPELINE API deliberately returns or passes the raw phpredis object, so queued commands do not cross `RedisConnection::__call()`. Preserve that Laravel-compatible API and observe native state at the two lifecycle boundaries that need it:

- in `PhpRedisConnection::reconnect()`, before replacing an existing connected standalone `Redis` instance, read its `getDBNum()` into `$database`; this preserves the database the old native generation actually entered, including a raw queued `SELECT` that executed, while an aborted/discarded queue reports the unchanged database;
- in `RedisConnection::release()`, retain the existing queueing/WATCH detection, CRITICAL diagnostic, and discard branch. Immediately before database restoration, if a standalone `Redis` client is disconnected, mark the wrapper invalid without calling `getDBNum()` or `select()`; otherwise compare its connected `getDBNum()` with the configured database and restore only when they differ. Require that restoration to return exactly `true`. A refused or thrown restore marks the wrapper invalid and closes that native generation before the normal `finally` clears tracked database/WATCH state and returns the wrapper to the pool. Closing is required because an invalidated but still-connected client would otherwise have its rejected database replayed by reconnect's `getDBNum()` inheritance.

Resolve the reconnect target before constructing the replacement client:

```php
$database = $this->connection instanceof Redis && $this->connection->isConnected()
    ? $this->connection->getDBNum()
    : ($this->database ?? $this->config['database']);
```

Use that resolved value for the new client's `select()` without converting a raw queued intention into state. When the value is nonzero, require `select()` to return exactly `true`; otherwise throw a `ConnectionException` naming the database and connection before publishing the replacement client or marking the wrapper reconnected. The `isConnected()` guard is required: phpredis's `getDBNum()` goes through `redis_sock_get_connected()`, whose connection accessor can reopen a disconnected socket, so reconnect must not trigger an unnecessary first reconnection merely to inspect it. Keep the existing tracked atomic value available as the fallback for an explicitly closed/null or disconnected native client.

Persist the resolved database when publishing the replacement client. The tracked value must describe the live native generation so a later reconnect from a disconnected replacement does not fall back to the configured database and silently switch databases.

The release guard belongs after the queueing/WATCH branch. Native `getMode()` reads the stored `RedisSock` through `redis_sock_get_instance()` without opening a socket, so a disconnected wrapper that was abandoned in MULTI/PIPELINE or WATCH state must still take the existing logged discard path. Only `getDBNum()` reaches the reconnecting accessor and therefore needs the connectedness guard. On an otherwise atomic disconnected wrapper, returning the invalid wrapper object through the normal `finally` is intentional: the next checkout makes `getActiveConnection()` replace its native generation, and the cleared database fallback selects `config['database']`. A connected generation whose restore failed must be closed before that return so reconnect cannot read its stale database back. Do not reconnect during cleanup.

On the connected-client paths above, `getDBNum()` returns phpredis's local `redis_sock->dbNumber` (measured locally at approximately 0.022 microseconds per call) and sends no Redis command. phpredis nevertheless declares `getDBNum(): int` while its disconnected C branch executes `RETURN_FALSE`; the explicit connectedness guards make that false sentinel unreachable and avoid another narrow static-analysis workaround. The release read is therefore simpler and more correct than a flag spread across exposure/reconnect/close/release paths, while adding no work to command execution. The `instanceof Redis` guard directly excludes Cluster, whose `getDBNum()` returns `false`, and safely handles a wrapper whose native client was explicitly closed. Abandoned queues retain the existing safer behavior of discarding the entire native generation before this cleanup path.

The reconnect observation must happen before `PhpRedisConnection::reconnect()` chooses `$this->database ?? $this->config['database']` and replaces the old client. This covers native MULTI/PIPELINE chaining without speculative queue state. If the native client is already null, the last successfully applied atomic wrapper `SELECT` remains the fallback. `release()` then either restores the configured database or closes the refused native generation before returning its invalid wrapper to the pool. Do not add a database-dirty flag, `client()` special case, close-time synchronization, Cluster branch, or database-state lookup to ordinary command execution; release cleanup owns the connected client's local read.

Remove all proxy-only bookkeeping:

- delete the `select` branch that calls `$connection->setDatabase()` in `RedisProxy::__call()`;
- delete `RedisConnection::setDatabase()`;
- remove `setdatabase` from `RedisProxy::CONNECTION_BOUND_METHODS`;
- remove `setDatabase` from `Redis` facade-documenter exclusions;
- remove test mocks and direct setup calls that exist only for the setter.

Do not add a Cluster `select` branch. `RedisCluster` has no `select`; an unsuccessful/missing native call cannot reach post-success tracking. Do not wrap the raw client or add flag-driven conditional cleanup.

### Tests

Rewrite setter-based cases in `tests/Redis/RedisConnectionTest.php`, `RedisProxyTest.php`, `RedisProxyNonCoroutineTest.php`, `RedisPoolHeartbeatTest.php`, and `MultiExecTest.php` to establish state through a real successful `select` call.

Cover, with a one-slot pool or exact native-client doubles:

- URL-derived and explicit string standalone database indexes leave `RedisConfig::connectionConfig()` as integers, including database zero, before a pool or connection consumes them;

- ordinary proxy `select` remains pinned and release restores the configured database;
- `withConnection(fn (RedisConnection $connection) => $connection->select(...))` restores on release;
- `withPinnedConnection(fn () => $proxy->select(...))` restores on release;
- a queued wrapper `select` is not recorded as applied before `exec()`, and release restores from the actual post-transaction state;
- raw callback-form and chaining-form MULTI/PIPELINE `select` calls restore the actual post-`exec()` database before release, including an aborted `exec()` and `discard()`;
- reconnect after a raw MULTI/PIPELINE selection preserves the old native client's actual database, not a queued intention;
- after inheriting an old native client's actual database, a second reconnect from the now-disconnected replacement still selects that inherited database;
- reconnect does not call `getDBNum()` or reopen an old native client when `isConnected()` is false, and instead uses the last applied atomic selection/configured fallback;
- reconnect aborts before publishing a new client when its nonzero database selection returns `false`, while database zero performs no native `SELECT`;
- release preserves queueing/WATCH detection, then marks an otherwise atomic disconnected standalone client invalid without `getDBNum()` or an implicit socket reopen; the subsequent borrower reconnects directly to the configured database rather than inheriting the previous phpredis `dbNumber`;
- a restore that returns `false` or throws marks the wrapper invalid, closes that connected native generation, logs the existing CRITICAL diagnostic, and lets the next checkout reconnect directly to the configured database without reading the failed generation's `getDBNum()`;
- a disconnected wrapper left in MULTI/PIPELINE or WATCH state still emits the existing CRITICAL diagnostic and is discarded instead of being requeued as an invalid wrapper;
- invalidating/reconnecting after selection reconnects to the selected database, and later release restores the configured database;
- failed `select === false` and queue-object `select` results do not change tracked state;
- selected state remains coroutine-local through each wrapper's ownership, as the existing integration isolation test requires.

## 4. Configure every secondary Swoole port explicitly

### Source changes

Edit the secondary branch in `src/server/src/Server.php`:

```php
$settings = array_replace($config->getSettings(), $server->getSettings());

if ($slaveServer->set($settings) === false) {
    throw new ServerException("Failed to configure server [{$name}].");
}
```

Call `Port::set()` even when the secondary has no local settings. Swoole stores the settings applied through the primary `Server::set()` and otherwise copies that first port's complete settings into untouched secondary ports during startup. Explicitly applying `global + this secondary local` prevents first-listener protocol/TLS options from leaking while still delivering global port-level settings such as `document_root`, HTTP/2, compression, and socket buffer options. `array_replace()` preserves the existing local-over-global precedence.

The installed IDE helper and Swoole stub declare `Port::set(): void`, but the 6.2.2 C implementation has `RETURN_FALSE` branches and falls through with `null` on success. Keep the exact runtime comparison and its short WHY note local to this call; PHPStan accepts the runtime check without an ignore, wrapper, or global configuration change.

Keep the primary `Server::set()` path unchanged. Swoole itself calls primary `Port::set()` without observing its result and returns `true`; applying settings twice or reimplementing SSL validation locally would be a fragile workaround. The upstream handoff owns that remaining native inconsistency.

### Tests

Extend `tests/Server/ServerTest.php` for the mocked configuration cases, and put the native contract regression in a separate `tests/Server/ServerNativeTest.php` whose `protected bool $runTestsInCoroutine = false` keeps real Swoole server construction outside `RunTestsInCoroutine` without changing the existing suite's execution mode:

- a mocked two-port configuration proves the main server receives `global + main local`, and the secondary always receives exactly `global + secondary local`;
- explicitly assert that a main-only setting does not appear on the secondary, a secondary override wins, and a secondary with no local settings still receives global settings;
- skip the native test when `SWOOLE_SSL` is undefined; otherwise construct a real Swoole 6.2.2 HTTP primary bound to `127.0.0.1:0` and a TCP+SSL secondary also bound to `127.0.0.1:0`, so parallel workers never share a fixed port;
- set malformed `ssl_sni_certs` on the SSL secondary whose hostname value is not an array, contain the expected warning locally, and assert `ServerException("Failed to configure server [name].")` occurs before that secondary's callbacks, `ServerManager` publication, `beforeStart`, and `BeforeServerStart` event;
- do not mock `Port::set()` returning `false`: the generated `void` signature makes that double misleading and cannot validate the runtime mismatch.

Run the native test method under PHPUnit's `RunInSeparateProcess` attribute, without `PreserveGlobalState`. A real `Swoole\Server` owns process-global native lifecycle state that must not enter a reusable PHPUnit worker. The supported 6.2.2 floor makes the failure concrete: discarding a never-started server leaves `SwooleG.server` set, so the next Swoole timer takes the server path and later coroutine timeouts never fire. Our merged Swoole PR #6136 (`637d6a884589`) fixes that released-version destructor defect upstream, but no release contains it yet and process isolation remains the correct boundary for this native test. Keep the real assertion and never start the server; the child process owns both ephemeral listeners and all native teardown.

This released-version defect has no supported production continuation path. `ServerFactory::configure()` propagates `ServerException`, and source has no catch that would discard the failed native server and continue boot into timer use. Do not add Hypervel cleanup or a version skip: cleanup is not exposed by Swoole, and skipping would remove the real `Port::set() === false` coverage on every currently supported release.

## 5. Prepare event handlers by finite registration key

### Source changes

Edit `src/events/src/Dispatcher.php`:

- keep the raw `$listeners`, `$wildcards`, `$observers`, and `$observerWildcards` registries as the finite boot-time source of truth;
- remove `$wildcardsCache`, `$listenersCache`, `$hasListenersCache`, `$observerWildcardsCache`, and `$observersCache` plus their full-map invalidation and selective-sweep code;
- add four lazily prepared maps keyed only by keys already present in the corresponding raw registry: exact listeners, wildcard listeners, exact observers, and wildcard observers;
- never insert an empty bucket for an unregistered exact runtime name;
- invalidate only the prepared bucket whose raw registration changed, and remove the corresponding prepared bucket in `forget()` while retaining the existing `interfaceListeners` cleanup;
- keep `getRawListeners()` returning the unchanged raw exact-listener registry. Do not add `getRawObservers()`.

Lazy preparation preserves the first-resolution timing of public, overridable `makeListener()` and `createClassListener()` and protected, overridable `makeObserver()`. Eager preparation inside `listen()` or `observe()` would move those extension points to registration time. The framework preparation methods only allocate closures: container resolution, queue decisions, and after-commit decisions remain inside the returned closure and run for each invocation. Preparation must remain non-yielding so the check-and-store is atomic under cooperative scheduling. A subclass override that yields can produce different prepared closure instances for two concurrent first resolutions without changing event behavior; closure identity is best-effort for such overrides, so do not add synchronization machinery.

`getListeners($eventName)` must assemble handlers in the existing order:

1. the prepared exact bucket when `$eventName` is a registered key;
2. each matching registered wildcard bucket in registration order;
3. each registered interface bucket implemented by a class event.

For the boolean interface guard, scan the finite registered interface keys with `is_a($eventName, $interface, true)` and stop at the first match. For resolved listeners, retain `class_implements($eventName)` order and select registered interface buckets from that result. This asymmetry is deliberate: the guard needs only a boolean and avoids allocating the event's full interface map, while resolution preserves Laravel's observable direct/inherited interface order. A guarded dispatch still builds the full interface map once during listener resolution; the change removes only the duplicate build and introduces no memoized runtime names.

`getObservers()` similarly assembles exact then matching wildcard buckets. Prepared closures are shared: two runtime names matching the same wildcard receive the same closure objects, and repeated `getListeners()` calls remain identical under PHP array `===`. The assembled result is returned without being stored under the runtime name.

`hasListeners()` must evaluate the existing exact, targeted-wildcard, and interface predicate directly. Observers remain excluded, including catch-all `listen('*', ...)` registrations routed through the observer pipeline. With no wildcard or interface registrations, framework guards perform only direct/empty checks. Registered wildcard matching measured approximately 0.36 microseconds for one pattern, 0.73 microseconds for two, and 2.9 microseconds for eight unmatched patterns on the reference runtime. If profiling ever finds a wildcard-heavy bottleneck, optimize the finite registered patterns; never reintroduce runtime-name memoization.

Interface resolution intentionally uses autoloading `class_exists($eventName)` when at least one interface listener is registered. Unlike Laravel's `class_exists(..., false)`, this preserves Hypervel's tested support for string-dispatched unloaded class events; the `interfaceListeners !== []` gate avoids autoloading when the feature is unused. A class-shaped nonexistent runtime string can therefore enter Composer's own worker-lifetime `ClassLoader::$missingClasses`, as it already does today. Do not restore Laravel's non-autoloading call. The invariant here is precise: dispatched runtime names cannot grow Dispatcher state.

### Tests

Replace the existing reflection assertions for the removed listener and `hasListeners` caches while retaining their behavioral coverage:

- repeated calls return the same prepared closure objects without relying on a runtime-name result cache;
- adding and forgetting exact, wildcard, interface, and observer registrations immediately changes resolution correctly;
- exact → wildcard → interface listener order and exact → wildcard observer order remain explicit invariants;
- multiple directly implemented interface listeners retain the class's `implements`-clause order even when registered in reverse order;
- `hasListeners()` preserves the exact/wildcard/interface truth table while observers remain invisible;
- raw listeners remain raw and keep their existing array shape;
- callable, class-string, queued, after-commit, subscriber, catch-all observer, halt, propagation-stop, and listener/observer failure behavior remains covered.

Use a test subclass that counts preparation calls to prove each registered bucket is prepared once. Assert directly that two distinct runtime names matching one wildcard receive the same closure instance.

Add one structural no-growth regression. Register and warm exact, wildcard, interface, exact-observer, and wildcard-observer buckets, then snapshot recursive entry counts for every non-static array property on the Dispatcher. Query and dispatch many distinct matching, nonmatching, dotted, and class-shaped nonexistent names and assert the snapshot is unchanged. The class-shaped cases exercise the intentional autoload path; Composer's external missing-class map is not Dispatcher state.

Search all source and tests after implementation to prove the five removed cache names have no remaining references.

## 6. Reclaim expired array-cache state with bounded mutation work

### Source changes

Keep the two array-cache lifetimes distinct:

- request-local `ArrayStore` remains coroutine-context backed and needs no background or rotating reclamation;
- worker-local `WorkerArrayStore` continues to persist live and forever values, tags, counters, serialization state, and cache locks across requests handled by one worker;
- worker-array locks coordinate only that worker. They are not distributed locks and must not be used for cross-worker or cross-server uniqueness.

Edit `src/cache/src/AbstractArrayStore.php`:

- factor the existing value expiry/decoding branch into one protected helper used by `get()` and the existing-key `increment()` branch, accepting an already-observed timestamp so a mutation does not construct the clock twice;
- keep cache misses from acquiring a timestamp and preserve null-value behavior;
- add a protected no-op `reclaimExpiredRecords()` seam. `put()`, the existing-key `increment()` branch, and a successful `touch()` call it once for the record they write. `forever()` and `decrement()` retain their current public delegation through `put()` and `increment()`; `RetrievesMultipleKeys::putMany()` retains its per-key public `put()` calls, so subclass overrides, serialization, and the “attempt every key” contract remain intact;
- make `touch()` evaluate the raw item's expiry before calling `calculateExpiration()` or extending it. An expired item is forgotten and returns `false`, matching Laravel's ordering and the existing `get()` contract. Never infer the current time by subtracting `$seconds` from `calculateExpiration()`: that protected Laravel extension point may apply jitter, alignment, or a TTL clamp;
- implement `getLockRecord()` once over a protected abstract `getLockRecords()` seam, mirroring the existing cache-item storage boundary. Both concrete stores expose their raw lock maps through that seam. The shared exact read deletes an expired physical record and returns `null` while avoiding a clock read for missing and permanent records;
- add named `isCacheItemExpired()` and `isLockRecordExpired()` predicates. The cache predicate documents that callers must exclude the `0.0` permanent sentinel. The lock predicate accepts exact `CarbonImmutable` values for both arguments and records that inclusive `<=` is the deliberate expiry boundary. Exact reads and worker maintenance guard permanent null expiries before calling it, avoiding clock work and preserving permanent locks;
- keep `putCacheItem()` as the raw storage primitive with no hidden maintenance and leave `all()`'s existing raw-store behavior unchanged.

Run the maintenance seam after successful existing-key `increment()` and `touch()` operations even though those operations do not grow the map. A workload that only increments counters or refreshes TTLs must still advance reclamation of unrelated expired records.

The worker override in `src/cache/src/WorkerArrayStore.php` must:

- return before acquiring a timestamp when both `$storage` and `$locks` are empty;
- otherwise inspect at most eight entries from each nonempty map per record write. Each reclamation pass takes at most one fakeable clock observation and reuses a real caller-supplied value timestamp when possible; this is separate from any expiration or read timestamps the public operation already took. When locks exist, one exact Carbon observation owns all lock comparisons and supplies the value timestamp only when the caller supplied none;
- rotate deterministically with `key()`, `next()`, and `reset()`. Maintenance is the only code that positions the maps' PHP internal pointers, but deletion elsewhere may advance one. At the top of every individual scan-step iteration, normalize `key($map) === null` with `reset($map)` before selecting an entry; do not perform this normalization only once before the loop. Advance the pointer before deleting the selected key. This makes arbitrary deletion and append-after-end safe and wraps within the same maintenance pass instead of following an appended tail forever;
- unset only values whose nonzero `expiresAt` has passed and locks whose non-null `expiresAt` has passed;
- perform the same bounded maintenance from `putLockRecord()`, covering both `ArrayLock::acquire()` and `ArrayLock::refresh()`;
- keep the full maintenance section non-yielding. `increment()` remains one cooperative-worker-atomic read/modify/write section.

Each write can introduce at most one record to either map while inspecting eight existing records in both populated maps, so traversal outpaces continuous growth without a store-size-dependent pause. Reference-runtime simulation over 200,000 one-record writes converged near the live TTL working set (budget eight: TTL 1,000, steady 1,004 / peak 1,142; TTL 5,000, steady 5,127 / peak 5,714). Permanent entries correctly grow when applications continually call `forever()`; they are live application-owned state and are removed only explicitly or by flush.

Reject periodic whole-map cleanup based on measured non-yielding stalls for a half-expired PHP map: approximately 1.1 ms at 10,000 entries, 22–35 ms at 100,000, and 217–254 ms at 1,000,000. The eight-entry pointer work measured below one microsecond per populated map and remains independent of existing cardinality. Work for `putMany()` is proportional only to the records the caller explicitly writes. A `forever()` write acquires a clock once existing state needs maintenance; the empty-map guard preserves today's cold-store cost.

In `src/cache/src/ArrayStore.php`, retain the existing coroutine-backed `getLockRecords()` implementation. Add the matching raw-map implementation to `src/cache/src/WorkerArrayStore.php`; neither concrete store duplicates exact-read expiry logic. This preserves every `ArrayLock` result: `acquire()` already treats expiry as available, while owner lookup, `refresh()`, and remaining-lifetime inspection already treat expiry as absent.

Update `src/docs/cache.md` without exposing implementation details:

- state that worker-array cache values and locks are visible only inside one worker;
- explain that subsequent mutations reclaim expired untouched records, while forever values and permanent locks remain until explicitly removed or the worker exits;
- do not advertise worker-array locks for distributed uniqueness, scheduling, or cross-process handoff.

### Tests

Extend `tests/Cache/CacheArrayStoreTest.php` and `tests/Cache/CacheWorkerArrayStoreTest.php`:

- direct `touch()` cannot revive an expired value;
- exact expired lock reads remove the physical record and retain the existing acquire/owner/refresh/lifetime results;
- later unrelated writes reclaim abandoned expired worker values and locks while preserving live, forever, and permanent-lock records;
- one write into large expired maps removes no more than the fixed eight entries per map, proving no whole-map pass;
- seed a map larger than one maintenance budget, then perform enough one-key writes for a correct per-step cursor to cycle through it while an incorrect tail-following cursor would retain the old rows; assert that resident cardinality stays below a bound derived from the live TTL working set, rather than merely checking that one chosen key disappeared;
- arbitrary exact deletion, an expired lock read deleting the current pointer, append-after-end, cache/lock flushes, and copy-on-write through `all()` do not break cursor rotation;
- existing coroutine sharing, tags, counters, serialization, lock restore/refresh, and separate cache/lock flush behavior remain covered.

Use test-only subclasses or reflection for physical-state and pointer assertions; add no production introspection or timing-based tests.

## 7. Preserve requested lifetimes at whole-second boundaries

### Shared time conversion

The full-suite file-funnel regression exposed a shared rounding defect: `InteractsWithTime::availableAt()` floors future instants to an integer timestamp. A one-second TTL or delay created at `1000.999999` can therefore expire or become runnable at `1001`, almost immediately. This is unsafe for locks and queue visibility and also shortens cache, credential, and client lifetimes promised by every other caller.

Edit `src/support/src/InteractsWithTime.php` so a whole-second deadline never falls before the instant the caller requested:

```php
$delay = $this->parseDateInterval($delay);

$now = Date::now();

$target = $delay instanceof DateTimeInterface
    ? Date::instance($delay)
    : $now->addSeconds($delay);

return $target > $now
    ? $target->ceilSecond()->getTimestamp()
    : $target->getTimestamp();
```

Keep `parseDateInterval()` authoritative. It preserves interval microseconds and remains shared with `secondsUntil()`. Capturing `$now` after parsing makes a zero interval equal to or older than `$now`, so it stays immediate. The same comparison also keeps zero/negative integers and past absolute times floored, while future integers, intervals, and fractional `DateTimeInterface` values round upward by less than one second. `DatabaseQueue::pushToDatabase()` calls `availableAt()` with no argument for immediately available batch rows; this is the load-bearing reason not to ceiling every call unconditionally.

Keep `secondsUntil()` unchanged. In particular, do not combine a ceiled duration with a ceiled storage deadline. `Worker::currentTime()` also remains unchanged: coroutine job timeouts already use the same precise monotonic float for registration and expiry checks.

This shared correction intentionally reaches all existing `availableAt()` consumers, including File/Storage cache entries and file locks, Redis and database queue delays, Redis reserved-job visibility, signed URLs, cookies/sessions, request-forgery cookies, rate-limit reset headers, Slack timestamps, and Inertia once-prop expiry. It preserves every signature and Laravel-shaped API; no porting-guide note is needed because callers receive the lifetime their existing call already expresses.

### Preserve absolute expiry during file and storage increments

`FileStore::increment()` and `StorageStore::increment()` currently reconstruct the original expiry by subtracting one whole-second clock observation in `getPayload()` and adding the remaining duration to a later observation in `put()`. This already extends the item by one second when the observations cross a whole-second boundary; rounding future deadlines upward merely makes the lossy round trip extend fractional-clock increments consistently. The existing tests that require an increment to retain the exact stored header are correct and must remain unchanged.

Edit `src/cache/src/FileStore.php` and `src/cache/src/StorageStore.php`:

- preserve Laravel's protected `time` payload member and add the original absolute `expiresAt` alongside it. Both `getPayload()` and `emptyPayload()` must document `array{data: mixed, time: ?int, expiresAt: ?int}`. Use one `currentTime()` observation for expiry validation and the remaining `time`, improving on Laravel's two observations without adding read-path work;
- add a short WHY comment where both representations are returned: `time` remains for Laravel-shaped subclasses, while `expiresAt` is authoritative for exact internal rewrites;
- make `emptyPayload()` return all three keys with null metadata;
- add a protected `putWithExpiresAt(string $key, mixed $value, int $expiresAt): bool` as the internal absolute-expiry write boundary. Public `put()` converts its duration once with `expiration($seconds)` and delegates; `increment()` writes the payload's original expiry directly, with `PERMANENT_TIMESTAMP` for the existing missing-key behavior;
- have `increment()` branch on `$raw['expiresAt'] ?? null`, not key presence. A non-null absolute expiry uses `putWithExpiresAt()`; a Laravel-shaped override or cache miss falls back to `put($key, $value, $raw['time'] ?? 0)`. This preserves Laravel subclasses that return only `data + time`, keeps cache misses permanent, and preserves exact base-store deadlines;
- replace the Hypervel-only protected `expirationHeader()` helper with `expiresAtHeader()`, which formats an already-computed absolute timestamp. Every duration caller must first call `expiration($seconds)`, keeping the fixed-width representation in one method without mixing duration and timestamp units. The new name makes the unit change explicit and causes old Hypervel subclass calls that still pass durations to fail loudly instead of silently writing invalid headers;
- keep `add()`, `touch()`, file-lock acquire/refresh, and every operation that deliberately starts a new TTL on the duration path.

`FileStore::add()` already writes without calling public `put()`, so `put()` is not a supported single write choke point; the new protected absolute writer gives both stores a clear internal boundary without changing the Laravel cache API or its protected payload extension point.

### Integer storage boundaries outside `availableAt()`

Edit `src/cache/src/DatabaseStore.php`:

- compute `putMany()` and `add()` expiry through `$this->availableAt($seconds)`;
- in `touch()`, keep the existing `$now = $this->getTime()` observation for the live-row predicate, then compute the new expiry separately through `$this->availableAt($seconds)`;
- do not add a protected expiry helper or change `getTime()`.

Edit `src/cache/src/DatabaseLock.php` so `expiresAt()` keeps the existing positive/default-timeout selection and returns `$this->availableAt($lockTimeout)`. A database lock created during a fractional second then remains held for `[N, N + 1)` seconds rather than `(N - 1, N]`.

Edit `src/queue/src/Jobs/DatabaseJobRecord.php` so `touch()` stores `CarbonImmutable::now()->ceilSecond()->getTimestamp()`. Keep `DatabaseQueue::isReservedButExpired()` unchanged. The integer `reserved_at` marker may be less than one second later than the actual reservation, but the reclaim comparison can no longer dispatch the job before `retryAfter`; repository inspection only tests marker nullness and does not expose it as the displayed job time.

### Redis all-tag expiry metadata

Add `StoreContext::expirationScore(int $seconds): int`, returning `now()->addSeconds($seconds)->ceilSecond()->getTimestamp()`. `StoreContext` already owns the all-tag expiry constants and is injected into every affected operation, so this one named accessor prevents nine copies of a subtle rule without adding a new class or service.

Use it for both standalone and Cluster branches in:

- `AllTag/Add.php`;
- `AllTag/Put.php`;
- `AllTag/PutMany.php`;
- `AllTag/Touch.php`;
- `AllTag/AddEntry.php` for positive TTLs, retaining `-1` for forever entries.

Keep both `FlushStale` current-time cutoffs floored. Ceiling the tag score reduces its former early-removal window from almost one second to at most the PHP-to-Redis command gap: the native `SETEX`/`EXPIRE` countdown begins after PHP computes the score. Do not add a fixed margin, extra command/round trip, fractional score, Lua clock, or other machinery for that residual window. Tag metadata lasting briefly after the value expires is safe; disappearing while the value is still live is the direction to avoid.

### Tests

Extend `tests/Foundation/FoundationInteractsWithTimeTest.php` with a clock frozen at fractional seconds and cover:

- future positive integers round up, while zero and negative integers stay immediate/past;
- positive intervals round up, while zero and inverted intervals do not;
- future fractional absolute dates round up, while past fractional dates do not;
- whole-second future targets remain unchanged.

Add deterministic fractional-clock regressions to `CacheFileStoreTest`, `CacheStorageStoreTest`, `CacheDatabaseStoreTest`, and `CacheDatabaseLockTest`. Prove a one-second item/lock created at `1000.900000` receives integer expiry `1002`, remains valid at `1001`, and expires at `1002` where the driver's public surface supports direct reads. For both file-backed stores, retain the existing exact-header increment assertions, add a finite-TTL fractional-clock increment case, and prove incrementing a forever value at a fractional instant preserves `PERMANENT_TIMESTAMP`.

For both file-backed stores, add a test subclass whose `getPayload()` returns Laravel's original `data + time` shape and prove `increment()` uses that finite duration rather than making the item permanent.

Keep general file/database lock tests that assert nominal durations on a whole-second frozen clock. They must assert simple exact deadlines rather than copying the production ceiling expression or expecting the conservative fractional extension. Dedicated fractional tests alone own that storage-boundary behavior.

Update the affected AllTag operation tests and add focused `Touch` coverage if no current file owns it. Pin fractional clocks to fixed instants and assert the resulting integer scores directly rather than recomputing them with the production formula. Assert every standalone and Cluster score uses `StoreContext::expirationScore()`, positive `AddEntry` TTLs ceiling correctly, forever entries remain `-1`, and stale pruning at the preceding whole second cannot remove the ceiled membership.

Add queue regressions in `QueueRedisQueueTest`, `QueueDatabaseQueueUnitTest`, and the relevant integration suites. Cover integer, interval, and absolute delayed jobs; Redis reservation scores; ceiled database `reserved_at`; and reclaim only at or after the requested visibility duration. Retain the existing precise `QueueWorkerTest` assertions unchanged.

Correct `CacheFunnelTestCase::testLeakedFunnelLeaseIsReclaimedAfterReleaseAfter()`:

- keep the abandoned and immediate competing leases at `releaseAfter(1)`;
- replace the in-flight expected-exception/finally shape with an explicit narrow `LimiterTimeoutException` catch;
- wait 2.2 seconds before reacquisition because a ceiled one-second expiry lives for `[1, 2)` seconds;
- use `releaseAfter(60)` for the cleanup lease so its asserted release cannot race its own short TTL;
- add one concise comment explaining why the wait exceeds the nominal TTL.

Run each changed test file immediately. Then run the complete `tests/Cache`, `tests/Cache/Redis`, and `tests/Queue` unit groups plus the affected cache/queue integration groups. Inspect every exact timestamp assertion that moves under a fractional fake clock; update it only when the new value represents the corrected never-early contract. The final `composer fix` remains authoritative because `availableAt()` also reaches auth, cookie, foundation, inertia, notifications, routing, session, and support callers.

## 8. Reclaim released coroutine mutex channels

### Source changes

Edit `src/coroutine/src/Mutex.php`. This package is Hyperf-ported, but Hyperf is only a historical reference under `docs/ai/porting-hyperf.md`; the current Hypervel API and Swoole behavior define the contract. The static channel map is worker-lifetime state keyed by the public arbitrary mutex key. Normal `lock()` / `finally { unlock(); }` use currently leaves one native channel per distinct key forever, while the documented `clear()` call appears optional.

- return the boolean result of `Channel::push(1, $timeout)` from `lock()` so every native failure is represented truthfully;
- make `unlock()` return `false` immediately when the key is absent or the published channel has length zero. The absent-key case is a deliberate correction from `true` to `false` because no mutex was released; an existing empty channel already eventually returns `false`, but this avoids waiting up to the default five seconds for a token that no holder owns;
- return `false` when `pop($timeout)` fails, including timeout or channel closure;
- after a successful `pop($timeout)`, first verify that the map still contains the exact same channel. Only then, when its length is zero, unset the map entry and close it. Unset before close;
- retain the channel when a producer was waiting. Native Swoole 6.2.2 hands the freed slot to the blocked producer before the owner's `pop()` returns, leaving length one; an uncontended release leaves length zero;
- check map identity before the post-pop length call so a fast waiter that releases the old channel and a later caller that publishes a replacement cannot have that replacement deleted by the earlier unlock;
- do not call `Channel::hasProducers()` or `hasConsumers()`; Hypervel's single Swoole `Channel` implementation throws for those methods (and for the other two inspection methods), as documented in `src/docs/coroutines.md`;
- mark `push()`, `pop()`, and `close()` as `@phpstan-impure` on both `ChannelInterface` and `Channel`: each returns a value while mutating native channel state. The concrete `pop()` tag is load-bearing for the pre-pop/post-pop length checks because the inherited Swoole declaration prevents PHPStan from inheriting the interface tag; `getLength()` remains a pure observation of current state;
- rewrite the `lock()` and `unlock()` return docblocks to describe their actual operation contracts. `lock()` returns whether the channel accepted the acquisition token. `unlock()` returns `true` only when it released a held mutex token and `false` when the key is absent, no token is held, or the pop fails; do not describe every `false` result as a timeout.

The API does not track ownership. Document that a successful acquisition must be released exactly once by its holder; an invalid concurrent/double unlock already destroys mutual exclusion today and will not gain owner-tracking machinery. Update `src/docs/coroutines.md` to describe `clear()` as explicit cancellation/reset rather than normal release hygiene.

### Tests

Extend `tests/Coroutine/MutexTest.php`:

- many unique uncontended lock/unlock pairs leave the static channel map empty;
- absent, empty, and sequential double unlocks return `false` immediately;
- contended acquisition remains mutually exclusive and hands the existing channel to one waiter at a time without publishing a replacement;
- a timed-out waiter leaves the owner's channel intact, while the final uncontended release removes it;
- a fast waiter may release the old channel and a later caller may publish a replacement without an older unlock touching or deleting that replacement;
- `clear()` and `flushState()` retain their cancellation/reset behavior and blocked callers fail safely.

Run `tests/Database/Eloquent/ModelBootTest.php` with the focused suite because model boot uses class-name-keyed `Mutex` acquisition and release.

## 9. Render scheduler durations with the existing time formatter

Edit `src/console/src/Commands/ScheduleRunCommand.php`:

- import and use `Hypervel\Support\InteractsWithTime`;
- change the finish format placeholder from `%sms` to `%s`;
- pass `$this->runTimeForHumans($start)` instead of rounded seconds.

`runTimeForHumans()` multiplies seconds by 1,000, renders sub-second work in milliseconds, and cascades longer durations to concise human units. It is already the framework convention in console `Task` and queue `WorkCommand`. Keep `ScheduledTaskFinished::$runtime` unchanged in seconds.

In `tests/Console/Scheduling/ScheduleRunCommandTest.php`, use a test subclass that overrides the protected formatter with a fixed value such as `1.50s`, then run a real successful event through `runEvent()`. Assert the final line contains the value exactly once and never appends a second `ms`. This proves the command delegates and removes its old literal suffix.

Also add deterministic shared-formatter coverage in `tests/Foundation/FoundationInteractsWithTimeTest.php`. Expose `Hypervel\Support\InteractsWithTime::runTimeForHumans()` through a tiny test fixture and pass explicit start/end values; cover a sub-second interval as milliseconds and representative values above one second through the cascading branch. These assertions exercise the trait's `* 1000` conversion directly, which the command-delegation double otherwise bypasses and which console `Task` and queue `WorkCommand` also rely on. Do not add a production clock seam, sleep, `hrtime()` refactor, or duplicate the formatter in the command.

## 10. Make database assertion diagnostics encoding-safe

Edit these Laravel-ported constraints:

- `src/testing/src/Constraints/HasInDatabase.php`
- `src/testing/src/Constraints/SoftDeletedInDatabase.php`
- `src/testing/src/Constraints/NotSoftDeletedInDatabase.php`

Match current Laravel's `exists()` query in both soft-delete `matches()` methods instead of counting every matching row; the boolean contract is unchanged and the database can stop at the first match.

At all seven `json_encode()` sites, include:

```php
JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
```

For `HasInDatabase::toString($options)`, OR these flags into the caller's integer options so `JSON_PRETTY_PRINT` and other formatting survive. Restore Laravel parity in `HasInDatabase::failureDescription()` by passing `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`; the safety flags are added by `toString()`. Keep `JSON_UNESCAPED_UNICODE` on both existing `HasInDatabase` additional-info branches while adding the safety flags. The two soft-delete constraints retain their upstream formatting choices plus the safety flags.

These two tolerant flags deliberately return a string for malformed UTF-8, binary bytes, non-finite floats, recursion, and depth overflow. Do not add `JSON_THROW_ON_ERROR`, a fallback based on `json_last_error_msg()`, or a shared encoder: assertion diagnostics should remain best-effort and preserve the three upstream class structures.

Add focused coverage in `tests/Foundation/FoundationInteractsWithDatabaseTest.php` (or a small `tests/Testing/Constraints` suite if it materially simplifies direct constraint coverage):

- all three `toString()` methods return strings containing replacement output for invalid UTF-8/binary values rather than throwing `TypeError`;
- `HasInDatabase` keeps readable unescaped Unicode in the pretty failure description;
- both `HasInDatabase` additional-info branches and both soft-delete additional-info paths remain strings when query results contain malformed bytes;
- one representative recursive/non-finite/depth case proves partial-output behavior without asserting unstable JSON error prose;
- the surrounding assertion still fails as a PHPUnit expectation with useful table/attribute diagnostics, not an encoding exception.

## 11. Make fake HTTP sinks match real transport completion and seekability

Edit only `PendingRequest::sinkStubHandler()` in `src/http/src/Client/PendingRequest.php`.

Keep the string-path branch's exact byte-count check. For resource and PSR-7 sinks, calculate body length once and repeatedly write the unwritten suffix until complete. Treat `false` or zero progress before completion as failure; an empty body is already complete. On the common full-write path each branch still performs one write.

For resources:

- retain warning suppression around `fwrite()` and normalize failure to the current runtime exception;
- inspect `stream_get_meta_data($sink)['seekable']` and call `rewind()` only when true;
- if an attempted rewind returns `false`, throw a clear runtime exception;
- never close the caller's resource.

For `StreamInterface`:

- use each returned byte count to advance through the suffix;
- throw on zero progress; allow native stream exceptions to propagate;
- call `rewind()` only when `isSeekable()` is true, matching Guzzle `CurlFactory::finish()`;
- let a seekable stream's rewind exception propagate.

Do not extract a writer class or combine PHP resources and PSR streams behind a new abstraction. The two native interfaces have different failure and seekability APIs, and the code is local to the fake handler.

Extend `tests/Http/HttpClientTest.php`:

- preserve existing complete path/resource/PSR cases;
- a partial PSR stream receives the exact remaining suffix until the complete body is present;
- a zero-progress PSR stream fails deterministically;
- a nonblocking resource that accepts a positive prefix and then cannot progress throws instead of silently truncating;
- nonseekable resource and PSR sinks receive the full body and are not rewound;
- seekable sinks still end at offset zero, and an actual rewind failure is propagated;
- failed fake requests remain recorded exactly as current tests require.

## 12. Complete log records without duplicate replay

Edit `src/log/src/Handlers/Concerns/PerformsSafeStreamOperations.php`, shared by Hypervel's `StreamHandler` and `RotatingFileHandler`.

Format the record once, acquire the optional lock once for the logical attempt, and loop over unwritten suffixes until the full record is written. Release the lock in `finally`. `false` and zero progress are terminal for that attempt.

Preserve the one URL reopen retry with a stricter safety condition:

- retry only when zero bytes of the record were written, this is the first attempt, and the URL is neither null nor `php://memory`;
- after a positive prefix, never replay from byte zero because that would duplicate log content; throw instead;
- caller-supplied resources are never closed or retried through a URL;
- URL-backed blocking file/stdout streams retain their one-write normal path;
- no readiness polling or coroutine scheduling is introduced.

Keep two short WHY comments in the loop: inode refresh cannot repeat because closing clears the saved inode before reopening establishes a new baseline, and a positive prefix cannot be retried from byte zero because that would duplicate log content. When a positive prefix is followed by no progress, retain the shared failure-message structure and append the written and expected byte counts; leave the zero-progress message unchanged.

Simplify inode rotation handling so closing a changed inode and opening the current URL does not consume the one write-failure retry. Close the stale stream and continue in the same first attempt instead of recursively marking the refreshed stream as already retrying. This is safe without a second inode-refresh guard because `closeStreamSafely()` clears `safeInodeUrl`, while `hasStreamInodeChanged()` can enter only when that property is non-null; opening the replacement stream establishes one new baseline rather than re-entering refresh. Preserve that invariant explicitly when restructuring the loop. This restores Monolog's intended distinction between inode refresh and write retry while reducing recursion.

Representative loop shape:

```php
$contents = (string) $record->formatted;
$length = strlen($contents);
$offset = 0;

while ($offset < $length) {
    $written = fwrite($stream, $offset === 0 ? $contents : substr($contents, $offset));

    if ($written === false || $written === 0) {
        break;
    }

    $offset += $written;
}
```

Extend `tests/Log/StreamHandlerTest.php` and its local stream wrapper:

- a wrapper that accepts small prefixes eventually records the complete formatted line exactly once;
- false/zero before any bytes causes one URL reopen and one retry, preserving the existing test;
- positive-prefix then false/zero throws, opens only once, and the stored prefix is not duplicated;
- one logical attempt with locking has one acquire/unlock pair even across multiple partial writes;
- two consecutive external inode replacements are each detected on their next write and cause exactly one reopen; a zero-progress write after the second refresh still has the independent one-time write-failure reopen available;
- a caller-owned nonblocking resource reproduces the former positive-short-write truncation and now fails on no progress;
- caller resources remain open after handler close/failure;
- `RotatingFileHandler` still writes through the shared boundary and rotates normally.

Retain the current normalized exception context and safe open/directory behavior.

## 13. Gate Boost documentation until Boost exists

`src/boost` has only package metadata, a README, license, and a dependency on `hypervel/docs`; it has no autoload surface, provider, command, installer, or tools. Do not build that product in this audit PR.

Edit `src/docs/installation.md` to remove:

- the `Hypervel and AI` / installer table-of-contents entries;
- the entire `Hypervel and AI`, `Installing Hypervel Boost`, and custom-guidelines section;
- every `composer require hypervel/boost` and `boost:install` instruction.

Edit `src/boost/README.md` to remove the now-invalid installation documentation link/anchor, leaving its minimal title and badge. Make `src/boost/composer.json`'s description truthful about the reserved/future package instead of claiming it already provides tools and guidelines. Update the Boost item in `docs/todo.md` to future tense: implement the installer/tools first, then add and verify the installation section. Remove its stale statement that current installation docs describe the intended workflow.

Do not add a placeholder command, service provider, fake package test, or partial MCP/tool roster. After deletion, search all tracked Markdown outside historical plans/TODO handoffs and assert no shipped documentation mentions `boost:install` or claims the tooling exists.

## Implementation order and verification

Follow tests-first development within each slice and edit one file at a time as required by `AGENTS.md`:

1. Redis result contracts and generated facade.
2. Redis event ownership and selected-database tracking.
3. Swoole secondary settings.
4. Dispatcher finite-key preparation and runtime-name state removal.
5. Array-cache expiry correctness and bounded worker-local reclamation.
6. Whole-second future-deadline rounding across support, cache, queue, and Redis tag metadata.
7. Coroutine Mutex channel reclamation.
8. Scheduler timing.
9. Testing constraint JSON diagnostics.
10. HTTP fake sink completion/seekability.
11. Log stream completion/retry behavior.
12. Boost documentation and TODO cleanup.

For each slice, add/adjust the focused test first, observe the intended failure where practical, implement, and rerun that file. Use at least these focused commands after the slice is complete:

```shell
composer test -- tests/Redis/PackageMetadataTest.php tests/Redis/RedisConnectionTest.php tests/Redis/RedisProxyTest.php tests/Redis/RedisProxyNonCoroutineTest.php tests/Redis/MultiExecTest.php tests/Redis/RedisPoolHeartbeatTest.php
composer test -- tests/Integration/Redis/RedisProxyIntegrationTest.php
composer facade "Hypervel\\Support\\Facades\\Redis"
composer facade -- --lint "Hypervel\\Support\\Facades\\Redis"
composer test -- tests/Server/ServerTest.php tests/Server/ServerNativeTest.php
composer test -- tests/Events/EventsDispatcherTest.php tests/Events/CoroutineEventsTest.php
composer test -- tests/Cache/CacheArrayStoreTest.php tests/Cache/CacheWorkerArrayStoreTest.php
composer test -- tests/Foundation/FoundationInteractsWithTimeTest.php tests/Cache tests/Queue
composer test -- tests/Coroutine/MutexTest.php tests/Database/Eloquent/ModelBootTest.php
composer test -- tests/Console/Scheduling/ScheduleRunCommandTest.php tests/Foundation/FoundationInteractsWithTimeTest.php
composer test -- tests/Foundation/FoundationInteractsWithDatabaseTest.php
composer test -- tests/Http/HttpClientTest.php
composer test -- tests/Log/StreamHandlerTest.php
composer --working-dir=src/boost validate --strict
```

Redis integration tests are opt-in through the copied `.env`; if the configured service is unavailable, retain deterministic unit coverage and report the skipped environmental verification rather than weakening assertions. The real Swoole test requires the repository floor, 6.2.2.

After all focused suites pass:

- run `grep` for removed Dispatcher cache symbols, `setDatabase`, and false Boost instructions;
- inspect every generated facade line and every TODO edit for stale claims;
- inspect the complete diff for accidental watcher changes, public API narrowing, broad ignores, new configuration, polling, recursion guards, store-size-dependent cleanup, or dead comments;
- run `composer fix` once at the final checkpoint. This owns formatting, both PHPStan configurations, the parallel suite, Testbench package tests, and dogfood tests;
- inspect `git status --short` to ensure Composer/vendor artifacts and temporary probes are not included.

## Completion criteria

- Serializer-backed Redis values and `SET ... GET` cross the facade without post-mutation `TypeError`; documented false/float results remain observable.
- A Redis command event can make a nested same-connection command with a one-slot pool, with exact context cleanup and no no-listener hot-path cost.
- Every standalone Redis database index is normalized to `int` before connection construction; every applied atomic wrapper-level `SELECT` is tracked by the owning connection, reconnect observes the old standalone client's actual selected database, refused selections abort before publication, and every standalone release either restores the configured database or closes the refused native generation without external setter or dirty-state machinery.
- Every secondary Swoole port receives only global plus its own settings, and a recoverable native false aborts configuration before publication.
- Dynamic event names cannot grow Dispatcher state; each finite registration bucket is prepared once with no dead invalidation code.
- Array-cache `touch()` never revives expired data; exact expired lock reads are physically removed; abandoned expired worker-array values and locks are reclaimed with fixed work per requested write and no operation scans existing state by cardinality.
- Future whole-second deadlines never precede the requested instant across cache TTLs, locks, queue delays/visibility, credentials, and client metadata; immediate/past values retain their current behavior, Redis tag tracking cannot disappear almost a second early, and precise Worker timeouts remain unchanged.
- Normal Mutex release removes quiescent channels, preserves contended handoff and replacement identity, and invalid unlocks fail immediately without unsupported producer introspection.
- Scheduler output uses truthful human-readable units while event runtime remains seconds.
- All database constraint diagnostics return useful strings for malformed data and preserve Laravel's Unicode formatting.
- Fake HTTP sinks and log handlers either write every byte exactly once or fail deterministically; neither spins nor closes caller resources.
- Shipped docs contain no Boost installation command until the package implements it, while TODOs accurately describe remaining future work.
- No watcher implementation/config/docs/tests change in this branch, no Laravel API is broken, and the full repository quality gate passes.
