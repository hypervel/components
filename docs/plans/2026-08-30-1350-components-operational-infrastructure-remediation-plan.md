# Operational Infrastructure Remediation

## Status

Implementation, verification, and review for audit findings 112, 124–126, 154, and 159 are complete against the current `0.4` branch, including the follow-up cache cleanup, prune correctness, test assertion, and timing corrections.

## Outcome

This slice will:

- provide an explicit recovery command for stale Redis-backed Reverb shared state after an abnormal shutdown;
- make bounded Redis pattern deletion fail visibly instead of reporting a false zero count;
- document the Redis and Valkey server versions Hypervel supports;
- apply PhpRedis's numeric packing exception consistently to standalone and Cluster connections;
- preserve underlying Sentinel and Cluster connection failures;
- align cache increment/decrement implementations with the existing `Store` contract;
- reject invalid cache tag modes at configuration boundaries;
- make tagged-cache pruning remove orphaned metadata without racing concurrent writers;
- publish all-mode cache values before their tag memberships and omit memberships for failed Cluster batch writes;
- make the queue worker tolerate a cache counter failure without treating booleans as counts;
- document the Redis queue migration batch argument;
- start Horizon through an array-form process command that uses executable paths, the application working directory, and useful failure diagnostics; and
- make recurring-timer continuation and stopping tests wait for the behavior they assert instead of relying on fixed scheduling delays.

The implementation must preserve Laravel's public contracts. It must not add automatic Reverb recovery, distributed liveness machinery, runtime version probes, blocking bulk deletion fallbacks, or steady-state Redis work.

## Scope and decisions

### 1. Reverb crash recovery (finding 112)

Redis-backed `RedisSharedState` stores global connection, subscription, presence, webhook-lock, and smoothing state under keys shaped as `reverb:{hash}:...`. Normal worker shutdown drains this state, but a hard crash cannot run that cleanup. Stale counters can therefore survive indefinitely and affect connection limits and channel state.

Add `reverb:clear-state` as a manual recovery command:

```php
#[AsCommand(name: 'reverb:clear-state')]
class ClearStateCommand extends Command
{
    protected ?string $signature = 'reverb:clear-state
        {--dry-run : Count matching Reverb shared-state keys without deleting them}
        {--force : Skip the confirmation prompt}';

    protected string $description = 'Clear Redis-backed Reverb shared state';
}
```

Match the neighbouring `InstallCommand`: import Symfony's `AsCommand` attribute and declare the attribute, signature, and description together so the command participates in the lazy command map without instantiation.

The command has these invariants:

- It selects `reverb.servers.reverb.scaling.connection`; it does not resolve `SharedState`, whose implementation depends on live server topology.
- `RedisSharedState` owns one public logical scan pattern, `reverb:{*}:*`, so the recovery boundary cannot drift from its key format.
- The pattern excludes webhook buffer keys shaped as `reverb:webhook:{tag}:...`, `reverb:message:*` keys, and unrelated Redis data without additional filtering machinery.
- A dry run counts matching keys and does not ask for confirmation.
- A destructive run always asks for confirmation unless `--force` is supplied, regardless of the application environment. Cancellation returns failure; a completed clear returns success.
- The warning requires every Reverb node sharing the selected Redis endpoint, logical database, and prefix to be stopped before deletion. The documented runbook is stop all nodes, dry-run or clear, then start all nodes. Explain that the clear removes short-lived webhook throttle and deduplication locks stored through `RedisSharedState`, which regenerate, but never buffered webhook payloads.
- The command is registered for console use before `reverb.enabled` can short-circuit `ReverbServiceProvider::boot()`. It is never scheduled or invoked during boot, worker startup, or ordinary shutdown.
- Reverb scaling continues to reject Redis Cluster. Do not add Reverb-specific Cluster behavior or tests.

Dry-run scanning must consume the generator while its pooled connection is still checked out:

```php
$count = $redis->withConnection(
    fn (RedisConnection $connection): int => iterator_count(
        $connection->safeScan(RedisSharedState::KEY_PATTERN),
    ),
    transform: false,
);
```

Do not expose `safeScan()` on `RedisProxy`: returning that generator would release the connection before iteration. Destructive deletion uses `$redis->flushByPattern(...)`, whose proxy method already owns the connection for the complete scan/delete operation.

### 2. Bounded Redis pattern deletion

`FlushByPattern` already scans without `KEYS`, strips `OPT_PREFIX`, buffers at most 1,000 logical keys, and deletes each batch with `UNLINK`. Preserve that design.

The existing docblock promises a `DEL` fallback that does not exist. Delete that false sentence; do not add the fallback. Hypervel will support Redis 6.2+ and Valkey 7.2+, both of which provide `UNLINK`; falling back to synchronous `DEL` could block Redis's main thread while arbitrary values are freed. Remove the inline Redis-4 availability comment as well: once the documented floor is Redis 6.2, it no longer explains a supported-runtime decision.

Before each `UNLINK` batch, clear the native PhpRedis last-error slot. If `UNLINK` returns an integer, add it to the deleted count. If it returns `false`, read the last error exactly once and throw `RedisException` with that message, or a stable `UNLINK` failure message when PhpRedis provides none:

```php
$this->connection->clearLastError();
$result = $this->connection->unlink(...$keys);

if (is_int($result)) {
    return $result;
}

throw new RedisException(
    $this->connection->getLastError() ?? 'Redis UNLINK failed while deleting keys by pattern.',
);
```

This adds no network request to successful deletion. `clearLastError()` and `getLastError()` access extension-local error state; the latter is used only after failure.

Remove `deleteKeys()`'s empty-array guard. Both callers already prove that the batch is non-empty, so retaining it would preserve dead control flow in a method being rewritten. Document beside `flushByPattern()` that a failed batch deletion raises `RedisException` rather than returning an under-count.

`FakeRedisClient` does not run the native `Redis` constructor. Give it a local nullable last-error value plus correctly typed `clearLastError()`, `getLastError()`, and a test setter so error behavior can be exercised without touching uninitialized extension state.

The cache benchmark's emergency cleanup output must not recommend the blocking `KEYS | DEL` pattern. Replace it with this cursor-based, non-blocking, bounded-batch command and update the existing output assertion:

```text
redis-cli --scan --pattern '<cachePrefix><KEY_PREFIX>*' | xargs -r -n 1000 redis-cli UNLINK
```

`BenchmarkContext::cleanup()` must remove benchmark-owned keys and tag storage from both modes regardless of the store's mode when cleanup begins. It must also sweep benchmark tag names from the explicit any-mode registry derived with `TagKeyBuilder(TagMode::Any, $context->prefix())`. A comparison interrupted after switching modes can otherwise leave registry members that no physical-key pattern matches. Preserve unrelated registry members.

### 3. Redis and Valkey support policy

Add a compact supported-server list to the Redis guide's Introduction, before the PhpRedis extension paragraph:

```markdown
<div class="content-list" markdown="1">

- Redis server 6.2+ ([Version Policy](https://redis.io/docs/latest/operate/oss_and_stack/install/version-mgmt/))
- Valkey 7.2+ ([Version Policy](https://valkey.io/topics/releases/))

</div>
```

The word `server` distinguishes the Redis release from the separately documented PhpRedis extension. Redis 6.2 is the compatibility floor; currently maintained Redis or Valkey releases remain preferable. Optional features retain their stricter Redis 8 / Valkey 9 requirements. Do not duplicate this list in the root README, add runtime probes, or add minimum-version CI in this slice.

### 4. PhpRedis numeric packing and Redis-backed counters

With a PhpRedis serializer enabled, numeric values may be stored as serialized bytes and rejected by Redis atomic increment/decrement commands. PhpRedis 6.2 added `OPT_PACK_IGNORE_NUMBERS` so numeric values remain raw while other values retain configured serialization.

`RedisConnection::setOptions()` currently discards named `pack_ignore_numbers` configuration on `RedisCluster`. That is incorrect: PhpRedis 6.2+ handles this option in the shared `Redis`/`RedisCluster` option path, and Cluster packing uses the same client flags.

Apply the named option to both standalone and Cluster clients:

```php
if ($name === 'pack_ignore_numbers'
    && ! defined(Redis::class . '::OPT_PACK_IGNORE_NUMBERS')) {
    throw new InvalidRedisOptionException(
        'The redis option `pack_ignore_numbers` requires PhpRedis 6.2 or later.',
    );
}
```

Then resolve and call `setOption()` normally. Update the PHPStan suppression to explain that the guard runs before constant resolution. Do not add a numeric-option guard: a numeric key cannot identify the missing named constant. Do not throw for every `setOption() === false`; shared option records may legitimately include the Cluster-only `failover` option for a standalone connection.

Document one solution in each owning place without repeating the full explanation:

- `redis.md`: correct the current standalone-only statement and explain under serialization that Redis atomic counters need `SERIALIZER_NONE` or PhpRedis 6.2+ `pack_ignore_numbers`, on standalone and Cluster connections.
- `cache.md`: add the actionable counter requirement beside `increment` / `decrement`.
- `queues.md`: state that `MaxExceptions` uses an atomic counter on the default cache store and link to the cache guidance.

Do not add an integration test that merely freezes a third-party serializer limitation.

### 5. Redis connection exception chains (finding 124)

Both Sentinel master resolution and Redis Cluster construction currently discard the original non-cancellation exception and omit punctuation. The merged cancellation work now detects a nested `CanceledException` with `RedisCancellation::cancellationFrom()` and rethrows it unchanged. Preserve that guard and improve only the ordinary failure branch:

```php
throw new ConnectionException(
    'Connection reconnect failed: ' . $exception->getMessage(),
    previous: $exception,
);
```

Apply this only to the existing non-cancellation branch in `PhpRedisConnection::createRedisSentinel()` and `PhpRedisClusterConnection::createRedisCluster()`. Do not catch, wrap, or otherwise alter the cancellation returned by `RedisCancellation::cancellationFrom()`. Successful construction is unchanged.

### 6. Cache increment/decrement contracts (finding 125)

`Hypervel\Contracts\Cache\Store` already declares `bool|int`. PhpRedis legitimately returns `false` from `INCRBY` / `DECRBY`, but `RedisStore` and its base operations narrow this to `int`, causing a `TypeError` instead of returning the contract value.

Use these precise internal types:

- `RedisStore::increment()` / `decrement()`: `int|false`.
- Base Redis `Increment::execute()` / `Decrement::execute()`: `int|false`.
- All six AnyTag operation boundaries (`execute`, Cluster helper, Lua helper for increment and decrement): `int|false`, matching their existing docblocks and AllTag operations.
- `FailoverStore::increment()` / `decrement()`: `bool|int`, because it delegates to arbitrary `Store` implementations and `true` is contract-valid.
- `NullStore::decrement()` value parameter: `int`, matching `Store`; its `bool` return remains correct.

Keep `Repository`, `TaggedCache`, `StackStore`, and the public contract at `bool|int`. Exceptions still propagate.

The AnyTag Cluster helpers have a second correctness obligation. Their native `MULTI` result can be `false`, or an array whose counter entry is `false`, while the TTL entry remains `-1`. The current code continues by destroying and rebuilding reverse indexes, tag hashes, and registry membership even though the counter did not change. Validate the result before destructuring or publishing metadata:

```php
$results = $multi->exec();

if (! is_array($results) || ! is_int($results[0] ?? null)) {
    return false;
}

[$newValue, $ttl] = $results;
```

The scripted standalone path aborts before metadata writes and `evalWithShaCache()` raises `LuaScriptException` with Redis's error. Do not force topology symmetry by swallowing that exception or manufacturing one for Cluster. Clarify on both AnyTag operation docblocks that Cluster can return `false`, while Redis errors from the scripted path raise.

AllTag's Cluster helpers currently publish sorted-set memberships before issuing the counter command. Reverse this sequential order: run `incrBy` / `decrBy`, return `false` unless the result is an integer, then publish memberships. This is side-effect free for a reported counter failure and adds no command or round trip. Do not split the standalone pipeline: gaining the same check there would add a real hot-path round trip to eliminate a recoverable stale membership.

### 7. Queue `MaxExceptions` counter

`Worker::markJobAsFailedIfWillExceedMaxExceptions()` compares the configured limit directly with the cache result. Besides the Redis `TypeError`, PHP's mixed-type comparison treats `true` as satisfying every non-negative threshold, while `false` satisfies only a zero threshold:

```text
maxExceptions=0 <= true  => true    maxExceptions=0 <= false => true
maxExceptions=1 <= true  => true    maxExceptions=1 <= false => false
maxExceptions=3 <= true  => true    maxExceptions=3 <= false => false
```

Capture the result once and act only on an integer:

```php
$exceptions = $this->cache->increment('job-exceptions:' . $uuid);

if (is_int($exceptions) && $maxExceptions <= $exceptions) {
    $this->cache->forget('job-exceptions:' . $uuid);
    $this->failJob($job, $e);
}
```

`false` and `true` are not counts and must not fail the job. This adds one local type check to an exception/retry path and no I/O.

### 8. Cache tag-mode validation (finding 126)

`TagMode::fromConfig()` currently maps every invalid value to `all`, silently changing invalid `any` configuration into different cache semantics. Throw `InvalidArgumentException` unless the value is exactly `all` or `any`; the message must name the rejected value and list both accepted values. Derive the accepted values once in `TagMode::supportedValues()` so configuration and command errors cannot drift.

Use `TagMode::All` as `CacheManager`'s default rather than the magic string `'all'`. Invalid explicit configuration must fail while the Redis store resolves.

The benchmark command must parse `--tag-mode` once with `TagMode::tryFrom()`, use `TagMode::supportedValues()` for its accepted-values message, and carry the enum through `runComparison()`, `runSuiteWithRuns()`, and `runSuite()`. Convert back to `$tagMode->value` only at the existing `ResultsFormatter::displayResultsTable()` string boundary and for console output. Comparison mode uses `TagMode::All` and `TagMode::Any` directly.

### 9. Tagged-cache prune ownership and writer ordering

Any-mode prune currently leaves an empty tag in the registry, while both tag modes use separate existence checks and metadata removals that can delete metadata published by a concurrent writer. Redis automatically deletes a hash or sorted set when its final field or member is removed, so the explicit final-key deletion is redundant and widens that race.

For standalone Redis, keep each scan page bounded and execute the existence checks plus conditional `HDEL` or `ZREM` in one Lua call. Finalize an any-mode tag with one Lua call that checks `HLEN` and removes the empty tag from its registry atomically. Do not add an unbounded script or move the full prune into Lua.

Redis Cluster cannot make the cross-slot value and metadata operations atomic. Collect orphan candidates, remove them with one variadic `HDEL` or `ZREM` per scan page, then recheck each candidate and conditionally repair it:

- Any mode restores a removed hash field with `HSETNX` only when both the value and reverse-index membership now exist. Run this repair after every integer `HDEL` result, including zero: fields can expire between `HSCAN` and `HDEL`, and zero cannot distinguish that harmless case from a concurrent remover followed by an interrupted writer. If an empty hash was removed from the registry but revived, restore only a missing registry member with `ZADD NX MAX_EXPIRY`, preserving a writer's real score.
- All mode restores a removed member with `ZADD NX -1` when its value appears after removal. The conservative forever score cannot cause premature expiry and preserves any score a writer already published.
- Treat every integer Cluster removal reply, including zero, as a completed removal attempt that requires the repair check. Another actor can remove the candidate before this prune's `ZREM` and a writer can publish the value before the repair read. Only a non-integer reply skips repair; the removal count remains statistics only.
- Subtract repaired candidates from the batch removal count and clamp the result at zero. Statistics remain exact without a concurrent writer and may conservatively undercount during the repair race because a batch result cannot identify which candidate it removed; that rare imprecision avoids one Redis removal round trip per orphan. Keep the existing `empty_hashes_deleted` and `empty_sets_deleted` statistic names, documenting that they include structures Redis removed with their final entry. Add `orphaned_tags_removed` for any-mode registry cleanup.

All-mode writers must publish the cache value before its memberships. Reorder `Put`, `Forever`, `Add`, `PutMany`, and the standalone `Increment` / `Decrement` pipelines without adding commands. For non-empty standalone `Add`, queue `SET NX` before the membership writes in the same pipeline; keep the direct `SET` path for empty tags. This removes its former second round trip while preserving unconditional membership publication when the key already exists.

The idempotent boolean writers `Put`, `Forever`, `Add`, `PutMany`, and Cluster `Touch` must report a membership write that returns strict `false`. Inspect results already returned by the pipeline or sequential commands; integer zero is a successful idempotent `ZADD`. Do not stop the remaining Cluster membership writes, add rollback, or retry. `Add` returns false when the key exists or any write fails, so its docblock must not claim that false proves the key existed. In Cluster `PutMany`, collect only successful `SETEX` keys, publish memberships only for those keys, skip `ZADD` when all writes fail, and still return false when any value or membership write fails. The standalone pipeline cannot filter memberships after failed value writes without another round trip; keep its single-round-trip behavior and rely on prune to reclaim metadata.

Do not apply membership-result reporting to `Increment` or `Decrement`. Once their counter command succeeds, returning false for a later metadata failure would falsely report that the mutation did not happen and could cause a retry to apply it twice. Keep their existing return ownership while prune repairs stale metadata.

### 10. Queue cleanup finding (154)

The driver-neutral database refactor already removed the never-supported SQL Server lock branch. Do not reintroduce a database-specific branch or add replacement runtime machinery.

The remaining source change is to document `ARGV[2]` as the migration batch size in `LuaScripts::migrateExpiredJobs()`. Preserve raw `usleep()` calls, the queue worker's existing overridable `sleep()` method, and the shutdown-only 1 ms poll; Swoole hooks the relevant sleep calls and the existing seams already make tests deterministic.

### 11. Horizon child process execution (finding 159)

`HorizonRestartStrategy` passes `PhpBinary::path()` to Symfony's array-form `Process`. `PhpBinary::path()` is shell-escaped text for command strings, not an executable path. Keep it in `SupervisorCommandString` and `WorkerCommandString`, but use Hypervel's executable helpers for the array command:

```php
$command = [php_binary(), artisan_binary(), 'horizon'];

if ($this->environment !== null && $this->environment !== '') {
    $command[] = '--environment=' . $this->environment;
}

return (new Process($command, $this->application->basePath()))->setTimeout(null);
```

`php_binary()` uses Symfony's executable finder, `artisan_binary()` preserves the framework's Artisan override, and the already-injected application contract supplies the deterministic working directory without reaching through a global helper.

If the child has already terminated after startup, throw a `RuntimeException` that always includes its exit code and appends trimmed stderr only when stderr is non-empty. Do not add an injectable binary-path seam.

## Files to change

### Source

- `src/reverb/src/Console/Commands/ClearStateCommand.php` (new)
- `src/reverb/src/ReverbServiceProvider.php`
- `src/reverb/src/Servers/Hypervel/Scaling/RedisSharedState.php`
- `src/redis/src/Operations/FlushByPattern.php`
- `src/redis/src/RedisProxy.php`
- `src/redis/src/RedisConnection.php`
- `src/redis/src/PhpRedisConnection.php`
- `src/redis/src/PhpRedisClusterConnection.php`
- `src/cache/src/TagMode.php`
- `src/cache/src/CacheManager.php`
- `src/cache/src/RedisStore.php`
- `src/cache/src/FailoverStore.php`
- `src/cache/src/NullStore.php`
- `src/cache/src/Redis/Console/Benchmark/BenchmarkContext.php`
- `src/cache/src/Redis/Console/BenchmarkCommand.php`
- `src/cache/src/Redis/Operations/Increment.php`
- `src/cache/src/Redis/Operations/Decrement.php`
- `src/cache/src/Redis/Operations/AnyTag/Increment.php`
- `src/cache/src/Redis/Operations/AnyTag/Decrement.php`
- `src/cache/src/Redis/Operations/AllTag/Increment.php`
- `src/cache/src/Redis/Operations/AllTag/Decrement.php`
- `src/cache/src/Redis/Operations/AllTag/Add.php`
- `src/cache/src/Redis/Operations/AllTag/Forever.php`
- `src/cache/src/Redis/Operations/AllTag/Put.php`
- `src/cache/src/Redis/Operations/AllTag/PutMany.php`
- `src/cache/src/Redis/Operations/AllTag/Prune.php`
- `src/cache/src/Redis/Operations/AllTag/Touch.php`
- `src/cache/src/Redis/Operations/AnyTag/Prune.php`
- `src/queue/src/Worker.php`
- `src/queue/src/LuaScripts.php`
- `src/horizon/src/Console/HorizonRestartStrategy.php`

### Tests and fixtures

- `tests/Reverb/Console/Commands/ClearStateCommandTest.php` (new)
- `tests/Reverb/DisabledReverbServiceProviderTest.php` (new)
- `tests/Reverb/Servers/Hypervel/Scaling/RedisSharedStateTest.php`
- `tests/Integration/Reverb/ClearStateCommandTest.php` (new)
- `tests/Redis/Fixtures/FakeRedisClient.php`
- `tests/Redis/Operations/FlushByPatternTest.php`
- `tests/Redis/RedisConnectionTest.php`
- `tests/Redis/PhpRedisClusterConnectionTest.php`
- `tests/Cache/TagModeTest.php`
- `tests/Cache/CacheManagerTest.php`
- `tests/Cache/Redis/AllTaggedCacheTest.php`
- `tests/Cache/Redis/RedisStoreTest.php`
- `tests/Cache/Redis/Operations/IncrementTest.php`
- `tests/Cache/Redis/Operations/DecrementTest.php`
- `tests/Cache/Redis/Operations/AnyTag/IncrementTest.php`
- `tests/Cache/Redis/Operations/AnyTag/DecrementTest.php`
- `tests/Cache/Redis/Operations/AllTag/IncrementTest.php`
- `tests/Cache/Redis/Operations/AllTag/DecrementTest.php`
- `tests/Cache/Redis/Operations/AllTag/AddTest.php`
- `tests/Cache/Redis/Operations/AllTag/ForeverTest.php`
- `tests/Cache/Redis/Operations/AllTag/PutTest.php`
- `tests/Cache/Redis/Operations/AllTag/PutManyTest.php`
- `tests/Cache/Redis/Operations/AllTag/PruneTest.php`
- `tests/Cache/Redis/Operations/AllTag/TouchTest.php`
- `tests/Cache/Redis/Operations/AnyTag/PruneTest.php`
- `tests/Integration/Cache/Redis/BenchmarkContextTest.php`
- `tests/Integration/Cache/Redis/PruneIntegrationTest.php`
- `tests/Cache/CacheFailoverStoreTest.php`
- `tests/Cache/CacheNullStoreTest.php`
- `tests/Cache/CacheRepositoryTest.php`
- `tests/Cache/Redis/Console/BenchmarkCommandTest.php`
- `tests/Coordinator/TimerTest.php`
- `tests/Queue/QueueWorkerTest.php`
- `tests/Horizon/Console/HorizonRestartStrategyTest.php`
- `tests/Testing/TestResponseTest.php`

### Documentation and workflow ownership

- `src/docs/reverb.md`
- `src/docs/redis.md`
- `src/docs/cache.md`
- `src/docs/queues.md`
- `.github/workflows/redis.yml`: run the standalone-only Reverb recovery integration test in the Valkey job, not the Redis or Cluster jobs. The existing `reverb.yml` workflow already runs the complete Reverb integration directory against Redis 8.
- `AGENTS.md`: once the owner approves implementing this plan, add the missing `reverb.yml` workflow row for `tests/Integration/Reverb`, and update the existing `redis.yml` row to name the standalone Valkey recovery test as well as the already-listed Cluster Reverb tests. Do not imply that `redis.yml` runs every Reverb integration test, and do not add unrelated gRPC documentation.
- `docs/plans/2026-08-22-0604-components-04-audit-remediation-plan-codex.md`: after implementation and verification, remove findings 112, 124–126, 154, and 159 plus summary/grouping prose that only describes those completed items. This active master plan is the exception to the historical-plan rule; this focused plan becomes the owning implementation record.

No root README or Laravel porting-guide entry is needed: the source fixes preserve public APIs, and the Reverb command is a Hypervel operational feature documented in its owning guide.

## Test plan

### Reverb command

- Assert the command name, `--dry-run`, and `--force` options.
- In the unconditional unit `RedisSharedStateTest`, create a small anonymous `RedisSharedState` subclass that exposes the real key builders for the connection counter and the existing six channel/member shapes. Assert with `fnmatch()` that `RedisSharedState::KEY_PATTERN` matches every generated key while rejecting webhook buffer keys, `reverb:message:*`, and unrelated keys. Do not use hand-written positive literals: this unit test must fail during the ordinary suite if the key builder and recovery pattern drift apart, even when Redis is unavailable.
- Assert dry-run uses the configured scaling connection, consumes the scan while the raw connection is held, reports the total, performs no deletion, and asks no question.
- Assert destructive execution uses the configured connection and exact logical pattern.
- Assert confirmation is required in every environment; rejection returns failure without deleting; confirmation and `--force` delete and return success.
- Assert the command remains registered when `reverb.enabled` is false.
- With standalone Redis and `InteractsWithRedis`, configure scaling to the per-worker default connection and seed all seven shared-state shapes through `RedisSharedState`'s public operations, including `acquireConnectionSlot()` for the app-scoped connection counter. Seed webhook buffer keys, a `reverb:message:*` key, and unrelated keys directly, then prove dry-run preserves everything while the destructive command removes only shared state. Do not extend the existing integration `keysForTest()` array with the connection key: that helper is intentionally channel-scoped and its Cluster-slot assertion would be invalid for an app-scoped key.

### Redis primitives and options

- Preserve existing transformed-connection rejection, prefix handling, scan iteration, and 1,000-key batching tests.
- Replace the false-to-zero `UNLINK` test with separate native-error and no-native-error exception tests. Assert the error slot is cleared before deletion and read only after failure.
- Assert named `pack_ignore_numbers` is applied to standalone and Cluster clients when the constant exists.
- When the constant is unavailable, assert named configuration throws the PhpRedis 6.2 requirement instead of being skipped.
- Preserve numeric option configuration and the legitimate mixed-topology `setOption(false)` behavior.
- For ordinary Sentinel and Cluster construction failures, assert wrapper message punctuation and exact previous exception identity, type, and message. Preserve the newly merged tests proving that nested cancellation escapes both boundaries as the same `CanceledException` instance.

### Cache and queue contracts

- Assert base Redis increment/decrement return positive, negative, and `false` results without coercion or `TypeError`.
- Assert `RedisStore` and `Repository` preserve `false`; keep existing exception propagation tests.
- Assert AnyTag Cluster success, non-array transaction failure, and a failed counter entry. Both failure shapes return `false` without reading or changing reverse indexes, tag hashes, or the registry. Preserve the standalone Lua exception and integer behavior.
- Assert AllTag Cluster success still uses sequential commands in counter-first order, while a failed counter returns `false` without any `zadd`. Preserve the single-round-trip pipeline behavior and its existing failure test.
- Assert Failover accepts any contract-valid boolean/integer result and NullStore's signature remains contract-compatible.
- Assert exact `all` and `any` parse successfully; typo, case mismatch, and empty values throw with both accepted values in the message.
- Assert invalid store configuration fails during `CacheManager` resolution while omitted configuration defaults to `TagMode::All`.
- Assert benchmark valid modes run unchanged, invalid CLI input returns failure, and enum values reach the existing formatter boundary.
- Assert `MaxExceptions` fails at an integer threshold, but `false` and `true` results do not fail or forget the counter, including the zero-limit boolean edge.
- Remove redundant `@test` annotations from already `test*`-named methods in the four touched tagged-counter test files; do not replace them with attributes.

### Queue and Horizon

- Keep queue migration behavior tests unchanged; verify the Lua argument documentation against the call order in `RedisQueue::migrateExpiredJobs()`.
- In a separate-process test, use `ParallelTesting::tempDir()` to create an application directory containing spaces and a temporary PHP `artisan` script. Start the real Horizon process, record its working directory and arguments, and verify ordinary and zero-named environments without leaking the strategy's signal handlers or application base path to other tests.
- Use a controlled `Process` test double for terminated startup failures and assert the exception includes the exit code and trimmed stderr. Also cover empty stderr without appending empty noise; do not race a real child against the production startup grace period.
- Preserve shell-string tests for `SupervisorCommandString` and `WorkerCommandString`.

### Tagged-cache pruning

- With the store configured for all mode, seed benchmark and unrelated members in the any-mode registry, run benchmark cleanup, and prove only the benchmark member is removed. Retain the existing physical-key and unrelated-key coverage in both modes.
- Assert standalone prune uses one bounded Lua call per scan page and atomically removes empty any-mode registry entries.
- Assert a custom scan count reaches the standalone `HSCAN` and bounds each Lua page.
- Assert Cluster prune batches same-metadata-key removals into one variadic command per page, permanently removes durable orphans, and repairs value/hash, value/member, and hash/registry races with `HSETNX` or `ZADD NX` without overwriting writer metadata.
- Assert Cluster prune still performs its repair reads and conditional `HSETNX` or `ZADD NX` when the batched any-mode `HDEL` or all-mode `ZREM` returns integer zero.
- Strengthen the real Redis integration coverage to prove any-mode prune removes both empty tag hashes and registry members; the same integration suite runs against the real Cluster service in its CI job.
- Assert every changed all-mode writer queues or issues the value command before memberships. For idempotent boolean writers, assert a strict-false membership reply returns false, while integer zero remains success on both pipeline and Cluster paths. Cover a whole pipeline failure separately. Preserve counter return ownership after a successful mutation.
- For Cluster `PutMany`, assert partial failure tags only successful values and total failure issues no `ZADD`. Keep the standalone pipeline single-round-trip failure coverage.

### PHPUnit assertion ownership

- Add explicit fluent-return assertions to the three successful `TestResponse` paths that otherwise perform no PHPUnit assertion. Both PHP runtimes execute those paths without assertions; why only the PHP 8.4 CI run reported them as risky is not established. The explicit assertions make the tests independent of that reporting difference without changing production assertion counting.

### Full-suite timing regression

- In both recurring-timer error-reporting tests, signal a `Channel` from the second callback and wait on that signal with a bounded timeout. Apply the same pattern to the stop-after-ten-ticks test. This preserves the exact continuation and stopping assertions without assuming the scheduler completes a fixed number of ticks within a short sleep.

## Verification

During implementation, run targeted tests after each coherent slice, then run:

```bash
composer fix
```

This is the required final gate and runs code style, PHPStan, and the parallel test suite. Run the real Redis integration test explicitly when local Redis is configured. In CI, `reverb.yml` owns the Redis run for the complete Reverb integration directory, while `redis.yml` adds only the distinct Valkey run for the recovery test. After all checks pass, trace every changed caller/callee and review the complete diff for stale comments, redundant branches, needless abstractions, public API drift, and hot-path overhead before requesting code review.

## Design boundaries

- automatic Reverb state recovery, TTLs, leases, heartbeats, or per-node state aggregation;
- Redis Cluster support for Reverb scaling;
- `DEL` fallback or runtime Redis/Valkey version detection;
- a general backend capability registry;
- changing Horizon's exact-key `DEL` calls, which remove small known keys rather than arbitrary bulk values;
- Redis `INCREX`, a Horizon notification-channel port, queue sleep rewrites, or a new concurrency primitive;
- unrelated changes from `docs/todo.md`;
- new package porting or an upstream PR.

## References

- Current audit scope: `docs/plans/2026-08-22-0604-components-04-audit-remediation-plan-codex.md`
- Reverb key owner: `src/reverb/src/Servers/Hypervel/Scaling/RedisSharedState.php`
- Redis pooled-operation boundaries: `src/redis/src/RedisProxy.php`, `src/redis/src/RedisConnection.php`
- Laravel cache contract and Redis behavior: `examples/laravel/framework/src/Illuminate/Contracts/Cache/Store.php`, `examples/laravel/framework/src/Illuminate/Cache/RedisStore.php`
- Laravel queue comparison: `examples/laravel/framework/src/Illuminate/Queue/Worker.php`, `examples/laravel/framework/src/Illuminate/Queue/LuaScripts.php`
- Laravel Redis numeric packing option: `examples/laravel/framework/src/Illuminate/Redis/Connectors/PhpRedisConnector.php`
- Redis [`UNLINK`](https://redis.io/docs/latest/commands/unlink/) and [large-deletion latency guidance](https://redis.io/faq/doc/16mfqr9c6n/how-to-troubleshoot-latency-issues-and-sporadic-failures-using-del/)
- Redis [version management](https://redis.io/docs/latest/operate/oss_and_stack/install/version-mgmt/)
- Valkey [release policy](https://valkey.io/topics/releases/)
- PhpRedis 6.2/6.3 source: [`redis.c`](https://github.com/phpredis/phpredis/blob/6.3.0/redis.c), [`redis_commands.c`](https://github.com/phpredis/phpredis/blob/6.3.0/redis_commands.c), [`redis_cluster.c`](https://github.com/phpredis/phpredis/blob/6.3.0/redis_cluster.c), [`cluster_library.c`](https://github.com/phpredis/phpredis/blob/6.3.0/cluster_library.c), and [`common.h`](https://github.com/phpredis/phpredis/blob/6.2.0/common.h)
