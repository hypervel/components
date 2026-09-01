# Hypervel Observability Framework Prerequisites

## Goal

Add the remaining framework-owned lifecycle contracts needed by first-party observability integrations without coupling the framework to OpenTelemetry. Each change must remain useful to Sentry, Telescope, profilers, application listeners, and third-party instrumentation on its own.

This plan is the sole implementation source for changes to existing framework components and their owning tests/docs. The [OpenTelemetry package plan](./2026-08-27-1815-hypervel-opentelemetry-package.md) owns the new component, root package wiring, canonical OTel docs/navigation, and OTel-specific tests/workflow entries while consuming the public contracts summarized below. The framework changes may land as one PR, with implementation and review still divided into the coherent package slices below.

## Design rules

- Preserve Laravel-compatible public APIs unless Hypervel needs a new additive contract. Do not repurpose existing events whose timing or payload has different semantics.
- Put each hook at the authoritative framework boundary and expose the final data already owned there. Do not make observers reconstruct state from earlier events.
- Guard event construction and dispatch with `hasListeners()`. Boot-only observer runners and View callbacks branch before descriptors, closures, timers, or state allocation when nobody observes them.
- Keep framework packages telemetry-vendor-neutral. They expose facts and lifecycles; instrumentation owns clocks, spans, metrics, propagation, formatting, and exporters.
- Preserve the framework's existing coroutine-cancellation contract at every new hook. A cancelled operation must not emit a false completion or invoke observers after its terminal boundary.
- Preserve ordinary framework error precedence and dispatcher behavior. Do not add catch-all isolation around public listeners or observers.
- Keep hot-path additions constant-time and allocation-free while inactive. Avoid registries, decorators, proxy layers, compatibility shims, and generic pipelines when one typed hook is sufficient.
- The final code and docs describe only the resulting design. Do not retain superseded alternatives or implementation history.

## Public contract summary

| Package | Contract | Purpose |
| --- | --- | --- |
| HTTP server | `ResponseSent` | Final route/status/transport outcome at the response-send boundary |
| Queue | `JobPayloadFinalizing` and `finalizePayloadForQueueing()` | Last-mile mutable encoded payload before storage/broker send |
| Database | `QueryFailed` | Final logical query failure after reconnect/retry handling |
| Cache | enriched failure events and failover identity | Distinguish false results from thrown causes and preserve logical versus backing store identity |
| Database/Redis pools | `PoolFactory::pools()` | Enumerate only pools already created in the worker |
| Connection/object pools | waiter accessors and object-pool stats | Collection-time pending-demand observation |
| WebSocket server | `MessageHandled` | Message completion with the final ordinary failure |
| View | `observeRendered()` / `notifyRendered()` | Paired render completion without a second rendering abstraction |
| Scout | `EngineOperationRunner` and typed engine entry points | Observe exact remote/search-engine operations without engine decorators |
| gRPC | `GrpcOperationRunner`, typed descriptors/results, and `StreamState::finalStatus()` / `finalFailure()` | Observe one complete logical RPC and expose its terminal facts without waiting |
| Console | guarded optional events and synchronized Artisan replacement | Avoid inactive event allocation and keep Kernel/container Artisan state consistent |
| HTTP client | typed option constants and prior physical-send count | Exact retry/redirect resend ordinals at middleware boundaries |
| Redis | boot-time pool event refresh | Make event configuration order-independent |
| Coordinator | terminal recurring-callback cancellation | Stop a cancelled tick without logging it as an ordinary callback failure |

## HTTP response completion

Add `HttpServer\Events\ResponseSent`, extending the existing HTTP event base. In `HttpServer\Server::onRequest()`, dispatch it whenever a request was created and the callback was not cancelled, after the response send attempt or after determining there is no response, with the cumulative transport exception and before terminable middleware. This boundary supplies the final route/status while excluding unrelated termination work. Exact cancellation skips `RequestHandled`, `ResponseSent`, and `RequestTerminated`, because no response was handled or sent and `Kernel::terminate()` did not run; the original instance then escapes. Response output and terminable middleware remain skipped as they are today.

Update the HTTP test request harness to dispatch `ResponseSent` after `RequestHandled` and before `kernel->terminate()`. Tests do not perform native sending, so this is the equivalent boundary.

Use the same HTTP lifecycle for two first-party entry points that currently bypass it:

- `WebSocketServer\Server::onHandshake()` dispatches `RequestReceived` after request creation/context seeding, `RequestHandled` after routing produces the upgrade response and before native output, and `ResponseSent` after the 101/rejection send attempt. It covers only the HTTP upgrade, not the WebSocket connection.
- `Reverb\Servers\Hypervel\HttpServer` retains the supplied server name during bootstrap and dispatches the same events around its routed HTTP request and send boundary. A preflight rejection before a Hypervel request exists remains outside the lifecycle.

Preserve each server's native send, error, and cancellation order. Every added dispatch is listener-gated before event allocation, and no completion listener runs after cancellation.

## Queue final-payload boundary

Keep Laravel-compatible `Queue::createPayloadUsing()`, `JobQueueing`, and `raiseJobQueueingEvent(): void` unchanged. Add `Queue\Events\JobPayloadFinalizing` and protected `finalizePayloadForQueueing(...): string` immediately before the broker/storage boundary. The event carries connection, queue, job, mutable encoded payload, and normalized delay; the method returns its input immediately when no listener exists.

- Base `enqueueNow()` finalizes, raises existing `JobQueueing`, invokes the driver callback, and emits the existing terminal event.
- `DatabaseQueue::enqueueBatch()` finalizes and writes back every payload before `JobQueueing`, record construction, and insertion.
- `SqsQueue::sendBatchedMessages()` finalizes and writes back every payload before entry construction, overflow selection/storage, and size-based chunking. `JobQueueing` remains inside each actual chunk immediately before broker send.
- Sync queues never enter this broker lifecycle.

Terminal events carry the final encoded payload. Once SQS payloads are finalized, retain an outstanding set until each logical message receives one `JobQueued` or `JobQueueingFailed`. Any later preparation, overflow-store, or send failure emits one failure for every outstanding message, including unsent later chunks, without changing `JobQueueing` semantics. Multi-chunk producer durations necessarily begin at finalization, so later chunks include earlier chunk work.

This is one concrete last-mile extension point, not a general transformation pipeline. With no listeners it costs one guarded method call/lookup and allocates no event. If an application listens to both `JobPayloadFinalizing` and `JobQueueing`, each asynchronous message deliberately dispatches both guarded events; document that explicit cost. A finalizer-listener exception follows normal dispatcher behavior and creates no invented terminal event.

## Database final failure

Add `Database\Events\QueryFailed` with query, bindings, elapsed milliseconds, connection/name, read/write role, and final `Throwable`. Dispatch it from `Connection::run()` only when the logical operation finally escapes after lost-connection handling. A failed first attempt followed by success emits only `QueryExecuted`, whose elapsed time covers the complete call.

The existing cancellation branch remains before failure dispatch and error-count mutation. Guard `QueryFailed` construction with `hasListeners()` and rethrow the same final ordinary throwable after successful dispatch. Listener failures retain normal dispatcher semantics.

## Cache completion and identity

Append `?Throwable $exception = null` after existing constructor arguments on `KeyWriteFailed`, `KeyForgetFailed`, `CacheFlushFailed`, and `CacheLocksFlushFailed`. Pass the exact caught exception from plain, stack, and Redis-tagged paths; false-result events retain null. Add guarded ordinary-failure dispatch around `Repository::clear()`, `Repository::flushLocks()`, `TaggedCache::flush()`, and `Redis\AllTaggedCache::flush()`, then rethrow the same throwable.

Keep `CacheFailedOver::$storeName` as the failed backing store and append nullable logical `failoverStoreName`. `CacheManager` supplies the configured logical name to `FailoverStore`.

Sentry treats `KeyForgetFailed` with a null cause as neutral and only an exact exception as internal error.

## Pool discovery and waiters

- Add `pools(): array` to database and Redis `PoolFactory`, keyed exactly like their internal caches. Enumeration never creates a pool.
- Add `waiters(): int` to both connection-pool and object-pool Channel implementations.
- Add `getWaiters(): int` to `Hypervel\Pool\Pool`, `Hypervel\ObjectPool\ObjectPool`, and the object-pool contract.
- Add `waiters` to the object-pool contract/implementation's existing `getStats()` snapshot.

Keep the two Channel implementations synchronized. Do not add a general stats array to `Hypervel\Pool` or expose `creating`, `closed`, or mutable ownership internals.

## WebSocket message completion

Add `WebSocketServer\Events\MessageHandled(int $fd, Frame $frame, string $server, ?Throwable $exception)`. Once `MessageReceived` has occurred, dispatch it after the application callback's normal or ordinary-failure path. Preserve the first receive-listener/application-callback failure, retain existing failure reporting, and guard event allocation. Cancellation returns immediately and emits no completion event.

## View completion observers

Keep `Factory::observeRendering()` and add matching `observeRendered(callable)` / `notifyRendered(ViewContract $view, ?Throwable $exception)`, including the corresponding Support facade annotations. Put the bracket in `View::renderContents()`, shared by `render()` and public `renderSections()`: notify rendering, run `getContents()`, then notify completion before `decrementRender()` so both observer halves see the same depth.

On ordinary failure, call every completion observer and preserve the original render throwable; if rendering succeeded, rethrow the first completion-observer failure after attempting the list. Cancellation from a pre-render observer or engine skips completion. Cancellation from a completion observer stops the list and supersedes an earlier ordinary render failure. Existing render-state cleanup still runs.

Preserve the current straight-line render path when there are no completion observers: no extra callback, try/catch, time read, or event. Core View owns no elapsed-time calculation. Consumers pair nested renders by exact `ViewContract` identity; do not add tokens or a second observer abstraction.

## Scout operation observers

Add readonly `Scout\EngineOperation` containing bounded operation, configured engine identity, model class, exact index/dataset, and nullable model count. Add `EngineOperationRunner` and `EngineOperationObserver`; `starting(EngineOperation): mixed` returns an opaque token passed to `finished(EngineOperation, mixed $token, ?Throwable $exception): void`. The runner brackets `search`, `paginate`, `update`, `delete`, `flush`, and `delete_by_filter` and branches before descriptor/allocation when unobserved.

Add non-abstract entry points to base `Scout\Engines\Engine`: `runSearch()`, `runPaginate()`, `runUpdate()`, `runDelete()`, `runFlush()`, and `runOperation(string $operation, Builder $builder, Closure $callback, ?string $index = null): mixed` for already-interface-narrowed Builder branches. Do not add wrappers for methods that exist only on optional interfaces. An omitted index means the Builder's explicit/read index, resolved lazily after the observer guard; controlled callers may supply another exact target. Do not derive operations or indices from user query data.

Route base `keys()`, `get()`, `cursor()`, Builder raw/pagination branches, Meilisearch `keys()`, Searchable synchronous paths, indexing jobs, and model flush through the appropriate wrappers. Base reads bracket only the engine call and leave mapping/hydration outside; a specialized optional pagination method is self-contained and is bracketed as a whole. Generic pagination applies its raw-result callback once after `runPaginate()` and shares that transformed payload with mapping/items and Builder total calculation. The total calculation keeps the already-prepared engine; when its query callback requires a complete ID search, base `Engine::keys()` delegates through `runSearch()` and deliberately emits a sibling `search` after `paginate`. Empty update/delete collections bypass observation. Derive read indices from explicit Builder index or `searchableAs()` and write indices from `indexableAs()`. For built-in filtered deletion, retain preparation/filter validation first, resolve the final write index once, and bracket only the remote deletion/wait using that same value. Third-party `DeletesByFilter` engines opt in through the public generic entry point and supply the explicit write index they actually target.

Preserve third-party engine compatibility: do not convert abstract methods to template methods and do not decorate engines. `EngineManager` injects the runner and calls `setOperationRunner(EngineOperationRunner $runner, string $engineName): static` after resolving built-in or custom engines. Directly constructed engines remain unchanged unless explicitly configured. Protected `hasObservableOperations(): bool` defaults true and is false on `NullEngine`; DatabaseEngine and CollectionEngine observe real reads but bypass no-op writes.

Observer failure precedence matches event dispatch. A starting failure prevents the engine call and completes prior starters for ordinary failures. An engine failure remains primary over ordinary completion failures. Success rethrows the first ordinary completion failure after attempting all. Cancellation at start/engine skips completion; cancellation during completion stops the list and wins.

## gRPC logical-call lifecycle

Add a gRPC-owned `GrpcOperationRunner`, observer interface, typed client/server descriptors, typed result, and internal handle. The observer contract is `starting(GrpcOperation): mixed` plus `finished(GrpcOperation, mixed $token, GrpcOperationResult): void`. Client metadata replacement is fluent on the same descriptor so observers can inject in registration order while still returning opaque tokens. The server descriptor contains only raw method/path/headers and retained server identity plus a tolerantly recognized nullable `ServiceMethod`; constructing it performs no validation or normalization that can alter preflight. `GrpcOperationResult` carries nullable final status, nullable final throwable, and logical attempt count. Branch before descriptor/handle allocation when there are no observers. Keep this runner separate from Scout; their contracts and lifecycles are materially different.

Add non-blocking `Client\StreamState::finalStatus(): ?Status` and `finalFailure(): ?Throwable`. Unlike `status()`, they return the already-published terminal facts without waiting. The logical-call owner uses this exact pair to distinguish status failures from transport failures; do not call the blocking accessor and catch its result as control flow.

Resolve one runner from the container for clients and server bootstrap without changing generated-client constructors. Client observation starts before metadata encoding, finishes pre-call failures immediately, and passes the handle into `Call` so one lifecycle spans unary and streaming calls across retries/backoff. Server observation starts after the worker-start gate and only the side-effect-free raw reads needed for a tolerant descriptor, before preflight; authoritative preflight retains independent validation. Finish after response emission/stream cleanup; do not attach telemetry to `GrpcStreamedResponse::completeUsing()`, which remains internal call-context cleanup whose producer must stay non-yielding and non-throwing. Derive the result from the final gRPC trailers or map a plain preflight response from its HTTP status, while an emission throwable takes precedence over undelivered stale trailers. Server cancellation performs fixed framework cleanup but skips observer completion.

Mark handles finished before callbacks. Attempt every ordinary completion callback, but preserve a real operation exception or non-OK status over observer failures; on an otherwise successful operation, rethrow the first ordinary completion failure. Cancellation from a completion observer stops the list and wins. Existing explicit call cancellation is a normal CANCELLED result and completes observers exactly once. Publish terminal call state before invoking observers, and keep the existing owner-token/finally ordering; no new lock, registry, or ownership state is needed.

## Recurring timer cancellation

Make `Coordinator\Timer::tick()` treat exact `CanceledException` from its callback as terminal: break the recurring loop and let the existing outer `finally` clear timer ownership and counters. Do not log the cancellation or continue to another tick. Ordinary callback failures retain the existing log-and-continue behavior. This aligns recurring callbacks with `Timer::after()`, whose callback cancellation already escapes through its outer cleanup, without adding another cancellation owner or cleanup path. Cancellation raised while waiting already escapes outside the callback catch and needs no change.

## Console lifecycle correctness

Preserve the current `AfterExecute` contract: framework dispatches include input, throwable, and normalized exit code, while the nullable constructor fields retain compatibility with manual event construction. Guard Foundation Console Kernel `CommandStarting`, `CommandFinished`, and `Terminating` event construction/dispatch with `hasListeners()`, matching the HTTP kernel and optional-event convention.

Make `Kernel::setArtisan()` the single owner of the Kernel property and the container's Console Application instance. Assign the property first so a rebound callback that resolves the contract re-enters `getArtisan()` through its existing-instance branch. A non-null application is then published with `instance()`; null clears the cached contract instance with `forgetInstance()` while preserving its lazy singleton binding. `getArtisan()` constructs and configures the application, then delegates publication to `setArtisan()`. This keeps direct replacements and test resets consistent without changing the Laravel-facing setter API.

## HTTP client resend facts

Expose typed public `PendingRequest::DATA_OPTION` for the existing structured-payload option and new `PRIOR_SENDS_OPTION`; update first-party consumers to use the constant. Keep the prepared-body option protected.

Track one integer physical-send count per logical call and reset it in `send()`. Copy the current count into `PRIOR_SENDS_OPTION` for each Hypervel retry attempt, then increment the live count in the before-send handler for each physical request. Redirect middleware preserves the attempt base and supplies `__redirect_count`, so consumers derive the exact zero-based ordinal as base plus redirect count. Do not seed or alter Guzzle's internal redirect counter.

## Redis event refresh

Make `RedisManager::enableEvents()` / `disableEvents()` apply their boot-time worker-wide override to pools created earlier during the same startup lifecycle. Set the config override first, inspect only existing `PoolFactory::pools()`, and call existing `purge($name)` for mismatched snapshots. Never create a pool while toggling events. Repeated matching calls do nothing; fail fast on the first purge failure. Existing proxies resolve the replacement generation; borrowed connections finish against the closed generation and are destroyed on release.

## Tests

Keep regression tests beside each owning component. The test suite must prove both the active contract and the inactive fast path.

### HTTP server, WebSocket handshake, and Reverb

- `ResponseSent` construction, request/response/exception data, and order after `RequestHandled` but before termination.
- Normal send, no-response, transport failure, and cancellation producing no completion event.
- HTTP test-harness parity.
- WebSocket 101 and rejection handshakes use the same three events without changing native output order.
- Reverb retains configured server identity, brackets routed requests, and leaves pre-request rejections outside the lifecycle.
- With no listeners, no new event object is constructed.

### Queue

- No-listener finalization returns the identical payload and constructs no event.
- Existing `createPayloadUsing()`, `JobQueueing`, terminal event timing, and Laravel-facing method signatures remain unchanged.
- Base, Database batch, and SQS batch paths persist/send the final listener-mutated payload.
- SQS overflow sizing/chunking uses finalized payloads; every finalized logical message receives exactly one terminal success/failure, including later unsent chunks after a preparation, overflow, or send failure.
- Sync queues bypass finalization.
- Finalizer listener failures retain normal dispatcher behavior and invent no terminal event.

### Database

- Successful query, final `QueryException`, reconnect/reconnector failure, and retry-then-success behavior.
- `QueryFailed` carries exact final throwable, full logical elapsed time, query/bindings, connection identity, and read/write role.
- Cancellation emits no `QueryFailed` event.
- Event construction is absent without listeners.

### Cache and Sentry

- Exact exception versus false-result null on all four enriched failure events, from plain, stack, and Redis-tagged paths.
- `CacheFailedOver` keeps distinct logical and failed-backing identities.
- Sentry does not duplicate failover emission and reports null-cause forget failure neutrally.

### Pools

- Database/Redis factory enumeration returns only existing cached pools and never creates one.
- Both Channel implementations report exact waiter count.
- Connection/object pools delegate waiter counts correctly; object-pool `getStats()` includes a consistent `waiters` value.
- Existing capacity, borrowed, release, and closed-generation behavior remains unchanged.

### WebSocket messages and View

- `MessageHandled` success/failure order, first ordinary failure preservation, failure reporting, no-listener allocation guard, and no completion after cancellation.
- View success and `renderSections()` pairing; pre-observer, engine, and completion-observer ordinary failures; all ordinary completions attempted; original-render failure precedence; completion before render-depth decrement.
- Exact-instance nested pairing and concurrent coroutine isolation, including an inner pre-observer failure before a later observer starts.
- Pre-render/engine cancellation skips completion; completion cancellation stops later observers and wins; fixed render state is still cleared.
- The no-completion-observer path remains the previous straight-line implementation.

### Scout

- All six operations through Builder, Searchable, jobs, and each built-in filtered-deletion implementation.
- The five typed wrappers and generic wrapper branch before descriptor/index resolution; optional-interface branches call only methods narrowed by their surrounding `instanceof`.
- Generic paginators share the transformed payload between results and metadata, retain query-callback-correct totals, and emit sibling `paginate` then `search` operations when total correction requires a complete ID search.
- Exact read/write index derivation with distinct `searchableAs()`/`indexableAs()` values, and equality between descriptor and remote SDK target.
- Validation/preparation failures and empty update/delete emit no operation.
- Exact model count, no-op-write bypass on Database/Collection engines, NullEngine silence, subclass opt-in, built-in/custom EngineManager wiring, direct-engine unchanged behavior, and public setter opt-in.
- Nested/concurrent token isolation and ordinary/cancellation observer precedence.

### gRPC

- Unary, client-streaming, server-streaming, and bidirectional client/server logical operations; metadata replacement order; retries/backoff, including one completion with the full successful attempt count; pre-call and preflight failures; stream completion; final status/error/attempt count; nested/concurrent calls; and no-observer allocation guards.
- `StreamState::finalStatus()` / `finalFailure()` return the available terminal fact without blocking or mutating state, including a status-less transport failure.
- Existing explicit call cancellation produces one CANCELLED observer result; runtime coroutine cancellation produces no completion callback.
- Deterministic runner tests cover token ordering, ordinary/cancellation precedence, and result aggregation; real HTTP/2 tests cover observer behavior across all call shapes.

### Console, HTTP client, and Redis

- `CommandStarting`, `CommandFinished`, and `Terminating` are constructed and dispatched only when listeners exist; active listeners still receive the current payload and `AfterExecute` remains unchanged.
- A non-null `setArtisan()` replacement is returned by both the Kernel and container, including through a resolving/rebound callback. After container `make()` then null reset, the historical `resolved()` marker remains true; after direct `getArtisan()` then null reset, it becomes false because only the published instance supplied that fact. Both paths preserve the lazy binding, perform no eager resolution on reset, and construct/publish one fresh application on the next access.
- Public option constants replace every production literal, including Telescope's structured-data consumer.
- Exact resend ordinal across mixed redirects and Hypervel retries, pinning Guzzle's `__redirect_count` behavior without mutating it.
- Redis events-off warm pool, selective purge on enable, symmetric disable, no purge for matching/repeated calls, override-before-purge order, and continued behavior through replacement generations.

### Coordinator

- A cancellation surfaced from inside a recurring tick callback stops the timer silently, runs the existing cleanup once, and never reaches a later tick; ordinary callback failures remain logged and the timer continues.

Framework Redis event-refresh integration tests live under `tests/Integration/Redis/` and are included in the applicable standalone Redis, Redis Cluster, and Valkey workflow commands. OTel-specific Redis instrumentation coverage remains owned by the package plan.

## Documentation

Update only public behavior that developers need:

- `src/docs/cache.md`: nullable failure causes and logical versus backing failover identities.
- `src/docs/queues.md`: the `JobPayloadFinalizing` last-mile payload boundary, its relationship to the existing `JobQueueing` event, and the explicit two-event cost when an application listens to both.
- `src/docs/redis.md` and Telescope watcher guidance: event toggles apply to already-created startup-lifecycle pools; remove ordering caveats.
- `src/docs/pools.md`: add one concise cross-reference from dynamic object-pool naming guidance to the canonical OpenTelemetry cardinality section. Do not duplicate that section.
- Public API docblocks for View completion observers, Scout runner/setter, gRPC observer values, cache event fields, pool waiter/stat methods, and HTTP option constants state their actual contracts.

Observer error precedence and guarded event construction require regression tests but no narrative documentation. The existing `docs/todo.md` Sentry View-decorator migration is a Sentry-package behavior fix enabled by these View observers, not a framework contract required here; retain that concise pointer until the Sentry change is deliberately implemented.

## Implementation sequence

Each slice includes its owning tests and removes superseded code/comments immediately.

1. HTTP `ResponseSent`, request-harness parity, WebSocket handshake, and Reverb HTTP lifecycle.
2. Queue final-payload hook plus Database/SQS ordering and complete SQS terminal coverage.
3. Database final-failure event.
4. Cache event causes/results/failover identity and Sentry integration.
5. Pool enumeration/waiters and object-pool stats.
6. WebSocket message and View completion hooks.
7. Scout runner, wrappers, EngineManager setter, and built-in call-site routing.
8. gRPC runner/descriptors/results and logical-call wiring.
9. Console optional-event guards, synchronized Artisan replacement, and HTTP physical-send facts.
10. Redis event refresh and recurring Timer cancellation.
11. Complete public documentation, focused integration workflows, static analysis, formatting, and full validation.

If an implementation slice exposes a new non-trivial framework or Swoole defect, stop modification, trace callers/callees/tests and invariants, obtain peer consensus, and replace the affected plan wording with the final design before proceeding. Do not add a workaround for a confirmed Swoole defect.

## Validation

Run the narrow owning suites after each slice, then the combined affected-package suites. Final validation is:

```bash
composer validate --strict
composer test -- --filter 'HttpServer|Queue|Database|Cache|Pool|WebSocket|View|Scout|Grpc|Console|Http|Redis|Sentry'
vendor/bin/phpunit --no-progress tests/Integration/Redis
composer fix
git diff --check
```

`composer fix` is the final repository checkpoint. If it fails, fix the failure, run that failed check, and then run every remaining script entry rather than repeatedly restarting completed expensive checks.

## Completion criteria

- Every added public seam describes a truthful framework lifecycle and is independently useful without OpenTelemetry.
- Existing Laravel-compatible APIs remain intact; new contracts are additive and narrowly typed.
- With no consumer, event/observer additions allocate no event, descriptor, timing state, or callback machinery beyond the stated guarded lookup/branch.
- New completion hooks respect the framework's existing cancellation boundaries and emit no false lifecycle.
- Ordinary listener/observer failures retain documented framework precedence and fail-fast behavior.
- Queue payloads and terminal outcomes, cache causes and identities, HTTP/gRPC/WebSocket/View/Scout completions, and client resend counts are final and unambiguous.
- Pool discovery reads only existing state and never creates resources for observation.
- Focused, integration, static-analysis, formatting, and full repository tests pass with no stale docs, TODOs for completed work, compatibility shims, or abandoned alternatives.
