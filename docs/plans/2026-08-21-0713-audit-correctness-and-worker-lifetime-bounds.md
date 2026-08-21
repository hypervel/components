# Audit Correctness and Worker-Lifetime Bounds

## Objective

Correct the confirmed non-watcher findings from the 0.4 audit without changing Laravel-compatible APIs, adding material hot-path machinery, or documenting behavior the repository does not ship. The finished code should have truthful Redis result contracts, reentrant Redis command events, connection-owned database tracking, correct secondary Swoole settings, bounded event lookup caches, accurate scheduler timing, robust diagnostics and stream writes, and no premature Boost installation instructions.

This plan targets branch `fix/audit-correctness-follow-up` from 0.4 commit `88190e640498`. Research references are the local Laravel checkout at `a659f095965b`, phpredis at `777f7377674a`, Swoole at `8e8c49915ca5`, and the installed PHP 8.4.23 / phpredis 6.3.0 / Swoole 6.2.2 runtime.

## Scope decisions and invariants

- All `FindDriver` / `FindNewerDriver` work is excluded. The complete watcher batch is already recorded under `docs/todo.md` and belongs in its own coherent PR.
- The audit's `reply_literal` explanation is false for phpredis 6.3.0. `SET ... GET` and serializer decoding are the real reasons the transformed `SET` result must be `mixed`; no `reply_literal` test or special case will be added.
- The Swoole audit is narrower than written. Most malformed settings fatal, but malformed `ssl_sni_certs` on an SSL port warns and returns `false`. Separately, untouched secondary ports inherit the first port's merged settings from Swoole, so Hypervel must explicitly configure every secondary with global plus that secondary's local settings.
- `Swoole\Server::set()` masks the primary port's recoverable `false` result by returning `true`. Hypervel will not duplicate Swoole validation or apply primary settings twice. The upstream handoff is `_tmp/swoole/pr-ideas/server-port-set-return-contract.md`.
- No Redis `EXISTS` probe will be added. With a serializer, a stored `false` and a missing key are deliberately indistinguishable through phpredis `GET`; a second command would also be racy.
- Redis listener reentrancy will reuse the connection that owns the event. It will not release the wrapper before dispatch, add a recursion guard, reserve a second pool slot, or alter event payloads. As in Laravel, nested commands emit their own events; an unconditional same-command listener therefore recurses instead of timing out on an accidental second pool checkout.
- Event cache bounds are private implementation constants. There will be no configuration, timer, request cleanup, LRU, FIFO queue, or hit-time mutation.
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
| Unbounded dispatcher caches | Remove redundant caches and cap the three useful caches |
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

Do not cast native values to satisfy annotations. Atomic result normalization remains separate from MULTI/PIPELINE: queueing mode must still reshape arguments but return the native `Redis`/`RedisCluster` queue object until `exec()`.

Update the corresponding class-level `@method` annotations on `RedisConnection` (`set`, `mget`, `zinterstore`, and `zunionstore`; the other affected annotations are already broad enough), then regenerate `src/support/src/Facades/Redis.php` with the repository facade tool after source signatures settle. Inspect the generated diff so `get`, `set`, `mget`, `hmget`, `zadd`, both score-range methods, and both store methods advertise the corrected unions. Remove the completed transformed-return-type audit item from `docs/todo.md`.

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

The supported MULTI/PIPELINE API deliberately returns or passes the raw phpredis object, so queued commands do not cross `RedisConnection::__call()`. Preserve that Laravel-compatible API and observe native state at the two lifecycle boundaries that need it:

- in `PhpRedisConnection::reconnect()`, before replacing an existing connected standalone `Redis` instance, read its `getDBNum()` into `$database`; this preserves the database the old native generation actually entered, including a raw queued `SELECT` that executed, while an aborted/discarded queue reports the unchanged database;
- in `RedisConnection::release()`, retain the existing queueing/WATCH detection, CRITICAL diagnostic, and discard branch. Immediately before database restoration, if a standalone `Redis` client is disconnected, mark the wrapper invalid without calling `getDBNum()` or `select()`; otherwise compare its connected `getDBNum()` with the configured database and restore only when they differ. The normal `finally` clears tracked database/WATCH state and returns the invalid wrapper object to the pool, where the next borrower makes `check()` reject and reconnect its native generation directly to the configured database.

Resolve the reconnect target before constructing the replacement client:

```php
$database = $this->connection instanceof Redis && $this->connection->isConnected()
    ? $this->connection->getDBNum()
    : ($this->database ?? $this->config['database']);
```

Use that resolved value for the new client's `select()` without converting a raw queued intention into state. The `isConnected()` guard is required: phpredis's `getDBNum()` goes through `redis_sock_get_connected()`, whose connection accessor can reopen a disconnected socket, so reconnect must not trigger an unnecessary first reconnection merely to inspect it. Keep the existing tracked atomic value available as the fallback for an explicitly closed/null or disconnected native client.

The release guard belongs after the queueing/WATCH branch. Native `getMode()` reads the stored `RedisSock` through `redis_sock_get_instance()` without opening a socket, so a disconnected wrapper that was abandoned in MULTI/PIPELINE or WATCH state must still take the existing logged discard path. Only `getDBNum()` reaches the reconnecting accessor and therefore needs the connectedness guard. On an otherwise atomic disconnected wrapper, returning the invalid wrapper object through the normal `finally` is intentional: the next checkout makes `getActiveConnection()` replace its native generation, and the cleared database fallback selects `config['database']`. Do not reconnect during cleanup or return a valid-looking client whose phpredis `dbNumber` can be replayed for the next borrower.

On the connected-client paths above, `getDBNum()` returns phpredis's local `redis_sock->dbNumber` (measured locally at approximately 0.022 microseconds per call) and sends no Redis command. phpredis nevertheless declares `getDBNum(): int` while its disconnected C branch executes `RETURN_FALSE`; the explicit connectedness guards make that false sentinel unreachable and avoid another narrow static-analysis workaround. The release read is therefore simpler and more correct than a flag spread across exposure/reconnect/close/release paths, while adding no work to command execution. The `instanceof Redis` guard directly excludes Cluster, whose `getDBNum()` returns `false`, and safely handles a wrapper whose native client was explicitly closed. Abandoned queues retain the existing safer behavior of discarding the entire native generation before this cleanup path.

The reconnect observation must happen before `PhpRedisConnection::reconnect()` chooses `$this->database ?? $this->config['database']` and replaces the old client. This covers native MULTI/PIPELINE chaining without speculative queue state. If the native client is already null, the last successfully applied atomic wrapper `SELECT` remains the fallback. `release()` then restores the configured database before returning a healthy wrapper to the pool. Do not add a database-dirty flag, `client()` special case, close-time synchronization, Cluster branch, or database-state lookup to ordinary command execution; release cleanup owns the connected client's local read.

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

- ordinary proxy `select` remains pinned and release restores the configured database;
- `withConnection(fn (RedisConnection $connection) => $connection->select(...))` restores on release;
- `withPinnedConnection(fn () => $proxy->select(...))` restores on release;
- a queued wrapper `select` is not recorded as applied before `exec()`, and release restores from the actual post-transaction state;
- raw callback-form and chaining-form MULTI/PIPELINE `select` calls restore the actual post-`exec()` database before release, including an aborted `exec()` and `discard()`;
- reconnect after a raw MULTI/PIPELINE selection preserves the old native client's actual database, not a queued intention;
- reconnect does not call `getDBNum()` or reopen an old native client when `isConnected()` is false, and instead uses the last applied atomic selection/configured fallback;
- release preserves queueing/WATCH detection, then marks an otherwise atomic disconnected standalone client invalid without `getDBNum()` or an implicit socket reopen; the subsequent borrower reconnects directly to the configured database rather than inheriting the previous phpredis `dbNumber`;
- a disconnected wrapper left in MULTI/PIPELINE or WATCH state still emits the existing CRITICAL diagnostic and is discarded instead of being requeued as an invalid wrapper;
- invalidating/reconnecting after selection reconnects to the selected database, and later release restores the configured database;
- failed `select === false` and queue-object `select` results do not change tracked state;
- selected state remains coroutine-local through each wrapper's ownership, as the existing integration isolation test requires.

## 4. Configure every secondary Swoole port explicitly

### Source changes

Edit the secondary branch in `src/server/src/Server.php`:

```php
$settings = array_replace($config->getSettings(), $server->getSettings());

if ($slaveServer->set($settings) === false) { // narrow PHPStan ignore: Swoole's void arginfo is wrong
    throw new ServerException("Failed to configure server [{$name}].");
}
```

Call `Port::set()` even when the secondary has no local settings. Swoole stores the settings applied through the primary `Server::set()` and otherwise copies that first port's complete settings into untouched secondary ports during startup. Explicitly applying `global + this secondary local` prevents first-listener protocol/TLS options from leaking while still delivering global port-level settings such as `document_root`, HTTP/2, compression, and socket buffer options. `array_replace()` preserves the existing local-over-global precedence.

The installed IDE helper and Swoole stub declare `Port::set(): void`, but the 6.2.2 C implementation has `RETURN_FALSE` branches and falls through with `null` on success. Use only the exact PHPStan identifier reported for this comparison, with a WHY note naming the upstream contract defect. Do not widen global static-analysis configuration.

Keep the primary `Server::set()` path unchanged. Swoole itself calls primary `Port::set()` without observing its result and returns `true`; applying settings twice or reimplementing SSL validation locally would be a fragile workaround. The upstream handoff owns that remaining native inconsistency.

### Tests

Extend `tests/Server/ServerTest.php` for the mocked configuration cases, and put the native contract regression in a separate `tests/Server/ServerNativeTest.php` whose `protected bool $runTestsInCoroutine = false` keeps real Swoole server construction outside `RunTestsInCoroutine` without changing the existing suite's execution mode:

- a mocked two-port configuration proves the main server receives `global + main local`, and the secondary always receives exactly `global + secondary local`;
- explicitly assert that a main-only setting does not appear on the secondary, a secondary override wins, and a secondary with no local settings still receives global settings;
- skip the native test when `SWOOLE_SSL` is undefined; otherwise construct a real Swoole 6.2.2 HTTP primary bound to `127.0.0.1:0` and a TCP+SSL secondary also bound to `127.0.0.1:0`, so parallel workers never share a fixed port;
- set malformed `ssl_sni_certs` on the SSL secondary whose hostname value is not an array, contain the expected warning locally, and assert `ServerException("Failed to configure server [name].")` occurs before that secondary's callbacks, `ServerManager` publication, `beforeStart`, and `BeforeServerStart` event;
- do not mock `Port::set()` returning `false`: the generated `void` signature makes that double misleading and cannot validate the runtime mismatch.

Keep the real test process-local and never start the server; object teardown releases both ephemeral listeners. Do not move it into an isolated subprocess unless direct non-coroutine construction proves nondeterministic under the repository's ParaTest runner.

## 5. Bound event lookup caches and remove redundant wildcard caches

### Source changes

Edit `src/events/src/Dispatcher.php`:

- remove `wildcardsCache` and `observerWildcardsCache`, including their declarations, registration invalidations, assignments, reads, and selective loops in `forget()`;
- have `getWildcardListeners()` and `getWildcardObservers()` return their computed arrays directly;
- retain `listenersCache`, `observersCache`, and `hasListenersCache`, because those avoid repeated listener construction/interface resolution/wildcard scans;
- add one private `10_000` entry limit shared by all three caches and one private insertion helper.

The wildcard-only caches are write-only in steady state: `getListeners()`/`getObservers()` write the final cache on the same miss, and every later call returns that final value before consulting the wildcard cache. Removing them reduces memory and invalidation code without changing behavior.

The insertion helper should flush only the individual cache receiving a new miss:

```php
private function cacheEventLookup(array &$cache, string $eventName, mixed $value): mixed
{
    if (count($cache) >= self::EVENT_CACHE_LIMIT) {
        $cache = [];
    }

    return $cache[$eventName] = $value;
}
```

Bind the helper's value type explicitly so PHPStan preserves the concrete return at every call site, especially the `bool` required by `hasListeners()`:

```php
/**
 * @template TValue
 * @param array<string, TValue> $cache
 * @param TValue $value
 * @return TValue
 */
```

The native signature still takes `array &$cache` so the flushed/replaced map is returned to the caller by reference. Call the helper only after the existing `isset` hit checks and after computing a miss. The hot hit remains a single `isset`; a miss adds one `count`/comparison. Full per-cache flush is intentional: it is bounded, allocation-free eviction metadata, and lets a changed working set recache. A 10,000-entry bound avoids thrashing ordinary Eloquent model-event namespaces (roughly fifteen names per model) while bounding high-cardinality external names; a local empty-result probe placed all three maps at roughly 2 MiB per worker at the limit.

`getListeners()` and `getObservers()` are public, so eviction is observable to callers that enumerate more than 10,000 distinct event names in one worker: an evicted lookup is recomputed and freshly prepared closures may have new object identities. Listener resolution and dispatch results remain unchanged. This is the deliberate bounded-memory contract; do not imply that the cap is invisible or preserve closure identity with a second unbounded structure.

Listener and observer registries remain unbounded because they contain intentional boot-time registrations, not request-derived lookup names. Existing `listen()`, `observe()`, and `forget()` full invalidations remain authoritative.

### Tests

Add cache-boundary cases to `tests/Events/EventsDispatcherTest.php` or `CoroutineEventsTest.php` using reflection/test subclasses to seed each protected cache to the production constant. Do not dispatch 10,000 events merely to reach the boundary.

For each retained cache:

- a hit at the limit remains cached and does not flush;
- the next distinct miss flushes that cache and inserts the new result;
- the other two caches are untouched;
- a previously evicted event recomputes correctly;
- false `hasListeners` entries remain cache hits;
- exact, wildcard, interface, and observer resolution still return the same callbacks after eviction;
- listener/observer registration and `forget()` still invalidate all affected final caches.

Search the repository after implementation to prove both removed wildcard cache names have no references.

## 6. Render scheduler durations with the existing time formatter

Edit `src/console/src/Commands/ScheduleRunCommand.php`:

- import and use `Hypervel\Support\InteractsWithTime`;
- change the finish format placeholder from `%sms` to `%s`;
- pass `$this->runTimeForHumans($start)` instead of rounded seconds.

`runTimeForHumans()` multiplies seconds by 1,000, renders sub-second work in milliseconds, and cascades longer durations to concise human units. It is already the framework convention in console `Task` and queue `WorkCommand`. Keep `ScheduledTaskFinished::$runtime` unchanged in seconds.

In `tests/Console/Scheduling/ScheduleRunCommandTest.php`, use a test subclass that overrides the protected formatter with a fixed value such as `1.50s`, then run a real successful event through `runEvent()`. Assert the final line contains the value exactly once and never appends a second `ms`. This proves the command delegates and removes its old literal suffix.

Also add deterministic shared-formatter coverage in `tests/Foundation/FoundationInteractsWithTimeTest.php`. Expose `Hypervel\Support\InteractsWithTime::runTimeForHumans()` through a tiny test fixture and pass explicit start/end values; cover a sub-second interval as milliseconds and representative values above one second through the cascading branch. These assertions exercise the trait's `* 1000` conversion directly, which the command-delegation double otherwise bypasses and which console `Task` and queue `WorkCommand` also rely on. Do not add a production clock seam, sleep, `hrtime()` refactor, or duplicate the formatter in the command.

## 7. Make database assertion diagnostics encoding-safe

Edit these Laravel-ported constraints:

- `src/testing/src/Constraints/HasInDatabase.php`
- `src/testing/src/Constraints/SoftDeletedInDatabase.php`
- `src/testing/src/Constraints/NotSoftDeletedInDatabase.php`

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

## 8. Make fake HTTP sinks match real transport completion and seekability

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

## 9. Complete log records without duplicate replay

Edit `src/log/src/Handlers/Concerns/PerformsSafeStreamOperations.php`, shared by Hypervel's `StreamHandler` and `RotatingFileHandler`.

Format the record once, acquire the optional lock once for the logical attempt, and loop over unwritten suffixes until the full record is written. Release the lock in `finally`. `false` and zero progress are terminal for that attempt.

Preserve the one URL reopen retry with a stricter safety condition:

- retry only when zero bytes of the record were written, this is the first attempt, and the URL is neither null nor `php://memory`;
- after a positive prefix, never replay from byte zero because that would duplicate log content; throw instead;
- caller-supplied resources are never closed or retried through a URL;
- URL-backed blocking file/stdout streams retain their one-write normal path;
- no readiness polling or coroutine scheduling is introduced.

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

## 10. Gate Boost documentation until Boost exists

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
4. Dispatcher cache simplification/bounds.
5. Scheduler timing.
6. Testing constraint JSON diagnostics.
7. HTTP fake sink completion/seekability.
8. Log stream completion/retry behavior.
9. Boost documentation and TODO cleanup.

For each slice, add/adjust the focused test first, observe the intended failure where practical, implement, and rerun that file. Use at least these focused commands after the slice is complete:

```shell
composer test -- tests/Redis/RedisConnectionTest.php tests/Redis/RedisProxyTest.php tests/Redis/RedisProxyNonCoroutineTest.php tests/Redis/MultiExecTest.php tests/Redis/RedisPoolHeartbeatTest.php
composer test -- tests/Integration/Redis/RedisProxyIntegrationTest.php
composer facade "Hypervel\\Support\\Facades\\Redis"
composer facade -- --lint "Hypervel\\Support\\Facades\\Redis"
composer test -- tests/Server/ServerTest.php tests/Server/ServerNativeTest.php
composer test -- tests/Events/EventsDispatcherTest.php tests/Events/CoroutineEventsTest.php
composer test -- tests/Console/Scheduling/ScheduleRunCommandTest.php tests/Foundation/FoundationInteractsWithTimeTest.php
composer test -- tests/Foundation/FoundationInteractsWithDatabaseTest.php
composer test -- tests/Http/HttpClientTest.php
composer test -- tests/Log/StreamHandlerTest.php
composer --working-dir=src/boost validate --strict
```

Redis integration tests are opt-in through the copied `.env`; if the configured service is unavailable, retain deterministic unit coverage and report the skipped environmental verification rather than weakening assertions. The real Swoole test requires the repository floor, 6.2.2.

After all focused suites pass:

- run `rg` for removed symbols (`wildcardsCache`, `observerWildcardsCache`, `setDatabase`) and false Boost instructions;
- inspect every generated facade line and every TODO edit for stale claims;
- inspect the complete diff for accidental watcher changes, public API narrowing, broad ignores, new configuration, polling, recursion guards, or dead comments;
- run `composer fix` once at the final checkpoint. This owns formatting, both PHPStan configurations, the parallel suite, Testbench package tests, and dogfood tests;
- inspect `git status --short` to ensure Composer/vendor artifacts and temporary probes are not included.

## Completion criteria

- Serializer-backed Redis values and `SET ... GET` cross the facade without post-mutation `TypeError`; documented false/float results remain observable.
- A Redis command event can make a nested same-connection command with a one-slot pool, with exact context cleanup and no no-listener hot-path cost.
- Every applied atomic wrapper-level `SELECT` is tracked by the owning connection, reconnect observes the old standalone client's actual selected database, and every standalone release restores the configured database without an external setter or dirty-state machinery.
- Every secondary Swoole port receives only global plus its own settings, and a recoverable native false aborts configuration before publication.
- Dynamic event names cannot grow any lookup cache beyond 10,000 entries; removed wildcard caches leave no dead invalidation code.
- Scheduler output uses truthful human-readable units while event runtime remains seconds.
- All database constraint diagnostics return useful strings for malformed data and preserve Laravel's Unicode formatting.
- Fake HTTP sinks and log handlers either write every byte exactly once or fail deterministically; neither spins nor closes caller resources.
- Shipped docs contain no Boost installation command until the package implements it, while TODOs accurately describe remaining future work.
- No watcher implementation/config/docs/tests change in this branch, no Laravel API is broken, and the full repository quality gate passes.
