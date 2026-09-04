# Sentry Runtime Context Integration

## Goal

Adopt Sentry PHP's runtime-context storage contract so Logs and Trace Metrics are isolated and flushed with each framework-owned Hypervel execution boundary. Keep Hypervel's coroutine-aware Hub because it gives child coroutines isolated scopes without constructing a full SDK context for every child. Cover HTTP requests, queue jobs, scheduled tasks, and every WebSocket callback while preserving the existing public middleware, configuration, facade, and pooled non-blocking transport APIs.

The implementation must have these properties:

- Sentry-inactive applications leave no runtime storage registered and add no execution listeners or propagation hook.
- An active application creates one SDK runtime context at each uncovered HTTP, queue, scheduled-task, WebSocket opening, WebSocket message, and WebSocket closing boundary. A nested boundary in the same coroutine reuses the active context.
- Child coroutines share their execution's Logs and Metrics buffers through counted ownership, but retain the existing eager Hub-scope and request snapshots. No child constructs another SDK Hub, Scope, RuntimeContext, or aggregator pair.
- The last owner to exit flushes once, regardless of whether the root or a child exits first. A holder is never copied by generic context-copy APIs without its owner count being retained.
- Sentry delivery coroutines inherit no application scope, request, or runtime context.
- Root console/global telemetry is flushed at application termination; graceful queue and worker shutdown retain their bounded delivery drains.
- Existing Laravel-shaped APIs remain intact. New Hypervel-owned code uses a `State` directory mirroring the SDK's `Sentry\State` namespace and follows the package's constructor-injection conventions.

## Verified constraints

- `sentry/sentry` on `dev-master` contains the merged `RuntimeContextStorageInterface`, `SentrySdk::setRuntimeContextStorage()`, `startContext(?HubInterface $hub = null)`, `endContext()`, and `flush()` APIs. No released constraint contains them yet, so both Composer manifests intentionally use `dev-master` for this work.
- `RuntimeContextManager::startContext($hub)` uses the provided Hub as-is while creating the RuntimeContext, Logs aggregator, Metrics aggregator, and context ID and resetting fatal-error-handler state. The default path also creates an SDK Hub and Scope. Hypervel must pass its existing Hub at execution boundaries and share the resulting RuntimeContext with child coroutines, avoiding the redundant boundary Hub/Scope, the follow-up `setCurrentHub()` and its storage lookup, and a full context per child.
- `RuntimeContextStorageInterface` expressly permits parent and child executions to share a context when storage retains it until every owner releases it.
- Hypervel's custom `Hub` is still needed: its layer stack and last event ID live in `CoroutineContext`, and the existing child hook eagerly clones every Layer/Scope and Request snapshot. Lazy parent lookup would lose the snapshot when a detached child first accesses Sentry after its parent exits.
- The custom Hub's complete `HubInterface` behavior and sampling path match the installed `dev-master` SDK Hub after accounting for its coroutine-local stack, last-event ID, bootstrap-scope, and worker-shared client adaptations. It needs no speculative rewrite in this change.
- `CoroutineContext` omits top-level values implementing `NonCopyableContext` from `fork()` and other generic copies. The Sentry hook can therefore be the only path that shares and counts the runtime holder.
- `Coroutine::afterCreated()` callbacks can be registered twice in supported Testbench application reloads before the global test cleanup runs. Storage inheritance must be idempotent for an already-populated child slot or an extra retain would never be released.
- `SentrySdk::setRuntimeContextStorage()` discards the current coroutine's active context whenever provider boot replaces or clears the registered storage. On a mid-test `resetApplicationWithConfig()`, the previous boundary's deferred `endContext()` therefore no-ops; tests must not mistake that intentional discard-on-reconfiguration behavior for a missing flush.
- Queue workers and scheduled tasks already execute work in finite coroutines. A synchronous queue job inside an existing command shares that command coroutine and therefore its existing context; a second terminal-event ownership system would be both early on failures and unnecessary.
- Sentry Logs and Trace Metrics select their aggregators through `SentrySdk::getCurrentRuntimeContext()`. Their deprecated `enable_logs` and `enable_metrics` options no longer gate manual APIs or integrations, so storage and execution boundaries cannot depend on either option.
- WebSocket handshakes call `Router::dispatchToCallback()` and do not pass through the HTTP kernel's global middleware. `ConnectionOpened` and `ConnectionClosed` occur after their application callbacks have started or completed, so new before-callback events are required for complete context ownership.
- Swoole defers are LIFO. A context end registered by `ConnectionOpening` runs after the later `deferOnOpen()`, keeping `ConnectionOpened` listeners and `onOpen()` inside the handshake context.
- The Sentry split already directly requires Hypervel Context and Coroutine. It does not require WebSocket Server and must not gain that optional dependency: `::class` is a safe string for an absent class, and the event dispatcher supports such listener keys. This matches the package's existing optional Sanctum event registration.
- An active Sentry install without WebSocket Server pays only the one-time boot classification and registration of the optional event-name strings. No WebSocket callback, SDK context construction, or recurring runtime work occurs when those events do not exist or fire.

## Runtime state design

Add a new internal `src/sentry/src/State/` directory mirroring the SDK's `Sentry\State` namespace, with three classes.

### `SharedRuntimeContext`

This small holder implements `NonCopyableContext`, owns one opaque SDK `RuntimeContext`, and starts with one owner. `retain()` increments the owner count. `release()` decrements it and returns the SDK context only for the final owner; earlier releases return `null`. It exposes no count or mutation API beyond those ownership operations.

```php
class SharedRuntimeContext implements NonCopyableContext
{
    private int $owners = 1;

    public function __construct(
        private readonly RuntimeContext $runtimeContext,
    ) {
    }

    public function getRuntimeContext(): RuntimeContext
    {
        return $this->runtimeContext;
    }

    public function retain(): void
    {
        ++$this->owners;
    }

    public function release(): ?RuntimeContext
    {
        --$this->owners;

        return $this->owners === 0 ? $this->runtimeContext : null;
    }
}
```

No destructor, secondary per-owner wrapper, lock, or underflow guard is needed. Retain/set and forget/release do not suspend, and all supported release paths are owned by the storage and Hypervel defer lifecycle.

### `CoroutineRuntimeContextStorage`

Implement `RuntimeContextStorageInterface` over one private `__sentry.runtime_context` key in `CoroutineContext`:

- `set()` wraps the SDK context in a new `SharedRuntimeContext` with the root owner.
- `get()` returns the holder's opaque SDK context.
- `remove()` forgets the current slot before releasing its owner. It returns a context only when that release was final, which makes the SDK's existing `endContext()` the single flush owner.
- `inheritFrom(ArrayObject $context, ?ArrayObject $parentContext): bool` is the package-only child fast path. It returns `false` when the child already has a holder, the parent is gone, or the parent has no holder. Otherwise it retains the parent holder, writes the same holder into the child container, and returns `true` so the caller registers exactly one deferred end.

The child-slot guard is required, not defensive decoration: after Testbench reloads an active application, both registered propagation callbacks run for the next child. The second callback must neither retain again nor register another end defer.

### `RuntimeContextBoundary`

Create a stateless, unbound concrete service so Hypervel auto-singletons it safely for the worker. Inject `HubInterface` and `CoroutineRuntimeContextStorage`.

```php
public function start(): void
{
    if (! Coroutine::inCoroutine() || $this->runtimeContextStorage->get() !== null) {
        return;
    }

    SentrySdk::startContext($this->hub);
    Coroutine::defer(static fn (): void => SentrySdk::endContext());
}
```

The outside-coroutine branch is required because there is no coroutine defer to own the end; root console telemetry uses the global SDK context and application-termination flush instead. The active-context branch makes nested boundaries reuse their owner rather than registering an end that could release it early. Pass the Hypervel Hub directly so the SDK does not create a disposable Hub and Scope, and register the defer immediately after `startContext()` so an application callback failure still releases the context.

## Provider boot and propagation

Update `src/sentry/src/SentryServiceProvider.php` in this order:

1. Compute active state once. On every boot, explicitly own the SDK's process-global storage registration: resolve `CoroutineRuntimeContextStorage` only when active, then call `SentrySdk::setRuntimeContextStorage($active ? $storage : null)` before resolving the package Hub. This clears storage left by a prior active Testbench application before an inactive application's Hub is installed. Registration is boot-only and unconditional with respect to Logs/Metrics options.
2. Eagerly resolve `HubInterface` as today. Construct the custom Hub with a clone of the current no-client SDK scope, following current `sentry-laravel`'s `cloneCurrentHubScope()` behavior. This preserves tags and other scope data configured through `withExceptions()` before the real client exists. Do not clone a Hub that already has a client.
3. Before `bootFeatures()`, register one closure for these five start events: `JobProcessing`, `ScheduledTaskStarting`, `ConnectionOpening`, `MessageReceived`, and `ConnectionClosing`. The closure resolves `RuntimeContextBoundary` from the current container at dispatch time, so application/test rebindings work. Do not add `class_exists()`, a Composer dependency, or a suggestion for the optional WebSocket event classes.
4. Register the active-only child propagation hook before application work can create children.
5. Keep feature boot, ordinary Sentry events, middleware, and tracing behavior in their current relative order after the early boundary listeners. Event dispatcher insertion order then guarantees queue/scheduling Sentry feature listeners see the installed runtime context.
6. Replace the current `enable_logs`-gated terminating callback with an active-only `SentrySdk::flush()` callback. Run it only when `CoroutineRuntimeContextStorage::get()` is `null`: HTTP termination occurs before its deferred context end and must not double-flush, while root console/global buffers still need flushing.

Change `registerCoroutineContextPropagation()` to accept the resolved storage instance. The hook must remain direct and allocation-conscious without adding a direct `hypervel/engine` dependency:

- Fetch the current raw container once through `CoroutineContext::getContainer()`. Although its declared return type is `array|ArrayObject|null`, narrow the in-coroutine value to `ArrayObject` in the hook using the repository's normal narrowing order; do not widen `inheritFrom()` to accept `array`.
- If the delivery marker is present, return immediately without looking up the parent or cloning anything.
- Fetch the parent raw container once. Reuse those two containers for the Hub stack, Request, and runtime-holder work; the current code performs repeated engine lookups for each value.
- Prefer an already-installed child stack/request (from `fork()`), otherwise use the parent's. Preserve the current eager Layer/Scope and Request cloning semantics.
- Perform runtime inheritance last. If `inheritFrom()` returns `true`, immediately defer `SentrySdk::endContext()` in the child.

This keeps the normal no-parent path at two context lookups, reduces today's repeated lookups, and adds only a holder retain/write/defer for a child of an active execution. Grandchildren remain safe after an intermediate parent exits because each child owns the shared holder directly; no parent-ID walk occurs later.

## Execution boundaries

### HTTP

Keep the public `FlushEventsMiddleware` class and its outermost position. Inject `RuntimeContextBoundary`, call `start()` before `$next`, and remove the direct `Integration::flushEvents()` defer. Update the provider and tracing comments to say that the outer runtime-context end defer runs after later tracing and after-response defers.

### Queue and scheduling

The early provider listeners start contexts before `QueueFeature` and `ConsoleSchedulingFeature` create scopes or spans. Remove explicit pre-pop or terminal flushes from:

- `TracksPushedScopesAndSpans::maybePopScope()`;
- `QueueFeature::handleJobExceptionOccurredQueueEvent()`;
- `ConsoleSchedulingFeature::handleScheduledTaskFinished()`;
- `ConsoleSchedulingFeature::handleScheduledTaskFailed()`.

Scope/span cleanup defers were registered later and therefore run before runtime-context end. Failed-job exception reporting also completes inside the finite job coroutine before that defer runs. Keep `QueueFeature::handleWorkerStoppingQueueEvent()` as a bounded drain.

### WebSockets

Add two Laravel-shaped public lifecycle events to `src/websocket-server/src/Events/`:

```php
new ConnectionOpening(int $fd, Hypervel\Http\Request $request, string $server = 'websocket');
new ConnectionClosing(int $fd, int $reactorId, string $server = 'websocket');
```

In `WebSocketServer\Server`:

- Dispatch guarded `ConnectionOpening` after the fd and bridged request are installed, before generic `RequestReceived`, security validation, routing, and handler resolution. Its throwable/cancellation behavior stays inside the existing handshake policy: ordinary failures produce the rendered handshake failure; cancellation escapes to the outer cleanup.
- Reuse `MessageReceived` at its current location after handler resolution/type validation and before `onMessage()`. Moving it earlier would start unmatched tracing/instrumentation lifecycles for invalid handlers.
- Dispatch guarded `ConnectionClosing` after a registered handler class is found and before logging, handler resolution, `onClose()`, and `ConnectionClosed`. Follow the surrounding per-step policy: cancellation returns, other throwables are reported, later close work continues, and the existing `finally` always clears the fd and connection context.

Sentry's five-event listener list makes opening, message, and closing contexts automatic only when Sentry is active. WebSocket applications without active Sentry retain `hasListeners()` fast paths and create no new event objects. Active Sentry intentionally creates one RuntimeContext and its Logs and Metrics aggregators per message for isolation while reusing the Hypervel Hub; this is the required per-message cost, not a child-propagation cost.

## Transport and flushing

### Delivery marker

Add `public const string DELIVERY_CONTEXT_KEY = '__sentry.delivery'` to `HttpPoolTransport`. In its owned child wrapper, set the marker before invoking the child runner, so the Sentry propagation hook sees it before its callback body begins. The hook skips Hub/request cloning and runtime ownership for the entire delivery coroutine. Keep all current transport checkout, failure, WaitGroup generation, release/discard, and cancellation behavior unchanged.

### SDK flush facade

Simplify `Integration` around the SDK's complete flush facade:

- `flushEvents()` calls `SentrySdk::flush()` directly.
- `drainEvents()` first calls `SentrySdk::flush()` so current Logs, Metrics, and accepted client work are published. It then preserves the existing null-client success result, positive timeout normalization, and final `client->flush($timeout)` bounded wait.
- Remove the private duplicate flush helper and now-unused Logs/Metrics imports.

The extra no-timeout client flush inside `SentrySdk::flush()` is only the pooled transport's WaitGroup count observation; the following positive flush captures and waits for the accepted generation. Keep the worker-exit drain and transport shutdown in `EventHandler::workerExitHandler()` unchanged.

## Configuration and documentation

- Keep the existing `enable_logs` and `enable_metrics` keys because they are current Sentry Laravel configuration APIs, even though the SDK marks them deprecated. Remove false unsupported comments, restore `enable_metrics` to the SDK/upstream default of `true`, and describe `logs_channel_level` and `log_flush_threshold` without unsupported wording. Do not introduce another configuration switch for runtime contexts.
- Remove test setup that sets `enable_logs` as though it activated the Logs API. Keep config parsing coverage for both deprecated compatibility keys without using either as a runtime gate.
- Update `src/docs/sentry.md` to remove the Logs and Trace Metrics warnings, state that both are execution-isolated, show the current `Sentry\traceMetrics()` API, and mention WebSocket callbacks among isolated lifecycles. Keep delivery/shutdown wording consistent with deferred context flush plus the asynchronous pool.
- Update `src/docs/websockets.md` to list all six events in lifecycle order with timing and payloads: `ConnectionOpening`, `ConnectionOpened`, `MessageReceived`, `MessageHandled` (currently missing), `ConnectionClosing`, and `ConnectionClosed`.
- Do not add a Laravel porting-guide entry: the two WebSocket events are Hypervel-native APIs and require no Laravel migration action.

## File-by-file work

1. Keep the approved `dev-master` constraint in root `composer.json` and `src/sentry/composer.json`; run `composer update sentry/sentry` so the installed SDK contains the caller-provided Hub API, without committing the ignored root lock.
2. Add `src/sentry/src/State/SharedRuntimeContext.php`.
3. Add `src/sentry/src/State/CoroutineRuntimeContextStorage.php`.
4. Add `src/sentry/src/State/RuntimeContextBoundary.php`.
5. Update `src/sentry/src/SentryServiceProvider.php` for storage registration, scope cloning, early boundaries, terminating flush, and optimized child propagation.
6. Update `src/sentry/src/Transport/HttpPoolTransport.php` with the delivery marker.
7. Update `src/sentry/src/Http/FlushEventsMiddleware.php` to start the boundary while preserving its public name and middleware position.
8. Update `src/sentry/src/Integration.php` to use `SentrySdk::flush()` and retain bounded drain semantics.
9. Remove superseded operation flushes/imports/comments from `TracksPushedScopesAndSpans.php`, `QueueFeature.php`, and `ConsoleSchedulingFeature.php`; update the defer-order comment in `Tracing/Middleware.php`.
10. Add `ConnectionOpening.php` and `ConnectionClosing.php`, then update WebSocket `Server.php` at the verified lifecycle points.
11. Update Sentry config and the two canonical documentation pages. Remove every statement that Logs or Trace Metrics are unsupported.
12. Update the affected tests serially, running each test file as soon as it changes.

## Testing

### Runtime storage and ownership

Add focused tests under `tests/Sentry/State/`:

- `CoroutineRuntimeContextStorageTest` covers set/get/remove, no-context removal, parent/child final-release behavior, and the already-populated-child idempotence guard. Assert returned SDK context identity; do not expose an owner-count production API for tests. Generic-copy omission and both coroutine exit orders are observable propagation behaviors and belong only in the end-to-end propagation tests.
- `RuntimeContextBoundaryTest` proves a coroutine boundary starts once with the exact Hypervel Hub, flushes at coroutine exit, reuses an already-active context, and does nothing outside a coroutine where no defer can own cleanup.

Extend `CoroutineContextPropagationTest` to prove:

- existing Hub Layer/Scope and Request snapshot behavior is unchanged for `create()`, full `fork()`, and selective `fork()`;
- an active runtime context is shared and released exactly once through `create()`, full `fork()`, and selective `fork()` children without constructing child SDK contexts, including a grandchild that outlives its intermediate parent;
- one log and metric buffer flushes exactly once when child-first and parent-first exit orders are forced with channels;
- reloading the application before creating a child does not double-retain or suppress the final flush when duplicate propagation hooks run;
- a marked delivery child gets no Hub stack, Request clone, or shared runtime owner.

### Boundary integration and flushes

- Update `FlushEventsMiddlewareTest` to inject the real boundary and assert context lifetime/flush ordering rather than a direct flush defer.
- Update `FlushLifecycleTest` for `SentrySdk::flush()` ordering and the two-step bounded drain (`flush(null)` observation followed by the positive wait). Replace terminal scheduled-task/pre-pop flush expectations with execution-end behavior, while retaining root console termination, graceful queue drain, immediate-stop, and worker-exit coverage.
- Extend `ServiceProviderListenerRegistrationTest` to assert all five start listeners are registered, the queue and scheduling boundary listeners precede their corresponding Sentry feature listeners, and every boundary listener resolves the service from the container at event time. The three new WebSocket events have no separate Sentry feature listeners to order against.
- Extend `ServiceProviderWithoutDsnTest` to assert inactive applications clear any prior SDK runtime storage and register none of the boundary listeners or coroutine propagation work.
- Add a provider regression proving scope data configured on the no-client SDK Hub before provider resolution survives when the real Hypervel Hub/client is installed.
- Adjust queue and scheduling integration assertions only where the new execution-end owner changes flush timing; keep their scope, span, exception, and check-in behavior intact.
- Update `HttpPoolTransportTest` to assert the delivery marker is installed before child startup hooks while preserving all existing ownership/failure tests.

### Logs, Metrics, and WebSockets

- Add concurrent Sentry integration coverage showing two root execution contexts cannot see or flush each other's Logs or Trace Metrics, and each envelope contains only its own items.
- Extend `ServerHandshakeTest` for `ConnectionOpening` order/payload, its ordinary-failure and cancellation policies, and cleanup.
- Extend `ServerTest` for `ConnectionClosing` order/payload. A closing-listener throwable must not prevent `onClose()`, `ConnectionClosed`, or fd/context cleanup; cancellation must stop later close callbacks while still cleaning up.
- Add `tests/Sentry/WebSocketRuntimeContextTest.php` for the cross-package behavior: an invalid `Sec-WebSocket-Key` after opening telemetry still ends and flushes one context; telemetry from `onOpen()` is included in the handshake's single context flush; concurrent messages flush isolated buffers; and close telemetry flushes after `onClose()`.
- Update config tests for the supported/default Metrics state and retain environment normalization coverage.

These tests cover public behavior, real owner interleavings, and the verified duplicate-hook regression. Do not add reflection-only owner-count assertions, artificial corruption branches, or tests for SDK reinitialization while other executions are active; the SDK contract explicitly disallows that state.

### Performance verification

Before the first source edit, create a disposable benchmark under `/tmp` and record warmed, repeated samples for:

1. child creation with active Sentry but no parent runtime context;
2. child creation under an active runtime context;
3. an Sentry delivery child;
4. an active-Sentry WebSocket message boundary.

Run the same script after implementation. The no-parent path must stay within measurement noise or improve from fewer context lookups. The delivery path replaces repeated lookups and cloning with one marker write and one lookup and must not regress beyond noise. The active-child path may add only one retain, one context write, one defer registration, and the matching empty/final release. The message result records the unavoidable absolute price of a RuntimeContext plus Logs and Metrics aggregators, with no disposable SDK Hub or Scope, blocking I/O, or unbounded growth. Investigate any larger cost before review. Delete the disposable benchmark and results after recording the comparison; do not add a maintenance surface solely for this change.

### Commands and final checks

- After each new or changed test file: `./vendor/bin/phpunit --no-progress <test-file>` from the components worktree root.
- After each coherent Sentry or WebSocket slice: run the affected package test directories.
- Run `composer fix` once at the final checkpoint. If it fails, follow the repository's targeted correction and remaining-script rules rather than rerunning successful full checks blindly.
- Inspect `git diff --check`, `git status --short`, Composer manifests, and the final diff for stale imports, direct optional dependencies, unsupported documentation, dead flush code, debug files, and benchmark artifacts.

## Completion criteria

- Logs and Trace Metrics are isolated across concurrent supported Hypervel executions and flushed exactly once by their final owner.
- HTTP, queue, scheduled, WebSocket opening/message/closing, nested child, failure, cancellation, and graceful shutdown paths all have explicit tested ownership.
- Execution boundaries pass the existing Hypervel Hub directly, with no disposable SDK Hub or Scope. There is no per-child SDK context construction, parent-lifetime lookup, global mutable request state, or extra active-path configuration branch.
- Inactive Sentry clears the process-global SDK storage at boot and adds no listeners or child hook. Active child overhead is the minimum counted-sharing work, and the delivery fast path skips all application propagation.
- The custom Hub remains current and tested; bootstrap scope data is no longer lost.
- Public middleware/config/facade APIs remain Laravel-shaped, the optional package graph is unchanged, and canonical docs contain no stale unsupported claims.
- Focused tests, performance comparison, and `composer fix` pass with no temporary or dead files remaining.
