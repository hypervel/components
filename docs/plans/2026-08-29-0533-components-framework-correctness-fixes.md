# Framework Correctness Fixes

## Goal

Fix a set of verified framework defects in cache, queue, console, gRPC, Sentry, and support code. Each correction stands on its own, preserves Laravel-shaped public APIs where they remain correct, and uses the smallest owning boundary. The changes must not add general observer machinery, compatibility shims, or unrelated coroutine-cancellation handling.

## Design rules

- Preserve existing Laravel APIs unless the verified defect is in the API contract itself.
- Fix each defect at the lowest framework boundary that owns the behavior.
- Keep hot paths unchanged except for the direct branch or return-value handling needed by the fix.
- Preserve exact `Swoole\Coroutine\CanceledException` only where a new exhaustive catch would otherwise turn cancellation into ordinary cleanup work. Do not widen this into a framework-wide cancellation audit.
- Preserve the first ordinary failure when cleanup must continue, while attempting all independently required cleanup.
- Add focused regression tests for every changed behavior. Do not add test-only production APIs or speculative edge-case machinery.
- Document only public behavior developers need to know.

## Cache tag flush results

`TagSet::reset()` and `flush()` return `bool` so tagged repositories can report the backing store's real outcome instead of unconditional success. Update `VersionedTagSet`, Redis `AllTagSet` and `AnyTagSet`, and `StackTagSet` accordingly.

The multi-item tag sets must:

- attempt every remaining tag or stack layer after a `false` result;
- return `false` when any operation returns `false`;
- retain the first ordinary exception, continue the independent remaining operations, then rethrow it;
- stop immediately and preserve the exact cancellation instance.

Keep this shared iteration rule on `TagSet`; it has several real consumers and avoids divergent implementations. Bulk flushes continue to dispatch through the per-tag `flushTag()` extension point, and Redis all-mode reset dispatches through `resetTag()`. These methods keep their Laravel-shaped string results; a missing delete target is an idempotent success, while backend failures throw. `VersionedTagSet::resetTag()` still returns the generated identifier even when a null store rejects persistence, matching its existing public contract, so bulk reset uses the protected `writeTagId()` extension point instead.

`TaggedCache::flush()` returns the tag-set result, emits `CacheFlushed` only for success, and emits the existing `CacheFlushFailed` event for a false result. A thrown tag-set failure continues to escape normally.

Update cache documentation for truthful tagged-flush results, including the null driver. Add one concise porting note because Laravel subclasses that retain `void` return types are not signature-compatible with Hypervel.

### Tests

- Every tag-set family reports its backing operation's result correctly.
- Empty and repeated tagged flushes succeed and emit success events.
- Multi-tag and stack implementations attempt later entries after false and ordinary failure, preserve the first ordinary exception, and stop on exact cancellation.
- Tagged cache emits the matching success or failure event and returns the exact boolean.
- Null-store tagged flush returns false while tagged reads remain misses.

## Cache failover history

`FailoverStore` currently replaces its consecutive-failure snapshot in `finally`, even when a `CacheFailedOver` listener interrupts an attempt. That can falsely mark unattempted stores as recovered and cause duplicate failure events on the next request.

When listener dispatch interrupts either failover loop, retain:

- failures observed during the current attempt; and
- previous failures for stores the current attempt did not reach.

Do not claim recovery for an unattempted store and do not duplicate a failure just observed. A normally completed operation still replaces the history with its fresh snapshot. Preserve the exact listener exception and keep normal failover ordering unchanged.

Normalize configured store names to a positional list once in the constructor. Sparse configuration keys must not be confused with iteration positions when retaining unattempted failure history.

### Tests

- An interrupting listener does not erase unattempted failure history.
- The next operation emits only genuinely new failure transitions.
- Both first-success and every-store attempt paths retain their existing ordinary success/failure behavior.

## Cache dispatcher refresh and Sentry configuration

`CacheManager::refreshEventDispatcher()` updates only resolved concrete `Hypervel\Cache\Repository` instances whose current dispatcher is non-null. This preserves stores configured with events disabled and avoids calling implementation-specific methods on custom repositories that implement only the cache contract. It remains a boot-or-tests worker-wide mutator.

Sentry cache tracing and breadcrumbs must honor each store's `events` setting. Remove the Sentry boot-time loop that force-enables events on every configured store. Do not add a second event source or mutate application cache configuration.

### Tests and docs

- Dispatcher refresh replaces dispatchers on enabled resolved repositories.
- Disabled repositories remain disabled after refresh and `Event::fake()` restoration.
- Contract-only custom repositories are left untouched.
- Sentry records no cache span or breadcrumb from a store whose events are disabled.
- Sentry documentation states that cache telemetry honors the store setting.

## Queue pop waiting

`Worker::daemon()` currently creates a `Waiter` with its ten-second default timeout. This contradicts the documented Redis `block_for=0` behavior and terminates idle workers when a configured blocking pop exceeds ten seconds.

Create the pop waiter through one protected `createPopWaiter(): Waiter` method returning `new Waiter(-1)`. Keep the per-pop child coroutine because it owns and releases coroutine-scoped pooled queue connections. Do not add polling, a second timeout setting, or new worker state.

### Tests

- The default pop waiter is unbounded.
- The protected factory remains overridable for focused worker tests.
- Existing timeout monitoring and queue-pop behavior remain unchanged.

## Deferred callback ownership

Deferred callbacks must drain at the execution boundary that owns them:

- The `JobAttempted` listener skips only connections whose event runs inline in another owner's frame. That is the `sync` connection. A `deferred` job runs from its coroutine-exit callback after the enclosing lifecycle has drained, while a `background` job runs in a fresh coroutine; both own their callback collections.
- A `Command` that creates its own coroutine drains that coroutine's collection after `AfterExecute` observers and isolation-mutex cleanup. Commands already running in a request, job, or command coroutine leave ownership with that enclosing lifecycle.
- `Console\Application::doRunCommand()` drains the non-coroutine frame for CLI execution. This also covers plain Symfony commands and callbacks registered in the application frame around a Hypervel command.
- The application owner is gated by `runningInConsole()`, absence of a coroutine, and one internal coroutine-context marker held through the drain. Nested non-coroutine command calls therefore leave the shared collection to the outer application frame. Do not expose this marker through a public API.
- Remove the `CommandFinished` drain listener. Its event fires in the wrong frame for coroutine commands and can recursively drain the same collection during nested programmatic calls.
- Resolve the scoped collection only when the owning frame already created it, so lifecycles that do not call `defer()` allocate nothing.
- A drain is one bounded pass. A callback registered while deferred callbacks are running is not executed by that lifecycle. In the non-coroutine console frame it remains on the shared collection for the next owning call; in a coroutine it disappears with that coroutine's scoped collection. Do not add a repeated drain or re-entrancy state: no supported framework path re-enters the same collection, while an unbounded drain can never terminate when callbacks register themselves.

When an owned command fails, run only callbacks whose `always` setting permits it. Preserve the first ordinary command or lifecycle failure. Exact cancellation remains terminal and skips arbitrary deferred callbacks, while fixed marker restoration and mutex cleanup still run. Keep this logic at the two existing console execution boundaries; do not introduce a callback runner or another lifecycle abstraction.

Immediate dispatch on the `deferred` queue connection naturally requires an active coroutine because it delegates to `Coroutine::defer()`. Keep the native failure rather than adding a redundant guard or fallback. Delayed dispatch remains valid because the timer invokes it from a coroutine.

### Tests

- Deferred and background queue jobs drain their own callbacks while sync jobs retain their existing behavior; jobs without callbacks do not resolve an empty collection.
- Default Hypervel commands drain their coroutine collection after fixed command cleanup, including when the event dispatcher is disabled.
- Non-coroutine Hypervel commands, plain Symfony commands, and the root CLI execution path drain the application-frame collection with correct success and `always` filtering.
- Non-console application frames do not claim or drain the non-coroutine callback collection, and leave no ownership marker behind.
- Commands that do not call `defer()` do not resolve or allocate the scoped collection.
- Nested programmatic calls do not drain the outer application collection; a real nested `Artisan::call()` from a deferred callback terminates and invokes each callback once.
- HTTP requests and queued jobs that invoke commands retain ownership of their collections.
- A callback registered during a non-coroutine drain remains for the next top-level call; one registered during a coroutine drain is not run and does not survive that coroutine.
- First ordinary failure precedence is preserved at both new ownership boundaries; exact cancellation skips deferred callbacks and preserves identity.

## gRPC abandoned-call cleanup

Dropping an unfinished client or bidirectional gRPC call before `writesDone()` can retain its `StreamState`, receiver coroutine, and pooled connection indefinitely when no deadline is configured.

Add `StreamState::abandonIfIncomplete(): void`. It is idempotent, releases buffered messages, and invokes the existing abandonment path only while no terminal status or failure exists.

Add a no-throw `Call::__destruct()` that invokes this resource-only path. It must not publish a status, create an application-level cancellation result, or run completion observers. Completed calls are naturally ignored by `abandonIfIncomplete()`. Destructors suppress cleanup failures because they may run during process shutdown.

### Tests

- Abandoning an incomplete state releases buffers and the native resource once without publishing a terminal result.
- Dropping an unfinished call invokes abandonment once.
- Dropping a completed call performs no abandonment work.
- Real client-streaming and bidirectional tests against the grpc-go interoperability server prove that dropping an unfinished call releases the connection for subsequent work. Hypervel's server exposes only unary and server-streaming routes.

## Support error fidelity

Remove broad catches from `SystemInfo::getCpuCores()`, `getTotalMemory()`, and `detectVirtualization()`. Their OS probes already represent unavailability through false or null results; dependency and runtime failures must remain visible. Reword the class contract to promise null only when the OS probe is unavailable. This also makes `getTotalMemory()` consistent with the existing uncaught `getMemoryLimitFormatted()` path when `ext-intl` is missing.

When `FileinfoMimeTypeGuesser` converts a finfo-construction failure to `RuntimeException`, retain the exact original throwable as `previous`.

### Tests

- Unavailable system probes still return null.
- A missing formatting dependency remains visible on both SystemInfo memory-formatting paths.
- Fileinfo construction failure preserves the exact previous exception.

## Documentation

Update only these public surfaces:

- `src/docs/cache.md`: truthful tagged-flush results, including null-store behavior.
- `src/docs/grpc.md`: dropping an unfinished call retires its pooled connection.
- `src/docs/helpers.md`: callbacks registered during deferred execution are not guaranteed to run.
- `src/docs/porting-from-laravel.md`: concise tag-set return-type and per-tag extension-point differences.
- `src/docs/queues.md`: immediate deferred dispatch requires an active coroutine; delayed dispatch supplies one through its timer.
- `src/docs/sentry.md`: cache telemetry honors each store's `events` option.

The remaining fixes preserve existing public behavior or repair internal lifecycle ownership and need regression tests rather than narrative documentation.

## Implementation sequence

1. Cache tag result contracts, implementations, tests, and docs.
2. Cache failover history and dispatcher refresh, with focused tests.
3. Sentry cache configuration behavior, integration test, and docs.
4. Queue pop waiter and focused worker test.
5. Deferred callback ownership across Console and Foundation, with focused lifecycle tests.
6. gRPC abandoned-call cleanup with unit and real integration tests.
7. SystemInfo and Fileinfo exception fidelity with focused tests.
8. Review the complete diff against `0.4`, run all changed test files, then run the full repository checks.

## Validation

Run each changed test file immediately after editing it. After every coherent package slice, run its focused suite. Final validation is:

```bash
composer fix
git diff --check
```

Review the final diff and commit series to confirm that every file belongs to one of the fixes above and that no observer hooks, broad cancellation audit, compatibility code, stale comments, or unrelated changes were included.

## Completion criteria

- Tagged cache flushes report the backing operation's real result.
- Failover history remains truthful when a listener interrupts an attempt.
- Cache dispatcher refresh and Sentry preserve explicit event-disable configuration.
- Queue workers honor indefinite and long blocking-pop settings.
- Deferred callbacks drain exactly once at the owning queue or console lifecycle.
- Abandoned unfinished gRPC calls release native resources without inventing a terminal result.
- SystemInfo and Fileinfo no longer hide the original supported failure.
- Focused and full checks pass, with no dead code, unnecessary machinery, or public API drift beyond the documented `TagSet` return type.
