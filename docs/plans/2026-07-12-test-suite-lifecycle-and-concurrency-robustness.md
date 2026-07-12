# Test-suite lifecycle and concurrency robustness

## Scope

Harden coroutine creation, test cleanup, queue and Horizon process lifecycle, test-owned resources, lock liveness, test database pooling, and deterministic programmatic console execution.

## Goal

Eliminate the intermittent parallel-suite hang and the related flakes uncovered while tracing it, then make the affected production and testing lifecycle boundaries correct by construction.

The finished design must have these properties:

- a failed coroutine spawn cannot strand a channel token, wait-group count, timer registration, or caller;
- every test-owned coroutine, process, socket, timer, coordinator, database lease, and Mockery container has a bounded and exception-safe owner;
- production queue and Horizon shutdown cannot wait forever on state that may never make progress;
- worker-global state is used only where the state is genuinely worker-global;
- synchronization failures fail loudly within a bounded interval rather than consuming a CPU forever;
- programmatic console calls are deterministic regardless of the parent test runner's verbosity;
- fixes live at the lowest correct production boundary, with focused regression tests demonstrating the old failure;
- no compatibility shims, obsolete reset calls, workaround comments, duplicated test seams, or dead helpers remain.

This is not a request to make every asynchronous operation synchronous or to add defensive timeouts indiscriminately. Each timeout, ownership boundary, and API change below corresponds to a concrete unbounded wait, leaked resource, or ambiguous failure contract found in the current code.

## Source material and verified evidence

### Repository sources

- `src/engine/src/{Coroutine,SafeSocket}.php`
- `src/engine/src/Http/Server.php`
- `src/coroutine/src/{Coroutine,Concurrent,WaitConcurrent,Parallel,Waiter}.php`
- `src/concurrency/src/CoroutineDriver.php`
- `src/redis/src/Subscriber/CommandInvoker.php`
- `src/prompts/src/{Task,Spinner}.php`
- `src/console/src/SignalRegistry.php`
- `src/signal/src/SignalManager.php`
- `src/core/src/Bootstrap/WorkerExitCallback.php`
- `src/coordinator/src/Timer.php`
- `src/foundation/src/Testing/Concerns/RunTestsInCoroutine.php`
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`
- `src/testing/src/Concerns/InteractsWithMockery.php`
- `src/foundation/src/Testing/{TestCase,DatabaseConnectionResolver}.php`
- `src/queue/src/Worker.php`
- `src/horizon/src/{Supervisor,MasterSupervisor,ProcessPool,WorkerProcess,SupervisorProcess,ListensForSignals}.php`
- `src/horizon/src/Console/HorizonRestartStrategy.php`
- `src/cache/src/SwooleTableState.php`
- `src/reverb/src/Servers/Hypervel/Scaling/SwooleTableSharedState.php`
- `src/reverb/src/Servers/Hypervel/Scaling/RedisPubSubProvider.php`
- `src/routing/src/CompiledRouteCollection.php`
- `src/console/src/Application.php`
- `src/foundation/src/Console/{ConfigCacheCommand,RouteCacheCommand}.php`
- `src/testbench/src/{Bootstrapper,Foundation/Process/RemoteCommand}.php`
- `src/pool/src/{Pool,Channel}.php`
- `src/object-pool/src/{ObjectPool,Channel}.php`
- `src/watcher/src/{Watcher,RestartStrategy,ServerRestartStrategy,WatchPath}.php`
- `src/watcher/src/Driver/{AbstractDriver,FindDriver,FindNewerDriver,ScanFileDriver,FswatchDriver}.php`
- the corresponding unit and integration tests under `tests/`

### Local upstream references

- Symfony Console's `vendor/symfony/console/Tester/ApplicationTester.php` saves, unsets, and restores `SHELL_VERBOSITY` around a single-threaded test run. That is useful evidence for the inherited-verbosity cause, but its process-global mutation is not safe for Hypervel's coroutine-concurrent framework API.
- Symfony Console's `Application::run()` also installs a process-global exception handler and saves/restores process-global shell verbosity around `configureIO()` and `doRun()`. Hypervel's programmatic API already disables Symfony exception catching, so the safe boundary is a dedicated global-free IO configuration followed by `doRun()`.
- Laravel's local `CompiledRouteCollection` keeps its name cache on the collection instance, not in a static shared across collections.
- Laravel Horizon scales worker pools down into a terminating collection and applies the configured worker timeout while pruning them.
- Laravel's queue worker uses a real sleeping primitive. Hypervel's production `Worker::sleep(0)` reaches hooked `usleep(0)`, which yields; the test double that replaced the method with recording-only behavior did not.

### Captured parallel-suite hang

The incomplete ParaTest run was not a generic ParaTest deadlock:

- the only missing class was `Hypervel\Tests\Queue\QueueWorkerTest`;
- exactly 34 tests from that class were absent from the final result;
- the assigned worker emitted 27 completed tests and stopped while entering test 28, `testWorkerStoppingIsDispatched`;
- the stuck PHP worker was runnable, consumed approximately one core, had no child process, and reported kernel wait channel `0`;
- the test's `InsomniacWorker::sleep()` override only recorded the duration and never yielded;
- `Worker::daemon()` can repeatedly revisit its concurrency-full branch and invoke that override while a child job coroutine is waiting to resume;
- an isolated probe reproduced the CPU-bound loop, while delegating to `parent::sleep(0)` allowed the child to run and the daemon to finish.

The exact scheduler interleaving that made an otherwise inline fake job park in the rare captured run cannot be reconstructed after the process is gone. The owning class, hot-loop mechanism, and deterministic regression are nevertheless established. Repeatedly running the full suite is not an appropriate reproduction strategy for a flake observed only a few times over many months.

### Additional verified failures found during the audit

1. Clearing `CoordinatorManager` after resuming a timer's coordinator can cause the timer coroutine to resolve a fresh open coordinator and wait forever.
2. A throwing coroutine teardown hook skips later hooks and the `WORKER_EXIT` resume, stranding child coroutines.
3. An unmet Mockery expectation throws before the global subscriber's framework reset list, leaving statics dirty; PHPUnit reports subscriber exceptions as extension warnings rather than failures belonging to the test.
4. `Swoole\Coroutine::create()` returns `false` at the coroutine limit. Assigning that to Engine Coroutine's `?int` ID produces a `TypeError`, while higher layers document `-1`/`false` and leak their pre-acquired bookkeeping.
5. The cache multiprocess test has bounded barrier setup but unbounded pipe reads and waits, without failure cleanup.
6. Engine socket tests reuse a fixed port and only shut servers down on the success path.
7. A renderer test manually joins child coroutines with unbounded channel pops that hide child exceptions.
8. ParaTest's `-v` exports `SHELL_VERBOSITY`; programmatic `Application::call()` inherits it, changing otherwise normal route and schedule output.
9. Compiled routes cache `Route` objects statically by route name. A later collection using the same name can receive the first collection's route object.
10. Supervisor persistence is detached solely to accommodate a test timing workaround, allowing writes to overlap, reorder, and report errors outside the loop that initiated them.
11. Horizon worker termination and queue-worker kill paths contain unbounded waits.
12. Cache and Reverb striped locks can spin forever after a holder dies; Reverb also logs from inside the critical section if its lock table is full.
13. The test database resolver permanently abandons each checked-out pooled wrapper after extracting its bare connection, so pool capacity is never returned.
14. `Frequency::frequency()` divides by an empty sample immediately after construction, before `flush()` has a prior second to zero-fill.
15. Testbench may kill a live PID based only on parent PID and a stale PID file, which is unsafe after PID reuse.
16. Several Redis tests own long-lived subscribers without guaranteed teardown.
17. Watcher drivers have inconsistent lifetimes: one blocks, three detach timers and return, and their failures cross different ownership boundaries. Watcher cannot observe completion or failure consistently and never invokes the driver's stop contract.
18. Programmatic console calls inherit Symfony's process-global shell verbosity even though Hypervel already clones each Command per execution and safely supports concurrent and nested command calls. Adding a second serialization layer would guard no observable state and deadlock commands that delegate work to child coroutines.
19. Config and route cache subprocesses do not inherit cache-path overrides stored only in PHP's `$_SERVER`, so a child can load a different cache from the one its parent cleared.
20. Tests that mutate Testbench bootstrap, provider, route, and published files use unchecked sequential restoration; one failed cleanup can silently contaminate a later subprocess boot.
21. Engine HTTP request dispatch does not catch a coroutine creation failure at the native Swoole callback boundary, so overload can escape without completing the response.
22. Pool and object-pool channels can enqueue or destroy an item and then throw while creating an outside-coroutine wake helper, reporting a committed ownership change as failed.
23. `SafeSocket` treats the valid string payload `"0"` as a closed receive, and Fswatch treats arbitrary read chunks as complete newline-delimited records.
24. Child-reaping tests treat every `waitpid()` error as success or fall back to an unbounded blocking wait, so interruption can hide or create an owned-process leak.
25. Reverb commits a live Redis subscriber before spawning its consumer. A failed spawn leaks that subscriber, and resetting the retry counter before the subscription handshake makes its retry limit unreachable.
26. Horizon can signal a newly forked worker or supervisor before that child installs its handlers. An early SIGUSR2 keeps its default terminating disposition, producing the intermittent paused-supervisor failure that strict child reaping exposes.
27. The queue worker accepts `--monitor-interval` and stores it on `WorkerOptions`, but the timeout monitor reads a separate constructor property that always retains its one-second default, so the documented option has no effect.

## Design principles

1. **Ownership is explicit.** The component that registers or borrows a resource records the exact handle and releases that exact handle in `finally`.
2. **Creation is transactional.** Code that reserves capacity before spawning either completes the spawn or rolls the reservation back.
3. **Cleanup is exhaustive.** One cleanup failure must not skip unrelated cleanup. The first failure remains primary.
4. **Timeouts guard external progress, not normal work.** Lock acquisition, child-process IPC, and process termination need bounds because the owner can die. Ordinary coroutine joins retain semantic completion waits once spawn is guaranteed.
5. **Production boundaries own production correctness.** Environment isolation belongs in `Application::call()`, route cache identity belongs in the route collection, and coroutine-spawn failure belongs in Engine.
6. **Tests use deterministic seams.** Tests inject a timer or process boundary rather than installing real signal handlers, leaving detached timers behind, or relying on fixed ports.
7. **Fix the lowest inconsistent contract.** Do not stack a local mutex, mode, retry, or identity mechanism over a lower-level contract that can be made correct for every caller.
8. **No unnecessary abstractions.** Do not add a general cancellation framework, configurable striped-lock policy, test-only coroutine factory, broad process supervisor, or defensive machinery for a state with no observable failure. The APIs below are the minimum reusable capabilities the verified ownership model lacks.

## Final design

### 1. Make coroutine creation a throwing, typed contract

#### Problem

Engine currently assigns the `false` returned by `Swoole\Coroutine::create()` to `?int`. High-level helpers then attempt to convert failure into `-1` or `false`, but the `TypeError` occurs first. Callers such as `Concurrent`, `WaitConcurrent`, `Parallel`, and `CoroutineDriver` mutate bookkeeping before spawn and cannot roll it back reliably under the current ambiguous contract.

#### Decision

Add `Hypervel\Engine\Exceptions\CoroutineCreateException`. Engine throws it immediately when native creation returns `false`. Every successful public spawn API returns an `int`; failure always throws.

```php
class CoroutineCreateException extends RuntimeException
{
    public static function fromLastError(): static
    {
        $code = swoole_last_error();

        return new static(
            sprintf('Unable to create coroutine: %s', swoole_strerror($code)),
            $code,
        );
    }
}
```

```php
public function execute(...$data): static
{
    $id = @SwooleCo::create($this->callable, ...$data);

    if ($id === false) {
        throw CoroutineCreateException::fromLastError();
    }

    $this->id = $id;

    return $this;
}
```

`Hypervel\Coroutine\Coroutine::create()` no longer catches `getId()` failures or documents `-1`:

```php
public static function create(callable $callable): int
{
    return Co::create(static function () use ($callable): void {
        try {
            foreach (static::$afterCreatedCallbacks as $callback) {
                try {
                    $callback();
                } catch (Throwable $throwable) {
                    static::printLog($throwable);
                }
            }

            $callable();
        } catch (Throwable $throwable) {
            static::printLog($throwable);
        }
    })->getId();
}
```

`co()` and `go()` become exact aliases returning `int`:

```php
function go(callable $callable, bool|array $copyContext = false): int
{
    return $copyContext === false
        ? Coroutine::create($callable)
        : Coroutine::fork($callable, is_array($copyContext) ? $copyContext : []);
}
```

#### Transactional rollback in callers

`Concurrent` releases its capacity token when spawn itself throws:

```php
$this->channel->push(true);

try {
    Coroutine::create(function () use ($callable): void {
        try {
            $callable();
        } catch (Throwable $exception) {
            $this->reportException($exception);
        } finally {
            $this->channel->pop();
        }
    });
} catch (Throwable $exception) {
    $this->channel->pop();
    throw $exception;
}
```

`WaitConcurrent` balances the wait group if its parent spawn fails:

```php
$this->wg->add();

try {
    parent::create(function () use ($callable): void {
        try {
            $callable();
        } finally {
            $this->wg->done();
        }
    });
} catch (Throwable $exception) {
    $this->wg->done();
    throw $exception;
}
```

`Parallel` and `CoroutineDriver` treat spawn failures as failures of the corresponding input key and balance both concurrency and wait bookkeeping immediately:

```php
try {
    $this->copyContext === false
        ? Coroutine::create($childCallable)
        : Coroutine::fork(
            $childCallable,
            is_array($this->copyContext) ? $this->copyContext : [],
        );
} catch (Throwable $exception) {
    $this->throwables[$key] = $exception;
    unset($this->results[$key]);
    $this->concurrentChannel?->pop();
    $wg->done();
}
```

`CoroutineDriver` has no concurrency channel, so its matching rollback is smaller:

```php
try {
    Coroutine::fork($childCallable);
} catch (Throwable $exception) {
    $exceptions[$key] = $exception;
    $waitGroup->done();
}
```

`Waiter` needs no compensating bookkeeping: a creation failure propagates immediately instead of being misreported ten seconds later as a channel timeout.

Database and Redis health checks are the two intentional boolean boundaries. Inability to create the probe coroutine means the pooled connection is not currently healthy:

```php
try {
    go($probe);
} catch (CoroutineCreateException) {
    return false;
}
```

Catch only `CoroutineCreateException`; do not swallow application or connection errors.

Audit every `Coroutine::create()`, `Coroutine::fork()`, `go()`, and `co()` caller. Callers without pre-spawn bookkeeping naturally propagate the exception. Delete all `-1`, `false`, and `bool|int` creation-failure branches and stale docblocks.

The complete caller audit requires these additional dispositions:

- `SafeSocket::loop()` resets `$loop` if spawning fails. Its child also clears that flag when the send loop exits, treats native `sendAll() === false` as terminal, and closes the channel/socket so later sends cannot enqueue behind a dead consumer. Expected closed/timeout conditions are not reported as critical errors; unexpected throwables still are. Closed receive errors retain the native message and code, and an already-closing channel never spawns a phantom consumer. `recvAll()` and `recvPacket()` use strict terminal checks so the valid payload string `"0"` is not mistaken for a closed socket.
- `Redis\Subscriber\CommandInvoker` wraps receive-loop creation and shutdown-watcher registration transactionally; if either fails, it interrupts the connection, closes all four channels (including `pingChannel`), and clears any timer it registered. Store the shutdown timer ID so normal `interrupt()` also removes the watcher. Constructor rollback preserves the creation failure if `interrupt()` also throws; cleanup cannot replace the primary failure.
- `Prompts\Task` and `Prompts\Spinner` move animation spawn inside their existing `try/finally`, so cursor/render cleanup also runs when spawn fails.
- `Console\SignalRegistry` rolls back the handler appended for a failed waiter spawn. Its array registration path tracks additions and removes only those additions if a later signal cannot be registered; it terminally cancels a newly created waiter only when no handlers remain. Both normal unregister and rollback use `throwException: true`, while the waiter catches `CanceledException` as expected control flow. The canceller alone owns the registry slot.
- `Signal\SignalManager::listen()` keeps a local array of IDs created by that invocation and terminally cancels those IDs if a later signal watcher cannot be created, preventing a partially installed listener set without adding persistent registry state. Its waiters likewise catch intentional `CanceledException` without reporting it. Swoole 6.2 was probed directly: default cancellation interrupts one `System::waitSignal()` call but lets an unconditional waiter loop continue spinning; exception-injecting cancellation is required to terminate the coroutine.
- `Core\Bootstrap\WorkerExitCallback` removes its unnecessary coroutine and resumes the worker-exit coordinator synchronously in `finally`, so a throwing worker-exit listener cannot strand shutdown waiters. `Coordinator::resume()` only closes its channel and is valid at that lifecycle point; eliminating the spawn also eliminates a shutdown-time creation failure.
- background queue dispatch, Sentry request dispatch, and server-process listeners hold no pre-spawn mutable bookkeeping. They propagate creation failure as the honest failure of the requested operation.
- Reverb Redis pub/sub startup is transactional. `connect()` creates and subscribes a locally owned `Subscriber` before committing it to the provider, checks `shouldRetry` again after the yielding handshake, then drains queued publishes and spawns a consumer that captures that exact subscriber and channel name. Any handshake, queued-publish, or spawn failure closes the local subscriber, clears committed state only when it still refers to that object, preserves the primary failure for logging, and enters the existing bounded reconnect path. The retry counter resets only after the complete startup transaction succeeds, so repeated failures reach the existing limit instead of resetting it on every attempt.

  Remove the unused public `subscribe()` method from `PubSubProvider`; `connect()` owns the complete lifecycle and the sole implementation has no external `subscribe()` caller. The internal consumer closes its captured subscriber and clears provider state by object identity before reconnecting, so an older consumer cannot clear a newer connection.

  Drain queued publishes in order and remove each payload only after a successful publish. A `JsonException` is a permanent payload defect: log and drop that one payload so it cannot poison every reconnect. A Redis or connection failure is transient: retain that payload and the remaining tail, abort the startup transaction, and retry through the existing bounded path. Do not replace the now-bounded `connect()`/`reconnect()` control flow with a general reconnect state machine.
- Engine HTTP request dispatch is a native callback boundary, not an ordinary propagation boundary. Catch `CoroutineCreateException` around the spawn itself, log the overload failure, and complete the native response with HTTP 503. The existing child `try/catch` cannot catch a failure that occurs before the child exists, and an exception must not escape through Swoole's native callback.
- pool and object-pool channels enqueue or destroy an item before `Channel::signal()` may create a helper coroutine for an outside-coroutine caller. A spawn failure must not report release/discard as failed after ownership already changed. Handle this once in both synchronized channel implementations: treat an unavailable helper spawn as a missed wake, and make pool checkout recheck idle/capacity state once at its deadline before reporting exhaustion. This also closes the ordinary timeout-versus-release race. Keep both channel implementations structurally aligned; do not catch the exception at every release caller or add a permanent dispatcher coroutine per pool.

The channel boundary is narrow:

```php
try {
    Coroutine::create($this->pushSignal(...));
} catch (CoroutineCreateException) {
    // State is already committed. The checkout loop performs one final state check.
}
```

The pool checkout loop records a timed-out wait, loops once more through its existing closed/idle/capacity checks, and throws exhaustion only if that final immediate pass still finds no progress. Pin that shape with a flag checked before any second wait:

```php
$timedOut = false;

while (true) {
    // Existing closed, idle-item, and capacity/create checks.

    if ($timedOut) {
        throw new RuntimeException(
            'Connection pool exhausted. Cannot establish new connection before wait_timeout.'
        );
    }

    $timedOut = ! $this->waitForStateChange($deadline);
}
```

The final pass may consume an item or create against newly freed capacity, but it cannot call `waitForStateChange()` again. Normal operation does not poll. Apply the same rule to connection pool and object pool with their existing concrete exhaustion messages.

- `FswatchDriver` already owns process teardown in `finally`. Remove its unnecessary raw per-batch coroutine: path matching runs inline in the owned driver coroutine, so matching failures flow through Watcher's failure slot, channel capacity provides bounded backpressure, delivery remains ordered, and no detached batch child survives teardown. `WatchPath` compiles its immutable glob regex once during construction instead of once per file match.
- `SignalRegistry`'s `$handling[$signo] = Coroutine::create(...)` assignment is naturally atomic because PHP does not assign the right-hand result when it throws; only its separately appended callback needs rollback.

Add focused creation-failure coverage for every stateful item in this list, not merely the four concurrency helpers.

### 2. Give coordinator timers stable identity and transactional registration

#### Problem

`Timer` resolves its coordinator inside the child coroutine. If teardown resumes and clears the manager before that child first runs, the child creates a new open coordinator and waits forever. `tick()` also waits for one more interval after a callback clears itself. A failed spawn leaves `$closures[$id]` registered.

#### Decision

Resolve the coordinator before spawning and capture the object. Check registration before every wait. Roll registration back if `go()` throws.

```php
public function tick(
    float $timeout,
    callable $closure,
    string $identifier = Constants::WORKER_EXIT,
): int {
    $id = ++$this->id;
    $coordinator = CoordinatorManager::until($identifier);
    $this->closures[$id] = true;

    try {
        go(function () use ($timeout, $closure, $coordinator, $id): void {
            $round = 0;

            try {
                ++Timer::$count;

                while (isset($this->closures[$id])) {
                    $isClosing = $coordinator->yield(max($timeout, 0.000001));

                    if (! isset($this->closures[$id])) {
                        break;
                    }

                    $result = null;

                    try {
                        $result = $closure($isClosing);
                    } catch (Throwable $exception) {
                        if ($this->logger !== null) {
                            $this->logger->error((string) $exception);
                        } else {
                            error_log((string) $exception);
                        }
                    }

                    if ($result === self::STOP || $isClosing) {
                        break;
                    }

                    ++$round;
                    ++Timer::$round;
                }
            } finally {
                unset($this->closures[$id]);
                Timer::$round -= $round;
                --Timer::$count;
            }
        });
    } catch (Throwable $exception) {
        unset($this->closures[$id]);
        throw $exception;
    }

    return $id;
}
```

Apply the same synchronous coordinator capture and registration rollback to `after()`, preserving its zero-timeout behavior exactly:

```php
public function after(
    float $timeout,
    callable $closure,
    string $identifier = Constants::WORKER_EXIT,
): int {
    $id = ++$this->id;
    $coordinator = CoordinatorManager::until($identifier);
    $this->closures[$id] = true;

    try {
        go(function () use ($timeout, $closure, $coordinator, $id): void {
            try {
                ++Timer::$count;

                $isClosing = match (true) {
                    $timeout > 0 => $coordinator->yield($timeout),
                    $timeout === 0.0 => $coordinator->isClosing(),
                    default => $coordinator->yield(),
                };

                if (isset($this->closures[$id])) {
                    $closure($isClosing);
                }
            } finally {
                unset($this->closures[$id]);
                --Timer::$count;
            }
        });
    } catch (Throwable $exception) {
        unset($this->closures[$id]);
        throw $exception;
    }

    return $id;
}
```

Keep the tick callback error-reporting behavior inline; a one-use helper would add indirection without creating a useful abstraction.

### 3. Make coroutine test teardown exhaustive

#### Problem

`RunTestsInCoroutine` invokes teardown hooks and cleanup sequentially. Any exception prevents context cleanup, native timer cleanup, `WORKER_EXIT` resume, and manager clear. That is a direct hang path when a child is waiting for worker exit.

#### Decision

Capture the first throwable and run every independent cleanup action. The test body exception remains primary; otherwise the first teardown failure is thrown. Later cleanup failures must not replace it. There is no suppressed-exception facility in PHP, so do not invent an exception aggregate solely for teardown.

```php
$capture = static function (callable $callback) use (&$exception): void {
    try {
        $callback();
    } catch (Throwable $throwable) {
        $exception ??= $throwable;
    }
};

try {
    // setup and test body
} catch (Throwable $throwable) {
    $exception = $throwable;
} finally {
    if ($shouldBootFramework) {
        $this->invokeTearDownInCoroutine($capture);
    }

    $capture(fn () => $this->cleanupTestContext());
    $capture(fn () => Timer::clearAll());

    $capture(fn () => CoordinatorManager::until(Constants::WORKER_EXIT)->resume());
    $capture(fn () => CoordinatorManager::clear(Constants::WORKER_EXIT));
}
```

Change the existing teardown helper to run each hook independently through that callback while preserving its current ordering:

```php
protected function invokeTearDownInCoroutine(callable $capture): void
{
    if (method_exists($this, 'tearDownInCoroutine')) {
        $capture(fn () => $this->tearDownInCoroutine());
    }

    foreach (class_uses_recursive(static::class) as $trait) {
        $method = 'tearDown' . class_basename($trait) . 'InCoroutine';

        if (method_exists($this, $method)) {
            $capture(fn () => $this->{$method}());
        }
    }
}
```

### 4. Move Mockery verification into test-case ownership and harden the subscriber fallback

#### Problem

The global finished subscriber calls `Mockery::close()` before framework state resets. An unmet expectation skips every reset. PHPUnit also treats subscriber exceptions as extension warnings, not as failures belonging to the test that created the expectation. Foundation tests do not currently close Mockery from their own lifecycle.

#### Decision

Move the shared trait to `Hypervel\Testing\Concerns\InteractsWithMockery` and use it from:

- `tests/TestCase.php`;
- `Hypervel\Testbench\PHPUnit\TestCase`;
- `Hypervel\Foundation\Testing\TestCase`.

Delete the old Testbench trait. Update imports and package autoload references; do not retain a forwarding alias.

The trait keeps assertion counting and `Mockery::close()`. Each base test case invokes it from `tearDown()` even if its framework teardown throws, preserving the first exception:

```php
protected function tearDown(): void
{
    $exception = null;

    try {
        if (! $this->withoutBootingFramework()) {
            $this->tearDownTheTestEnvironment();
        }
    } catch (Throwable $throwable) {
        $exception = $throwable;
    }

    try {
        $this->tearDownTheTestEnvironmentUsingMockery();
    } catch (Throwable $throwable) {
        $exception ??= $throwable;
    }

    if ($exception !== null) {
        throw $exception;
    }
}
```

The subscriber remains a fallback for arbitrary PHPUnit base classes. It preserves the existing callback-before-Mockery order, captures either failure, performs every framework reset, then rethrows the first failure:

```php
public function flushStateAfterTest(): void
{
    $exception = null;

    try {
        AfterEachTestCleanup::runCallbacks();
    } catch (Throwable $throwable) {
        $exception = $throwable;
    }

    try {
        Mockery::close();
    } catch (Throwable $throwable) {
        $exception ??= $throwable;
    }

    $this->flushFrameworkState();

    if ($exception !== null) {
        throw $exception;
    }
}
```

Treat `DatabaseConnectionResolver::flushCachedConnections()` as throwable resource cleanup rather than a pure static reset. Run it through the same first-failure capture before `flushFrameworkState()`, so an invalid or already-closed pooled wrapper cannot skip the remaining authoritative framework-static reset list.

Remove `Mockery::close()` from `flushFrameworkState()`. Framework `flushState()` methods are designed as no-throw reset boundaries; do not allocate an array of hundreds of closures on every test merely to guard hypothetical contract violations.

`tests/TestCase.php` must also guard its own pre-Mockery cleanup. Run `HandleExceptions::flushState($this)` and `tearDownTheTestEnvironmentUsingMockery()` as independent captured actions so an exception from the first cannot skip expectation verification.

Update both Mockery guidance and the general teardown guidance in `AGENTS.md`: framework base cases own Mockery verification, while the extension provides fallback verification and authoritative framework-static cleanup. Tests must not add ad hoc `Mockery::close()` calls, but the shared base-case trait is the intentional exception to the general rule against duplicating subscriber-owned cleanup. Update `docs/ai/differences-vs-laravel.md`, which also says Mockery is handled only globally. Search all current component guidance for the old convention and leave no instruction that tells test authors or porting agents to rely exclusively on the subscriber for expectation verification.

### 5. Correct the queue worker's timer, signal, and kill lifecycle

#### Problem

Queue worker tests install real signal handlers and detached timers through duplicated callable seams. The worker does not own or clear its monitor ID on every daemon exit. `monitorLocked` remains true if timeout processing throws. `kill()` waits for active job coroutines even though the timeout path is specifically declaring the worker unrecoverable. The test worker's recording-only `sleep()` creates the captured CPU loop.

#### Decision

Inject one optional `Coordinator\Timer` dependency into `Worker`; default it to a real timer. Remove both monitor callables: the constructor `$monitorTimeoutJobs` and `daemon()`'s optional callback. Store the timer and its returned ID. The monitor interval has one source of truth: use `WorkerOptions::$monitorInterval`, which is populated by the documented `--monitor-interval` command option, and remove the redundant Worker constructor interval.

```php
public function __construct(
    /* existing dependencies */,
    ?Timer $timer = null,
) {
    $this->timer = $timer ?? new Timer;
}

protected function monitorTimeoutJobs(WorkerOptions $options): void
{
    if ($this->monitorId !== null) {
        return;
    }

    $this->monitorId = $this->timer->tick(
        $this->monitorInterval,
        function () use ($options): void {
            $this->withCoroutineContext($options, function () use ($options): void {
                if ($this->monitorLocked) {
                    return;
                }

                $this->monitorLocked = true;

                try {
                    $this->terminateTimeoutJobs($options);

                    if ($this->hasTimeoutJobs()) {
                        $this->shouldQuit = true;
                        $this->kill(static::EXIT_SUCCESS, $options);
                    }
                } finally {
                    $this->monitorLocked = false;
                }
            });
        },
    );
}
```

Wrap the daemon body in `try/finally` and clear only the monitor this worker owns:

```php
$this->monitorTimeoutJobs($options);

try {
    while (true) {
        // The existing daemon loop, including all of its current return paths.
    }
} finally {
    if ($this->monitorId !== null) {
        $this->timer->clear($this->monitorId);
        $this->monitorId = null;
    }
}
```

Do not extract a one-use `runDaemonLoop()` solely to create the `try/finally`; wrap the existing monitor registration and daemon loop directly so ownership remains visible where the timer is acquired.

`kill()` dispatches its stopping event and immediately invokes the process-kill boundary. It must not wait for unrelated active coroutines after deciding a timed-out job requires process death. Under concurrency greater than one this intentionally terminates healthy sibling jobs too: once any job has exceeded its hard timeout, the worker process is poisoned and cannot safely remain alive merely to drain unrelated work. Pin that behavior with a regression test. Preserve `$timeoutJobIds`, because `process()` uses them after `fire()` to suppress `JobProcessed` for timed-out work.

```php
public function kill(int $status = 0, ?WorkerOptions $options = null): never
{
    $this->events->dispatch(new WorkerStopping($status, $options));
    $this->terminateProcess($status);
}

/**
 * Terminate the current worker process immediately.
 */
protected function terminateProcess(int $status): never
{
    if (extension_loaded('posix')) {
        posix_kill(getmypid(), SIGKILL);
    }

    exit($status);
}
```

The protected process boundary is the test seam. Production still has an immediate terminal path; tests override it by throwing a sentinel exception instead of killing PHPUnit. Do not inject a general process controller into `Worker` for this one terminal operation.

In `InsomniacWorker`:

```php
public function sleep(int|float $seconds): void
{
    $this->sleptFor = $seconds;
    parent::sleep(0);
}

protected function supportsAsyncSignals(): bool
{
    return false;
}
```

Keep `InsomniacWorker::$sleptFor` scalar and type it as `int|float|null`, preserving the two existing single-sleep assertions. The regression needs scheduler progress, not a new sleep-history API.

No extra production yield is needed. Production's hooked `usleep(0)` already yields. `Coroutine::yield()` is not a substitute: it parks until an explicit resume and would introduce a new hang.

### 6. Bound Horizon persistence and termination

#### Problem

`Supervisor::loop()` and `MasterSupervisor::loop()` detach persistence into `go()` to accommodate a test retry. This allows overlapping and reordered writes and moves failures outside the initiating loop's exception boundary. `Supervisor::terminate()` waits without a deadline. ProcessPool's timeout fallback calls Symfony's default `stop()`, which can itself wait for another long timeout.

#### Decision

Persist synchronously before dispatching the looped event:

```php
$this->persist();
event(new SupervisorLooped($this));
```

Coroutine-hooked Redis yields the current coroutine while I/O is pending; it does not block the worker. Remove detached-persistence imports, nested exception reporting, retry comments, and tests that only compensate for eventual persistence.

Terminate a supervisor through the existing pool state machine:

```php
$this->working = false;
app(SupervisorRepository::class)->forget($this->name);

$this->processPools->each->scale(0);

if ($this->shouldWait()) {
    while ($this->processPools->map->runningProcesses()->collapse()->isNotEmpty()) {
        sleep(1);
    }
}

$this->shouldExitLoop = true;
```

`scale(0)` moves active workers into `terminatingProcesses`, records `terminatedAt`, and sends graceful termination. `runningProcesses()` prunes that set, applying each pool's configured timeout.

Add an immediate hard-stop primitive:

```php
public function kill(): void
{
    if ($this->process->isRunning()) {
        $this->process->stop(0);
    }
}
```

`ProcessPool::stopTerminatingProcessesThatAreHanging()` calls `kill()` after the configured grace period. `MasterSupervisor::terminate()` retains its longest-active-timeout deadline but hard-stops any supervisor process still running after the deadline instead of merely abandoning it. `SupervisorProcess` extends `WorkerProcess`, so the same public immediate kill primitive is the required mechanism:

```php
$runningSupervisors = $this->supervisors->filter(
    fn (SupervisorProcess $supervisor): bool => $supervisor->isRunning(),
);

if ($deadlineExpired) {
    $runningSupervisors->each->kill();
    break;
}
```

Retain the existing master/supervisor repository cleanup, fast-termination cache cleanup, working flags, and loop-exit flags around these replacements.

Protect child control signals during process bootstrap. Before `WorkerProcess::start()` invokes Symfony Process, block the exact signal set handled by that child and capture the parent's prior mask. The fork/exec child inherits the blocked mask, so an early signal remains pending rather than taking its default action. Restore the parent's exact prior mask in exhaustive cleanup, preserving the first start or restoration failure. Queue workers block SIGQUIT, SIGTERM, SIGINT, SIGUSR2, and SIGCONT; `SupervisorProcess` overrides the set with SIGTERM, SIGUSR1, SIGUSR2, and SIGCONT.

After queue `Worker::listenForSignals()` and Horizon `ListensForSignals::listenForSignals()` install their handlers, each unblocks exactly the set it registered. Pending control signals are then delivered to the installed asynchronous handlers. Keep the sets as protected constants on their owning classes/trait and assert parent-block and child-handler equality in tests so cross-package drift cannot silently leave a signal unprotected or permanently blocked.

Do not replace the manual block/restore boundary with Symfony Process `setIgnoredSignals()`. Symfony suppresses sends for ignored signals when PHP uses SIGCHLD handling, but Horizon must continue sending these signals through `Process::signal()`. The manual mask changes only fork-time inheritance and does not classify the signals as ignored.

Horizon test teardown must own both active and terminating processes. In `finally`, request graceful termination, then unconditionally hard-stop remaining real child processes and reap them. Delete the stale teardown TODO and any polling whose sole purpose was waiting for detached persistence.

### 7. Add bounded striped-lock acquisition without burdening the API

#### Problem

Cache and Reverb locks can spin forever if a process dies while holding a stripe. Reverb has no backoff at all, so contention consumes a full core. Reverb also logs from inside the lock when its lock table is full, extending and potentially yielding in the critical section.

#### Decision

Use the same bounded internal policy in both state objects:

```php
protected const LOCK_ACQUIRE_TIMEOUT_NANOSECONDS = 1_000_000_000;
protected const SPINS_BEFORE_BACKOFF = 64;

protected function acquire(Atomic $lock): void
{
    $deadline = null;
    $spins = 0;

    while (! $lock->cmpset(0, 1)) {
        $deadline ??= hrtime(true) + static::LOCK_ACQUIRE_TIMEOUT_NANOSECONDS;

        if (++$spins < static::SPINS_BEFORE_BACKOFF) {
            continue;
        }

        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out acquiring a Swoole table state lock.');
        }

        $spins = 0;
        usleep(1);
    }
}
```

Use `static::` for the protected constants so tests can shorten only the timeout with a subclass. The deadline is initialized lazily after the first failed compare-and-set, so the normal uncontended path remains exactly one compare-and-set. The fixed one-second failure bound is an internal invariant, not new user configuration.

Because `acquire()` can now throw, Cache's all-stripe acquisition must include the acquisition loop inside the protected region and release only successfully acquired locks:

```php
$acquired = [];

try {
    foreach ($this->rowLocks as $lock) {
        $this->acquire($lock);
        $acquired[] = $lock;
    }

    return $callback();
} finally {
    while ($lock = array_pop($acquired)) {
        $this->release($lock);
    }
}
```

This prevents a timeout on stripe N from permanently leaking stripes 0 through N-1.

Reverb's `setLockRow()` returns status only. Its callers release the stripe first, then call a shared reporting method if insertion failed:

```php
$stored = false;

try {
    $stored = $this->setLockRow($key, $timestamp);
} finally {
    $this->release($lock);
}

if (! $stored) {
    $this->reportFullLockTable($key);
}
```

Keep all critical sections short, non-yielding, and free of logging or arbitrary callbacks. Replace comments that say holder death leaves a permanent lock with the new bounded-failure contract.

For `tryLock()`, preserve its live-marker early return and defer reporting until after release:

```php
$stored = false;

try {
    $row = $this->lockTable->get($key, 'locked_at');
    $now = microtime(true);

    if ($row !== false && ($now - (float) $row) < ($ttlMs / 1000.0)) {
        return false;
    }

    $stored = $this->setLockRow($key, $now);
} finally {
    $this->release($lock);
}

if (! $stored) {
    $this->reportFullLockTable($key);
}

return $stored;
```

The early `return false` still runs `finally`, while only a failed write reaches the post-release reporter.

### 8. Make compiled route object caching collection-owned

#### Problem

The static name cache is keyed only by route name, but separate compiled collections can use the same name for different routes. Replacing the collection therefore does not establish object identity unless every replacement site remembers a global flush. Tests can receive stale routes when cleanup was skipped.

#### Decision

Make the cache an instance property:

```php
/** @var array<string, Route> */
protected array $cachedRoutesByName = [];

public function getByName(string $name): ?Route
{
    if (isset($this->attributes[$name])) {
        return $this->cachedRoutesByName[$name]
            ??= $this->newRoute($this->attributes[$name]);
    }

    return $this->routes->getByName($name);
}
```

Delete `CompiledRouteCollection::flushCache()`, remove its subscriber reset, and remove it from `Router::flushRoutingCaches()`. Update warmup and cache comments to say collection-owned rather than worker-global. Keep the other genuinely static routing-cache resets.

### 9. Isolate programmatic console verbosity

#### Problem

`Application::call()` is a programmatic API, but Symfony's root `run()` wrapper reads and writes process-global terminal and `SHELL_VERBOSITY` state. A verbose parent runner therefore silently makes route and schedule commands verbose, changing tested output. Saving, clearing, and restoring those globals would race between coroutines.

Hypervel already solves the real shared command-state problem at the lower boundary: `freshCommandForRun()` clones every command before execution. Symfony's remaining Application state does not justify serialization for programmatic calls:

- `runningCommand` is only observed by `run()`'s exception renderer, which the programmatic path bypasses;
- `wantHelps` is set, consumed, and reset synchronously without a coroutine yield;
- existing concurrent and nested command tests prove that per-execution command clones isolate input and output state.

A per-Application mutex would therefore guard no observable failure and would deadlock a command such as `schedule:run` when it waits for a child coroutine that calls the same Application.

#### Decision

Keep the existing coroutine-local output buffer. Do not copy Symfony Tester's save/unset/restore workaround and do not add a programmatic-mode context flag: `call()` already owns the programmatic path and can invoke its dedicated IO configuration directly.

```php
public function call(
    string|SymfonyCommand $command,
    array $parameters = [],
    ?OutputInterface $outputBuffer = null,
): int {
    [$command, $input] = $this->parseCommand($command, $parameters);

    if (! $this->has($command)) {
        throw new CommandNotFoundException(sprintf(
            'The command "%s" does not exist.',
            $command,
        ));
    }

    return $this->runProgrammatically(
        $input,
        CoroutineContext::set(
            self::LAST_OUTPUT_CONTEXT_KEY,
            $outputBuffer ?: new BufferedOutput,
        ),
    );
}

protected function runProgrammatically(
    InputInterface $input,
    OutputInterface $output,
): int {
    $this->configureProgrammaticIO($input, $output);

    return $this->doRun($input, $output);
}
```

Place this synchronization comment on the real method so future Symfony upgrades surface the deliberate boundary during source review:

```php
/**
 * Run a command without Symfony's process-global CLI wrapper.
 *
 * Symfony's run() owns root CLI concerns: terminal environment export,
 * process-global exception handling, shell-verbosity restoration, and
 * auto-exit. Programmatic calls need explicit IO configuration and doRun() only.
 * Keep this boundary aligned with Symfony through the parity tests.
 *
 * @see SymfonyApplication::run()
 */
```

Add `configureProgrammaticIO()` with Symfony's current explicit input-option handling, a normal default, and no process-global writes. Root CLI execution keeps Symfony's inherited `configureIO()` unchanged:

```php
protected function configureProgrammaticIO(InputInterface $input, OutputInterface $output): void
{
    if ($input->hasParameterOption(['--ansi'], true)) {
        $output->setDecorated(true);
    } elseif ($input->hasParameterOption(['--no-ansi'], true)) {
        $output->setDecorated(false);
    }

    $shellVerbosity = match (true) {
        $input->hasParameterOption(['--silent'], true) => -2,
        $input->hasParameterOption(['--quiet', '-q'], true) => -1,
        $input->hasParameterOption('-vvv', true)
            || $input->hasParameterOption('--verbose=3', true)
            || 3 === $input->getParameterOption('--verbose', false, true) => 3,
        $input->hasParameterOption('-vv', true)
            || $input->hasParameterOption('--verbose=2', true)
            || 2 === $input->getParameterOption('--verbose', false, true) => 2,
        $input->hasParameterOption('-v', true)
            || $input->hasParameterOption('--verbose=1', true)
            || $input->hasParameterOption('--verbose', true)
            || $input->getParameterOption('--verbose', false, true) => 1,
        default => 0,
    };

    $output->setVerbosity(match ($shellVerbosity) {
        -2 => OutputInterface::VERBOSITY_SILENT,
        -1 => OutputInterface::VERBOSITY_QUIET,
        1 => OutputInterface::VERBOSITY_VERBOSE,
        2 => OutputInterface::VERBOSITY_VERY_VERBOSE,
        3 => OutputInterface::VERBOSITY_DEBUG,
        default => $output->getVerbosity(),
    });

    if ($shellVerbosity < 0
        || $input->hasParameterOption(['--no-interaction', '-n'], true)
    ) {
        $input->setInteractive(false);
    }
}
```

`runProgrammatically()` deliberately calls `configureProgrammaticIO()` and `doRun()` directly instead of Symfony's root `run()` wrapper. Hypervel has exception catching disabled for this API, and bypassing that wrapper avoids its process-global exception-handler, terminal export, and `SHELL_VERBOSITY` save/restore machinery. The normal root CLI path still delegates to Symfony's `run()` and keeps shell-verbosity semantics. Programmatic calls ignore inherited `SHELL_VERBOSITY`, honor explicit `--silent`, `-q`, `-v`, `-vv`, and `-vvv`, never mutate process-global environment, and preserve Hypervel's coroutine-local `output()` behavior. Do not modify `PendingCommand`: every programmatic caller deserves the same boundary.

Keep the source comment on `runProgrammatically()` and focused behavioral parity tests as the Symfony-upgrade tripwire. Cover command execution, arguments and options, output, exit code, exception propagation, ANSI decoration, interaction flags, quiet/explicit verbosity, help handling, and console event ordering. Run `run()`-versus-`call()` verbosity-mapping parity with `SHELL_VERBOSITY` absent from `getenv()`, `$_ENV`, and `$_SERVER`, so the intended inherited-default divergence does not mask a future change to Symfony's explicit option mapping. Retain the existing concurrent and nested command-cloning regressions rather than adding serialization assertions. Nested calls continue through `doRun()` and retain their normal Application event lifecycle. Do not assert Symfony source text or a source hash; harmless upstream refactors must not fail the suite.

Delete the mutex, depth constant, context restoration, `runNestedProgrammatically()`, LazyCommand unwrapping, and tests that assert serialized execution or suppressed nested events. They solve no observed state problem and conflict with legitimate child-coroutine command execution.

### 10. Add a real discard operation to connection pools

#### Problem

The testing database resolver checks out a pooled wrapper, extracts its bare `Connection`, and abandons the wrapper. The pool continues to count it as borrowed forever. Disconnecting the bare PDO does not clear pool ownership or restore capacity. The current docblock explicitly rationalizes this leak.

#### Decision

Add explicit discard to the existing full connection-pool contracts. The abbreviated snippet shows only the new neighboring methods; retain every current method:

```php
interface PoolInterface
{
    public function release(ConnectionInterface $connection): void;

    public function discard(ConnectionInterface $connection): void;
}

interface ConnectionInterface
{
    public function release(): void;

    public function discard(): void;
}
```

`Pool::discard()` accepts only a connection currently borrowed from that pool, then destroys it and releases capacity:

```php
public function discard(ConnectionInterface $connection): void
{
    $this->assertBorrowed($connection, 'discard');
    $this->destroyConnection($connection);
}
```

`Connection` and `KeepaliveConnection` delegate to their owning pool:

```php
public function discard(): void
{
    $this->pool->discard($this);
}
```

Audit every implementation and test double of both contracts. Do not make `discard()` an alias of `close()` on the wrapper: pool bookkeeping must be updated atomically by the owner.

The contract boundary is intentionally narrow and already connection-specific:

- `Hypervel\Contracts\Pool\PoolInterface` is implemented by abstract `Hypervel\Pool\Pool`; `SimplePool\Pool`, `Database\Pool\DbPool`, and `Redis\Pool\RedisPool` inherit the implementation;
- `Hypervel\Contracts\Pool\ConnectionInterface` is directly implemented by `Pool\Connection`, `Pool\KeepaliveConnection`, and `Database\Pool\PooledConnection`; their SimplePool and Redis descendants inherit delegation;
- update `NonCoroutinePoolConnection`, `PoolConnectionStub`, and every anonymous/mock implementer in tests.

Do not touch `Hypervel\ObjectPool\Contracts\ObjectPool`: it is a separate object-pool hierarchy used by Sentry and `SimpleObjectPool`, and it already has correct `discard(object)` semantics.

The testing resolver stores both objects:

```php
use Hypervel\Contracts\Pool\ConnectionInterface as PoolConnectionInterface;
use Hypervel\Database\ConnectionInterface;
```

Keep the explicit alias: the bare database connection and its owning pooled wrapper implement different interfaces with the same short name.

```php
/** @var array<string, ConnectionInterface> */
protected static array $connections = [];

/** @var array<string, PoolConnectionInterface> */
protected static array $pooledConnections = [];
```

On first resolution:

```php
$pooled = $this->factory->getPool($connectionName->requested)->get();

try {
    $connection = $pooled->getConnection();

    if (! $connection instanceof ConnectionInterface) {
        throw new LogicException('The database pool returned an invalid connection.');
    }
} catch (Throwable $exception) {
    $pooled->discard();
    throw $exception;
}

static::$pooledConnections[$connectionName->requested] = $pooled;
static::$connections[$connectionName->requested] = $connection;
```

The pool owns cleanup failure handling: `destroyConnection()` already reports connection-close failures, and the channel change in section 1 makes a missed wake non-throwing after committed ownership changes. A valid borrowed wrapper's `discard()` is therefore terminal and no-throw. Do not add a second catch/report layer in the resolver merely to guard an internal ownership assertion that would indicate a framework invariant bug.

Split the misleading reset API:

- `resetCachedConnections()` resets reusable bare connections for another setup phase, detects a new container, discards old-container wrappers, and registers the dispatcher rebinding hook;
- `flushCachedConnections()` is terminal: discard every cached wrapper and clear both arrays, the container ID, and rebinding state;
- `flush($name)` discards that name's wrapper and removes both entries.

`InteractsWithTestCaseLifecycle` calls `resetCachedConnections()` after creating the application. During teardown it calls terminal `flushCachedConnections()` before `PoolFactory::flushAll()`, so borrowed wrappers are returned to pool ownership before the pool closes. The subscriber retains terminal flush as a fallback.

Delete the stale “hybrid lifecycle” and “discard is acceptable” comments. Document the actual reason the bare connection remains stable during a test: its wrapper is deliberately retained and explicitly discarded at teardown.

### 11. Bound and clean up the cache multiprocess test harness

#### Problem

The start barrier has a deadline, but parent pipe reads and child reaping do not. Any child that exits early or never writes can hang the suite. Failure paths do not release the start barrier, close pipes, kill live children, or reap owned PIDs.

#### Decision

Make every child operation owned by a single `try/finally`. Record PIDs returned from `start()`. Put pipes in nonblocking mode and poll them against a monotonic deadline. Prefix each serialized payload with a fixed-width length, enforce a small maximum frame size, and accumulate reads until the complete frame arrives; do not assume one pipe read returns one write.

```php
try {
    foreach ($processes as $process) {
        $pid = $process->start();

        if ($pid === false) {
            throw new RuntimeException('Unable to start cache concurrency child.');
        }

        $pids[$pid] = $process;
        $process->setBlocking(false);
    }

    $this->waitForReadyProcesses($ready, $count);
    $start->set(1);

    foreach ($pids as $pid => $process) {
        $payload = $this->readChildPayload($process, $pid, $deadline);
        // validate ok/result/error protocol
    }
} finally {
    $start->set(1);

    foreach ($pids as $pid => $process) {
        if (Process::kill($pid, 0)) {
            Process::kill($pid, SIGKILL);
        }

        $process->close();
    }

    $this->reapOwnedChildren($pids);
}
```

`readChildPayload()` loops on nonblocking `read()`, checks whether the child is still alive, uses `hrtime(true)` for its deadline, and sleeps briefly between empty reads. `reapOwnedChildren()` uses `pcntl_waitpid($pid, $status, WNOHANG)` for each recorded PID against a short deadline. Treat the PID result as reaped, and treat `PCNTL_ECHILD` as already collected; retry `PCNTL_EINTR` and throw a descriptive error for any other wait failure. It never calls global `Process::wait()`, so it cannot consume an unrelated child's status.

The child catches `Throwable` and writes an explicit `{ok,error}` payload through a write-all helper that handles partial writes. The forced `SIGKILL` remains necessary to avoid inherited PHPUnit/Testbench shutdown handlers deleting the parent's runtime app. Apply the same bounded `WNOHANG` reap rule to the Reverb lock regression; it must not finish cleanup with an unbounded blocking `waitpid()`.

### 12. Give Engine socket tests ephemeral ports and unconditional teardown

Use `new Server('127.0.0.1', 0)` and read the assigned `$server->port`. The native constructor binds and listens immediately; signal readiness through a capacity-one channel after handler registration and immediately before `start()`. Do not probe readiness with a synthetic client connection: that connection executes the real handler and can fill completion channels or make assertions pass for the wrong client.

```php
$ready = new Channel(1);
$finished = new WaitGroup(1);
$serverErrors = [];

go(function () use ($server, $ready, $finished, &$serverErrors): void {
    try {
        $server->handle(/* parent-visible error capture */);
        $ready->push(true);
        $server->start();
    } catch (Throwable $exception) {
        $serverErrors[] = $exception;
    } finally {
        $finished->done();
    }
});

try {
    if ($ready->pop(0.5) !== true) {
        throw $serverErrors[0] ?? new RuntimeException('The Engine test server did not become ready.');
    }

    // Connect the one real client and close it in its own finally block.
} finally {
    $server->shutdown();
    $finished->wait(1.0);
    $ready->close();
}
```

Child server coroutines capture errors into parent-owned state and are joined through a bounded primitive; they do not invoke PHPUnit assertions asynchronously. Replace every fixed `9506`, every success-only shutdown, every active readiness probe, and any server created without being started or closed.

### 13. Make the Watcher own and observe its asynchronous work

#### Problem

`DriverInterface::watch()` has no consistent lifetime contract. `FswatchDriver` blocks for the lifetime of its subprocess, while `FindDriver`, `FindNewerDriver`, and `ScanFileDriver` register detached coordinator timers and return immediately. `Watcher` therefore cannot interpret a return as either successful startup or terminal completion.

The old Watcher starts the driver through the raw Engine coroutine. An uncaught Fswatch failure can escape fatally from that coroutine, while polling-driver callback failures are caught and logged by `Timer` outside Watcher's ownership. The current WaitGroup implementation is also incorrect: it treats the three polling drivers' immediate return as completion and stops them as soon as they start.

The lower-level driver contract must be made consistent before Watcher observes it.

`ServerRestartStrategy` has the same ownership gap: its server coroutine pops the single launch token but only pushes it back on the success path. An exception from `proc_open()` or `proc_close()` permanently prevents later restart attempts. It also uses the raw Engine coroutine without an internal catch, so either exception is process-fatal. Its PID file is cast without validation; empty or corrupted contents can become PID 0 (the entire process group) or an unrelated numeric-prefix PID.

#### Decision

Define `DriverInterface::watch()` as a blocking, single-lifecycle operation: it returns only after `stop()` unblocks it or the driver reaches terminal completion, and it throws terminal failures to its caller. `stop()` remains public and idempotent.

Polling drivers do not need a detached `Timer`. Move the shared `$stopping` flag to `AbstractDriver`, give it one nullable owned stop channel, and add a small interval-loop helper. Remove the abstract driver's Timer property, timer ID, import, destructor, and `FindNewerDriver`'s duplicate stopping property. Waiting on the stop channel with the scan interval as its timeout both preserves timer cadence and lets `stop()` wake the driver immediately. The scan callback runs in the driver coroutine, so failures propagate naturally instead of being caught by an unrelated Timer coroutine.

```php
protected function watchAtInterval(float $seconds, callable $scan): void
{
    if ($this->stopping) {
        return;
    }

    $stopSignal = $this->stopSignal = new Channel(1);

    try {
        while (! $this->stopping) {
            $signal = $stopSignal->pop($seconds);

            if ($signal !== false || ! $stopSignal->isTimeout()) {
                return;
            }

            $scan();
        }
    } finally {
        if (! $stopSignal->isClosing()) {
            $stopSignal->close();
        }

        $this->stopSignal = null;
    }
}

public function stop(): void
{
    if ($this->stopping) {
        return;
    }

    $this->stopping = true;
    $this->stopSignal?->close();
}
```

Create the stop channel lazily inside the owned driver coroutine and remove `AbstractDriver::__destruct()`: native channel teardown must be explicit and must not run after Swoole has destroyed the channel handle. A driver instance owns one watch lifecycle and is not restarted after `stop()`.

`FindDriver`, `FindNewerDriver`, and `ScanFileDriver` call `watchAtInterval()` with their existing scan bodies. Preserve `FindNewerDriver`'s scanning reference-file ownership while replacing its timer registration: its override calls `parent::stop()` first, then removes reference files immediately only when no scan is active; an active scan retains the existing deferred-finally cleanup. `FswatchDriver` remains a blocking process loop and its `stop()` calls the parent stop before terminating the subprocess and closing its pipes. Buffer incomplete `fread()` data between reads, emit only newline-terminated paths, and process any final buffered path before treating EOF as terminal. A read can split a path at any byte; exploding each chunk independently loses changes under a large batch.

Add public idempotent `stop(): void` to `RestartStrategy`. `ServerRestartStrategy` implements it by stopping the managed server. `Horizon\Console\HorizonRestartStrategy`, the other implementer, changes its existing protected `stop()` to public; its nullable-process/running check is already idempotent and matches the contract.

With every driver now blocking, `Watcher::run()` can own one `WaitGroup` for the driver coroutine and a shared child failure slot. The child always completes the wait group; the parent checks the non-blocking `count()` before each existing millisecond channel poll, then rethrows the captured failure or returns after a clean driver exit.

```php
$driverFinished = new WaitGroup(1);
$driverFailure = null;
$driverStarted = false;
$exception = null;
$capture = static function (callable $callback) use (&$exception): void {
    try {
        $callback();
    } catch (Throwable $throwable) {
        $exception ??= $throwable;
    }
};

try {
    $this->strategy?->start();

    Coroutine::create(function () use (
        $channel,
        $driverFinished,
        &$driverFailure,
    ): void {
        try {
            $this->driver->watch($channel);
        } catch (Throwable $throwable) {
            $driverFailure = $throwable;
        } finally {
            $driverFinished->done();
        }
    });
    $driverStarted = true;

    while ($driverFinished->count() > 0) {
        // Existing debounce and restart handling.
    }

    if ($driverFailure !== null) {
        throw $driverFailure;
    }

    while ($channel->getLength() > 0) {
        $file = $channel->pop();
        $this->output->writeln('<info>File changed:</info> ' . $file);
        $result[] = $file;
    }

    if ($result !== []) {
        $this->strategy?->restart();
    }
} catch (Throwable $throwable) {
    $exception = $throwable;
} finally {
    $capture(fn () => $this->driver->stop());
    $capture(fn () => $this->strategy?->stop());
    $capture(fn () => $channel->close());

    $capture(function () use ($driverStarted, $driverFinished): void {
        if ($driverStarted && ! $driverFinished->wait(1.0)) {
            throw new RuntimeException('The file watcher did not stop within one second.');
        }
    });
}

if ($exception !== null) {
    throw $exception;
}
```

`WaitGroup::wait(0.0)` must not be used as a poll: native `Channel::pop(0.0)` blocks on an empty channel. Preserve a primary driver/restart exception if bounded cleanup also fails, using the same first-exception rule as other teardown in this plan. Cleanup stops the driver and strategy, closes the owned change channel, then performs the positive one-second join. `stop()` interrupts the polling interval and Fswatch process waits, while closing the change channel interrupts a driver blocked while pushing a large batch. The join is therefore a contract assertion, not a configurable watch timeout.

The post-completion drain is required because a synchronous driver can fill the buffered channel and exit before the parent enters the loop. The final restart is independently required because the last item can be popped immediately before the wait-group count reaches zero, without allowing the debounce timeout branch to run.

`ServerRestartStrategy::launchServer()` uses the high-level reporting coroutine and restores its launch token in `finally` after a successful pop:

```php
Coroutine::create(function (): void {
    $this->channel->pop();

    try {
        $process = $this->openProcess($descriptorSpec, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to launch the watched server process.');
        }

        $this->closeProcess($process);
    } finally {
        $this->channel->push(1);
    }
});
```

Use protected `openProcess()` and `closeProcess()` wrappers only as deterministic native test boundaries; retain precise resource docblocks. A launch or close failure is a genuine asynchronous error and is reported by the high-level coroutine wrapper rather than killing the watcher.

Before stopping a server, trim the PID file, require every character to be a digit, convert to `int`, and reject non-positive values before output or event dispatch. Model `BeforeServerRestart::$pid` as `int`. Route both the liveness probe and SIGTERM through one protected `signalProcess()` native boundary. This prevents PID 0 process-group signals and numeric-prefix corruption without adding a process abstraction.

Keep the argv-array command boundary already established by the watcher package. Do not reintroduce shell tokenization. Do not add a general driver task, cancellation, or event abstraction: one owned coroutine, one stop channel for polling drivers, and the existing change channel express the complete lifecycle.

### 14. Replace hand-written coroutine joins where the channel is not under test

`ListenerContextIsolationTest` should use `parallel()`:

```php
$results = parallel([
    'a' => fn (): array => $this->recordQueries('conn-a', ['SELECT a1', 'SELECT a2']),
    'b' => fn (): array => $this->recordQueries('conn-b', ['SELECT b1']),
]);
```

This propagates child exceptions and owns the wait group. Do not mechanically replace tests whose subject is channel behavior.

For Redis integration tests that explicitly instantiate a channel-based `Subscriber`, wrap the subscriber lifetime in `try/finally` and close it there. The callback subscription API already owns cleanup through `RedisProxy::handleSubscribe()` and `CommandInvoker`'s worker-exit watcher; do not add a second public cancellation API.

### 15. Fix empty-window frequency calculation

```php
public function frequency(): float
{
    $this->flush();

    $sampleCount = count($this->hits);

    if ($sampleCount === 0) {
        return 0.0;
    }

    return array_sum($this->hits) / $sampleCount;
}
```

This preserves the existing average-per-populated-second calculation, including zero-filled seconds created by `flush()`. It only defines the previously undefined empty sample immediately after construction. Do not seed a fake hit or change low-frequency thresholds.

### 16. Verify Testbench process identity before killing a PID

#### Problem

`Bootstrapper::isOrphanedServeProcess()` can act on a reused PID because PPID 1 and a PID-file match do not prove the process is the Testbench server that created the runtime directory. The current owner-token implementation closes that gap only for `remote('serve')`; a direct `vendor/bin/testbench serve` receives no injected token, writes no marker, and can never be positively identified for later cleanup.

#### Decision

Identify the process itself rather than one launch path. When a runtime copy is created, write a private marker containing the current PID and its OS process-start identity. On Linux use `/proc/<pid>/stat` field 22; parse after the command's closing parenthesis so spaces or parentheses in the command name cannot shift fields. On macOS use the trimmed `ps -p <pid> -o lstart=` value. The marker needs no secret: it distinguishes process incarnations after PID reuse and is not an authentication boundary against the same local user.

macOS `lstart` has one-second resolution, unlike Linux's clock-tick start identity. Record that bound in the reader's source comment. Do not add another token, elapsed-time reconstruction, or other hardening: matching an orphaned PID, same-second PID reuse, and the same validated serve command is not a realistic remaining collision.

Require all of:

1. the PID file parses to the queried positive PID;
2. the process is alive and its parent is PID 1;
3. its command line identifies a Hypervel/Testbench `serve` command;
4. the PID and process-start identity exactly match the marker written when the runtime directory was created.

```php
$startIdentity = static::processStartIdentity(getmypid());

if ($startIdentity !== null) {
    $filesystem->replace(
        join_paths($runtimePath, '.testbench-process'),
        json_encode([
            'pid' => getmypid(),
            'started_at' => $startIdentity,
        ], JSON_THROW_ON_ERROR),
        0600,
    );
}
```

Read and validate the marker as a typed two-field record. Compare the stored start identity to a fresh reading for the candidate PID. On Linux, read `/proc/<pid>/cmdline`; on macOS, use `ps -p <pid> -o command=`. If identity cannot be positively proven, do not signal the live process or delete its runtime directory. Dead-PID cleanup remains unchanged because there is no live process to misidentify.

Extract small protected readers only where necessary to test OS data independently:

```php
protected static function processCommand(int $pid): ?string;

protected static function processStartIdentity(int $pid): ?string;
```

Delete `TESTBENCH_RUNTIME_OWNER`, its `RemoteCommand` injection, environment parser, token tests, and token commentary. Keep `TESTBENCH_BASE_PATH` behavior unchanged: `serve` still creates its own runtime copy rather than reusing its parent's. Do not add a general process-inspection service for one bootstrap cleanup boundary.

### 17. Remove stale implementation guidance and document test ownership

Update or remove all commentary made false by this design:

- detached Horizon persistence and retry guidance;
- the database resolver's abandoned-wrapper rationale;
- permanent-lock comments in Cache and Reverb;
- worker-global compiled-route cache and reset guidance;
- queue monitor callback seams and real-signal test setup;
- `AGENTS.md`'s inaccurate Mockery rule;
- `docs/ai/differences-vs-laravel.md` and any other current guidance that says Mockery closes only through global cleanup;
- coroutine documentation that says creation returns `false` or `-1`;
- console mutex/depth/nested-execution comments and tests;
- Testbench owner-token environment and process-environment parsing guidance;
- Watcher comments that describe timer-registration return as terminal driver completion.

Add a concise section to `src/boost/docs/testing.md` explaining that tests which create child coroutines, subscribers, processes, servers, or other asynchronous resources must join or close them in `finally`, and should use framework ownership primitives such as `parallel()` rather than unbounded hand-built channel joins when the channel is not the subject.

Update `src/boost/docs/coroutines.md` to document that `go()`, `co()`, `Coroutine::create()`, and `Coroutine::fork()` return a positive coroutine ID on success and throw `CoroutineCreateException` when the native runtime cannot create one.

Do not turn public documentation into an incident report. Document the resulting contracts and recommended usage only.

Do not add a Horizon README divergence note for the internal persistence and termination fixes. The repository's three-place divergence rule applies when a Laravel feature or method is intentionally omitted, not when Hypervel preserves the public behavior with safer Swoole-aware internals.

### 18. Keep cache subprocess paths and test-owned route sources deterministic

`RouteCacheCommand` and `ConfigCacheCommand` spawn fresh-application subprocesses after clearing a resolved cache path in the parent. PHP-array-only environment overrides are not inherited by Symfony Process, so each command must explicitly pass its own resolved cache path:

```php
'APP_ROUTES_CACHE' => $this->hypervel->getCachedRoutesPath(),
'APP_CONFIG_CACHE' => $this->hypervel->getCachedConfigPath(),
```

Do not switch Testbench's parallel configuration to `putenv()`: that would reintroduce process-global mutation. Event and view cache commands run in-process and need no subprocess propagation.

Add a test-only `CleanupActions` helper that executes every supplied cleanup callback, preserves the first throwable, and rethrows it after all actions run. It contains no file, application, or PHPUnit knowledge. Use it in the six tests that own route-producing Testbench state:

- API and Broadcasting install command tests;
- Route cache command tests;
- Horizon and Telescope install command tests;
- provider generator tests.

Each owner keeps its ordered resource list locally, restores bootstrap/provider files through checked atomic `Filesystem::replace()`, deletes every owned file through checked `Filesystem::delete()`, and includes `parent::tearDown()` as the final independent action. A focused helper test proves later cleanup continues and the first failure remains primary.

`RouteCacheCommandTest` also retains an assertion-only worker-state guard: runtime `bootstrap/app.php` and `bootstrap/providers.php` must match the pristine Testbench skeleton, and `routes/` must contain no PHP source files before each test. The guard reports contamination but never repairs it.

## Implementation sequence

The order is chosen so lower-level failure contracts exist before their consumers are hardened.

1. Add the Engine coroutine-creation exception and exact `int` return contract; define the native HTTP overload response.
2. Update high-level coroutine helpers, transactionally balance all pre-spawn bookkeeping including Reverb pub/sub startup, and make connection/object-pool wake failures non-throwing after committed state with one final checkout-state pass.
3. Stabilize `Coordinator\Timer` identity and creation rollback.
4. Harden `RunTestsInCoroutine` cleanup and Mockery ownership/fallback reset.
5. Add pool/connection `discard()` and repair test database resolver lifecycle.
6. Make compiled route caching instance-owned and isolate programmatic console verbosity through the dedicated global-free IO path, retaining the existing command-cloning boundary for concurrent and nested calls.
7. Refactor queue-worker timer ownership, test fake yielding, signal seams, and kill behavior.
8. Make Horizon persistence synchronous, termination bounded, and child control signals safe during process bootstrap.
9. Bound Cache/Reverb striped locks and move Reverb logging outside locks.
10. Standardize every Watcher driver as a blocking, explicitly stoppable lifecycle; then make Watcher/RestartStrategy ownership terminal, observable, and exception-safe.
11. Propagate resolved route/config cache paths into subprocesses and make all six test owners restore their Testbench files exhaustively through the shared cleanup helper.
12. Harden the cache/Reverb fork harnesses, Engine socket tests, renderer join, Redis subscriber tests, frequency calculation, Fswatch stream framing, and Testbench process-incarnation identity.
13. Remove stale code/comments/imports, update documentation and `AGENTS.md`, and run the full verification matrix.

Each step must include its focused tests before moving to the next. Do not leave temporary adapters or compatibility wrappers between steps.

## Testing plan

### Coroutine creation and bookkeeping

Use a process-isolated test or a purpose-built subprocess so changing Swoole's process-global `max_coroutine` cannot pollute the test worker.

- exhaust the native coroutine limit and assert Engine throws `CoroutineCreateException` with the native error;
- assert `Coroutine::create()`, `fork()`, `go()`, and `co()` return positive `int` IDs on success;
- assert `Concurrent` restores its channel length after spawn failure;
- assert `WaitConcurrent` balances its wait group and does not hang;
- assert `Parallel` records the failure under the correct key and produces `ParallelExecutionException` normally;
- assert `CoroutineDriver` rethrows the first failure in input order without waiting forever;
- assert `Timer` removes a just-registered closure when spawn fails;
- assert database and Redis health checks return false specifically for creation failure;
- assert `Waiter` surfaces creation failure immediately, not as a timeout;
- assert SafeSocket resets its loop flag when creation fails, treats a real native `sendAll() === false` as terminal in both caller modes, closes its channel/socket, and rejects later sends without a phantom consumer spawn;
- assert SafeSocket returns the valid payload `"0"` from both receive methods;
- assert CommandInvoker interrupts a partially constructed subscription and removes its shutdown timer;
- make CommandInvoker construction and connection cleanup both fail; assert the construction failure remains primary and every channel still closes;
- assert Task and Spinner restore cursor/render state when animation spawn fails;
- assert SignalRegistry removes only registrations from the failed call, normal unregister terminally removes the native waiter, SignalManager cancels a partially created watcher set, and intentional cancellation is never reported to the exception handler;
- assert worker-exit coordination no longer consumes a coroutine slot;
- make an `OnWorkerExit` listener throw and assert the worker-exit coordinator is still resumed while the listener failure propagates;
- exhaust creation from the Engine HTTP native request callback and assert it logs the failure, returns HTTP 503, and does not invoke the application handler;
- for both connection pool and object pool, release and discard from outside a coroutine while a waiter exists and helper creation is unavailable; assert ownership remains committed, the releasing caller does not receive a false failure, the waiter performs exactly one final immediate state pass, and neither implementation enters a second wait or reports false exhaustion;
- exhaust creation after Reverb completes the Redis subscription handshake; assert the exact subscriber is closed, provider state is not left connected, and reconnect begins without leaking its receive coroutine or shutdown timer;
- make Reverb subscriber creation or subscription fail repeatedly with faked sleeps; assert the retry counter reaches its existing limit instead of resetting on every attempt;
- disconnect while the Reverb subscription handshake yields; assert the locally owned subscriber is closed without being committed or spawning a consumer;
- queue a permanently unencodable Reverb payload followed by publishable payloads, then make the first Redis publish fail transiently; assert the poison payload is logged and dropped while the failed payload and ordered tail remain for the next successful connection;

Keep one isolated test per distinct bookkeeping or boundary invariant. Consolidate cases that only repeat the same exact public alias, but do not remove separate rollback coverage merely because every case is triggered through the same native limit. Measure the isolated group before and after consolidation. Avoid a mutable global coroutine-factory seam in production solely for these tests.

### Timer and coroutine test lifecycle

- capture a coordinator, register a timer, resume and clear the manager before the child first waits, and assert the timer observes the captured closed coordinator;
- assert `after(0.0, ...)` still executes immediately and reports the captured coordinator's current closing state without yielding;
- clear a tick from inside its callback and assert no extra interval is awaited;
- make `tearDownInCoroutine` throw while a child waits for `WORKER_EXIT`; assert the test returns, the original exception is thrown, and the child is resumed;
- use multiple trait teardown hooks where the first throws; assert later hooks, context cleanup, native timer cleanup, coordinator resume, and coordinator clear all occur;
- make the test body and cleanup both throw; assert the test body exception remains primary.

### Mockery lifecycle

- create an unmet expectation in each supported base test case and assert PHPUnit attributes the failure to that test rather than emitting only an extension warning;
- verify Mockery expectation counts still contribute to assertion counts;
- invoke subscriber fallback with an unmet expectation and a sentinel framework static; assert the sentinel is reset before the Mockery failure is rethrown;
- make fallback database-wrapper cleanup throw; assert the failure remains primary and the framework-static reset sequence still runs;
- verify cleanup callbacks still run and their first failure is preserved;
- make `HandleExceptions::flushState()` throw in the components base case and assert Mockery verification still runs.

### Queue worker

- run a daemon with a deliberately yielding fake job and concurrency one using `InsomniacWorker`; assert it completes and the fake records sleeps;
- assert the fake does not install process signal handlers;
- inject a fake Timer and verify its exact monitor ID is cleared on every daemon return and throw path;
- set a non-default monitor interval through `WorkerOptions` and assert the owned Timer receives it;
- make timeout scanning throw once and assert `monitorLocked` is reset so the next tick can run;
- assert `kill()` dispatches the stopping event and reaches the kill seam without waiting for unrelated active jobs;
- with concurrency greater than one, assert a hard timeout terminates immediately rather than waiting for a healthy sibling job;
- retain the scalar `$sleptFor` assertions while proving `parent::sleep(0)` yields;
- retain tests proving timeout IDs suppress `JobProcessed`.

### Horizon

- assert supervisor and master state is persisted before the corresponding looped event is observed;
- assert persistence exceptions are reported by the same loop invocation;
- remove retry loops needed only for detached persistence;
- use controlled time to assert a worker receives graceful termination, remains in the terminating set, then is hard-stopped after `SupervisorOptions::$timeout`;
- assert supervisor termination scales every pool to zero and completes after pruning;
- assert master termination hard-stops any supervisor process still alive at its deadline;
- exercise teardown failure paths and verify all active and terminating child processes are stopped and reaped.
- delay child handler installation, signal it during that window, then assert the inherited blocked signal is delivered after handler installation/unblock and the child remains alive;
- assert `WorkerProcess::start()` restores the parent's exact prior signal mask after both successful and throwing starts;
- assert queue-worker parent block and child handler sets are equal, and supervisor-process parent block and Horizon child handler sets are equal;
- pause a newly started worker without an arbitrary readiness sleep and assert SIGUSR2 does not terminate it before handler installation.

### Pool discard and test database resolver

- assert releasing a borrowed connection returns it idle, while discarding destroys it, decrements current connections, and restores capacity;
- reject foreign, idle, already released, and already discarded connections with the existing ownership error style;
- cover `Connection` and `KeepaliveConnection` delegation;
- repeatedly resolve and flush a testing database connection against a max-one pool and prove capacity is never exhausted;
- assert `resetCachedConnections()` retains the wrapper but resets bare per-test state;
- assert terminal flush, named flush, construction failure, and container change discard the wrapper;
- retain write-routing and event-dispatcher rebinding behavior;
- assert resolver flush occurs before pool factory closure during test teardown.

### Locks

- verify the uncontended Cache and Reverb paths acquire and release normally;
- hold a stripe briefly in another process/coroutine and assert the contender backs off and later succeeds;
- pre-lock a stripe in a test subclass with a millisecond timeout and assert a descriptive `RuntimeException` rather than a hang;
- make all-stripe acquisition fail on stripe N and assert every earlier acquired stripe is released;
- fill the Reverb lock table and assert reporting occurs only after the stripe is released;
- exercise all three `setLockRow()` callers so none reintroduces logging under lock.

Use subprocess isolation for any regression whose old implementation would spin forever; a PHPUnit timeout alone is not sufficient if the worker cannot process its signal.

### Process, socket, Redis, and routing tests

- cache harness: child exits before ready, exits before payload, writes an error payload, and stalls; each case fails within its deadline and leaves no live or unreaped owned PID;
- cache and Reverb harnesses retry `EINTR`, accept only the owned PID or `ECHILD` as reaped, and never use an unbounded final wait;
- Engine sockets: parallel tests bind independent ephemeral ports; readiness signaling never opens a synthetic connection; a client assertion failure still closes the client and server; child server exceptions surface in the parent;
- renderer context test: a child exception propagates through `parallel()` and no channel pop can block forever;
- Redis channel subscriber: an assertion failure still closes the subscriber and raw publish connection;
- compiled routes: create two collections with the same route name and different domain, port, URI, and action; assert each returns its own stable object without calling a global reset;
- router collection replacement keeps the remaining static routing caches correct.

### Watcher

- a driver exception is rethrown by `Watcher::run()` rather than leaving its poll loop alive;
- a clean driver return ends the command cleanly;
- a driver spawn failure after strategy start still stops both driver and strategy;
- a restart exception still stops the driver and managed server;
- a driver whose `stop()` does not unblock within one second produces a bounded diagnostic;
- a driver blocked pushing beyond the full change-channel capacity is unblocked by cleanup, reaches completion before the bounded join returns, and preserves the parent's primary failure;
- if operation and cleanup both fail, the operation failure remains primary and later cleanup still runs;
- a synchronous final driver batch is drained and restarted exactly once; a previously debounced batch followed by a terminal tail restarts twice without duplication;
- `ServerRestartStrategy` reports rather than fatals after `proc_open()` failure or `proc_close()` failure, returns its launch token after each failure and normal server exit, and allows the next start/restart attempt;
- invalid PID-file contents (empty, whitespace, zero, negative, non-numeric, or numeric-prefix corruption) perform no POSIX call; a dead positive PID is probed but not terminated; a live positive PID dispatches an integer event and receives SIGTERM;
- Fswatch batches are delivered in order without detached children, and a path-matching failure propagates through the owned driver coroutine;
- split one Fswatch path across two reads and place multiple complete paths around the split; assert every path is emitted once and in order;
- immutable WatchPath glob patterns are compiled once and retain all existing matching behavior;
- both Server and Horizon restart strategies expose idempotent public `stop()` implementations;
- exercise `FindDriver`, `FindNewerDriver`, and `ScanFileDriver` through their real interval loop; assert `watch()` remains active until `stop()`, stop wakes it immediately, and a scan failure reaches Watcher rather than an unrelated Timer logger;

### Console and Testbench

- with `SHELL_VERBOSITY` absent from all three process-global stores, compare regular `run()` and programmatic `call()` behavior for command execution, arguments/options, output, exit codes, console-event ordering, exception propagation, ANSI decoration, interaction, quiet/explicit verbosity, and help handling;
- set different values in `getenv()`, `$_ENV`, and `$_SERVER`; assert a normal `Application::call()` is not made verbose and none of the three process-global stores changes;
- repeat when the called command throws and with two concurrent coroutine calls using different explicit verbosity;
- assert `output()` remains isolated per coroutine;
- retain the existing overlapping and nested call regressions proving distinct command clones, isolated output, and the normal console event lifecycle; add no mutex, execution-peak, depth-marker, or suppressed-event assertions;
- assert explicit `-v`, `-vv`, and `-vvv` still take effect;
- run the affected schedule and route PendingCommand tests under an inherited `SHELL_VERBOSITY`;
- set custom resolved route and config cache paths while placing stale data at the defaults; assert each subprocess loads and writes only the parent's resolved path;
- force an early `CleanupActions` callback to fail; assert every later callback runs and the original throwable remains primary;
- exercise the six Testbench file owners and assert their checked restoration/deletion leaves pristine bootstrap/provider files and no owned route or generated-provider source;
- assert the RouteCache worker-state guard diagnoses bootstrap, provider, and route-source contamination without repairing it;
- Testbench PID cleanup: matching PID/start identity/serve command is recognized; command mismatch, start-identity mismatch, malformed marker, and unreadable identity are refused; a dead PID file is cleaned without signaling a real process;
- exercise both direct `vendor/bin/testbench serve` and `remote('serve')` runtime creation and assert each writes the same process-incarnation marker format for its own process while `serve` still does not receive `TESTBENCH_BASE_PATH`.

### Frequency

- immediately after construction, including within the same second as `beginTime`, it returns `0.0`;
- non-empty window calculations and low-frequency behavior remain unchanged.

### Full gates

Run focused tests after modifying each source/test pair. At completion run:

```bash
composer fix
```

This repository's `fix` gate includes formatting/linting, PHPStan, parallel tests, Testbench tests, and dogfood tests. Also run the focused Redis and Horizon integration groups with their required services so a skipped service-dependent path cannot masquerade as verification.

Finally, run a full diff review and trace every new/changed contract through all implementers, callers, fakes, providers, docs, and reset registries.

## Fresh-review checklist

Before implementation begins, and again before declaring it complete, verify:

- [ ] every native coroutine creation path has one failure contract;
- [ ] every pre-spawn counter/token/registration is rolled back exactly once;
- [ ] native HTTP request overload completes with a controlled 503 instead of escaping through Swoole;
- [ ] pool/object-pool wake failure cannot turn a committed release or discard into a reported failure, and checkout performs one final state pass;
- [ ] Timer captures coordinator identity before scheduling;
- [ ] cleanup preserves the first failure but never skips later independent cleanup;
- [ ] Mockery verification belongs to test lifecycle and subscriber fallback cannot skip resets;
- [ ] queue tests do not install real signals or detach unowned timers;
- [ ] production queue sleep retains its existing efficient hooked yield; no redundant yield was added;
- [ ] Horizon writes cannot overlap across loop iterations and all termination waits are bounded;
- [ ] Horizon child control signals remain blocked until the child installs its exact matching handler set, and every parent spawn restores its prior signal mask;
- [ ] lock acquisition adds no work to the uncontended path beyond the existing compare-and-set;
- [ ] partial all-stripe acquisition releases every stripe acquired before failure;
- [ ] Reverb performs no logging or arbitrary callback while holding a stripe;
- [ ] compiled route objects cannot cross collection identity;
- [ ] programmatic console calls never mutate `SHELL_VERBOSITY`, retain context-local output, honor explicit verbosity, and rely on per-execution command clones without mutex, mode, or nested-path machinery;
- [ ] route/config cache subprocesses receive the exact paths resolved by their parent Application;
- [ ] all six Testbench file owners use checked exhaustive cleanup, and the assertion-only route guard never repairs state;
- [ ] pool discard updates ownership and capacity before/while closing the connection;
- [ ] the test resolver never holds a bare connection without retaining the owning wrapper;
- [ ] process cleanup kills and reaps only PIDs it started;
- [ ] socket and subscriber cleanup is unconditional;
- [ ] every Watcher driver blocks for one owned lifecycle, is immediately unblocked by idempotent `stop()`, and propagates terminal failures to Watcher;
- [ ] Fswatch preserves newline framing across partial reads;
- [ ] Testbench process-incarnation markers cover direct and remote serve paths and prevent signaling a process whose identity cannot be proved;
- [ ] no obsolete `flushCache()`, monitor callable, detached-persistence retry, abandoned-wrapper comment, owner-token code, console mutex/depth path, or old Mockery trait remains;
- [ ] public docs describe final contracts without incident history;
- [ ] focused regressions fail or hang against the old implementation for the intended reason and pass with the fix;
- [ ] `composer fix` and service-backed focused integration tests pass.
