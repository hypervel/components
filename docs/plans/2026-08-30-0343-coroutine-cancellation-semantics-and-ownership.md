# Coroutine Cancellation Semantics and Ownership

## Goal

Make coroutine cancellation a predictable terminal control-flow signal throughout Hypervel. A canceled operation must not be mistaken for an application failure, retried, wrapped, reported, converted into a fallback value, or allowed to leave owned coroutine and resource state inconsistent.

This is a framework correctness change, not a general exception-hardening exercise. It applies only where Hypervel owns both the cancellation contract and the boundary that can corrupt observable state. It preserves Laravel-compatible public APIs and all ordinary exception behavior.

The implementation must remain Hypervel-specific:

- use Swoole coroutine primitives directly;
- do not add Fiber support or a generic async abstraction;
- do not attempt to repair third-party libraries by walking exception cause chains;
- do not add a global coroutine registry, cancellation token system, cleanup scheduler, or shutdown budget;
- do not add catches merely because a method contains `catch (Throwable)`.

## Runtime facts and design invariants

The design is based on reproduced Swoole 6.2 behavior and the current Hypervel call graph:

- Swoole 6.1 added per-call exception delivery to coroutine cancellation. Passing `throwException: true` injects a catchable `CanceledException`; it is not a PHP.ini or process-global mode. Hypervel chooses it only at boundaries that need reliable interruption and exact ownership cleanup.
- `Coroutine::cancelById($cid, throwException: true)` delivers the exact `Swoole\Coroutine\CanceledException` and normally resumes a target parked on blocking I/O synchronously until it terminates or yields again. A target suspended inside child creation accepts throwing cancellation but receives it when next scheduled; non-throwing cancellation is refused in that state.
- Non-throwing cancellation makes supported blocking operations return `false` and expose Swoole's canceled error state. Some hooked operations cannot be interrupted reliably without exception delivery.
- An uncaught cancellation escaping a raw `Hypervel\Engine\Coroutine` callback can terminate the worker. The high-level `Hypervel\Coroutine\Coroutine` wrapper has a terminal boundary; raw engine children do not.
- A caught cancellation does not guarantee that the coroutine or worker has terminated. Code after the catch may run.
- `Channel::pop()` returns `false` on cancellation and its error code remains available until the channel's next operation.
- The current-coroutine cancellation flag is volatile: it is useful immediately after a failed native operation or a catch, but must not be stored or treated as durable state.
- `Coroutine::join()` returns `false` for several reasons, including timeout, an inactive target, and cancellation. Callers must inspect cancellation immediately when the distinction matters.
- Child creation starts the child synchronously. The parent can nevertheless be canceled while `create()` is paused after the child has started and yielded.

These facts establish the following rules.

In this plan, preserving an exact cancellation means rethrowing the same delivered object. A non-throwing native cancellation has no exception object to preserve, so its immediate native error state is converted once into a new `CanceledException` whose message names the observation boundary.

### Boundary rules

1. A native blocking boundary distinguishes success, timeout/closure, and cancellation immediately after the native operation.
2. A transparent framework boundary rethrows the exact cancellation object unchanged. It does not wrap, report, retry, fail over, emit a failure event, or return a fallback.
3. A terminal child boundary consumes exact cancellation without logging when no owner remains to observe it. This prevents cancellation from escaping a raw Swoole callback as a worker-fatal uncaught exception.
4. A parent canceled while it owns live children cancels only those children that are still live, then rethrows its own exact cancellation. A child canceled independently becomes `ChildCancellationException` for an uncanceled parent.
5. Fixed, non-yielding local cleanup always runs. Finite cleanup over an already-acquired, configuration-bounded external set may continue only where abandonment would leak a resource or permanently orphan state.
6. The original cancellation remains primary when cleanup also fails ordinarily. A cancellation raised by required cleanup supersedes an ordinary primary failure. Ordinary cleanup failures preserve existing reporting behavior.
7. Ordinary exceptions, timeouts, empty results, retries, failover, events, and public return values remain unchanged.

### Inclusion criteria

A boundary belongs in this change only when all of the following are true:

- Hypervel or Swoole can deliver exact cancellation there through a supported path;
- cancellation being treated as an ordinary failure causes concrete harm such as duplicate publication, false reporting, wrong retry/failover, worker termination, leaked capacity, or an ownership deadlock;
- Hypervel owns the affected boundary;
- the correction is local, testable, and does not invent a second cancellation model.

The audit must include every Hypervel call site of `cancelById(..., throwException: true)` and the non-throwing cancellation owners whose blocking result must be classified. At the current baseline, exact cancellation is delivered by `Waiter`, database pooled connection ping, Redis heartbeat checks, `SignalManager`, `SignalRegistry`, and the gRPC server deadline. Non-throwing cooperative cancellation is used by `Coordinator\Timer::clear()` and gRPC client receiver shutdown.

### Explicit exclusions

- Vendor internals and hypothetical cancellation swallowed by third-party code.
- Generic inspection of `Throwable::getPrevious()` to recover cancellation.
- Broad changes to every `catch (Throwable)` without a first-party cancellation path and concrete failure.
- Private fire-and-forget children with no exact owner cancellation and no broken terminal behavior.
- Waiting for arbitrary user cleanup, deferred callbacks, or pooled release I/O after parent cancellation.
- A configurable shutdown-cleanup allowance. No current contract requires one.

The daemon guarantee is therefore precise: synchronous fixed cleanup completes during `cancel()`. Cleanup that yields may continue after the daemon returns. Broker jobs remain reserved for normal redelivery; the framework does not wait indefinitely for user cleanup.

## Public contracts

### Engine cancellation state

Add cancellation classification to the existing engine contracts rather than exposing Swoole constants throughout higher-level packages:

```php
interface ChannelInterface
{
    public function isCanceled(): bool;
}

interface CoroutineInterface
{
    public static function isCanceled(): bool;
}
```

The Swoole channel implementation compares the immediately available channel error code with `SWOOLE_CHANNEL_CANCELED`. The coroutine implementation returns `false` outside a coroutine and otherwise delegates to Swoole's current-coroutine cancellation state.

Document both as immediate classification helpers, not durable state. Document that a failed `join()` is intentionally ambiguous until the caller checks `CoroutineInterface::isCanceled()`.

If PHPStan's bundled Swoole stub lags the installed 6.2 API, use a line-scoped `@phpstan-ignore` at the `isCanceled()` call, matching the existing `cancelById()` and `join()` precedents in `Engine\Coroutine`. Do not add or broaden a `phpstan.neon.dist` ignore rule.

### Independent child cancellation

Add `Hypervel\Coroutine\Exceptions\ChildCancellationException extends RuntimeException`. It represents a child that was canceled while its owner was not canceled. Preserve the native cancellation as `$previous`.

This distinction prevents an independent child cancellation from being confused with cancellation of the parent operation. The owner-facing wait/create path classifies its own cancellation separately; a cancellation stored or delivered as a child outcome is therefore always wrapped without a defensive current-coroutine check:

```php
if ($failure instanceof CanceledException) {
    throw new ChildCancellationException(
        'A child coroutine was canceled while its owner remained active.',
        previous: $failure,
    );
}
```

Use a boundary-specific message at each construction site so logs and aggregate failure formatting identify whether the canceled child belonged to Waiter, Parallel, Saloon, or Scout.

Do not add a cancellation token, status enum, or public ownership registry.

### Performance and API constraints

- Keep normal success paths straight-line. Cancellation checks belong only immediately after a failed native operation or in an existing exceptional path.
- Allocate no exception, event, cleanup descriptor, or reporting context on the success path solely for cancellation. Limit normal-path ownership bookkeeping to local booleans and active-child maps.
- Bound active-child maps by the caller's existing concurrency limit and remove entries as soon as the child body completes.
- Keep listener/event allocation behind existing `hasListeners()` guards.
- Replace the queue daemon's polling with blocking structured coordination; do not add a timer or supervisor coroutine.
- Add no cancellation configuration. Correct terminal control flow is not an application policy toggle.
- Preserve Laravel-shaped method names, return values, exception behavior for ordinary failures, and existing extension points. Keep the added surface limited to the two immediate engine classifiers, `WaitConcurrent::cancel()`, `ChildCancellationException`, gRPC `Call::cancel()`, the Lease failure helpers used across pooled packages, and the shared Foundation health-route invokable.

## Implementation

### 1. High-level coroutine terminal boundary

Update `Hypervel\Coroutine\Coroutine` so its wrapper has one explicit terminal outcome for exact cancellation:

- an exact cancellation escaping the child callback is consumed without reporting;
- ordinary uncaught failures retain configured exception-handler reporting;
- cancellation raised while resolving or invoking the reporter is rethrown rather than hidden by logging fallback;
- exact cancellation raised by an `afterCreated` callback remains terminal;
- ordinary `afterCreated` failures retain the current report-and-continue behavior.

Keep the protected `printLog(Throwable): void` signature. Extract a protected `reportUncaught(Throwable): void` shared by the child-body and deferred-callback terminal boundaries. `printLog()` rethrows cancellation raised during reporting and documents that exception contract; `reportUncaught()` documents and enforces that it consumes both a cancellation argument and cancellation from `printLog()` because no owner exists above that terminal frame.

Keep `afterCreated` as a synchronous startup hook. Its documentation must state that callbacks must not suspend. Ordinary hook reporting remains prompt and coroutine-local, so the exception handler itself may yield before the application callable begins.

Ownership-sensitive framework children therefore use one narrow internal lifecycle seam:

```php
Coroutine::createOwned($callable, function (Closure $run): void {
    try {
        $started = true;
        $children[Coroutine::id()] = true;
        $run();
    } finally {
        // Fixed child-owned cleanup.
    }
});
```

Add `createOwned(callable $callable, Closure $wrapper): int` and `forkOwned(callable $callable, Closure $wrapper, array $keys = []): int`. Mark both `@internal`. The wrapper runs at native child entry, must not suspend outside `$run`, and must invoke `$run` exactly once. Its start marker is the first statement inside its `try`, so every path after ownership transfer is covered by its `finally`.

The shared private implementation passes a void `$run` closure containing context installation, startup hooks, inline hook-failure reporting, and the application callable. Its outer terminal catch encloses both the owner wrapper and `$run`, so wrapper failures cannot escape the raw Swoole callback. A pre-child `fork()` context-replication failure never enters the wrapper and remains parent-owned. Normal `create()` and `fork()` use the same implementation with one nullable branch and no extra normal-path closure.

Do not change raw `Hypervel\Engine\Coroutine` into the high-level wrapper. Raw children are used by infrastructure that deliberately owns its terminal behavior; each such callback must contain its supported terminal outcomes.

### 2. WaitGroup and Waiter

`WaitGroup::wait()` must clear its internal waiting flag in `finally`. When the underlying wait returns `false`, it must immediately distinguish current-coroutine cancellation from an ordinary timeout and throw exact cancellation only for the former.

`Waiter` owns one child and needs an explicit handoff:

1. Allocate the result channel before creation.
2. Have the child publish its own coroutine ID before entering user work, so a parent interrupted before `create()` returns still has the child identity.
3. If `pop()` throws cancellation, or returns `false` with a canceled channel, cancel only the published, still-live child and preserve or synthesize exact parent cancellation.
4. Do not join the child from the cancellation path.
5. Use a local boolean to prevent a second cancel during cleanup.
6. If cancellation is delivered while joining after a result was published, discard that result and preserve or synthesize exact parent cancellation.
7. Preserve result values and exact ordinary exception identity.
8. Convert an independently canceled child to `ChildCancellationException`.

Preserve the existing timeout contract: when the result can no longer be used, `Waiter` cancels its child, gives it the configured bounded cleanup allowance, and honors strict `waitForChildTermination`. This differs intentionally from reusable `WaitConcurrent`, whose timed-out children remain active. The queue worker already constructs its pop waiter through `createPopWaiter()` and uses an unbounded wait; preserve that extension point and behavior.

### 3. Parallel, Concurrent, and WaitConcurrent ownership

#### Parallel

Track only live child IDs. Each child publishes itself and removes itself in `finally`. Because throwing cancellation resumes a child synchronously, cancellation must snapshot the keys but re-read the live map before each cancel:

```php
foreach (array_keys($children) as $cid) {
    if (isset($children[$cid])) {
        EngineCoroutine::cancelById($cid, throwException: true);
    }
}
```

Use a separate never-cleared `$started` flag around each creation attempt. Move that marker, active-child publication, and fixed slot/WaitGroup/map cleanup into the owned wrapper. Keep results and throwables in arrays local to one `wait()` call; children capture only those arrays, and the object publishes them after ordinary WaitGroup and join completion. Make the child callable static and release its run-local captures after publication, so a published throwable's trace retains neither the run aggregates nor the `Parallel` instance. This prevents a canceled run's surviving children from writing into a later run on the same instance. Reset the published object state when a run begins so inspection after a canceled run is empty and stable. Keep result and throwable classification around the application callback: the private active map is canceled only when the owning `wait()` immediately rethrows, so recording a startup cancellation would add unobservable state. A map entry may be consumed by child completion and therefore cannot prove whether creation started. Join on ordinary completion only; do not add a post-cancel join. Preserve input keys, deterministic failure ordering, context-copy behavior, and ordinary aggregate semantics. Store `ChildCancellationException` in keyed failure data for independently canceled callback bodies.

#### Concurrent

Treat each concurrency slot as a single-owner resource:

- the child releases the slot in `finally` after it starts;
- the parent releases it only when creation fails before the child starts;
- never release from an unconditional parent `finally`;
- classify every failed channel operation immediately.

This prevents both leaked and double-released capacity when `create()` throws after the child has started. A protected starter shared by `create()` and `fork()` accepts an optional framework-owned inner wrapper. Its contract matches the public internal seam: it runs at native entry, does not suspend outside `$run`, and invokes `$run` exactly once.

`create()` and `fork()` acquire with an unbounded push: exact cancellation escapes, non-throwing cancellation is synthesized from the immediate channel state, and a closed channel throws `ChannelClosedException`. Neither may start a child without a token. `waitForAvailableSlot($timeout)` has four outcomes: `true` after a successful probe, `false` only for timeout, exact/synthesized cancellation, or `ChannelClosedException`.

#### WaitConcurrent

Expose a public `cancel()` method because callers such as Saloon can yield while producing work outside the `create()`/`fork()` frame. Track only active bodies and their IDs. `WaitConcurrent` composes its per-invocation owned wrapper inside `Concurrent`'s slot wrapper while retaining its own started flag and outer catch: its WaitGroup count is reserved before slot acquisition, so slot-acquisition and pre-child failures must still release the two resources through their respective parent catches. On native entry, both wrappers transfer ownership without yielding; child finalizers remove the active entry, complete the WaitGroup, and then release the slot in the existing order. Each call to `cancel()` cancels the bodies active at that time, including one that caught an earlier cancellation and remained active. Timeout leaves active children reusable. Parent cancellation cancels active children and rethrows exactly; independent child cancellation is surfaced as `ChildCancellationException`. No cleanup runs while a concurrency slot is held.

Update the two concrete consumers whose child failures otherwise disappear:

- Saloon pool orchestration calls `cancel()` when cancellation interrupts the user-supplied request iterable, moves the ordinary `wait()` out of `finally`, and records independently canceled request/callback children as `ChildCancellationException` in the existing keyed failure collection. It must not invoke response or exception handlers for the canceled operation.
- Scout's coroutine-scoped `ConcurrentImportRunner` records an independently canceled import as `ChildCancellationException`, allowing `throwIfFailed()` to expose it rather than reporting a successful partial import.

Saloon has three child catch regions to preserve: connector send, exception handler, and response handler. A canceled send is a keyed request failure without invoking the exception handler; cancellation from either handler is a keyed callback failure. A successfully received response remains recorded when its response handler is canceled.

### 4. Concurrency driver

Replace the coroutine driver's duplicate WaitGroup scheduler with `Parallel(copyContext: true)`. Call `wait(false)`, inspect keyed throwables, preserve original keys, and surface the first failure in input order. The coroutine driver intentionally ignores the facade timeout just as the synchronous driver does; add concise docblocks to both drivers rather than constructing a timeout mechanism that their contracts do not use.

### 5. Creation-time ownership handoffs

Audit every child creation where the parent records a child ID, compensates in a broad catch, or owns a count/token/resource. Use the owned wrapper to publish identity and transfer cleanup at native child entry, before context installation, hooks, or reporting can yield. ID-only owners publish and invoke `$run`; consumable owners set a separate `$started` flag and place their fixed cleanup in the wrapper's `finally`. Do not infer start state from a child-owned map entry that may already have been removed.

Apply the patterns as follows:

- `Waiter`: publish only the private child ID in the owned wrapper. Keep result initialization, result-defer registration, exception capture, and result conversion in the body. Parent creation, result-pop, and timeout cancellation paths already stop waiting after canceling the child; moving result delivery ahead of `$run` would incorrectly reorder it behind defers registered by startup hooks. Remove the now-dead fallback from the returned creation ID.
- Database `PooledConnection::ping()` and Redis heartbeat checks: the owned wrapper publishes the child ID; parent wait cancellation cancels a live child and preserves or synthesizes exact parent cancellation. Ordinary timeout/unhealthy results remain `false`.
- `Coordinator\Timer::{after,tick,until}`: precreate the timer slot, validate it in the child, publish the child ID there, and retain existing `clear()` rollback when creation itself fails. `clear()` remains non-throwing cooperative cancellation and does not promise to interrupt a callback already executing.
- `Engine\SafeSocket`: use a started flag. Parent clears the loop only before start; child `finally` owns cleanup after start. Keep its exhaustive ordinary-error catch because it is a raw engine child.
- Reverb `RedisPubSubProvider::connect()`: use the owned variant directly, transferring subscriber ownership before startup while retaining internal connection and message error handling.
- Sentry `HttpPoolTransport`: carry the owned wrapper through both branches of its protected `createCoroutine()` seam. In a coroutine it delegates to `createOwned()`; outside one, the same wrapper encloses the callback passed to root `run()`. Move only fixed release/discard and WaitGroup cleanup into the wrapper. Keep send outcome classification in the body, so startup cancellation releases an untouched transport rather than discarding it.
- `Watcher`: retain the raw engine child. Its first statement publishes start, and that raw callback owns channel/WaitGroup cleanup before invoking the pluggable driver. Preserve the first exact cancellation; cleanup cancellation supersedes only an ordinary primary failure. Always attempt the required driver, restart-strategy, and channel teardown, but do not begin the child WaitGroup wait once cancellation is primary. The raw callback's exhaustive terminal catch remains required.
- `SignalManager`: the owned wrapper appends its child ID to the rollback collection before `$run` can block. Remove parent-side publication.
- `SignalRegistry`: the owned wrapper publishes the active handler ID. Scalar rollback removes the failed handler and calls `cancelSignal()` when its handler stack becomes empty, so the started signal waiter cannot remain parked and later re-raise the signal with its default disposition. Array rollback applies that same scalar operation to both the failing signal and every earlier member already installed. Test rollback while the waiter is suspended in startup reporting.
- `PromptAnimation`: the owned wrapper publishes the property ID before `$run` can wait.
- gRPC client receiver: retain the raw engine child, set receiving state before creation, publish its ID as the callback's first statement, and clear it in the callback's `finally`. Parent creation cleanup applies only to `CoroutineCreateException`; `close()` retains ownership after a post-start parent cancellation.
- HTTP server worker-start gate: use the boolean returned by the unbounded `Coordinator::yield()`. Its only `false` outcome is non-throwing cancellation, so synthesize a boundary-named `CanceledException` and leave the HTTP worker-started flag unset. Do not apply this latch to gRPC, Reverb, or WebSocket servers, whose startup paths differ.

Leave the following fire-and-forget paths unchanged unless implementation tracing reveals a real owner and exact cancellation source: watcher restart strategy, server process listener, pool signal workers, BackgroundQueue's internal child, Redis command invoker, Sentry event handler, Prompt render helper, and private gRPC receiver error handling.

### 6. Queue daemon structured ownership

The worker currently polls admitted jobs with `usleep(1000)`. Use the existing `WaitConcurrent` owner instead:

- orderly shutdown calls an unbounded `wait()`, preserving the current unbounded drain contract without 1,000 wakeups per second;
- abnormal daemon exit calls `cancel()` for still-active admitted jobs;
- cancellation runs each child's exact-cancellation catch and non-yielding `finally` synchronously;
- do not join or perform a second drain after cancellation;
- clear the monitor and fixed worker state regardless of the terminal path.

Land and verify the queue terminal-boundary changes before enabling daemon cancellation. A daemon-canceled job must not be reported, failed, released, retried, counted as an attempt, or emitted as a completion. It remains broker-reserved for normal redelivery. Local non-yielding accounting such as processed counters completes; yielding deferred callbacks or lease release may continue after daemon return.

The active-child map remains bounded by configured queue concurrency. This is structured ownership, not a global registry.

This abnormal-exit path is also the resolution when cancellation interrupts `waitForAvailableSlot()`: the resulting exception unwinds the daemon into its owner cleanup, which cancels every admitted child rather than bypassing the orderly `waitForRunningJobs()` sites and orphaning them.

### 7. Queue execution and publication

#### Execution boundaries

In `Worker`, `SyncQueue`, `DeferredQueue`, `BackgroundQueue`, `Job`, `FailOnException`, and `ThrottlesExceptions`:

- rethrow exact cancellation before reporting, failure policy, release, retry, throttling, or completion events;
- always clear fixed running-job state;
- let cancellation during required failure cleanup take precedence according to the boundary rules;
- do not emit `JobAttempted` or `JobFailed` for cancellation;
- treat cancellation from `getNextJob()` as terminal, not as a lost connection or empty-pop sleep.

`Job::fail()` must preserve cancellation at batch rollback, delete, failed-callback, and event boundaries. `WithoutOverlapping` relies on the centralized cache-lock cleanup rule; do not duplicate it there.

#### Publication boundaries

In queue payload construction and backend publication:

- `Queue::createObjectPayload()` rethrows cancellation from cloning, magic serialization, encryption, or container resolution before the existing `RuntimeException` wrapper.
- `enqueueNow()` rethrows before `JobQueueingFailed`.
- `DatabaseQueue::enqueueBatch()` does not convert cancellation into per-job publication failures.
- `FailoverQueue` never marks a connection failed, emits failover events, or tries the next backend after cancellation. The first backend may already have accepted the job; fallback could publish a duplicate.

For SQS overflow payloads:

- any failure while storing an overflow body before broker publication cleans the current attempted key plus confirmed writes from that chunk; single-message publication applies the same rule;
- an original cancellation remains primary over cleanup failure, while cleanup cancellation supersedes an ordinary primary failure;
- cancellation from `sendMessageBatch()` retains overflow pointers because broker acceptance is ambiguous and emits no failed events;
- `cleanupOverflowPayloads()` continues over the bounded current set, remembers the first cancellation, reports ordinary cleanup failures, and throws the retained cancellation after the loop;
- `forget() === false` remains a reported cleanup failure because supported stores use it for real deletion failure; do not add an existence probe, semantic flag, or second helper;
- do not add an overflow TTL because SQS retention and redrive can outlive any invented cache lifetime;
- `clear()` naturally preserves cancellation from purge or overflow-store flush. Do not retry an ambiguous purge or start an unbounded global flush during cancellation unwind.

Queue lock-release paths attempt the required bounded releases with the same precedence: cancellation from the first release prevents starting unrelated yielding work; cancellation from a required second release supersedes an ordinary primary failure; ordinary behavior remains unchanged.

### 8. Resource pools and leases

#### Native pool waits

In object-pool and connection-pool channel adapters, a failed push/pop must distinguish cancellation immediately through `ChannelInterface::isCanceled()`. Never create, return, or recycle a resource after the acquiring coroutine was canceled.

`ObjectPool::destroyObject()` and the connection pool's destruction path must retain exact cancellation, complete fixed bookkeeping and capacity signalling, then rethrow. Ordinary destruction failures retain existing report/suppress behavior.

If connection-pool low-frequency maintenance is canceled after `Pool::get()` records a new borrow, discard that unreturned connection and preserve the original cancellation. `Connection::getConnection()` must not retry exact cancellation. A canceled release listener still returns its connection exactly once; its cancellation remains primary over cleanup failure, while cancellation from release itself escapes when no earlier cancellation exists.

Leave `checkIdleConnection()` and frequency clearing unchanged. First-party pools install the non-yielding `Frequency`, while the optional `ConstantFrequency` uses only non-throwing timer cancellation; neither boundary has a first-party exact-cancellation delivery path.

#### Lease failure contract

Centralize ownership transfer in `ObjectPool\Lease` with two narrow cross-package helpers:

```php
public function releaseAfterFailure(Throwable $failure): never;

public function discardAfterFailure(Throwable $failure): never;
```

Their precedence is:

- original exact cancellation remains primary;
- cleanup cancellation supersedes an ordinary primary failure;
- ordinary cleanup failure is reported while the primary failure is rethrown.

Acquire the `Lease` immediately after `get()`, before borrowed-resource configuration. Configuration failure discards through the lease. Use the helpers from `PoolProxy`, `FilesystemPoolProxy`, `ClientPooledFilesystem`, `QueuePoolProxy`, pooled `Job`, and `LeasedStream::wrap()` after it closes the source stream. Inner `QueuePoolProxy::pop()` recovery may finalize and throw into the outer failure boundary; the outer helper remains safe because `Lease` finalization is idempotent.

SQS job deletion must not start overflow-cache deletion if lease release is canceled; cancellation during overflow deletion escapes exactly.

Destructors and stream-close fallbacks remain no-throw best-effort boundaries. `PoolErrorReporter` ignores exact cancellation supplied to it or raised while resolving the handler, so these terminal fallbacks neither throw nor misreport control flow. Correct fixed pool bookkeeping prevents a capacity leak even when external cleanup cannot complete.

#### Finite close drains

Pool `close()` paths may continue only over their already-acquired, configured idle resources. Retain the first cancellation from closing or destroying an idle resource, finish the finite local drain, then rethrow. The connection-pool wrapper preserves its existing full-drain guarantee after destruction begins rethrowing cancellation; it does not add cancellation handling around non-throwing frequency cleanup.

Leave `PoolRecycler` unchanged. It uses non-throwing timer cancellation while waiting, owns no public exact cancellation source, and has no demonstrated leak requiring more machinery.

### 9. Cache boundaries

Apply exact-cancellation transparency at the cache-owned boundaries that otherwise emit false events, fall back, or return failure values:

- `Repository`, tagged repositories, `FileStore`, and `StorageStore` rethrow before miss/write/forget/flush failure conversion.
- `FailoverStore` does not record cancellation as a store failure, emit `CacheFailedOver`, or try another backend. Its history retains only ordinary failures observed before cancellation; do not preserve stale history for unvisited stores solely for a caller that catches terminal cancellation and starts another operation.
- `StackStore` and stack-tagged operations stop rather than reporting a child store failure and continuing. When a stacked write throws, compensate the throwing layer followed by the previously completed layers in full reverse order because the write may have committed before PHP observed the failure. Deepest-first invalidation prevents a concurrent shallow-first read from reaching a not-yet-cleared lower value and back-filling it upward. Do not compensate the current layer when it explicitly returns `false`; invalidating it could destroy a pre-existing value the failed operation never changed. Finish the finite configured compensation set, preserve an original cancellation over cleanup failure, and let cleanup cancellation supersede an ordinary failure or `false` result. Multi-store operations stop immediately when cancellation follows an earlier ordinary failure and surface the cancellation by identity.
- Redis tagged cache operations rethrow before partial-success/failure classification.
- locks and concurrency limiters preserve cancellation through acquire, block, release, and refresh cleanup. Explicit multi-store lock flushing stops before starting another backend after cancellation, while ordinary failures retain the existing finite aggregate.

For lock and limiter cleanup, preserve exact operation cancellation as primary; cleanup cancellation supersedes an ordinary primary failure; ordinary cleanup does not hide an ordinary primary failure.
Keep `ConcurrencyLimiterBuilder::then()` in two phases so its failure callback handles acquisition timeout only; a `LimiterTimeoutException` thrown by the user callback must escape unchanged. The builder and limiter retain their short local copies of the cleanup rule because sharing them would require broader public or trait machinery.

`SwooleStore` has one necessary special case: interval/index mutation can be interrupted while it owns a shared claim. Complete only the bounded claim repair needed to keep the store usable. Preserve an original cancellation, let cleanup cancellation supersede only an ordinary primary failure, and do not add retry loops, helper registries, or a state machine.

No new public cache API or event is required.

### 10. Database, HTTP client, Redis, and filesystem boundaries

#### Database

`Connection::runQueryCallback()` and `Connection::run()` rethrow cancellation before query wrapping, retry, lost-connection handling, or failure events. Pooled connection heartbeat ownership follows the creation handoff above.

Transaction commit callback executors in `DatabaseTransactionRecord` and `DatabaseTransactionsManager` stop immediately and preserve exact cancellation because later commit callbacks may start new outbound work. Rollback callback executors retain the first cancellation, finish their finite registered cleanup sets, and throw cancellation ahead of any ordinary failure. Existing exhaustive first-ordinary-failure behavior remains unchanged.

Database pool shutdown detaches the complete selected pool set before cleanup, drains that finite configured set, retains the first cancellation, and preserves the existing first ordinary failure otherwise. Single-pool flush remains unchanged. Connection resolver and lifecycle drains follow the same cancellation precedence. A pooled connection always returns or discards its lease exactly once after rollback or release-listener failure; exact cancellation remains primary over ordinary release cleanup, while release cancellation supersedes an ordinary primary failure. `Connection::disconnect()` always resets local transaction state, with cancellation winning over ordinary disconnect or reset failures.

Connection resolver setup/discard cleanup follows that same failure precedence. A pooled close always clears its held connection when disconnect fails, whether ordinarily or through cancellation.

Transaction methods keep their existing state-update order. Cancellation from the transaction manager stops before commit/rollback events because those events are observational and may contain unbounded listeners. After an ordinary manager failure, the matching event still runs; event cancellation wins, while two ordinary failures retain the first. The six existing cleanup catches in transaction exception handling, begin, commit recovery, and rollback recovery stop converting cleanup cancellation into retry or recovery state: cleanup cancellation supersedes an ordinary primary, while an original cancellation remains primary when the required rollback is also canceled. Use local catches at those owning boundaries rather than a shared helper or trait.

#### HTTP client

When an exact cancellation reaches `PendingRequest`, rethrow it before retry callbacks, connection wrapping, promise fallback, or response synthesis. Cancellation in the global synchronous `retry()` helper likewise escapes before its predicate, delay, or retry. Do not add cause-chain recovery for cancellation transformed by a third-party HTTP stack.

Do not add cancellation-specific public HTTP configuration.

#### Redis

phpredis can wrap cancellation in native `RedisException`/`RedisClusterException` at extension boundaries. Add one package-private `RedisCancellation` classifier that recognizes only those native extension exceptions combined with the immediately observed current-coroutine cancellation state. It must not walk arbitrary previous chains.

Normalize cancellation at Redis-owned command, transaction, pool factory, proxy, manager, and connection boundaries:

- no command failure event, retry, cluster fallback, sentinel fallback, or connection reuse;
- invoke the classifier as the first catch action at every direct phpredis boundary; exact catches remain sufficient after a wrapper has normalized the native failure and at non-phpredis boundaries;
- `RedisConnection::close()` owns normalization of its native `close()` call, always clears wrapper state, suppresses ordinary close errors as before, and declares that cancellation may escape;
- cancellation release marks the connection invalid, resets its held state, and returns it through an observer-free pool release exactly once; an open pool does not proactively close or discard it, while an already-closed pool retains its existing bounded destruction and capacity-repair behavior;
- a later borrow reconnects an invalid connection from the last confirmed tracked database rather than inspecting the canceled native client; ordinary invalidation still closes the known-bad socket deterministically;
- cancellation raised while logging release failure takes the same invalidating observer-free release path, while successful queueing/watch logging retains the existing discard behavior;
- fixed pool bookkeeping runs, the original cancellation remains primary over cleanup failure, and cancellation raised by required cleanup supersedes an ordinary primary failure;
- standalone, Sentinel, and cluster return shapes remain unchanged;
- `MULTI`/`EXEC` cleanup maps per-entry results without inventing a false cluster `EXEC === false` state.

`withoutSerializationOrCompression()` keeps its bare `finally` because serializer and compression are nonthrowing, in-memory phpredis fields. Managing an I/O-backed option would require failure-aware cleanup.

The Laravel-style subscriber proxy is a separate stream/channel boundary. Replace its truthy `Channel::pop()` loop with an explicit `false` check so only the channel sentinel signals closure and non-throwing cancellation is classified immediately. Required subscriber close still runs. Preserve an original cancellation over any close failure, let close cancellation supersede an ordinary primary failure, and otherwise retain the existing ordinary `finally` precedence. Do not apply the phpredis classifier to the subscriber's hooked PHP stream I/O.

Document the helper only as an internal phpredis boundary rule. Keep Telescope and Sentry consumers unchanged; correct event suppression means they naturally receive no false failure.

Canceling phpredis 6.3.0 during a TLS connection handshake under Swoole 6.2.2 has reproduced a PHP 8.4 process exit with code 139 before Hypervel can catch an exception. This is consistent with the hooked TLS cancellation defect addressed by [swoole/swoole-src#6182](https://github.com/swoole/swoole-src/pull/6182), but the shared root cause is not proven against a patched build. Cover the Hypervel boundary in a child PHP process so a native crash cannot terminate PHPUnit. The child enables `SWOOLE_HOOK_ALL`, runs a local stalled TLS peer, publishes connection and handshake readiness through child-local channels, cancels without sleeps, and succeeds only when `PhpRedisConnection` surfaces exact `CanceledException`. Skip this regression on the known-broken `SWOOLE_VERSION_ID <= 60202`; later unfixed runtimes fail through the child process. Do not add a Hypervel workaround or external TLS Redis service.

#### Filesystem

The shared `FilesystemAdapter::readStreamRange()` and the Hypervel-owned AWS and Google Cloud range readers rethrow exact cancellation before local wrapping, reporting, or `null` fallback. The shared adapter closes its locally owned source stream before rethrowing. This is the shared adapter's only broad `Throwable` catch; its named Flysystem catches already leave exact cancellation untouched. `FileResponseBuilder` also needs no change because its broad catch preserves throwable identity while arranging stream cleanup. Do not promise that arbitrary Flysystem adapters preserve cancellation and do not add vendor-specific cause-chain recovery.

### 11. Foundation, support, console, events, and servers

#### Generic framework helpers

Rethrow exact cancellation at these proven transparent boundaries:

- global `rescue()` before report/fallback;
- `SafeCaller` from both the user callback and its reporting path;
- `Timebox` from the callback before padding; cancellation during padding escapes naturally;
- `Container::get()` before PSR `EntryNotFoundException` wrapping;
- `Str::toStringOr()` before fallback;
- `StdoutLogger` Stringable and JSON conversion before logging fallback.

Do not widen this into a generic catch audit. Tests must preserve each helper's ordinary fallback and wrapping contract.

#### Exception and rendering pipeline

Preserve cancellation in Blade source mapping, source-href resolution, deprecation logging, environment encrypt/decrypt commands, and the exception handler's report/render/console paths. `shouldntReport()` returns true for exact cancellation, while cancellation raised during policy, throttle, context, logger, or renderer resolution escapes rather than becoming a second reported failure. `renderHttpException()` must not report or fall back after cancellation.

HTTP and console kernels rethrow cancellation before ordinary report/render/status conversion. In termination, cancellation stops subsequent yielding stages, wins over an earlier ordinary failure, and still allows fixed property/context cleanup. Populate lifecycle duration data only when a matching listener needs it.

`Foundation\Application::terminate()` stops at exact cancellation and rethrows it unchanged. Its existing exhaustive first-ordinary-failure behavior remains for non-cancellation exceptions.

`Console\Command::execute()` and `Console\Application::doRunCommand()` preserve cancellation raised by mutex release or deferred callback draining. Once cancellation is primary, do not start another deferred drain whose predicate helper could consume the control-flow signal. Ordinary cleanup remains exhaustive and preserves the first ordinary failure.

#### Health endpoint

Move the duplicated application and testbench health closure into a plain invokable `Hypervel\Foundation\Http\HealthCheckController`. This class exists to keep both route owners on one response and cancellation contract, rather than duplicating the same catch and rendering path. Inject the Foundation application contract, event dispatcher, exception handler, routing response factory, and View factory. Both route registrations resolve the same controller, preserving production HTML/JSON negotiation, the existing health view, debug rethrow, ordinary report/down behavior, and consistent testbench behavior. Exact cancellation escapes instead of becoming a false unhealthy response. Do not introduce a base controller or a new extension system.

#### Event and completion observers

At framework event boundaries:

- listener cancellation skips failure/completion observers and escapes;
- observer cancellation supersedes an ordinary listener failure and stops later observers;
- ordinary listener and observer behavior remains unchanged.

Completion events are allowed after cancellation only when they are the matching cleanup boundary for work already begun and their contract does not claim success. Console `AfterExecute` remains such a cleanup boundary. HTTP handled, terminated, and deferred lifecycle events are not emitted for a canceled request.

#### HTTP, Reverb, WebSocket, and View

- Main HTTP server preserves exact cancellation and skips success/failure response lifecycle events. The request remains in `RequestContext` through coroutine-deferred request cleanup; coroutine-context destruction clears it after those callbacks finish. Do not forget it early in `onRequest()`.
- Reverb HTTP server does not render or send a synthetic error response after cancellation; its fixed coroutine-local request state still unwinds normally.
- WebSocket handshake cancellation does not send a synthetic error response or publish a committed connection. Message callback cancellation terminates that callback quietly; connection-close cleanup always removes descriptor and context state.
- View rendering preserves exact cancellation while flushing renderer state. Cleanup cancellation follows the normal precedence rules and must not turn cancellation into an ordinary view error.

### 12. gRPC client and server

gRPC has both raw engine children and protocol state shared by multiple waiters, so cancellation behavior must be explicit rather than inferred from broad catches.

#### Client call and stream state

- Preserve exact cancellation through `BaseClient`, `Call`, unary, client-streaming, server-streaming, and bidirectional call APIs.
- Add public idempotent `Call::cancel(): void`. Applications need a deterministic way to abort an in-flight unary or long-lived streaming call before its deadline while retaining the multiplexed connection; relying on object destruction is nondeterministic, and canceling one waiting coroutine intentionally cannot terminate a shared logical call. The method closes the native stream, publishes a truthful CANCELLED status, prevents later retry publication, and clears retained payload state. Runtime coroutine cancellation remains local to one waiter and must not call this shared operation-level method.
- A canceled waiter detaches only itself. It must not poison shared stream state or cancel unrelated waiters.
- Retain a destructively dequeued payload in a per-reader pending slot until deserialization succeeds. A canceled or yielding reader leaves the payload for the next reader. Zero-length payloads are valid.
- Release reader claims in `finally`.
- Roll back retry/backoff state only for transitions that were not externally published.
- Explicit call cancellation and ordinary terminal protocol errors clear pending state.
- Retry keeps its existing attempt semaphore for transition serialization, but `cancel()` never waits on that semaphore. A lazily allocated private channel wakes a positive backoff delay; cancellation during backoff or attempt creation is rechecked before publication, an unpublished replacement state is abandoned, and an ordinary failure from the unpublished factory does not replace the already-published cancellation.
- Logical cancellation claimed while a completed attempt is still retry-eligible is stored on the call and mapped before the four reachable terminal consumers use status: `metadata()`, `trailers()`, `status()`, and `nextPayload()`. Writer completion needs no mapping because retryable calls cannot expose streaming writes and direct cancellation already rewrites that state.
- Increment the attempt count only when a replacement state is published. Repeated cancellation remains idempotent, and cancellation after an already-final logical outcome preserves that outcome.

#### Connection receiver

The raw receiver child self-publishes ownership as described above. `close()` can cooperatively cancel and join it; no exact cancellation escapes unhandled from the raw child. Cancellation while native transport state is uncertain terminates that connection for other multiplexed calls while preserving the canceled caller's exact exception. Closing a set of client connections retains the first cancellation over ordinary close failures.

In `closeRetiredConnectionIfIdle()`, terminate the connection and return success instead of throwing a post-termination native-close exception that has no consumer and would escape the raw receiver as worker-fatal:

```php
$this->terminateWhileLocked(new ConnectionException(...));

return true;
```

Keep `terminateWhileLocked()` returning its enriched exception because its other callers consume that value.

#### Server deadline and terminal boundary

The deadline owner cancels only the active handler. Exact handler cancellation is a terminal deadline outcome, not an internal error. Preserve the deadline response and fixed context cleanup without reporting the handler as an application failure.

`MessageSerializer`, `ExceptionMapper`, `ResponseFactory`, and server/client protocol boundaries rethrow exact cancellation before `RpcException` conversion. This includes unary encoding, streaming iterator priming/advancement, frame serialization, and failure reporting. Server callbacks that run as raw children consume terminal cancellation after publishing the correct deadline state.

## File map

The final implementation is expected to touch these package areas. Existing correct cancellation branches in the baseline should be retained and tested rather than rewritten for churn.

- Contracts/engine: `src/contracts/src/Engine/{ChannelInterface,CoroutineInterface}.php`, `src/engine/src/{Channel,Coroutine}.php`.
- Coroutine/concurrency: `src/coroutine/src/{Coroutine,WaitGroup,Waiter,Parallel,Concurrent,WaitConcurrent}.php`, `src/coroutine/src/Exceptions/ChildCancellationException.php`, `src/concurrency/src/{CoroutineDriver,SyncDriver}.php`, Saloon pool orchestration, and Scout's concurrent import runner.
- Coroutine owners: coordinator Timer, Engine SafeSocket, console SignalRegistry, signal SignalManager, prompts PromptAnimation, watcher Watcher, Reverb RedisPubSubProvider, Sentry HttpPoolTransport, database/Redis heartbeat owners, HTTP startup gate, gRPC receiver.
- Queues: Queue, Worker, SyncQueue, DeferredQueue, BackgroundQueue, DatabaseQueue, SqsQueue, FailoverQueue, jobs, and cancellation-sensitive middleware.
- Pools/filesystems: object-pool Channel/ObjectPool/Lease/proxies/streams, connection-pool Channel/Pool/Connection, queue and filesystem pooled adapters, shared filesystem byte-range reads, and AWS/GCS range readers.
- Cache/Redis/database/HTTP: the concrete boundaries named above, Redis connection/proxy/subscriber ownership paths, database transaction callback executors, plus the internal Redis cancellation classifier.
- Foundation/support/server: helpers, Container, Foundation Application, exception pipeline, console command/application, kernels, health controller/registrations, event dispatcher, HTTP/Reverb/WebSocket/View boundaries.
- gRPC: client Call/Connection/StreamState/call variants/retry, serializer, and server deadline/callback paths.
- Public docs: `src/docs/coroutines.md`, `src/docs/concurrency.md`, and `src/docs/grpc.md` for explicit call cancellation.

Do not add documentation merely to narrate internal catch placement.

## Testing plan

Use deterministic channels/barriers and explicit ownership signals. Do not use sleeps to guess whether a child started. Assert the same cancellation object where exact identity is part of the contract.

### Engine and coroutine primitives

- Channel reports canceled only immediately after a canceled operation and distinguishes timeout/closure.
- Coroutine reports false outside a coroutine and true at the immediate cancellation boundary.
- WaitGroup clears waiting state on timeout, ordinary failure, and cancellation.
- Waiter cancels a live child when its parent is canceled, avoids double cancel, preserves ordinary results, and wraps independent child cancellation.
- High-level Coroutine does not report terminal cancellation; ordinary child failures still report.
- Ordinary startup-hook failures are reported promptly in the child context and retain report-and-continue behavior. Exact hook cancellation remains terminal without requiring a test that suspends inside a callback whose contract forbids suspension.

### Structured owners

- Parallel handles cancellation during child creation, cancels only live children, cannot mutate-snapshot into an invalid cancel, preserves keys/order, and wraps independent child cancellation. One deterministic reuse regression keeps two canceled callbacks parked until the next run starts, then makes one return and one throw; neither result nor throwable may enter the next run's published state.
- Concurrent never leaks or double-releases a slot across pre-start failure, post-start parent cancellation, child failure, and success.
- WaitConcurrent timeout remains reusable; explicit cancel targets every body active at call time; parent and independent-child cancellation remain distinct.
- A supported startup regression makes an ordinary hook fail and its exception handler yield before the body. It proves prompt in-context reporting, ownership transfer before reporting, and exactly-once slot/count release for Concurrent and WaitConcurrent after cancellation. The test releases the blocked reporter and joins relevant child IDs so it cannot pass with a stranded coroutine.
- Saloon cancellation while its request iterable is suspended cancels active bodies; independently canceled children surface in keyed pool failures without invoking handlers. Scout surfaces the same child failure through its runner.
- Waiter, Parallel, Saloon, and Scout assertions verify that every `ChildCancellationException` has a boundary-specific message and the exact native cancellation as its previous exception.
- CoroutineDriver preserves keys, context, first failure order, and documented timeout behavior.
- Queue daemon orderly stop waits without polling; abnormal stop cancels admitted jobs and emits no false queue outcome.

### Creation handoffs

For every listed high-level owner, force cancellation after the owned wrapper transfers state but before the application callable begins. Use the supported yielding-reporter path rather than a suspending startup hook. For raw infrastructure children, interrupt only after their first-statement marker. Prove that the child is discoverable or the consumable is released exactly once. Cover scalar and array signal registration rollback, timer creation failure, HTTP worker-gate retry, and gRPC receiver close.

The scalar SignalRegistry regression must prove that a registration failure cancels the newly started waiter when the handler stack becomes empty; array coverage must prove the failing signal and every prior successful registration are all rolled back.

### Queue and external publication

- Cancellation in payload serialization/encryption does not wrap or emit failure.
- Database batch and failover queue do not continue publication.
- SQS tests cover single and batched pre-broker overflow cleanup, ambiguous broker-cancellation pointer retention, bounded multi-path cleanup precedence, unmatched queueing events, per-chunk isolation, and listener cancellation. Job deletion coverage proves that lease-release cancellation prevents overflow deletion from being invoked and that overflow-deletion cancellation supersedes an ordinary release failure.
- Worker tests prove no report/fail/release/retry/attempt/completion after daemon cancellation and that reserved work can be redelivered.
- Queue middleware and every concrete execution mode preserve exact cancellation.

### Pools, cache, Redis, database, HTTP, and filesystem

- Pool channel cancellation never returns/creates a resource; fixed capacity bookkeeping survives destruction cancellation.
- Connection-pool contention tests cover native throwing and converted non-throwing cancellation, unchanged capacity, no phantom borrow, and successful later checkout. Post-checkout maintenance cancellation discards the unreturned borrow and preserves exact identity.
- Lease tests cover all four primary/cleanup combinations and configuration failure immediately after acquire. Focused consumer tests prove that each pooled filesystem, stream, queue, and job boundary delegates to that shared precedence instead of reimplementing it.
- Pool close tests prove finite drain and first-cancellation retention without unbounded waiting.
- Connection tests prove exact cancellation is never retried, canceled release listeners still release exactly once, an earlier cancellation survives release cleanup, and cancellation from release itself escapes when no earlier failure exists.
- Cache tests prove no miss/failure/failover event, fallback, partial stack continuation, or false lock/limiter result on cancellation. Failover coverage proves first-success, every-store, and lock-flush operations stop before the next backend while ordinary listener interruption and failure aggregation remain unchanged. Stack coverage proves a throwing or canceled current write is compensated with earlier completed layers and cannot be resurrected by read back-fill, an explicit `false` result preserves an untouched pre-existing value, full reverse-order compensation continues with the required failure precedence, and later cancellation supersedes an earlier ordinary multi-store failure.
- SwooleStore tests interrupt shared-claim cleanup and prove the store remains usable with correct precedence.
- Database tests prove no retry/wrap/fallback after cancellation, pool/resolver/lifecycle drains finish their bounded selected sets with the required precedence, pooled release finalizes exactly once, disconnect resets local state, manager cancellation skips transaction events, ordinary manager failure still permits its event, and exact cancellation escapes each transaction recovery catch. Commit callbacks stop within and across transaction records, while rollback callbacks finish their bounded drain with cancellation precedence. HTTP tests prove no retry/wrap/fallback after cancellation.
- Redis unit and integration tests cover native standalone/cluster wrapping, transactions, pooling, heartbeat, events, and the subprocess-isolated TLS connection cancellation above. Connection release coverage proves exact and wrapped cancellation classification, exactly-once observer-free release, invalid reconnect database selection, ordinary deterministic close, no proactive open-pool close during cancellation, and correct closed-pool cleanup. Subscriber coverage proves boundary-named synthesis after non-throwing channel cancellation, exact callback-cancellation identity, cleanup precedence, falsy message handling, and unchanged orderly closure and ordinary failure behavior. Run integration coverage against the supported Redis extension/service matrix.
- Shared, AWS, and GCS adapter tests prove exact cancellation at Hypervel-owned range-reader boundaries under report-and-fallback configuration, no false report, shared source-stream cleanup, and unchanged ordinary fallback.

### Framework and server boundaries

- `rescue`, SafeCaller, Timebox, Container, Str, and StdoutLogger preserve exact cancellation and retain ordinary contracts.
- Exception handler and HTTP/console kernels do not report/render/status-convert cancellation, including cancellation raised during policy/report/render/termination.
- Shared health controller preserves HTML and JSON behavior while cancellation escapes in application and testbench routes.
- Event tests cover listener cancellation, observer cancellation precedence, and ordinary observer behavior.
- HTTP tests prove request completion events are not emitted after cancellation, a coroutine-deferred listener sees the exact active request, and the parent has no request context after the child request coroutine exits. Reverb, WebSocket, and View tests prove no synthetic error/success work continues and fixed context/render state is cleared.

### gRPC

- Unit tests cover serializer transparency, idempotent operation-level cancel, per-waiter detachment, pending payload reuse including empty payload, cancellation during retry backoff and attempt creation, concurrent retry observers, exact attempt publication counts, unpublished-state abandonment, retry rollback boundaries, receiver ownership, and retired-connection close.
- Integration tests cover unary and every streaming shape, deadline cancellation, one waiter canceling while another continues, abandoned call cleanup, and connection reuse/closure.
- Use the real integration server for lifecycle behavior; mocks are sufficient only for local protocol state.

### Regression and quality gates

For each corrected boundary, keep at least one ordinary exception/timeout/fallback assertion so cancellation handling cannot silently change the existing Laravel-shaped contract.

Run focused package tests while implementing, then:

```bash
composer fix
```

This must complete formatting, static analysis, and the parallel test suite. Run required Redis and gRPC integration workflows separately when their services are not included in the default suite.

## Implementation order

1. Add engine cancellation classification, child-cancellation exception, and deterministic primitive tests.
2. Correct Coroutine, WaitGroup, Waiter, Parallel, Concurrent, WaitConcurrent, and concurrency driver ownership.
3. Correct every creation-time ownership handoff and test the post-start cancellation window.
4. Correct queue execution boundaries and verify them before replacing daemon polling with structured cancellation.
5. Correct pool/channel/lease ownership, then cache, Redis, database, HTTP, filesystem, and queue publication boundaries. Queue-level failover tests may land first, but Redis-backed failover cancellation is not complete until phpredis exceptions are normalized at the Redis-owned boundary.
6. Correct Foundation/support/event/server boundaries and consolidate the health controller.
7. Correct gRPC call, receiver, payload, deadline, and raw-child terminal behavior.
8. Update the two public coroutine/concurrency docs and any strictly necessary package contract notes.
9. Run focused integration matrices and `composer fix`.
10. Review the complete diff for unsupported catch additions, duplicate cleanup logic, public API drift, yielding work after cancellation, and stale documentation.

## Completion criteria

- Every first-party exact cancellation owner has a defined parent, child, and terminal boundary.
- No included boundary reports, wraps, retries, fails over, publishes completion, or returns an ordinary fallback for cancellation.
- Independent child cancellation is distinguishable from parent cancellation.
- Creation-time ownership cannot leak a child, count, slot, signal, timer, receiver, or pooled resource.
- Queue shutdown no longer polls and does not create false job outcomes.
- Required fixed cleanup is bounded; no generic cleanup scheduler, token model, or vendor workaround exists.
- Ordinary behavior and Laravel-compatible public APIs are preserved.
- Public documentation explains the stable contract without exposing internal implementation trivia.
- The phpredis TLS-handshake regression is isolated in a subprocess, skipped only on the known-broken Swoole runtime, and surfaces exact cancellation through Hypervel without a native workaround.
- Focused service integration tests and `composer fix` are green.
