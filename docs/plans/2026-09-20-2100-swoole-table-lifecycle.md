# Swoole Table Lifecycle

Worktree: `components-swoole-table-lifecycle`, branch `fix/swoole-table-lifecycle` (from `0.4`).

## Problem

`composer test:parallel` jumps from ~2.4 GB to ~7.6 GB of process memory around the 75% mark and stays there. The cause is leaked Swoole table shared memory, which PHPUnit's memory figure and `memory_limit` cannot see.

Two defects combine:

1. `HypervelServerProvider::register()` builds Reverb's two shared-state tables (65,536 + 8,192 rows, ~9 MB) on every app boot. Cache (`CreateSwooleTable`) and the rate limiter (`InitializeSwooleTables`) create theirs from a `BeforeServerStart` listener instead. Reverb's tables are only useful inside the Swoole server, so every console command, queue worker, scheduler run, and Reverb test pays for them.
2. Tests that create Swoole tables never release them, and ParaTest reuses each worker process for the whole run.

## Verified facts

- Swoole 6.2.2 `table_free_object()` (`ext-src/swoole_table.cc:110`) only calls `zend_object_std_dtor()`. Shared memory is released only by `Table::destroy()`. Measured: 20 create/unset cycles of a 65,536-row table leave 20 orphaned shared mappings; create/`destroy()` leaves none.
- `Table::destroy()` (`src/memory/table.cc:195-218`) frees the table struct and its process-shared mutex back to Swoole's global shared pool. Calling it from a forked worker breaks its siblings. PHP runs destructors at process exit in every worker.
- Any method call on a destroyed table is a fatal `E_ERROR` (`table_get_and_check_ptr2()`), so it cannot be caught or asserted on.
- `Server.php:125-128` dispatches `BeforeServerStart` per server before `start()`, so before the fork.
- `HypervelServerProvider` is a 1:1 port of Laravel Reverb's `ReverbServerProvider`: config array in the constructor, `publishesEvents` set from it, `withPublishing()` for tests, built by the manager during `register()`. The table branch is Hypervel-original.
- Testbench ignores package discovery by default; only `ReverbTestCase`, `ReverbWatcherTest`, and a few Reverb tests register `ReverbServiceProvider`.
- Testbench order (`CreatesApplication`): `#[WithEnv]` → load config → `#[WithConfig]` → register providers → `defineEnvironment()` → boot providers. Class-level attributes are inherited from parent test cases (`AttributeParser::forClass()`). `WithEnv` restores the previous value through `beforeApplicationDestroyed()`.
- PHPUnit appends `#[After]` methods after `tearDown()` (`HookMethodCollection::defaultAfter()`, `shouldPrepend = false`) and includes protected, inherited, and trait methods. Verified order on both base classes: `beforeApplicationDestroyed` callbacks → app destroyed → `#[After]`. If `tearDown()` throws, `HookMethodInvoker::doInvoke()` stops and later hooks are skipped.
- Swoole rounds table sizes below 64 up to 64. A 64-row Reverb table costs ~8 KB.
- All `SharedState` consumers are on the Reverb server path (`Channel`, `InteractsWithPresenceChannels`, `Server`, `EventHandler`, `EventDispatcher`, `DeferredWebhookManager`, `checkTableCapacity()`). `ClearStateCommand` goes to Redis directly.
- Children forked by `SwooleStoreConcurrencyTest` and `CacheSwooleStoreConcurrencyTest` end with `posix_kill(getmypid(), SIGKILL)`, so they never run PHPUnit hooks.

## Decisions

| Decision | Why |
|---|---|
| No destructor on table owners | Needs a PID guard to be safe in forked workers, gives production nothing (memory is freed at process exit), and makes `table()` getters fatal for any caller that outlives the owner, e.g. `$this->createState(bytes: 8)->table()` in `CacheSwooleStoreTest`. |
| No static table registry flushed by `AfterEachTestSubscriber` | Production code that exists only for tests, and it cannot cover tests that call `new Table` directly. |
| Keep the constructor config snapshot; no live config read in the `SharedState` binding | Upstream structure. The injected array is the class contract: `testRedisClusterIsNotValidatedWhenScalingIsDisabled` passes `rows => 16` directly. |
| Small test tables through `#[WithEnv]`, not `#[WithConfig]` or `defineEnvironment()` | `defineEnvironment()` runs after `register()`. `#[WithConfig]` runs before it, but the skeleton has no `reverb.php` and `mergeConfigFrom()` is a shallow merge, so a partial `reverb.servers` would replace the package defaults. |
| `boot()` listener guarded by `shouldNotPublishEvents()` | The same predicate `register()` branches on. Keeps the Redis branch lazy so no Redis connection opens before the fork. |
| No `seal()` for Reverb | Cache and the rate limiter seal because tables are created by name on demand. Reverb has one fixed owner, resolved by the provider that binds it. |
| `#[After]` on the trait, method named `destroySwooleTables()` | Most table tests extend the unit `Hypervel\Tests\TestCase`, which has no trait booting, so neither the `tearDown{Trait}` convention nor Testbench's `#[TearDown]` attribute would run there (both are driven by `setUpTraits()` on the foundation case). A `tearDownInteractsWithSwooleTables()` name would also be called a second time by `setUpTraits()`. |
| Accept that a throwing `tearDown()` skips the hook | Running the hook before `tearDown()` would break `ReverbTestCase`, which tracks during `tearDown()`. The cost is one table per already-failing test. |
| Track every test-created table, including 64-row ones | A rule a reviewer can apply without measuring. |
| No permanent memory assertion | Depends on machine, PHP version, and test order. The lazy-creation regression test covers the root cause; memory is checked once under Verification. |

## 1. Source: `src/reverb/src/Servers/Hypervel/HypervelServerProvider.php`

Import `Hypervel\Core\Events\BeforeServerStart`.

Replace the `else` branch of `register()` (the eager tables and `instance()` call, with its comment):

```php
} else {
    // Bound lazily so processes that never start the server allocate no shared
    // memory. boot() resolves it on BeforeServerStart, so the tables and the
    // striped locks exist before the fork and are shared by every worker.
    $this->app->singleton(SharedState::class, function (): SwooleTableSharedState {
        $table = new Table($this->config['swoole_shared_state']['rows']);
        $table->column('count', Table::TYPE_INT);
        $table->create();

        $lockTable = new Table($this->config['swoole_shared_state']['lock_rows']);
        $lockTable->column('locked_at', Table::TYPE_FLOAT);
        $lockTable->create();

        return new SwooleTableSharedState($table, $lockTable, new StripedLock);
    });
}
```

`boot()`:

```php
$events = $this->app->make('events');

if ($this->shouldNotPublishEvents()) {
    $events->listen(BeforeServerStart::class, function (): void {
        $this->app->make(SharedState::class);
    });
}

if ($this->subscribesToEvents()) {
    // existing AfterWorkerStart listener, unchanged
}
```

Update the `SwooleTableSharedState` constructor docblock: replace "Must be created before fork (via instance(), not singleton())" with wording that says it must be created before the fork, without naming a binding method.

Nothing changes in cache or rate-limiter source.

## 2. Trait: `src/foundation/src/Testing/Concerns/InteractsWithSwooleTables.php`

```php
trait InteractsWithSwooleTables
{
    /**
     * The Swoole tables created by the test.
     *
     * @var list<Table>
     */
    protected array $swooleTables = [];

    /**
     * Track Swoole tables so their shared memory is released after the test.
     */
    protected function trackSwooleTable(Table ...$tables): void
    {
        $this->swooleTables = [...$this->swooleTables, ...$tables];
    }

    /**
     * Destroy the tracked Swoole tables.
     */
    #[After]
    protected function destroySwooleTables(): void
    {
        foreach ($this->swooleTables as $table) {
            $table->destroy();
        }

        $this->swooleTables = [];
    }
}
```

No guards. Clearing the list matters: PHPUnit calls the hook again after a test that invoked it, and a second `destroy()` is fatal.

Test `tests/Foundation/Testing/Concerns/InteractsWithSwooleTablesTest.php` (unit base): track a 64-row table, call `destroySwooleTables()`, assert the list is empty. The automatic `#[After]` call that follows proves a cleared list is safe.

## 3. Reverb tests

`tests/Reverb/ReverbTestCase.php`:

- `use InteractsWithSwooleTables;`
- Class attributes `#[WithEnv('REVERB_SWOOLE_SHARED_STATE_ROWS', '64')]` and `#[WithEnv('REVERB_SWOOLE_SHARED_STATE_LOCK_ROWS', '64')]`.
- Add `setUp()`: call `parent::setUp()`, then register a `beforeApplicationDestroyed` callback. If `$this->app->resolved(SharedState::class)` and the instance is a `SwooleTableSharedState`, track `table()` and `lockTable()`.

Every `ReverbTestCase` subclass below inherits the trait; only `SwooleTableSharedStateLockTest` (unit base) adds it itself.

`tests/Reverb/Servers/Hypervel/HypervelServerProviderTest.php`:

- `testBindsRedisSharedStateWhenScalingEnabled`, `testScalingSharedStateDefaultsToReverbRedisConnection`: replace the `config()->set()` + second-provider block with `#[WithEnv('REVERB_SCALING_ENABLED', 'true')]` and assert on the booted app. `testScalingSharedStateUsesConfiguredRedisConnection` also gets `#[WithEnv('REVERB_SCALING_CONNECTION', 'queue')]`. Those tests asserted a path the app never takes. Verified: register-time `validateScalingRedisConnection()` passes because `reverb` and `queue` exist in shipped `database.php`, and the env does not leak into the next test.
- `testCreatesSwooleTableWithConfiguredRows`: assert `64` for both `table()->getSize()` and `lockTable()->getSize()` instead of `> 0`.
- Replace `testSharedStateIsEagerlyCreated` (it would still pass with a false name and comment) with:
  - `testSharedStateIsNotCreatedDuringBoot`: `bound()` true, `resolved()` false.
  - `testSharedStateIsCreatedBeforeTheServerStarts`: `resolved()` false, dispatch `BeforeServerStart`, `resolved()` true.
  - `testRedisSharedStateIsNotResolvedBeforeTheServerStarts` with `#[WithEnv('REVERB_SCALING_ENABLED', 'true')]`: dispatch, `resolved()` stays false.
  - The last two unit-test `HypervelServerProvider::boot()` against its own dispatcher. Dispatching on the app dispatcher would also fire the cache and rate-limiter `BeforeServerStart` listeners, which create their own tables (the merged `rate-limiter.stores.swoole` is 65,536 rows); suppressing those from a Reverb test would need knowledge of every other listener on the event. The real wiring (`ReverbServiceProvider::boot()` → `ServerProviderManager::boot()` → listener → tables shared across workers) is covered by `tests/Integration/Reverb/MultiWorkerServerTest.php`, which runs a real two-worker server with scaling off and fails if each worker gets a private table.

    ```php
    protected function bootServerProvider(): Dispatcher
    {
        $this->app->instance('events', $events = new Dispatcher($this->app));

        $provider = new HypervelServerProvider($this->app, config()->array('reverb.servers.reverb'));
        $provider->register();
        $provider->boot();

        return $events;
    }
    ```
- Keep `testRedisClusterScalingIsRejectedWithoutCreatingAPool` and `testRedisClusterIsNotValidatedWhenScalingIsDisabled` as they are. They unit-test the constructor contract and need mocks bound before `register()`.

`tests/Reverb/Servers/Hypervel/GracefulShutdownTest.php`: in `testDisconnectScalingSubscriberCallsDisconnect`, replace the `config()->set()`, second `HypervelServerProvider`, and `withPublishing()` lines with `#[WithEnv('REVERB_SCALING_ENABLED', 'true')]`. Verified green. Remove imports that become unused.

`tests/Reverb/Servers/Hypervel/Scaling/SwooleTableSharedStateTest.php`: the two-table block appears four times (`setUp()`, `testThrowsExceptionWhenTableIsFull`, `testPresenceCreationFailureDoesNotPublishOnlyOneCounter`, `testTryLockReturnsFalseWhenLockTableFull`). Replace with one private helper that builds both tables, tracks them, and returns the state:

```php
/**
 * @template T of SwooleTableSharedState
 * @param class-string<T> $class
 * @return T
 */
private function createState(int $rows, int $lockRows, string $class = SwooleTableSharedState::class): SwooleTableSharedState
```

Sizes stay as they are: 1024/256, 4/4, 128/128, 1024/4.

`tests/Reverb/Servers/Hypervel/Scaling/SwooleTableSharedStateLockTest.php` (unit base): add the trait, track both tables inside the existing `createState()`.

`tests/Reverb/ReverbServiceProviderTest.php::testTableCapacityWarningsUseTheFrameworkLogger`: track its `Table(4)`.

No change: `tests/Telescope/Watchers/ReverbWatcherTest.php` never resolves `SharedState`. `ReverbServiceProviderTest` re-runs `register()` on purpose to test the `bound()` guards. Other `withPublishing()` callers mirror upstream tests.

## 4. Cache tests (unit base; add the trait to each class)

| File | Change |
|---|---|
| `CacheSwooleStoreTest` | Track `$state->table()` in `createState()`. In `testSealingRetainsExistingTablesAndRejectsLateCreation`, track `$first->table()`. |
| `CacheSwooleStoreIntervalTest` | Track in `createState()` (128 rows × 65,536 bytes, ~0.8 MB each). `createControllableState()` reuses that table. The subprocess script is untouched. |
| `CacheSwooleStoreConcurrencyTest` | Track in `createState()`. |
| `CacheManagerTest` | In `testManagerBuiltSerializingStoresShareOnePolicy` and `testSwooleDriverUsesConfiguredSerializableClasses`, after the store resolves, track `$app->make(SwooleTableManager::class)->get('default')->table()`. |
| `CreateSwooleTableTest` | In `testInitializesAndSealsTablesAcrossRepeatedServerStartEvents`, track `$state->table()` right after `$state` is assigned. |

Table sizes in these tests are part of what they assert; do not shrink them.

## 5. Rate-limiter tests

| File | Change |
|---|---|
| `SwooleStoreTest` (unit) | Trait; track `$state->table()` in `store()`. |
| `SwooleStoreConcurrencyTest` (unit) | Trait; track in `state()`. |
| `SwooleTableManagerTest` (unit) | Trait; track in `testCreatesAndCachesAnEightByteIntegerTable` (`$table`), `testSealingRetainsExistingTablesAndRejectsLateCreation` (`$first->table()`), and `testAcceptsConflictProportionsThatSwooleHonorsExactly` (assign the table to a variable first). The invalid-configuration cases throw before `new Table`. |
| `RateLimiterTest` (Testbench) | Trait. In `testSwooleStoreMayOmitTheMemoryLimitBuffer`, set `$config['rows'] = 64` on the copied config and track `$this->app->make(TableManager::class)->get('swoole-default')->table()`. The invalid-buffer provider case throws before the table is created. |

No change: `InitializeSwooleTablesTest` and `RegisterSwooleMaintenanceTimersTest` / `RegisterPruneTimerTest` use mocks; `SwooleMaintenanceTimerWorkerRecycleTest` runs a subprocess.

## 6. Documentation

Done in this worktree: the `AGENTS.md` bullet under "Coroutine and Worker-Lifetime State", the `AGENTS.md` paragraph under "Static state and test cleanup", and the `src/docs/testing.md` paragraph under "Owning Asynchronous Test Resources". They name `InteractsWithSwooleTables`, so they ship with this change. No `porting-from-laravel.md` or README change: upstream Reverb has no tables.

## Verification

Run each changed test file with PHPUnit as it is edited. Then `composer lint:fix`, `composer analyse`, and `composer test:parallel`.

Confirm the real wiring with the multi-worker server. `composer test:parallel` skips these tests unless `TEST_SERVER_HOST` is set, so a green local suite says nothing about the listener being wired. Run `./bin/test-servers.sh reverb` in one terminal, then `TEST_SERVER_HOST=127.0.0.1 ./vendor/bin/phpunit --no-progress tests/Integration/Reverb/MultiWorkerServerTest.php`, and check the output reports no skipped tests.

Green tests do not show released memory. For the single-process rows, measure peak process memory with `/usr/bin/time -v ./vendor/bin/phpunit --no-progress <path>` ("Maximum resident set size") next to PHPUnit's reported heap, before and after. `time -v` reports one process, so for the full suite sample the total across workers while it runs: `while sleep 1; do ps -C php -o rss= | awk '{s+=$1} END {print int(s/1024)}'; done`.

| Suite | `0.4` baseline | Expected after |
|---|---|---|
| `tests/Reverb` | 118 → 4,432 MB, 26 s | under ~200 MB |
| `tests/Cache/CacheSwooleStoreTest.php` | 98 → 138 MB, heap flat | growth close to heap growth |
| `tests/RateLimiter` | +9.5 MB from one test | no table growth |
| Full suite peak | 7,666 MB | ~3,200 MB or lower |

## Status

Documentation (section 6) is done. Next step: section 1, `src/reverb/src/Servers/Hypervel/HypervelServerProvider.php`.
