# Sentry Correctness, Coroutine Ownership, and Performance

## Status

Complete; implementation, authoritative validation, fresh self-review, and independent code review are signed off.

## Scope and outcome

Complete the Sentry audit against current Hypervel, `examples/sentry-laravel`, the installed Sentry SDK, and the long-lived Swoole runtime. Preserve the existing low-footprint design: coroutine-local Hub state, fail-fast bounded transport capacity, pooled SDK transports, cached boot decisions, sampled-span early exits, and propagation even when local recording is disabled.

The final package keeps ordinary requests and jobs nonblocking, gives every detached send and span one exact owner, makes bounded drains truthful at process-lifecycle boundaries, installs no instrumentation that cannot produce output, restores applicable current upstream behavior, and documents the operational contract in Laravel-docs prose. Shared defects in Coroutine, Core, Object Pool, Filesystem, Cache, Notifications, and Queue are fixed at those owners rather than hidden by Sentry registries.

References checked:

- Hypervel components `d80d05adfb38ae8cfe5b83b609737abad95c3147`, including all Sentry source/tests and connected framework owners;
- Sentry Laravel `dbc6a5d029e9051f999f7691545c61c97b2d65be`;
- Laravel framework `8df67f9d176d1d0375a866d8c6780be95ce0336e`;
- installed `sentry/sentry` 4.30.0, Guzzle 7.15.3, Flysystem adapters, PSR interfaces, and Symfony PSR bridge;
- Swoole 6.2.2 source behavior and the focused probes below.

The scratch audit's `sentry-01` through `sentry-29` labels are investigation-local and are not reused. Durable `sentry-01` already belongs to completed Redis work.

This plan is the post-compaction implementation reference. It therefore reproduces the core plan's "What this audit is not" section and principles 7–10 verbatim below; principles 1–6 remain in the core audit plan.

## What this audit is not

This audit is not permission to add defensive machinery for every imaginable failure. Do not add an abstraction, state machine, retry loop, configurable timeout, registry, mutex, context slot, cache, or compatibility API merely because it sounds robust.

Complexity must pay for itself with at least one of:

- a demonstrated failure;
- a complete source trace proving a realistic vulnerable schedule;
- a clear general capability with real consumers and owner approval;
- deletion of greater or riskier complexity elsewhere.

Typical Laravel lifecycle semantics define the supported contract. A package that intentionally relies on model events, middleware, listeners, transactions, or another documented mechanism is not defective merely because userland can explicitly bypass that mechanism. Do not build a parallel enforcement path for `withoutEvents()`, raw database writes, disabled middleware, direct transport access, or comparable deliberate bypasses unless the public contract explicitly promises behavior through that bypass.

Underengineering is equally a failure. Fix every verified defect completely at its lowest owning boundary, never with a partial fix or a local patch over a broken shared contract, and always surface meaningful evidence-backed improvements rather than dropping them to avoid effort. Restraint applies to speculative machinery and cosmetic change, not to complete fixes or worthwhile opportunities.

Do not treat an upstream difference as a bug without tracing it. Do not treat upstream parity as proof of correctness. A real Hypervel defect remains a defect when Laravel, Hyperf, Symfony, or an SDK has the same hole.

The audit categories are discovery lenses, not boundaries around what may be corrected. Any genuine issue discovered while auditing, implementing, testing, or reviewing must be investigated, assigned to its lowest owning boundary, and taken through the applicable consensus, implementation, validation, review, and approval workflow—even when it is outside the current package, initial taxonomy, or changed diff. Do not dismiss a verified issue as unrelated or defer it merely to preserve package order. This rule applies only after the evidence threshold is met; it does not turn speculative concerns, deliberate bypasses, unsupported use, or contract violations into work.

### 7. Preserve hot-path quality

For every fix, inspect:

- additional allocations;
- container or facade resolutions;
- locking and atomics;
- hashing and serialization;
- new yields or sleeps;
- retries and polling;
- logging or exception construction;
- retained worker memory;
- cache invalidation and eviction.

A correctness guard on a cold failure path has a different cost from a new lock or resolver on every request. State the difference explicitly.

Any proposed change with a measured or source-proven hot-path regression requires explicit owner approval before implementation, even when it fixes a defect. Present the expected frequency and magnitude, the evidence, and the viable alternatives. Do not hide an unavoidable tradeoff inside a general correctness claim.

Performance improvements must provide a meaningful practical benefit after accounting for code complexity and divergence from upstream. Measure representative behavior where practical. Always surface an evidence-backed opportunity to the owner, but do not implement it without approval; a micro-optimization within measurement noise is neither a reason to diverge nor an actionable finding.

### 8. Remove superseded design completely

When a fix changes the owning model, delete obsolete helpers, callbacks, properties, config keys, comments, tests, and documentation. Do not leave a compatibility path or comment describing behavior that no longer exists. Preserve intentional upstream comments unless the new design makes them incorrect.

### 9. Treat remediation patterns as candidates

The established patterns later in this plan are a vocabulary, not a lookup table. Choose among per-call parameters, immutable values, scoped bindings, cloning, CoroutineContext, factories, explicit ownership, static reset, or resource teardown only after proving the real lifetime and owner.

### 10. Reject speculative complexity

Record low-confidence concerns under rejected or unresolved analysis. Do not implement them. Surface every evidence-backed, meaningful non-defect improvement to the owner with its benefit, cost, and alternatives, then stop for explicit approval. This requirement exists to keep worthwhile opportunities visible, not to discourage finding them.

## Verified runtime facts

The following Swoole 6.2.2 probes are load-bearing:

- `OnWorkerExit` ran outside a coroutine. A child started there but did not resume while the callback remained active: `{"callback_in_coroutine":false,"child_progressed_during_callback":false,"child_finished_after_callback":true}`. The callback must not wait, poll, or sleep.
- Queue-worker normal and timer paths ran in coroutines (CIDs 1 and 3) and could wait while children progressed.
- `WaitGroup::add()` during `wait()` was rejected. A generation swap waited about 20 ms for the captured group and left one later send pending in the new group, matching WaitGroup's explicit misuse guard.
- Native worker exit repeatedly invoked its callback while reactor work remained. A two-second child completed within the default budget; a 3.2-second child was forcibly terminated. Swoole defaults are `reload_async = true` and `max_wait_time = 3`.

These facts establish three constraints: worker-exit cleanup is best effort and nonblocking; queue-worker graceful cleanup may perform a bounded wait; and a drain must swap WaitGroup generations rather than wait a group to which later sends can add.

## Findings and final decisions

| ID | Category / severity | Final decision |
|---|---|---|
| `coroutine-08` | Framework defect / Major | Install copied fork context before `afterCreated` callbacks, then revalidate Sentry and Telescope. |
| `core-09` | Framework lifecycle defect / Major | Dispatch `OnWorkerExit` once per worker exit despite repeated native callbacks. |
| `object-pool-05` | Capability defect / Major | Add `InvalidatesPool::invalidatePool()` and make pool proxies expose it. |
| `object-pool-06` | Diagnostic defect / Minor | Point fingerprint conflicts at distinct identities or explicitly declared construction equivalence. |
| `filesystem-15` | Correctness/API enhancement / Major | Preserve names required by span labels and signed URLs through builds, custom creators, and scoped reconstruction, and purge through the capability contract. |
| `filesystem-16` | Serving capability defect / Major | Register signed serving routes from exact `serve => true` capability instead of discarding decorated and custom disks by concrete driver name. |
| `cache-21` | Event lifecycle defect / Major | Emit explicit one-key and many-key retrieval-failure events carrying the exception. |
| `cache-22` | Event lifecycle defect / Major | Emit existing write/forget failure terminals when store operations throw, then rethrow. |
| `notifications-22` | Event lifecycle defect / Major | Emit `NotificationSkipped` for `shouldSend()` and `NotificationSending` vetoes. |
| `notifications-23` | Lifecycle/API enhancement / Major | Emit `NotificationDelivered` immediately after the channel returns, before post-delivery callbacks. |
| `queue-43` | Lifecycle/API enhancement / Major | Expose `WorkerStopping::$terminatesImmediately` so cleanup listeners do not delay forced termination. |
| `queue-44` | Event lifecycle defect / Major | Emit `JobQueueingFailed` at the actual enqueue attempt boundary. |
| `queue-45` | Queue correctness defect / Major | Give Database/SQS bulk enqueue accurate terminals and Database bulk complete after-commit behavior without losing bulk writes. |
| `sentry-02` | Transport ownership defect / Major | Delete the synthetic HTTP client; detach only after one pooled SDK transport is exclusively owned by the child. |
| `sentry-03` | Drain/shutdown defect / Major | Use WaitGroup generations for truthful nonblocking/positive-timeout flushes and an internal worker-exit pool shutdown. |
| `sentry-04` | Hub/coroutine defect / Major | Clone inherited layer scopes/requests, preserve placeholder scope, and maintain one authoritative root layer. |
| `sentry-05` | Tracing ownership defect / Major | Keep Guzzle propagation but never install an operation-local child as Hub current; remove the dead client-config branch. |
| `sentry-06` | Tracing ownership defect / Major | Separate response spans from per-connection nested transaction spans and handle null query time honestly. |
| `sentry-07` | Privacy/correctness defect / Major | Omit Redis parameters without PII consent, redact session keys with consent, and preserve key `"0"`. |
| `sentry-08` | Failure/finalization defect / Major | Catch `Throwable` at the four real boundaries and install exact per-owner orphan cleanup. |
| `sentry-09` | Initialization defect / Minor | Initialize `LogsHandler::$batchFormatter` to null. |
| `sentry-10` | Process-global state defect / Major | Restore `error_reporting()` from TestCommand in `finally`. |
| `sentry-11` | Publication defect / Major | Publish environment values through `Env::writeVariables(..., overwrite: true)`. |
| `sentry-12` | Callable defect / Minor | Normalize model violation callbacks with `Closure::fromCallable()`. |
| `sentry-13` | I/O/type defect / Minor | Read compiled view origin once and return null on read failure. |
| `sentry-14` | Context defect / Minor | Filter only null auth context fields and preserve useful falsey identifiers. |
| `sentry-15` | Metadata defect / Minor | Use the dynamic Hypervel SDK identifier/version and a stable fallback everywhere. |
| `sentry-16` | Configuration/docs defect / Minor | Resolve the Hypervel DSN before the generic DSN and make command guidance match. |
| `sentry-17` | HTTP tracing/lifecycle defect / Major | Restore trace-continuation/current middleware parity and one correctly ordered after-response flush. |
| `sentry-18` | Storage feature gap / Major | Port lazy Storage tracing without global config mutation or breaking pool/stream ownership. |
| `sentry-19` | Queue tracing defect / Major | Start publish spans at `JobQueueing`, carry the resolved destination in the payload, consume exact terminals, and port job sampling middleware. |
| `sentry-20` | Notification tracing defect / Major | Finish delivery spans on delivered/failed/skipped terminals while retaining breadcrumbs on `NotificationSent`. |
| `sentry-21` | Cache tracing defect / Major | Consume exact read/write/forget success and failure terminals. |
| `sentry-22` | Performance defect / Major | Register no hook, listener, event emission, AOP, or decorator that cannot produce output; derive SDK capabilities from one merged-config owner. |
| `sentry-23` | Package metadata defect / Major | Declare every direct split dependency and require Sentry SDK `^4.27` in root and split metadata. |
| `sentry-24` | Documentation defect / Major | Add complete Laravel-prose Sentry guidance and keep README minimal. |
| `sentry-25` | Dead/stale design / Minor | Remove obsolete context, config, resolver, Guzzle, and temporary flush surfaces. |
| `sentry-26` | Flush-order defect / Major | Flush Logs and TraceMetrics before client drain so their envelopes enter the captured generation. |
| `sentry-27` | Orphan cleanup defect / Major | Register one local defer per owning feature/handler and unwind only its remaining scopes/spans. |
| `sentry-28` | Availability/diagnostic defect / Major | Make the provider the sole feature-phase failure boundary and log truthful partial-failure consequences. |
| `sentry-29` | Duplication/performance defect / Minor | Share Redis command success/failure recording without erasing nullable duration semantics. |
| `sentry-30` | Current parity gap / Major | Port applicable SDK Hub, profiles sampler, metrics, monitor, health-route, and continuation behavior without Laravel-only integrations. |

## Ownership model

| Lifetime | Owner |
|---|---|
| One Sentry HTTP send | The child coroutine and its borrowed SDK `HttpTransport` until release/discard. |
| One drain generation | The captured WaitGroup; later sends use the replacement generation. |
| Worker transport pool | `HttpPoolTransport`; only its internal worker-exit shutdown closes it. |
| Coroutine Hub layers | The active coroutine; fork callbacks clone every inherited mutable layer value. |
| One feature's scopes/spans | That feature class's coroutine-local LIFO or exact local-span map plus its one cleanup defer. |
| Response preparation spans | Tracing EventHandler's response stack. |
| Database transaction spans | Tracing EventHandler's stack keyed by exact connection identity. |
| Framework enqueue/delivery/cache lifecycle | Queue, Notifications, and Cache terminal events respectively. |
| Pooled filesystem invalidation | Any outer object implementing `InvalidatesPool`, forwarded to the actual pool owner. |

## Implementation

### Make detached transport ownership exact

Delete `src/sentry/src/HttpClient/HttpClient.php`. `Pool` creates the SDK HTTP client directly, so the SDK receives real response codes and rate-limit headers. Each pooled `HttpTransport` keeps its SDK-private rate limiter. Do not copy it or use reflection: the bounded consequence is at most one extra request per pooled transport before the whole pool has learned a rate limit.

`HttpPoolTransport::send()` borrows fail-fast, reserves the current generation, and transfers the transport to one child:

~~~php
$transport = $this->pool->get();

// Cooperative scheduling and no yield between capture and add are required:
// a drain may swap generations and begin waiting immediately afterward.
$group = $this->group;
$group->add();

try {
    Coroutine::create(function () use ($event, $group, $transport): void {
        $discard = false;

        try {
            $transport->send($event);
        } catch (Throwable) {
            $discard = true;
        } finally {
            try {
                if ($discard) {
                    $this->pool->discard($transport);
                } else {
                    $this->pool->release($transport);
                }
            } finally {
                $group->done();
            }
        }
    });
} catch (Throwable) {
    try {
        $this->pool->release($transport);
    } finally {
        $group->done();
    }

    return new Result(ResultStatus::failed());
}

return new Result(ResultStatus::success(), $event);
~~~

Pool exhaustion or closure remains the existing skipped/backpressure result. Returning the accepted Event preserves `Client::captureEvent()` EventId behavior. A coroutine-creation failure returns a failed Result after releasing the transport and balancing the generation; telemetry failure never becomes an application exception. The child logs and applies the real SDK result; only an unexpected escaping `Throwable` discards the transport. There is no worker queue, task/result registry, polling scheduler, retry path, or mutable Event snapshot.

### Separate nonblocking flush, bounded drain, and worker shutdown

`HttpPoolTransport::close()` never closes the pool. Null/zero is an observation: success if the active group is empty, otherwise unknown. A positive timeout swaps generations and waits only for the captured group:

~~~php
public function close(?int $timeout = null): Result
{
    if ($timeout === null || $timeout <= 0) {
        return new Result($this->group->count() === 0
            ? ResultStatus::success()
            : ResultStatus::unknown());
    }

    $group = $this->group;
    $this->group = new WaitGroup;

    return new Result($group->wait($timeout)
        ? ResultStatus::success()
        : ResultStatus::unknown());
}
~~~

The shared helper flushes Logs and TraceMetrics before the client flush swaps generations:

~~~php
public static function flushEvents(): void
{
    self::flush(null, false);
}

public static function drainEvents(?int $timeout = null): Result
{
    return self::flush($timeout, true);
}

private static function flush(?int $timeout, bool $drain): Result
{
    $client = SentrySdk::getCurrentHub()->getClient();

    if ($client === null) {
        return new Result(ResultStatus::success());
    }

    if ($drain) {
        $timeout = max(1, $timeout ?? (int) ceil($client->getOptions()->getHttpTimeout()));
    }

    Logs::getInstance()->flush();
    TraceMetrics::getInstance()->flush();

    return $client->flush($timeout);
}
~~~

Both delegate to one private helper which flushes Logs, then TraceMetrics, then the client. `flushEvents()` passes no timeout and discards the transport status; ordinary request and job paths therefore never wait. Console completion and graceful queue-worker stopping call `drainEvents()` without resolving the client. The helper derives a positive bound from that client's configured HTTP timeout when no explicit timeout is supplied and returns the bounded client result. An explicit zero or negative timeout is normalized to one second; callers wanting nonblocking behavior use `flushEvents()`. A missing client returns a successful Result. Remove `flushEvents()`'s stale temporary/internal wording.

Add an idempotent internal `HttpPoolTransport::shutdown(): void` that closes the pool only from `OnWorkerExit`. Worker exit flushes Logs and TraceMetrics, closes the pool to reject new acquisitions, and returns immediately; active child sends finish while Swoole's reactor remains alive. Final delivery is best effort and bounded by independent server `max_wait_time`.

`WorkerStopping` gains a final additive property:

~~~php
public function __construct(
    // Existing fields...
    public bool $terminatesImmediately = false,
) {
}
~~~

`stop()` passes false and `kill()` passes true. Its docblock states that listeners cannot start cleanup which must complete before control returns when true. Sentry performs no new flush/drain when true or when a graceful reason is `MaxMemoryExceeded`; every other graceful stop, including null reason, receives a bounded drain. Do not close the pool on this event because a programmatic queue worker may return into a still-running process.

### Make the framework worker-exit event exactly once

`WorkerExitCallback` owns the native repetition guard and sets it before dispatch:

~~~php
protected bool $dispatched = false;

public function onWorkerExit(Server $server, int $workerId): void
{
    if ($this->dispatched) {
        return;
    }

    $this->dispatched = true;

    try {
        $this->dispatcher->dispatch(new OnWorkerExit($server, $workerId));
    } finally {
        CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
    }
}
~~~

The server already resolves and registers one callback instance; its source does not change. Setting the guard before dispatch prevents replay after a throwing listener. Revalidate the server wiring and record it in the dependency index without inventing server source work.

### Give HTTP after-response cleanup one outer owner

Add `tracing.continue_after_response`, backed by `SENTRY_TRACE_CONTINUE_AFTER_RESPONSE` and defaulting to `true`. `FlushEventsMiddleware::handle()` registers one nonblocking coroutine defer before entering the rest of the stack. Remove its terminable method and remove flushing from the tracing defer. Register middleware in this exact order:

~~~php
$httpKernel->prependMiddleware(TracingMiddleware::class);
$httpKernel->prependMiddleware(FlushEventsMiddleware::class);
$httpKernel->pushMiddleware(SetRequestIpMiddleware::class);
~~~

The second prepend makes Flush outermost and Tracing inner. Swoole's LIFO defers always put the server's later `RequestTerminated` defer before coroutine-deferred feature cleanup and the final flush. When `continue_after_response` is true, the complete order is:

1. the server's later `RequestTerminated` defer;
2. transaction and feature orphan finalizers;
3. the one final nonblocking Logs, TraceMetrics, and client flush.

When `continue_after_response` is false, Tracing finishes the request transaction once in terminable middleware; `RequestTerminated`, remaining feature finalizers, and the final flush then run in that order. The response is already sent before coroutine defers run, so this adds no client latency. It also covers exceptions that skip terminable middleware.

### Correct fork ordering and Hub ownership

Refactor Coroutine creation through one private helper so a fork installs captured context before `afterCreated` callbacks while ordinary `create()` supplies an empty context. Preserve the existing per-callback and outer exception handling:

~~~php
private static function createWithContext(callable $callable, array $context): int
{
    $coroutine = Co::create(static function () use ($callable, $context): void {
        try {
            CoroutineContext::setMany($context);

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
    });

    return $coroutine->getId();
}
~~~

Document that guarantee in the coroutine guide and test it directly. Revalidate Telescope's callback because it is the other production consumer: `fork()` must retain its capture-time values, while ordinary `create()` keeps the callback's parent-propagation behavior.

Sentry's callback prefers an already-installed fork value and otherwise reads the parent for ordinary `create()`. This fallback is intentional for selective `fork()` calls too: Sentry propagation is infrastructure context and remains present even when the caller's key list excludes its stack. The callback replaces the inherited stack with new Layers holding cloned mutable Scopes, preserving each Layer's client and each Scope's current span pointer, and clones the inherited HTTP Request. The root Hub behavior must:

- preserve a placeholder Hub's configured Scope when binding the real client;
- clone the worker baseline Scope for every coroutine root;
- initialize one authoritative root Layer before public use;
- refuse to pop the last Layer;
- port current installed SDK 4.30 behavior while retaining coroutine-local storage; sampling-decision source/validation logging dates to 4.12 and `profiles_sampler` support to 4.27.

Do not make worker-level `bindClient()` layer-local: that would add a lookup to every `getClient()` without a demonstrated request-level need.

### Make Guzzle and database spans locally owned

The Guzzle aspect always propagates trace headers when Sentry is active, even when local span recording is disabled. It captures any `http.client` child locally, never installs it as Hub current, and finishes that exact span from success/failure handling. Lower-level spans therefore no longer nest under `http.client`; this is the accepted safety trade that avoids restoring a stale parent after an asynchronous transfer.

Delete the dead concrete-client config branch and its bound private-property closure. Guzzle merges client defaults into `$options` before the aspect observes them, so the only opt-out test is:

~~~php
return ($options['no_sentry_aspect'] ?? false) === true;
~~~

Tracing EventHandler uses distinct storage:

~~~php
/** @var list<array{span: Span, parent: ?Span}> */
$responseSpans = ...;

/** @var array<int, list<Span>> keyed by spl_object_id($connection) */
$transactionSpans = ...;
~~~

Transactions are never installed as Hub current. Queries parent to the top transaction for their exact connection, or otherwise to the current Hub span. A null `QueryExecuted::$time` creates an instantaneous span and skips duration-dependent origin collection. Nested and interleaved connections each pop their own LIFO.

### Add exact local orphan cleanup

`TracksPushedScopesAndSpans` registers one defer per using feature class on that class's first push in a coroutine. It unwinds only that class's remaining scopes/spans in LIFO order with aborted/internal status. Tracing EventHandler separately registers one defer for its response and connection stacks, restoring any saved response parent before coroutine exit. After finishing abandoned transaction spans, the finalizer clears the complete connection map so a later connection cannot meet a stale entry through a reused `spl_object_id`.

Normal terminals empty the stacks, making these defers constant-time no-ops. They are a final safety boundary, not replacements for framework terminal events, which remain required for correct end time, status, and parent restoration before callers continue. The outer HTTP flush defer is registered first, so all later cleanup runs before final envelope flush.

### Complete Cache lifecycle terminals

Add guarded public events:

~~~php
new KeyRetrievalFailed($storeName, $key, $exception, $tags);
new ManyKeysRetrievalFailed($storeName, $keys, $exception, $tags);
~~~

Use the repository's existing event helper and metadata conventions. `getRaw()` and `manyRaw()` cover both the store read and incomplete-class value normalization with the matching failure terminal, then rethrow. `manyRaw()` normalizes the complete batch before dispatching per-key success terminals so a later handler failure cannot follow an earlier partial-success sequence. Write/forget paths do the same with existing `KeyWriteFailed` and `KeyForgetFailed`. The three finite-TTL aggregate `putMany()` implementations return true before dispatch or store delegation when the values are empty, matching the read and Redis-operation guards and preventing a `WritingManyKeys` start with no per-key terminal. For nonempty batches, the aggregate store result is final before the same-outcome per-key terminals begin, so the first terminal correctly closes Sentry's batch span; do not add redundant many-write events or completion counters. New construction/dispatch work exists only on failure and only when a listener exists.

Sentry's Cache feature consumes exact success/failure terminals. It creates no pending-operation registry and installs no listeners when neither spans nor breadcrumbs can be recorded.

Hypervel's many-key Cache events carry resolved `list<string>` keys from every framework emitter. Type that owner contract and consume the lists directly; delete Sentry Laravel's associative-key normalizer because Laravel emits raw `many()` input while Hypervel resolves keys before emitting. This removes a dead branch and one Collection allocation/closure from each traced cache operation.

### Complete Notification delivery boundaries

Add guarded events with normal notification context:

~~~php
new NotificationSkipped($notifiable, $notification, $channel);
new NotificationDelivered($notifiable, $notification, $channel, $response);
~~~

`NotificationDelivered` is an owner-level lifecycle improvement: observers measuring actual channel delivery need the boundary where the channel returns, before post-delivery application callbacks. Sentry is a consumer, not the reason the boundary exists.

`NotificationSender` preserves Laravel's order and failure meaning:

1. `shouldSend()` false or `NotificationSending` veto dispatches Skipped;
2. the existing coroutine-local failure marker covers the complete pre-delivery phase;
3. a throwing sending listener or channel dispatches existing `NotificationFailed` unless the channel already did;
4. channel return dispatches Delivered;
5. `afterSending()` runs, then existing `NotificationSent` dispatches;
6. post-delivery errors propagate and are never relabeled as delivery failures.

Sentry finishes spans on Delivered, Failed, or Skipped and keeps its breadcrumb on `NotificationSent`. Its local finalizer covers a terminal listener that throws before later listeners observe it.

### Complete Queue enqueue and bulk ownership

Move `partitionJobsByAfterCommit()` from `SqsQueue` to base `Queue` beside `shouldDispatchAfterCommit()` and rollback helpers. Add `JobQueueingFailed` carrying the same queue/job/payload/delay context as `JobQueueing` plus the exception. `enqueueNow()` owns the terminal pair:

~~~php
$this->raiseJobQueueingEvent(...);

try {
    $jobId = $callback($this, $payload, $queue, $delay);
} catch (Throwable $exception) {
    $this->raiseJobQueueingFailedEvent(..., $exception);
    throw $exception;
}

$this->raiseJobQueuedEvent(...);

return $jobId;
~~~

Sentry's payload hook injects propagation data, publish time, and the resolved queue name. `JobQueueing` intentionally carries Laravel's raw queue argument, so the already-created payload is the authoritative source for the destination label; the handler falls back to the raw event value for manually constructed payloads. It starts `queue.publish` at `JobQueueing`, so a transaction rollback before enqueue creates no span or synthetic rollback event.

Bulk Database and SQS attempts emit multiple starts before terminals, and SQS response order is not positional. Publication spans therefore use the exact unchanged event payload string as a short-lived coroutine-local key rather than the feature LIFO. `trackLocalSpan()` records the child without installing it as Hub current; `JobQueued` finishes that exact span with `ok`, and `JobQueueingFailed` finishes it with `internal_error`. The shared tracking concern's existing one feature defer also finishes and forgets any remaining local spans. Queue-created lifecycle payloads are JSON objects with unique UUIDs, while arbitrary `pushRaw()` payloads emit no lifecycle events; do not add numeric-key guards or decode payloads for correlation.

`DatabaseQueue::bulk()` preserves both optimization and single-job semantics:

- create payloads at dispatch time;
- partition immediate/deferred jobs by the shared policy;
- register unique/debounced rollback callbacks per deferred job;
- reacquire a pooled Queue through `afterCommitDispatcher`;
- compute delayed `available_at` at the actual insert attempt;
- emit per-job Queueing, one bulk insert per attempted group, then per-job Queued with nullable ID or Failed;
- avoid event construction when no matching listeners exist.

A mixed batch therefore performs one immediate insert and one after-commit insert. That is the necessary transactional split. Rollback leaves no orphan database jobs.

SQS emits starts only when each chunk is attempted. Successful entries emit Queued; explicit rejects and a thrown ambiguous request emit Failed; later unattempted chunks never start. Preparation/overflow errors close only starts already emitted. Redis, Beanstalkd, and base bulk already delegate through per-job push.

### Port Storage tracing without breaking pooling

Port upstream `configureDisk()` / `configureDisks()` with the same public array-returning, flat transformed configuration. Keep `sentry_disk_name`, original driver, and scoped-prefix values as siblings; nesting the original config would lose scoped-prefix expansion.

Add the optional logical name without changing one-argument behavior or string-path normalization:

~~~php
public function build(array|string $config, ?string $name = null): Filesystem
{
    $config = is_array($config) ? $config : [
        'driver' => 'local',
        'root' => $config,
    ];

    return $this->resolveWithLogicalName(
        $name ?? self::ON_DEMAND_DISK_NAME,
        $config,
        $name,
    );
}
~~~

Keep protected `resolve(string $name, ?array $config = null)` unchanged for configured disks and delegate it to one private `resolveWithLogicalName()` helper. `build()` enters that helper directly because an anonymous build must carry a null logical name separately from the valid configured disk name `ondemand`; it therefore no longer passes through protected `resolve()`. This is a deliberate protected-extension difference: customize on-demand construction through `Storage::extend()` or the public driver creator methods rather than overriding `resolve()`. The built-in branch uses `$name` for construction and fingerprints; the custom branch uses `$logicalName` for both.

Pass that logical name through custom-creator calls and scoped-driver reconstruction. Built-in filesystem creators already receive the active name; the custom-creator boundary is the one construction path that drops it. `callCustomCreator(array $config, ?string $name = null)` closes that asymmetry with an additive nullable third callback argument: the configured disk name, or null for anonymous construction. Existing one-argument calls remain valid, while subclasses overriding this protected method must adopt the optional parameter. Existing two-argument creator callbacks remain valid because PHP user callbacks ignore extra arguments. `createScopedDriver(array $config, ?string $name = null)` passes the name into `build()`. The custom `sentry` creator uses the callback name when non-null and falls back to `sentry_disk_name` for anonymous construction. This is required when scoped expansion reaches a separately transformed parent whose stored name differs from the active outer name; otherwise local signed URLs use the parent's route.

Whole-driver identity includes that name for publicly poolable built-in and custom drivers because it is construction input. S3/GCS client pools ignore the name because only the client is pooled. Custom whole-driver pools that safely ignore the name may declare equivalent construction with the same `pool.fingerprint`; a shared `pool.name` is optional but also requires that shared fingerprint when other construction input differs.

Update the shared pool fingerprint-conflict diagnostic accordingly: recommend a distinct explicit identity, or a matching explicit fingerprint only when the differing value does not affect construction. Purging may replace one obsolete registered definition, but it cannot reconcile two live definitions that share an identity; the pool would alternate between them. The diagnostic cannot distinguish those cases, so it does not recommend purge.

`configureDisks()` can transform both a scoped disk and its configured parent. Reconstructing that scoped disk therefore resolves an inner Sentry decorator before the outer child decorator. Name propagation already gives the inner wrapper the child's label; a marker could therefore avoid duplicate spans. The child may legitimately override the parent's span/breadcrumb flags, however, so every Sentry wrapper implements the internal `DecoratedFilesystem` contract with `getFilesystem(): Filesystem`. The creator unwraps the inner wrapper and applies one outer wrapper with the child's name and flags, the fully expanded prefix, and the original pool/stream owner. Do not inherit the parent's flags, retain nested spans, or create a generic decorator registry.

The custom `sentry` driver reconstructs the original disk through `build($config, $logicalName)` and wraps the returned filesystem or pool proxy. It never swaps process-global config or binds a protected manager method. Logical-name propagation preserves span labels and signed local URL disk identity. Reconstructing the same flat configuration preserves pool identity, scoped prefixes, client pooling, and stream lease ownership.

Adapter wrappers delegate temporary-URL capability checks and callback mutators to the wrapped adapter through one typed accessor; `serve()` and `serveUsing()` remain outer-owned together. Their three traced fluent assertion overrides discard the wrapped adapter's return and return the outer decorator so chains remain instrumented. Trace the four direct-I/O methods otherwise hidden by inherited base implementations: `fileExists()`, `directoryExists()`, `checksum()`, and `mimeType()`. The negative convenience methods keep composing their decorated positive operation, so no duplicate relabeling wrappers are added. Add a test-only reflection guard over non-static public adapter instance methods: each must be implemented by a decorator trait or explicitly classified as outer-owned/composition-only. Inheritance can silently shadow adapter-trait delegation; contract-only wrappers remain compile-time complete for their declared contracts, while concrete-only dynamic helpers do not justify a generic identity-rewriting proxy.

Add the shared capability:

~~~php
interface InvalidatesPool
{
    public function invalidatePool(): bool;
}
~~~

`PoolProxy` and `ClientPooledFilesystem` implement it. Every Sentry filesystem wrapper exposes the capability once through its shared implementation and forwards only when the unwrapped filesystem also implements it; otherwise it returns false, meaning no pool was removed.

`FilesystemManager::purge()` checks the contract instead of concrete types. When no cached disk exists but a configured driver does, resolve that exact configured disk and invalidate through the same capability:

~~~php
if ($disk === null && ! empty($config['driver'])) {
    $disk = $this->resolve($name, $config);
}

if ($disk instanceof InvalidatesPool) {
    $disk->invalidatePool();
}
~~~

This deletes the second implementation of pool-identity derivation, supports transparent userland wrappers without manager knowledge, and preserves distinct configured and on-demand whole-driver identities. Resolving framework pooled drivers creates only lazy proxies and performs no pool/client I/O; built-in non-pooled drivers construct their filesystem stack; custom non-pooled creators may run. This is acceptable on the boot/test/operational-recovery purge path. Invalid configured drivers and throwing creators now propagate just as normal disk resolution does; unconfigured or driverless names remain no-ops.

Document the third custom-creator argument in the filesystem guide and its whole-driver fingerprint effect in the pools guide. Record the additive creator signature under the filesystem README's `Differences From Laravel`; do not generalize it to managers whose built-in creators do not consume a logical name.

Make filesystem route registration capability-based: exact boolean `serve => true` registers the signed download/upload routes for any configured disk, while false or absent values register nothing. This intentionally stops accepting truthy non-booleans and differs from Laravel's local-only gate. Every shipped disk already provides the serving surface used by `ServeFile` / `ReceiveFile`; custom opted-in drivers must do the same. Record the lasting difference in the filesystem README and update the guide heading/anchor rather than retaining local-only wording. Do not resolve disks, inspect effective/original drivers, or add Sentry-specific route code during boot.

If neither spans nor breadcrumbs are enabled, the custom driver returns the original filesystem directly.

Cover the applicable filesystem contract, Cloud URL operations, `readStreamRange`, eager temporary URLs, and purge. Do not add a generic disk-decorator registry or machinery for lazy streamed-response callbacks whose terminal is outside this integration.

### Make Redis PII and session resolution safe

Redis spans/breadcrumbs omit command parameters unless `send_default_pii` is true. With PII enabled, parameters remain observable but the current session key is redacted. Preserve `"0"` by filtering only null/absence, never PHP truthiness.

Redis command parameters are `array<array-key, mixed>`. Resolve the session key once per command, replace only matching string parameters with one shared placeholder, and pass non-string parameters—including null—through unchanged so a missing session key cannot redact a null Redis argument.

Extract one shared Cache/Redis session-key concern with this resolution order:

1. use the already-resolved session store when available;
2. use the current request's session cookie when available;
3. only as a last resort resolve/build the store under a coroutine-local reentry guard;
4. catch `Throwable` and return no key when session resolution itself fails.

The guard exists only around last-resort construction; ordinary resolved-store/cookie paths allocate no context state. Tighten `replaceSessionKey(string $value)` and remove its unreachable nullable branch.

Extract the duplicated Redis success/failure recorder while preserving distinct nullable-duration behavior. Keep Redis pool metrics and no-op early exits intact.

### Gate instrumentation at worker boot

Use merged boot configuration because Guzzle AOP registration must precede proxy generation. Do not register the global Guzzle aspect or child-Hub clone hook when neither DSN nor Spotlight is active.

Treat Spotlight as active when configured with exact `true` or a non-empty URL string, and read `SENTRY_SPOTLIGHT` in the shipped config. Do not duplicate the SDK's URL validator.

One small `SdkCapabilities` service owns the merged-config rules for an active endpoint, SDK tracing, and breadcrumbs. Feature instances resolve that auto-singleton through their existing container and retain their per-feature boolean memoization; the Guzzle aspect receives it through constructor injection; and the provider's protected DSN/Spotlight extension seams delegate to it. This removes duplicate array/dot readers without changing Feature's constructor contract or adding request-path resolution.

Cache two independent booleans:

~~~php
$canRecordSpans = $traceFeatureEnabled && $sdkTracingEnabled;
$canRecordBreadcrumbs = $breadcrumbFeatureEnabled && $maxBreadcrumbs > 0;
~~~

Use them to skip unusable database/view/routing/scheduled hooks, Redis event emission, Cache/Notification listeners, and Storage decorators. Propagation remains independent: incoming trace continuation, outbound Guzzle headers, and queue payload trace data still work when local spans are disabled.

Derive these two booleans from merged config because Storage may resolve before the Hub is built. Mirror both SDK tracing rules: `enable_tracing === true` implies the SDK's default sample rate, otherwise tracing requires `enable_tracing !== false` and a non-null `traces_sample_rate` or `traces_sampler`. Use `Options::DEFAULT_MAX_BREADCRUMBS` for the breadcrumb default. Keep PII decisions Hub-derived because they have no pre-boot reader. Cap per-disk Storage overrides by these global capabilities.

`Feature::boot()` and `bootInactive()` stop swallowing errors. Remove redundant explicit singleton bindings because concrete features already auto-singleton and existing instance swaps must survive. The provider catches each register/boot phase once and logs a structured warning with feature class, phase, and exception. State that the phase failed and did not complete, effects applied before the throw remain, and the phase is not retried for the worker lifetime. A successful ConsoleScheduling register followed by a failed boot therefore leaves its macro installed. Continue later phases independently; do not report through Sentry or add rollback, retry, or disable state.

Revalidate `di-02`: inactive Sentry must not force proxy generation; no DI source change is expected.

### Restore current applicable parity

Implement the following without Laravel-only integration code:

- dynamic version and identifier `sentry.php.hypervel` in provider, pool, About, and Test surfaces, with a stable fallback;
- DSN precedence `SENTRY_HYPERVEL_DSN`, then `SENTRY_DSN`, and matching Publish/Test guidance;
- `continue_after_response`: deferred finalization when true, one terminate-phase finalization when false;
- always call `continueTrace()` for propagation; start no transaction when SDK tracing is disabled;
- per-job `SentryTracesSampleRate` middleware;
- scheduled monitor expression override;
- `enable_metrics` and default `/up` ignore behavior;
- current SDK Hub behavior: sampling-decision source/validation logging introduced in 4.12 and `profiles_sampler` introduced in 4.27;
- About output recognizing a custom profile sampler.

Keep monitor/job propagation independent of local span recording. Do not port Laravel-only AI, Livewire, Folio, Pennant, Lighthouse, or Octane integrations.

### Complete focused correctness cleanup

- Initialize `LogsHandler::$batchFormatter = null`.
- Catch `Throwable` in general EventHandler, Tracing EventHandler, Redis session resolution, and Cache session resolution.
- Restore `error_reporting()` in TestCommand with `try/finally`.
- Use `Env::writeVariables(..., overwrite: true)` in PublishCommand.
- Normalize model violation callables with `Closure::fromCallable()`.
- Read a compiled view origin once and treat false as null.
- Preserve falsey auth IDs/fields by filtering only null.
- Encode queue payload data once; if `json_encode()` returns false for a custom Job's non-encodable data, publish a null body-size attribute instead of throwing from instrumentation.
- Check scope count before flushing in `maybePopScope()` so a first/no-scope job does no work.

Remove the unused Hub context-ID constant, redundant `breadcrumbs.sql_bindings` special option, no-op `tracing.default_integrations` and unused resolver argument, duplicate view wording, dead concrete Guzzle client branch, and temporary flush comments.

### Correct package metadata and optional integrations

Declare direct split requirements for Guzzle, Monolog, Nyholm PSR-7, PSR HTTP message/log, Symfony PSR bridge, Hypervel Filesystem, and Hypervel Validation. Set `sentry/sentry` to `^4.27` in both root and split metadata because profiles-sampler parity requires 4.27; do not raise to the installed version without an API reason.

Sanctum stays out of split requirements and suggestions. Keep its event class string registered without `class_exists()`: the `::class` expression is a free literal, while the guard would force an unnecessary autoload attempt at boot. The listener is inert when Sanctum is absent, and installing Sanctum does not unlock a Sentry capability that warrants a suggestion.

### Document the supported operating contract

Create `src/boost/docs/sentry.md` in Laravel-docs prose and add it to the documentation index. Cover installation, DSN precedence, errors, logs, tracing and sampling, metrics, queue sampling middleware, monitors, Storage, Redis/cache PII, Spotlight, transport pooling/backpressure, and shutdown.

State accurately:

- normal request/job capture is detached and nonblocking;
- pool exhaustion drops telemetry rather than blocking application work;
- graceful queue-worker and command drains are bounded;
- worker-exit final delivery is best effort;
- `server.settings.max_wait_time` should be strictly greater than `SENTRY_HTTP_TIMEOUT`, with margin for other shutdown work;
- the two timeouts remain independently configured.

Keep `src/sentry/README.md` minimal: documentation link, the genuine bounded asynchronous-transport difference, and upstream reference. The Storage configuration methods are upstream parity and belong only in the guide.

## Affected source and documentation

Primary Sentry files:

- `src/sentry/src/Transport/{HttpPoolTransport,Pool}.php`; delete `src/sentry/src/HttpClient/HttpClient.php`;
- `src/sentry/src/{Hub,Integration,SdkCapabilities,SentryServiceProvider,EventHandler,Version}.php`;
- `src/sentry/src/Aspects/GuzzleHttpClientAspect.php`;
- `src/sentry/src/Tracing/{EventHandler,Middleware,ViewEngineDecorator}.php`;
- `src/sentry/src/Features/{CacheFeature,ConsoleIntegration,ConsoleSchedulingFeature,NotificationsFeature,QueueFeature,RedisFeature}.php`;
- `src/sentry/src/Features/Concerns/{ResolvesEventOrigin,TracksPushedScopesAndSpans,WorksWithSpans}.php` plus one shared session-key concern;
- `src/sentry/src/Http/FlushEventsMiddleware.php`;
- `src/sentry/src/Logs/LogsHandler.php`;
- `src/sentry/src/Console/{AboutCommandIntegration,PublishCommand,TestCommand}.php`;
- new Storage feature/decorator files following the upstream feature shape, including the internal `DecoratedFilesystem` unwrapping contract;
- `src/sentry/config/sentry.php`, root/split `composer.json`, `src/sentry/README.md`, `src/boost/docs/sentry.md`, and the docs index.

Framework-owner files:

- `src/coroutine/src/Coroutine.php` and `src/boost/docs/coroutines.md`;
- `src/core/src/Bootstrap/WorkerExitCallback.php`;
- `src/object-pool/src/Contracts/InvalidatesPool.php`, `src/object-pool/src/PoolProxy.php`, and `src/object-pool/src/PoolManager.php`;
- `src/filesystem/src/{ClientPooledFilesystem,FilesystemManager,FilesystemServiceProvider}.php`, `src/filesystem/README.md`, `src/boost/docs/filesystem.md`, and `src/boost/docs/pools.md`;
- Cache events, `src/cache/src/Repository.php`, same-family tagged paths shown by source tracing, and `src/boost/docs/cache.md`;
- Notifications events, `src/notifications/src/NotificationSender.php`, and `src/boost/docs/notifications.md`;
- Queue events, `src/queue/src/{Queue,DatabaseQueue,SqsQueue,Worker}.php`, and `src/boost/docs/queues.md`.

`src/server` is revalidated but unchanged. Add or update focused tests under each owning package; do not hide framework-owner coverage inside Sentry-only tests.

## Testing plan

### Transport and shutdown

- accepted sends retain EventId, exclusively own one transport, and release after real completion;
- pool exhaustion/closure skips without blocking; unexpected child failure discards; spawn failure balances the generation, releases, and returns a failed Result without throwing into application code;
- real status/rate-limit headers affect the next send on the same transport;
- zero close reports success/pending without waiting; positive close captures one generation and leaves later sends in the next;
- Logs/TraceMetrics envelopes are created before drain generation capture;
- queue graceful drain waits; immediate/max-memory stops do not flush or wait;
- repeated native WorkerExit dispatches one framework event/resume; a throwing listener is not replayed;
- OnWorkerExit flushes once, shuts down once, returns without waiting, and rejects later acquisitions.

### Coroutine, Hub, and spans

- fork copied context is visible before callbacks; create behavior remains stable; Sentry/Telescope callback results survive;
- parent/child/sibling Hub Scope and Request objects are isolated while client/span pointers remain correct;
- placeholder scope survives client binding; every root clones its baseline; the final root cannot pop;
- Guzzle propagation survives disabled spans; its child never becomes Hub current; the exact child finishes on success/error;
- nested/interleaved database connections pop only their own spans; response stacks remain separate; null query time is instantaneous;
- caught missing-terminal paths are locally unwound at coroutine end, while normal paths leave finalizers as no-ops.

### Framework terminal owners

- Cache one/many store-read or unserializable-class-handler throws emit one failure terminal and rethrow; a many-read handler failure emits no partial Hit/Missed terminals; write/forget throws use existing failures; no event construction without listeners;
- empty finite-TTL batch writes return true without dispatching `WritingManyKeys` or touching the store in Repository, Redis all-tag, and Redis any-tag paths;
- Notification skipped/delivered/failed/sent order is exact; pre-delivery dedup holds; post-delivery errors propagate without false failure;
- Queue single enqueue emits Queueing then Queued/Failed; rollback before enqueue emits nothing;
- Database bulk preserves one insert per attempted group, after-commit delay origin, rollback behavior, reacquisition, and exact per-job terminals;
- SQS partial/failed chunks terminalize only attempted entries with the unchanged payload; later chunks never start;
- publication spans never become Hub current; a defaulted queue keeps the same resolved destination on publish and process spans, and three starts followed by mixed out-of-order success/failure terminals finish the exact keyed spans;
- forced queue termination exposes `terminatesImmediately` and is never delayed by telemetry.

### Storage, PII, gating, and parity

- string-path/on-demand builds and existing two-argument custom creators retain their contracts;
- custom creators receive null for anonymous `build()`, the literal configured name for `disk('ondemand')`, and the explicit name for `build($config, 'uploads')`; anonymous and configured custom pool identities remain distinct;
- scoped local disks generate signed routes for the outer configured name, and transformed Sentry scoped disks preserve the same route;
- custom creators receive the configured nullable name; custom poolable identities split by name unless explicit pool controls declare equivalence;
- S3/GCS client pools remain name-independent; built-in whole-driver pools already include names; custom whole-driver pools now include names and require a shared explicit fingerprint to converge safely across them;
- pool fingerprint conflicts explain both valid remedies, with the explicit-fingerprint path qualified by construction equivalence;
- flat configured disks remain lazy and preserve logical identity, scope prefix, URLs, Cloud behavior, `readStreamRange`, temporary URLs, temporary-URL capability checks and callback mutators, leases, and purge invalidation;
- serve-enabled and ordinary local disks report true and false temporary-URL capability respectively; the adapter ownership guard classifies every non-static public method on both base and S3 adapter/decorator pairs;
- `fileExists`, `directoryExists`, `checksum`, and `mimeType` each emit the expected span/breadcrumb without adding wrappers for composed negative operations;
- all three traced fluent assertions return the outer decorator and a following chained operation remains instrumented;
- configured nested/scoped disks use the outer logical name and feature flags with exactly one Sentry decorator;
- a forgotten configured scoped pooled disk resolves its configured identity and removes that pool;
- named and anonymous scoped whole-driver builds keep distinct identities, and purging the named disk leaves the anonymous pool alone;
- transformed Sentry disks forward purge invalidation after `forgetDisk()`; non-pooled decorators return false;
- purge remains a no-op for driverless names and throws for an unsupported configured driver;
- no-op Storage returns the original disk;
- exact `serve => true` controls route registration for local and custom/decorated disks; an opted-in non-local disk serves through the registered route, while absent/false values register nothing;
- a transformed served local disk keeps its outer logical route, serves successfully, and emits eager `file.mimeType` telemetry;
- Redis PII disabled/enabled/redacted cases, key `"0"`, resolved-store, cookie, last-resort recursion, and Throwable behavior;
- inactive provider installs no unusable listeners/AOP/events/decorators; Spotlight URL configuration is active; span/breadcrumb decisions remain independent; `enable_tracing => true` and a zero trace rate mirror SDK semantics; propagation remains active;
- feature-phase failures log their exact partial-effect and no-retry consequence once without overwriting pre-registered feature instances;
- dynamic SDK metadata, DSN precedence, both `continue_after_response` finalization modes, continuation, profiles sampler, metrics, the conventional health route path, monitor expression, and job middleware;
- split metadata leaves optional Sanctum integration unlisted without forcing its event class to autoload at boot;
- Logs formatter default, error-report restoration, Env publication, callable normalization, falsey auth, view-origin failure, non-encodable custom-job data producing a null queue body size, and first-job no-flush behavior.

Run each changed test file immediately, then all Sentry and connected framework tests, relevant filesystem/queue integrations, and finally `composer fix`.

## Performance, scalability, and compatibility

- Request and ordinary job paths add no wait, retry, sleep, polling, lock, container resolution, or synchronous Sentry network work.
- A captured WaitGroup reference plus `add()`/`done()` surrounds only an actual Sentry network send; this bounded integer bookkeeping replaces unsafe coroutine-context transport tracking.
- Queue publication span correlation adds one short-lived coroutine-local map entry only for a sampled publish attempt; it avoids Hub mutation, payload decoding, and positional/LIFO mismatches.
- Feature gating removes listeners, event construction, Redis event emission, decorators, and AOP work when output is impossible.
- Cache/Notification/Queue event additions are `hasListeners()`-guarded; new work is on accepted operations or cold failure paths.
- Database bulk retains bulk inserts. A mixed immediate/deferred batch necessarily uses two inserts to preserve transaction correctness.
- Storage stays lazy and preserves existing pools rather than constructing disks at boot or bypassing lease ownership. Uncached purge may reconstruct a configured non-pooled disk solely to discover its invalidation capability; it performs no client/pool I/O for framework pooled drivers and is outside request hot paths.
- Serving-route registration reads one existing boolean config value at boot and never resolves a disk. Non-local/custom disks participate only when explicitly configured with exact `serve => true`; this intentional capability contract differs from Laravel's local-only gate.
- Name-aware custom poolable drivers split pools by logical name for correctness. A shared explicit fingerprint preserves cross-name convergence when the author knows name-independent reuse is safe; an optional shared explicit name may choose the identity but does not replace fingerprint compatibility. Existing `pool.name`-only custom configurations with differing names now fail rather than unsafely share. No request-operation work is added.
- Redis/session redaction fast paths use already-resolved state before fallback resolution.
- Public Laravel-facing behavior remains compatible except for the documented Filesystem protected construction seams required to preserve anonymous logical identity. Additive framework surfaces are `FilesystemManager::build(..., ?string $name)`, `createScopedDriver(..., ?string $name)`, `callCustomCreator(array $config, ?string $name = null)`, the nullable logical-name argument supplied to custom filesystem creators, `InvalidatesPool`, Cache retrieval-failure events, Notification skipped/delivered events, Queue failure event, and `WorkerStopping::$terminatesImmediately`. Existing one-argument `callCustomCreator()` calls remain valid, but overrides must adopt the optional parameter; `resolve()` remains the configured-disk seam and no longer intercepts `build()`. These surfaces solve verified general owner defects without hidden compatibility machinery.
- The accepted Guzzle trace-tree change prevents corrupt parent restoration; lower-layer spans no longer appear beneath `http.client`.
- Final worker-exit delivery is deliberately best effort, not a false guarantee or a reason to couple Sentry and server timeout configuration.

## Rejected concerns and machinery

- No worker-global pending-send/task/result registry, transport queue, retry system, poll scheduler, or copied Event snapshot.
- No synchronous request-path Sentry delivery or worker-pool closure from `TransportInterface::close()`.
- No private SDK rate-limiter sharing through reflection or copied internals.
- No wrapper/fork of SDK Logs/Metrics runtime managers; envelopes snapshot attribution, and early cross-request flush does not misattribute them.
- No layer-local worker Hub client binding without a demonstrated request-level consumer.
- No model/property-cache redesign without evidence.
- No generic filesystem decorator registry, process-global config swap, bound protected manager call, or lazy streamed-response observer.
- No reflection/arity registry or poolable-only callback signature for filesystem custom creators; both would make construction identity depend on callback shape or registration mode.
- No failed-publication event for transaction rollback before enqueue because no attempt began.
- No relabeling of post-delivery notification errors as delivery failures and no reordering of Laravel's `afterSending()` / `NotificationSent` lifecycle.
- No dynamic feature toggles, feature retry/disable registries, or feature-registration reporting through Sentry.
- No Laravel-only AI, Livewire, Folio, Pennant, Lighthouse, or Octane integrations.
- Existing scheduled-task failure handling already checks exit status and is not reopened.

## Records and completion

During implementation, replace pending plan wording with the final design rather than appending decision history. After code review:

- add one compact ledger work unit for the Sentry audit and allocate the durable IDs above;
- revalidate existing `sentry-01`, `redis-15`, and `di-02`;
- reopen and amend the completed Coroutine, Core, Object Pool, Filesystem, Cache, Notifications, and Queue entries for every shared owner finding above, then revalidate their named consumers;
- record `filesystem-16` as the exact serving-capability predicate and its intentional Laravel difference;
- record that filesystem built-in and custom creators receive the active logical name, so whole-driver pool identity includes it; a shared explicit fingerprint may declare safe convergence, while S3/GCS client pools ignore the name;
- record the custom `pool.name`-only behavior change and the corrected shared fingerprint-conflict remedy;
- record that this closes a filesystem-specific built-in/custom construction asymmetry and must not be copied mechanically to managers whose built-in creators have no logical-name input;
- add dependency-index rows for every shared owner finding, including Core → Sentry, Server as an explicitly revalidated unchanged callback-wiring consumer, and the pending Telescope package as a revalidated consumer of `coroutine-08` without reopening Telescope;
- update the routing index to the exact Sentry work-unit heading and required cross-package entries;
- check off Sentry only after implementation, all gates, fresh self-review, independent code review, owner approval, and the final bookkeeping commit;
- state that public Laravel APIs/configuration remain compatible and list the additive general framework surfaces above.

No accepted defect, TODO, compatibility path, or deferred implementation remains in this work unit after completion.
