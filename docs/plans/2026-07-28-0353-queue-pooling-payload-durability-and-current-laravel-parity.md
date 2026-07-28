# Complete Queue Pooling, Payload Durability, and Current Laravel Parity

## Status

The package audit, second-opinion loop, owner review, implementation, full
gate, and fresh self-review are complete. The owner approved every public API,
configuration, contract, and Improvement gate, including the confirmed
Beanstalk and QueueFake defect corrections. Final review corrections,
`queue-39`, focused regressions, the full gate, and fresh self-review are
complete. Final code review is signed off.

## Scope

Complete the Queue audit as one coherent work unit across Queue, Support
fakes/facades, Foundation configuration, Contracts, Broadcasting,
Notifications, Horizon, Telescope, Testing, Boost documentation, package
metadata, and audit records.

The final implementation must:

- keep every pooled queue object and lease within its owning coroutine;
- preserve dispatch-time payload creation without retaining a lease through a
  database transaction;
- batch SQS sends and make overflow storage durable under every known partial
  failure;
- preserve FIFO identifiers and retry options exactly;
- terminally fail malformed external payloads without losing or endlessly
  redelivering them;
- keep Redis reservation atomic for every nonconforming raw payload shape;
- preserve primary failures through queue, pool, cache, and file cleanup;
- adopt current supported Laravel Queue APIs without importing
  worker-singleton assumptions that are unsafe under concurrent coroutines;
- keep Hypervel's concurrent worker, pooling, route, debounce, payload-context,
  and Laravel-interoperability enhancements intact;
- make failed-job storage and operational commands truthful;
- remove stale contract, dependency, suppression, fallback, comment, and
  documentation state superseded by the final design; and
- add no speculative capability, compatibility layer, registry, pagination
  API, or generic proxy forwarding.

## Post-compaction and anti-overengineering rules

After compaction, read `AGENTS.md` and this plan in full before resuming. This
section carries every anti-overengineering constraint needed from the
framework-wide plan so that plan need not be reread during Queue
implementation.

### Evidence and scope

- A change needs a demonstrated failure, a complete source trace proving a
  realistic vulnerable schedule, an approved capability with real consumers,
  or deletion of greater/riskier complexity. Merely conceivable states,
  unsupported misuse, style preference, theoretical flexibility, and
  benchmark noise do not justify machinery.
- Typical Laravel lifecycle semantics define the supported contract. Do not
  build parallel enforcement for deliberate bypasses such as direct transport
  access, disabled listeners/middleware, raw writes, or comparable escape
  hatches unless the public contract promises behavior through that path.
- Trace upstream differences before classifying them. Upstream parity is
  neither proof of correctness nor permission to retain a real defect.
- Audit categories and the initial diff are discovery lenses, not scope
  excuses. Investigate and fix any verified same-family or lower-level issue at
  its owner; do not use this rule to promote speculative concerns into work.
- Prefer focused source reasoning and one discriminating probe when native or
  scheduler behavior is uncertain. Do not rerun the full suite hoping a rare
  failure reproduces.
- Use accurate severity/confidence. Do not inflate a label to make a weak case
  actionable.

### Design and ownership

- Choose the simplest complete design. Do not add an abstraction, state
  machine, retry loop, configurable timeout, registry, mutex, context slot,
  cache, compatibility API, second reporter, or wrapper merely because it
  sounds robust.
- Fix the lowest inconsistent owner. Never add a consumer catch, proxy
  workaround, or duplicated guard over a broken shared contract.
- Make resource identity explicit: the component that acquires a lease,
  connection, callback, timer, process, stream, or lock releases that exact
  handle rather than reconstructing it from mutable state.
- Creation is transactional. If an operation reserves or publishes state
  before later work can fail, either complete it or roll back exactly what was
  acquired—no more and no less. Do not report failure after a successful
  ownership commit merely because later notification failed.
- Cleanup is exhaustive and preserves the earliest failure. Use an existing
  no-throw reporter for secondary cleanup failures; never mask the primary
  failure.
- Bound waits only when progress depends on an external process, peer, socket,
  lock owner, or service that can disappear. Do not add arbitrary timeouts to
  internally owned coroutine joins.
- Prefer existing seams—factories, timers, connection factories, filesystem
  boundaries, process wrappers, and protected overrides. Do not add a public
  production hook solely for tests.
- Treat established remediation shapes—explicit parameters, immutable values,
  scoped bindings, cloning, coroutine context, factories, retained handles,
  static reset, and deterministic teardown—as candidates, not prescriptions.
  Select one only after proving the real lifetime and owner.
- Do not convert ported code to dependency injection merely for style. In
  particular, preserve `FileFailedJobProvider`'s public constructor.
- Do not pool stateless or already-shared layers.

### Public API and parity

- Hypervel 0.4 is greenfield: churn, blast radius, and backward compatibility
  do not justify retaining a flawed Hypervel-only API or internal design.
  This does not make current Laravel public APIs, config structure, documented
  behavior, or conventional extension patterns disposable.
- A Laravel-facing divergence requires a concrete meaningful Hypervel benefit,
  explicit owner approval, and an account of compatibility, alternatives,
  performance, complexity, and ongoing upstream-comparison cost. All such
  Queue gates are recorded as approved below.
- Before removing an API because a lower dependency deprecated it, verify that
  Queue's direct upstream also removed or deprecated its wrapper.
- Inspect contracts, facades, managers, factories, registries, repositories,
  drivers, transports, adapters, connectors, pools, and SDK wrappers as one
  public surface. Proxies must not leak borrowed internals.
- Add a method to a contract only when every conformer should implement it.
  Optional concrete capabilities do not become core-contract methods merely
  because framework implementations share them.
- Keep each configuration default at one owner. Required driver settings are
  declared in shipped config and read directly. Optional settings inside a
  replace-whole block may be omitted from shipped config and defaulted by the
  consuming driver.
- Do not duplicate an upstream method merely to avoid one harmless line, but
  do not preserve upstream process-global mutation that races in Swoole.

### Hot-path discipline

- For every change inspect allocations, container/facade/config resolutions,
  locks/atomics, hashing, serialization, I/O, yields/sleeps, retries/polling,
  logging/exception creation, cache effects, and retained worker memory.
- A cold failure-path check is not equivalent to a lock or resolver on every
  dispatch. State frequency and magnitude rather than calling all overhead
  “noise.”
- Any measured or source-proven hot-path regression requires owner approval,
  even for correctness. Present alternatives and do not hide the trade-off
  inside a general correctness claim.
- A performance improvement must remain meaningful after its complexity and
  upstream-divergence costs. Surface real opportunities; reject
  micro-optimizations within measurement noise.
- Do not allocate a generalized callback/closure collection when a small
  fixed `try`/`catch`/`finally` sequence is clearer.
- Do not mutate worker-global config, environment, timezone, locale, encoding,
  PHP INI, exception handlers, terminal state, or SDK globals during yielding
  request/programmatic work. Boot-time configuration is process-global;
  request behavior uses explicit or coroutine-local values.
- Never save/mutate/restore a singleton prototype around yielding work; clone
  or create the owned object before mutation.

### Failure and static-analysis boundaries

- Do not catch broad `Throwable` around user hooks, event dispatch, container
  resolution, or database work. Catch only the verified payload exception at
  the exact containment points below.
- Guard optional observational events before constructing expensive payloads.
  Do not guard dispatch when dispatch is the requested action.
- Metadata checks do not replace the native operation. `exists()` before
  read/open is still a race; check the native false/error result and translate
  it to the declared framework failure.
- Suppress a native warning only where the return is immediately checked and
  converted to a named failure contract.
- Resolve or narrow dynamic collaborators truthfully for PHPStan. Do not
  distort source, add redundant runtime guards, or weaken types merely to
  silence analysis; use the narrowest scoped ignore only when a valid dynamic
  API cannot be modeled.
- Do not register a listener for one operation and later remove all listeners
  for its event. Retain/remove the exact callback, or use one boot listener
  reading coroutine-local state.
- Do not put throwable I/O or resource release in no-throw static
  `flushState()` methods, and do not manually forget ended coroutine context
  there.

### Completeness and tests

- Underengineering is also failure. Fix every verified defect completely at
  its lowest owner and surface worthwhile improvements; restraint applies to
  speculative machinery and cosmetic churn, not complete fixes.
- When the owner model changes, remove obsolete helpers, callbacks,
  properties, config keys, comments, tests, documentation, and compatibility
  paths. Preserve upstream comments only while still true.
- Test the old observable failure and invariant, not the implementation's
  private shape. Do not add reflection-heavy or brittle source-shape tests
  where behavior proves the contract.
- Treat tests as lifecycle code: restore exact global/container/facade/env
  state in `finally`; use `ParallelTesting::tempDir()` and isolated services;
  close every stream, process, timer, lock, channel, connection, and lease.
- Do not weaken assertions, increase arbitrary waits, or ignore newly skipped
  tests to obtain green output.
- Any unexpected defect or design contradiction stops that implementation
  slice for full investigation and second-opinion consensus. For a confirmed
  Components or Swoole defect, follow the owner notification/authorization
  rule rather than silently adding a workaround.

## Fixed architecture and research

### Ownership and lifecycle

| Surface | Final owner and lifetime |
|---|---|
| Queue manager and connection proxies | Worker lifetime |
| Borrowed Beanstalk/SQS queue | One synchronous proxy call |
| Popped pooled job lease | The job until delete, release, or discard |
| Deferred dispatch | Transaction callback containing prepared payload and a proxy dispatcher, never a borrowed queue |
| SQS bulk after commit | One transaction callback and one lease for the whole batch |
| SQS overflow body | Cache entry from successful/ambiguous publication until terminal delete |
| Decoded job payload | One job object; a validated array or a cached `InvalidPayloadException`. A Redis job with valid job/data but invalid attempts caches both, and its override always surfaces the exception |
| Redis raw/reserved member | Exact bytes returned by the existing Lua reservation round trip |
| Concurrent running jobs | Worker daemon state; entries remain visible until completion/timeout |
| QueueFake hooks and inspection state | One fake instance |
| Worker control flags | Boot-only process-global state, reset after tests |

`DatabaseTransactionsManager::addCallback()` executes immediately when no
callback-applicable transaction exists. Therefore the dispatcher must use the
current borrowed queue in that case; blindly borrowing again can deadlock a
pool whose maximum size is one.

Queue pool identity excludes the logical connection name. Two logical
Beanstalk/SQS connections with identical construction configuration may share
one physical pool, so the logical name and deferred dispatcher must be
overwritten on every borrow and the dispatcher cleared on release.

### Upstream references

The implementation reference is the current local Laravel Framework checkout
at `examples/laravel/framework`, commit
`23e9e71f382b91510c70b5b6f9ae0776f1b88e12`. Originating changes are discovery
evidence only; port current source and tests:

| Surface | Originating Laravel PRs |
|---|---|
| SQS overflow storage | #59734 |
| SQS credential providers | #59733, #59754, #59866, #59867, #60000 |
| SQS FIFO retry options | #58936 |
| SQS `SendMessageBatch` bulk | #60645 |
| Queue inspection | #59511, #59997, #60326, #60374 |
| Queue metrics | #56010 |
| QueueFake delayed/reserved jobs / push hooks | #60636, #60644, #60689 |
| Release middleware | #60630 |
| Job release exception | #60823 |
| Worker, events, options, and commands | #59308, #59310, #59370, #60023, #60072, #60109, #60134, #60153, #60176, #60201, #60592, #60613 |
| Command and middleware parity | #60168, #60279, #60722, #60799 |

Current Laravel remains the API/code reference, but its verified overflow,
exact-zero middleware, and malformed-payload holes are corrected rather than
copied.

### Owner-approved gates

The owner approved:

- current additive SQS, worker, middleware, inspection, command, and QueueFake
  APIs;
- the explicit `QueuePoolProxy::withConnection()` boundary for SQS retry
  options;
- the one-slot pooled after-commit dispatcher;
- the protected `enqueueUsing()` callback change from Laravel's
  payload-first shape to an explicit operation-owner parameter;
- the appended nullable Redis reservation-attempt parameter on
  `RedisJob::__construct()`;
- fail-fast handling of unsupported `queue.failed.driver` values;
- `WorkerOptions` constructor placement for `stopWhenEmptyFor`, followed by
  Hypervel's additional options;
- removal of `getChannels()` from the core Broadcasting contract;
- widening Notification Factory notifiables to `mixed` while keeping
  `Factory::sendNow()` at two parameters; and
- concise operational documentation for eager inspection memory use.

## Finding summary

| ID | Category | Severity | Final boundary |
|---|---|---:|---|
| `queue-15` | Defect | Major | Fresh-lease dispatcher for real after-commit work; current lease for immediate callbacks |
| `queue-16` | Current Laravel parity | Improvement | One ordered SQS batch operation, including one post-commit lease per batch |
| `queue-17` | Parity and upstream defects | Major | SQS overflow publication, retrieval, deletion, and cleanup with exact failure precedence |
| `queue-18` | Parity and exact-value defects | Major | Current credentials and FIFO retry; preserve `"0"` identifiers |
| `queue-19` | Defect | Major | Decode and validate each consumed payload once |
| `queue-20` | Defect | Major | Terminal poison-job worker path with complete events and no retry |
| `queue-21` | Defect and upstream defect | Major | Redis `pcall` reservation for malformed JSON, scalars, missing/nonnumeric attempts, and raw `"0"` |
| `queue-22` | Cross-package defect | Major | Horizon/Telescope contain only invalid-payload telemetry; other listener failures propagate |
| `queue-23` | Durability defects | Major | Raw-safe failed providers, checked retry, persistence before output, and terminal-safe poison diagnostics |
| `queue-24` | Defect | Major | Truthful `InvalidPayloadException` and inspection diagnostics |
| `queue-25` | Defect | Major | Non-expiring zero timeout and truthful timeout exit/reason |
| `queue-26` | Current Laravel parity | Improvement | Boot-only report/lost-connection controls without singleton current-job state |
| `queue-27` | Current Laravel parity | Improvement | `WorkerIdle`, quiet-period stop, and completion-based worker metadata |
| `queue-28` | Defect | Major | Preserve daemon state while paused and remove zero-start `--max-time` exit |
| `queue-29` | Parity and output defects | Minor | Worker options, command option precedence, exact JSON, and operational output |
| `queue-30` | Defect | Major | Release unique-until-processing through the actual framework job |
| `queue-31` | Parity and upstream defects | Minor | Enum/exact-zero middleware APIs, callable backoff, Release middleware |
| `queue-32` | Contract defect | Minor | Normalize `DateInterval` where `InteractsWithQueue` already accepts it |
| `queue-33` | Parity and exact-value defects | Minor | Current database failed-provider shape and exact filters |
| `queue-34` | Durability defect | Major | Checked, atomic file failed-job publication under the existing lock |
| `queue-35` | Metadata defect | Minor | Truthful direct dependencies and shared listener binary helpers |
| `queue-36` | Current Laravel parity | Improvement | Concrete eager inspection APIs; no core contract addition |
| `queue-37` | Parity and upstream defects | Minor | Disjoint QueueFake state, delay-faithful dispatch, reserved inspection, and ordered instance push hooks |
| `queue-38` | Current Laravel parity defect | Minor | Beanstalk total size includes ready, delayed, and reserved jobs in one stats read |
| `queue-39` | Defect | Major | Preserve the original timeout when batch rollback also fails so failed-job transaction cleanup and diagnostics remain correct |
| `database-14` | Cross-package contract defect | Minor | Declare the rollback level already supported by the implementation and required by Queue's timeout cleanup |
| `redis-21` | Cross-package defect | Major | Queue inspection and pattern deletion require raw phpredis scan results; reject transformed SafeScan connections before scanning or deleting |
| `redis-22` | Cross-package metadata and API defect | Major | Truthful case-insensitive command signatures and distinct Redis-command versus pool-lifecycle discard APIs |
| `contracts-09` | Contract defect | Minor | Remove optional broadcaster capability and false Collections dependency |
| `notifications-07` | Contract defect | Major | Factory accepts every supported notifiable shape without changing `sendNow()` arity |

Revalidate carried `queue-01`, `queue-11`, `queue-12`, `queue-13`,
`queue-14`, `reflection-04`, `events-03`, `support-02`, `bus-03`, `bus-10`,
`bus-17`, `bus-18`, and `redis-13`.

## Implementation order

1. Correct `InvalidPayloadException`; later decode boundaries rely on it.
2. Implement the pooled dispatcher and its exact release/reset behavior.
3. Port SQS batching, overflow, credentials, and retry options on that
   ownership model.
4. Implement payload validation, Redis reservation, worker poison handling,
   failed-job durability, and observability containment.
5. Complete worker lifecycle, events, options, commands, middleware,
   attributes, and providers.
6. Port concrete inspection and QueueFake APIs.
7. Correct cross-package contracts, dependencies, configuration,
   documentation, facade metadata, and audit records.
8. Run focused tests, `composer fix`, fresh self-review, and code review.

## 1. Make pooled after-commit dispatch ownership explicit

### Files

- `src/queue/src/Queue.php`
- `src/queue/src/QueueManager.php`
- `src/queue/src/QueuePoolProxy.php`
- `src/queue/src/BeanstalkdQueue.php`
- `src/queue/src/SqsQueue.php`
- `src/queue/src/DatabaseQueue.php`
- `src/queue/src/RedisQueue.php`
- `src/horizon/src/RedisQueue.php`
- `src/contracts/src/Queue/Queue.php`
- `tests/Queue/BeforeCommitContractTest.php`
- `tests/Queue/QueueBeanstalkdQueueTest.php`
- `tests/Queue/QueueManagerTest.php`
- `tests/Queue/QueuePoolProxyTest.php`
- `tests/Queue/QueueSqsQueueTest.php`
- `tests/Integration/Horizon/Feature/QueueProcessingTest.php`
- new focused pool/transaction integration coverage where existing unit
  fixtures cannot prove concurrent reuse

### Base queue dispatcher

Add one nullable dispatcher to the base queue. It is configuration applied by
the proxy, not process-global state:

```php
/** @var null|Closure(Closure(Queue): mixed): mixed */
protected ?Closure $afterCommitDispatcher = null;

public function setAfterCommitDispatcher(?Closure $dispatcher): static
{
    $this->afterCommitDispatcher = $dispatcher;

    return $this;
}
```

Tighten `enqueueUsing()`'s payload from `?string` to `string`; every caller
already supplies a created payload and the event boundary already requires a
string. Change every queue-package callback in Beanstalk, SQS, Database, and
Redis to be static and receive the queue that owns the operation. The payload
remains prepared before transaction registration. Keep the immediate path
direct through a small protected helper so ordinary dispatch does not gain
another closure allocation:

```php
protected function enqueueNow(
    object|string $job,
    string $payload,
    ?string $queue,
    DateInterval|DateTimeInterface|int|null $delay,
    callable $callback,
): mixed {
    $this->raiseJobQueueingEvent($queue, $job, $payload, $delay);

    return tap(
        $callback($this, $payload, $queue, $delay),
        fn ($id) => $this->raiseJobQueuedEvent($queue, $id, $job, $payload, $delay),
    );
}
```

Only when `callbackApplicableTransactions()` is nonempty, register an
after-commit closure. If a dispatcher is configured, that closure sends a
static owner-parameterized operation through it; a nonpooled queue keeps the
same queue instance. With no callback-applicable transaction, invoke
`enqueueNow()` directly on `$this`. This is required for max-size-one pools:
borrowing again while the current lease is live would deadlock.

Read the configured dispatcher into a local before registering the transaction
callback and capture that local by value. The borrowed queue's dispatcher slot
is cleared when its lease returns, so a commit-time read from the queue would
both retain the borrowed object and see null.

Horizon has two additional `enqueueUsing()` callbacks. Give both the leading
owner parameter. Make the `push()` callback static because it uses only that
owner. Keep `later()` bound because `parent::laterRaw()` requires class
binding; its leading owner parameter exists because the base callback shape
supplies it, but is intentionally unused. Record those facts once beside
`later()`, including that Horizon's Redis queue is not pooled. The invariant
is that a deferred callback never captures a borrowed queue, not that every
callback is static.

Resolve `UniqueLock` and `DebounceLock` before registering rollback callbacks,
then capture those values. A transaction callback must never capture the
borrowed queue or its container.

The direct `SyncQueue::push()`, `BackgroundQueue::later()`, and
`DeferredQueue::later()` branches follow the same one-manager pattern already
used by `SqsQueue::bulk()`: resolve the transaction manager once and pass that
exact instance to both rollback helpers and `addCallback()`. Background and
Deferred inherit `SyncQueue::push()` and specialize it through
`executePayload()`, so only their `later()` methods need separate edits.
Preserve these queues' execution-time payload snapshots and synchronous event
semantics; do not route them through `enqueueUsing()`. The local prevents a
naive fix from adding repeated resolutions rather than reducing the current
path. Register each callback as a statement and then return null explicitly:
Hypervel truthfully gives `DatabaseTransactionsManager::addCallback()` a
native `void` return where Laravel has only a docblock, so Laravel's returned
one-liner is not valid here. Null is also the only predictable result for
after-commit work because an active transaction has no execution or timer
result yet; do not make the return depend on ambient transaction state.

The five hand-built after-commit test fixtures must model the same ownership:
bind a strict, expectation-free Cache mock where lock construction is
reachable, and return a nonempty collection containing a real
`DatabaseTransactionRecord` where a mocked manager represents an active
transaction. The Cache mock deliberately proves registration does not call
the repository; do not replace it with a permissive real store.

### Proxy borrow and release

Construct one dispatcher closure per worker-cached logical proxy and reuse it
on every borrow, matching the other pool proxies:

```php
/** @var Closure(Closure(Queue): mixed): mixed */
protected Closure $afterCommitDispatcher;

public function __construct(
    PoolDefinition $definition,
    Closure $resolver,
    Factory $pools,
    ?Closure $releaseCallback = null,
) {
    $this->afterCommitDispatcher = fn (Closure $callback) => $this->usingConnection($callback);

    parent::__construct(
        $definition,
        $resolver,
        $pools,
        static function (Queue $queue) use ($releaseCallback): void {
            $queue->setAfterCommitDispatcher(null);
            $releaseCallback?->__invoke($queue);
        },
    );
}
```

`usingConnection()` is a protected exception-safe helper that returns/releases
exactly one lease. Public `withConnection()` delegates to it only after
requiring `$definition->resourceType === 'sqs'`; this gives `queue:retry` the
concrete SQS capability without advertising it on Beanstalk. Keep this
driver-definition check before the borrow so an unsupported proxy does not
create a pool. Adapt the narrower public callback inside `usingConnection()`
with a local `SqsQueue` `@var` narrow; do not replace the definition gate with
a post-borrow `instanceof` check.

Rename the imported queue contract to `QueueContract`; keep
`configureBorrowed(object $object)` compatible with `PoolProxy` and narrow its
local `$object` to the abstract `Hypervel\Queue\Queue`. Type the cleanup
callback against the same abstract base. Only subclasses of that base are
poolable, and the dispatcher is not a method on the public contract. On every
borrow overwrite the logical name and assign the stored dispatcher:

```php
$queue->setConnectionName($this->connectionName);
$queue->setAfterCommitDispatcher($this->afterCommitDispatcher);
```

Preserve the existing release callback and its failure semantics. Shared
physical pools must never retain another logical connection's name or
dispatcher. Borrow-time overwrite is also what makes discard safe:
`Lease::discard()` skips release callbacks, so any discarded object's stale
state is irrelevant, and any later usable borrow always overwrites both
values. Release-time clearing is load-bearing: it breaks the idle queue's
reference through the closure to its logical proxy before returning that queue
to a shared pool, even when the existing driver release callback then fails.
Do not add explicit cycle-management machinery for the bounded proxy-owned
closure.

### Bulk invariant

`SqsQueue::bulk()` partitions after-commit and immediate jobs once. The
after-commit subset registers one callback whose fresh queue sends the whole
prepared batch. It must not call deferred `push()` per job or acquire one lease
per job. This one-lease-per-batch invariant is SQS-only. Beanstalk has no
batch API, so its per-job `bulk()` correctly performs one checkout per job.

### Standardized Beanstalk size

Laravel PR #56010 added the distinct queue metrics and changed Beanstalk
`size()` to the same total represented by the other drivers. Hypervel ported
the separate methods but retained the original ready-only implementation, so
`size()` still duplicates `pendingSize()` and `queue:monitor` can miss a large
delayed or reserved backlog.

Fetch `TubeStats` once and sum `currentJobsReady`, `currentJobsDelayed`, and
`currentJobsReserved`. Do not include buried jobs: they are Beanstalk's
dead-letter state, not active queue workload. Remove the redundant integer
casts from the three natively typed metric fields, and correct the stale
`pendingSize()` title in the Queue contract and proxy to “Get the number of
pending jobs.” No extra stats request, API, or monitor-specific path is added.

### Regressions

- no transaction and a size-one pool does not self-deadlock;
- one real transaction does not use the queue after its original lease returns;
- a sibling coroutine may borrow the same physical object before commit
  without receiving the deferred dispatch;
- commit borrows once and releases once;
- rollback sends nothing and releases resolved unique/debounce locks;
- identical pool fingerprints under two logical names use the current logical
  name;
- Beanstalk and SQS both obey the dispatcher boundary;
- Beanstalk total size uses one stats read and includes ready, delayed, and
  reserved jobs;
- Horizon's two Redis callbacks use the new signature without changing their
  nonpooled behavior;
- Sync, Background, and Deferred after-commit tests finish green, including
  their existing payload-snapshot and unique/debounce rollback coverage;
- QueueManager's borrowed pool fixtures use concrete Queue subclasses so
  dispatcher assignment and release-time clearing execute through the real
  base-class lifecycle;
- the Bus payload-context and Queue connection integration fixtures preserve
  end-to-end callback execution and active-transaction classification;
- a custom queue subclass observes the owner-first protected callback contract
  on immediate and deferred paths;
- after-commit SQS bulk uses one callback and one lease for the batch;
- existing release callbacks still run and dispatcher state is cleared even
  when they fail.

## 2. Complete SQS batching, overflow storage, credentials, and retry

### Files

- `src/queue/src/SqsQueue.php`
- `src/queue/src/Jobs/SqsJob.php`
- `src/queue/src/Connectors/SqsConnector.php`
- `src/queue/src/Console/RetryCommand.php`
- `src/foundation/config/queue.php`
- `src/boost/docs/queues.md`
- `src/support/src/Facades/Queue.php`
- `tests/Queue/QueueSqsQueueTest.php`
- `tests/Queue/QueueSqsJobTest.php`
- `tests/Queue/QueueSqsConnectorTest.php`
- `tests/Queue/RetryCommandTest.php`
- `tests/Queue/QueueConfigTest.php`

### Ordered batch publication

Port current `SendMessageBatch` behavior:

- payloads are prepared at dispatch time;
- jobs are partitioned into immediate and after-commit sets;
- queueing/queued events keep source order and returned message IDs;
- chunks contain at most ten entries and at most one MiB of effective SQS
  message bodies;
- a successful HTTP response containing a `Failed` entry becomes an
  `SqsException`;
- sending stops after the first failed chunk. Chunks remain serial; do not add
  parallel publication machinery.

Do not store overflow bodies while planning every batch. Keep each AWS entry
valid and hold overflow path/raw-payload metadata in a parallel local map
keyed by entry ID:

```php
$entries[] = [
    'Id' => (string) $id,
    'MessageBody' => $pointerBody,
    ...$options,
];

$overflow[(string) $id] = ['path' => $path, 'payload' => $payload];
```

Form chunks from `$entries`, then persist only the overflow bodies whose IDs
belong to the chunk immediately before it is sent. The private metadata can
never leak into AWS SDK request validation. These are local arrays, not a
registry or new value-object hierarchy.

For each chunk:

1. write its overflow entries, treating `put() === false` as failure;
2. if a local write fails, remove already-written entries for that unsent
   chunk and surface the write failure;
3. call `sendMessageBatch`;
4. retain pointers for successful entries;
5. remove pointers for explicit SQS rejections;
6. retain all current-chunk pointers when the request throws because delivery
   is ambiguous;
7. never create pointers for later unsent chunks.

For one-message `pushRaw()`, retain a written pointer when `sendMessage()`
throws because delivery is likewise ambiguous.

### Overflow retrieval and terminal cleanup

Use the interoperable prefix:

```php
protected const EXTENDED_PAYLOAD_CACHE_PREFIX = 'laravel:sqs-payloads:';
```

`SqsJob::getRawBody()` caches a resolved body. When a valid pointer's cache
body is missing or not a string, cache and return the original SQS pointer
body rather than throwing or returning null. The checked `Job::payload()`
boundary then rejects that valid JSON because it has no job/data shape,
retains the exact pointer bytes in `InvalidPayloadException::$value`, and lets
the post-delete `JobFailed` listener persist those same bytes. The pointer
still names the missing cache key, so no diagnostic evidence is lost.

Terminal delete ordering is load-bearing:

```text
delete from SQS
  failure -> discard lease, retain overflow, throw transport failure
success
  -> release lease
  -> attempt overflow cleanup even if lease release failed
  -> if both fail, report cleanup through PoolErrorReporter and throw release failure
  -> if only cleanup fails, throw cleanup failure
```

`release()` changes visibility and releases the lease but never deletes the
overflow body.

`clear()` flushes overflow storage only when both `enabled` and
`flush_on_clear` are true. Call the declared backing
`Store::flush(): bool` boundary through `getStore()` and throw a named
`RuntimeException` when it returns false; the already-completed SQS purge
cannot be compensated. Document that the configured cache store should be
dedicated before enabling this destructive option.

Overflow paths reuse the payload UUID only when it is a nonempty string.
Generate a fresh UUID for every other raw shape. `pushRaw()` is intentionally
unrestricted and `queue:retry` can replay external poison payloads, so array
and object UUIDs must not trigger PHP conversion failures, while booleans and
empty strings must not collapse unrelated payloads onto shared cache keys.
Do not add schema validation or a UUID value object.

### Options and credentials

Port current connector credential-provider resolution:

- callable/object providers pass through;
- named `ecs` and `instance` providers receive options and are memoized;
- static `key`/`secret` credentials retain a separate optional token;
- client configuration excludes `token` and `overflow`;
- invalid named providers fail descriptively;
- object/callable pool configs still require the documented explicit pool
  fingerprint.

Make `getQueueableOptions()` public. Filter only null values:

```php
return array_filter($options, static fn ($value) => $value !== null);
```

This preserves group/deduplication identifiers equal to `"0"`.

`queue:retry` obtains options from a direct `SqsQueue` or through the proxy's
explicit SQS `withConnection()` boundary. Do not add `__call`, expose the AWS
client, or pretend Beanstalk supports SQS options.

### Regressions

- count and byte chunk boundaries, ordering, and first-failure stop;
- one after-commit batch callback/checkout;
- queueing and queued events for successful entries only;
- HTTP-200 entry rejection with correct exception context;
- overflow disabled, threshold, always mode, and pointer prefix;
- nonempty string UUID reuse and generated paths for array, object, boolean,
  and empty-string UUIDs;
- `put() === false`, thrown writes, partial local writes, missing cache body,
  explicit rejected entries, ambiguous transport errors, and later unsent
  chunks;
- missing/nonstring overflow bodies terminally persist the original SQS pointer
  after message deletion;
- SQS delete/discard/release/cache-cleanup precedence, including two failures;
- released jobs retain overflow state;
- clear opt-in, false-flush failure, and dedicated-store warning;
- `"0"` FIFO group and deduplication IDs;
- direct and proxied FIFO retry;
- a real pooled SQS definition permits FIFO retry while a real pooled
  Beanstalk definition rejects it before borrowing;
- static, token, callable, ECS, instance, invalid, and memoized credentials.

## 3. Make external payload consumption durable

### Files

- `src/queue/src/InvalidPayloadException.php`
- `src/queue/src/Jobs/Job.php`
- `src/queue/src/Jobs/RedisJob.php`
- `src/queue/src/LuaScripts.php`
- `src/queue/src/RedisQueue.php`
- `src/queue/src/Worker.php`
- `src/queue/src/Events/JobReleasedAfterException.php`
- `src/queue/src/Failed/FileFailedJobProvider.php`
- `src/queue/src/Failed/DatabaseUuidFailedJobProvider.php`
- `src/queue/src/Console/RetryCommand.php`
- `src/queue/src/Console/WorkCommand.php`
- `src/horizon/src/JobPayload.php`
- `src/horizon/src/RedisQueue.php`
- `src/horizon/src/Events/JobsMigrated.php`
- inspected unchanged `src/horizon/src/Events/RedisEvent.php`
- `src/horizon/src/Listeners/ForgetJobTimer.php`
- `src/horizon/src/Listeners/MarshalFailedEvent.php`
- `src/telescope/src/Watchers/JobWatcher.php`
- `tests/Integration/Horizon/Feature/QueueProcessingTest.php`
- focused Queue, Horizon, Telescope, Sentry, and Log Context tests

### One checked decode

Correct the exception before using it anywhere:

```php
public function __construct(?string $message = null, mixed $value = null)
{
    parent::__construct($message ?? 'Unable to decode the queue job payload.');
    $this->value = $value;
}
```

Keep current Laravel's encode-failure job/queue context when constructing
payloads.

`Job::payload()` owns separate nullable fields for the validated array and
cached exception, then returns or rethrows without decoding again:

```php
protected ?array $decodedPayload = null;
protected ?InvalidPayloadException $payloadException = null;

if ($this->decodedPayload !== null) {
    return $this->decodedPayload;
}

if ($this->payloadException !== null) {
    throw $this->payloadException;
}

$rawBody = null;

try {
    $rawBody = $this->getRawBody();
    $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)
        || ! isset($payload['job'])
        || ! is_string($payload['job'])
        || $payload['job'] === ''
        || ! array_key_exists('data', $payload)) {
        throw new InvalidPayloadException(
            'The queue job payload does not contain a valid job and data.',
            $rawBody,
        );
    }

    return $this->decodedPayload = $payload;
} catch (InvalidPayloadException $e) {
    throw $this->payloadException = $e;
} catch (JsonException $e) {
    throw $this->payloadException = new InvalidPayloadException(
        'Unable to decode the queue job payload: ' . $e->getMessage(),
        $rawBody,
    );
}
```

The qualified cache name is deliberate. Laravel's base `Job` has no payload
property, while concrete jobs legitimately use `$payload` for their raw or
fake-owned storage. The Hypervel-only decoded cache must not claim that
extension-point name.

Keep `getRawBody()` inside the checked region so a concrete driver's named
payload exception is cached unchanged. Cache the same
`InvalidPayloadException` instance as well as valid payloads. Queue-package
`pushRaw()` remains intentionally unrestricted and validates at consumption.
Horizon's Redis queue must decode raw payloads before publication because it
decorates them; its existing publication-time restriction now fails with
`InvalidPayloadException` rather than typed-property fallout.

### Worker poison path

Validate before `JobProcessing`, attempt-limit reads, and timeout
registration. On `InvalidPayloadException`:

1. dispatch `JobExceptionOccurred`;
2. mark the job failed and delete it;
3. skip only payload-dependent batch/timeout enrichment and the unavailable
   user `failed()` hook;
4. dispatch `JobFailed`;
5. dispatch `JobAttempted`;
6. report the exception once;
7. never calculate backoff or release the job.

Ordinary payloads keep current exception behavior. Do not catch container,
database, event, or user-hook failures under a broad guard.

`Job::fail()` lets the initial payload read populate its existing
`$payloadException` cache. After deletion, it invokes the user `failed()` hook
only when that cache is null. This skips the hook for an invalid stored payload
without swallowing an `InvalidPayloadException` independently thrown by a user
hook for a valid payload. Database rollback, delete, and `JobFailed` dispatch
remain mandatory.

When a timed-out Batchable job fails, detect the trait through the exact
`class_uses_recursive()` key and keep the batch repository rollback
best-effort without capturing its exception. A secondary batch rollback
failure must not replace the original timeout: failed-job database transaction
cleanup and `JobFailed` both still receive that timeout.

The existing failed-job provider is the terminal record. Do not add a poison
registry or a second dead-letter mechanism.

### Redis reservation

Protect decode and attempts mutation inside the existing Lua call:

```lua
local job = redis.call('lpop', KEYS[1])
local reserved = false
local attempts = false

if job ~= false then
    local decodeSucceeded, payload = pcall(cjson.decode, job)

    if decodeSucceeded and type(payload) == 'table' then
        local currentAttempts = tonumber(payload['attempts'])

        if currentAttempts ~= nil then
            attempts = currentAttempts + 1
            payload['attempts'] = attempts
            reserved = cjson.encode(payload)
        end
    end

    if reserved == false then
        reserved = job
    end

    redis.call('zadd', KEYS[2], ARGV[1], reserved)
    redis.call('lpop', KEYS[3])
end

return {job, reserved, attempts}
```

The final Lua requires a decoded table whose `attempts` value `tonumber()` can
coerce. This preserves the current support for numeric strings while JSON
arrays, malformed JSON, scalars, missing attempts, and genuinely nonnumeric
attempts retain their original bytes and return `false` as the attempt
sentinel. Do not normalize missing/nonnumeric values to zero: a JSON array is
also a Lua table, so that shortcut would mutate exact external bytes into a
different object-shaped payload. Continue draining the notify list for each
reserved job. Redis returns Lua numbers as integers, truncating fractional
values, so the strict `?int` boundary below cannot receive a float even when a
foreign payload contains a fractional attempts value.

Append a required `?int $attempts` parameter to `RedisJob::__construct()`.
`RedisQueue` maps the strict-false Lua sentinel to null. The constructor stores
that value without decoding. `RedisJob::payload()` first uses the shared
checked/cached decoder, then rejects a null attempt with the same inherited
exception cache:

```php
public function payload(): array
{
    $payload = parent::payload();

    if ($this->attempts === null) {
        throw $this->payloadException ??= new InvalidPayloadException(
            'The Redis queue job payload does not contain a valid attempts count.',
            $this->job,
        );
    }

    return $payload;
}
```

Malformed JSON/scalars/arrays therefore keep their more specific shared
diagnostics. The Redis-specific exception is load-bearing only for an
otherwise-valid job/data object with uncountable attempts, preventing it from
reporting attempt one forever. This reuses the job's existing cached-invalid
state rather than adding another terminal mechanism. `attempts()` returns the
received count or one for poison-event diagnostics.

Removing the eager decoded array also removes `getJobId()`'s old source. Keep
the accessor lazy and non-throwing without decoding twice: read the shared
parent payload, bypassing only the Redis attempts guard, return a string ID,
and return null when the shared decoder rejects the raw payload:

```php
public function getJobId(): ?string
{
    try {
        $id = parent::payload()['id'] ?? null;
    } catch (InvalidPayloadException) {
        return null;
    }

    return is_string($id) ? $id : null;
}
```

This preserves an available ID for an otherwise-valid payload whose attempts
marker is invalid, while malformed JSON and invalid job/data shapes remain
unidentified. `Horizon\Listeners\ForgetJobTimer` must skip a null ID because
the Queue job contract permits null and `Stopwatch::forget()` requires a
string. Do not widen the stopwatch or contain unrelated listener failures.

Redis and phpredis return each empty tuple element as PHP `false`, not `null`:
`pop()` checks `$reserved !== false`, and the blocking branch checks
`$job === false`, so raw `"0"` remains a real job. The existing
`empty($nextJob)` branch guards a failed/empty eval result and returns
`[false, false, false]` for the new three-value destructure. The reserved raw
member remains the exact value used by `zrem` and release.

### Failed records, retry, and output

File and database-UUID failers use the payload UUID when it is a nonempty
string; otherwise generate a UUID while preserving the exact raw payload.

`queue:retry` decodes with `JSON_THROW_ON_ERROR`, preserves arbitrary fields,
and only deletes the failed record after a successful re-enqueue. It never
pushes `"null"` or destroys the only evidence for malformed JSON.

`WorkCommand` stores the failed record before resolving/formatting payload
display output. Both CLI and JSON formatting catch only
`InvalidPayloadException` from payload-derived display fields and emit a
fallback containing the connection, queue, usable job ID, and exception
message. All unrelated formatting failures still propagate. Normalize the
nullable/int-or-string job ID and resolve the display name once at the start
of CLI formatting, inside the single invalid-payload containment boundary.
Use those locals for interpolation and both width calculations, so verbose
output never passes null to `mb_strlen()` and neither accessor is re-read.

For CLI output, use the fallback as the displayed job name and preserve the
existing status, duration, and styling. For JSON output, keep the existing
schema, use an explicit invalid-payload job label, and preserve the named
exception and message. A Redis payload with a valid ID but invalid attempts
keeps that ID in the diagnostic while `uuid` remains unavailable and is
omitted by the existing optional-field filtering, even if the raw payload
contains one: `getJobId()` deliberately bypasses only the attempts guard,
whereas the standard `uuid()` accessor honors full payload validity. Test and
retain that asymmetry instead of adding a Redis-specific UUID reader. When the
shared decoder cannot validate the payload, both identifiers are unavailable.
Do not introduce a second payload parser, output DTO, or generic safe-access
layer.

### Horizon and Telescope

`Horizon\JobPayload` translates invalid JSON/shape into Queue's
`InvalidPayloadException`. It requires a decoded array and a string identifier
selected by `uuid` then `id`, matching what `id(): string` unconditionally
reads. Do not add a recursive schema system for optional telemetry fields.
After the queue action succeeds, contain only that exception when Horizon
telemetry cannot be constructed:

- `RedisQueue` skips malformed reserved, deleted, and released telemetry after
  the corresponding queue action;
- `JobsMigrated` retains valid payloads in mixed batches;
- `MarshalFailedEvent` builds its Horizon event before dispatch;
- ordinary listener exceptions still propagate.

`Telescope\JobWatcher::recordFailedJob()` returns on the same exception before
its second payload read. Log Context and Sentry need no new catch because
validation now precedes `JobProcessing`.

### Regressions

- valid payload decoded once across all accessors;
- malformed JSON, scalar JSON, missing/empty/nonstring `job`, and missing
  `data`;
- exact cached exception identity;
- poison event order, one report when enabled, no report when disabled,
  terminal delete, no user hook, and no release;
- an `InvalidPayloadException` thrown by a valid-payload user `failed()` hook
  propagates while `JobFailed` still dispatches;
- a throwing Batchable repository rollback cannot replace the original
  timeout, prevent failed-job database rollback to level zero, or change the
  `JobFailed` exception;
- ordinary user hook/listener/container/database failures still surface;
- Redis empty Lua tuples remain three PHP `false` values and preserve blocking
  notify-list behavior;
- Redis numeric-string attempts remain incrementing, while missing/nonnumeric
  attempts and every invalid raw shape terminate with exact evidence;
- Redis fractional attempts arrive at the strict PHP boundary as an integer;
- Redis raw bytes and attempts for every invalid shape, including `"0"`;
- Redis valid attempts retain current behavior;
- Redis job IDs are lazy, preserve a usable ID through an invalid-attempts
  failure, and return null without throwing for an undecodable payload;
- fallback failed-job IDs preserve raw payload;
- retry leaves malformed evidence and preserves valid arbitrary fields/FIFO
  options;
- WorkCommand logs before display, emits useful CLI/JSON poison diagnostics,
  resolves CLI names/IDs once, handles verbose null IDs, preserves a usable
  Redis ID while omitting UUID for invalid attempts, and still propagates
  unrelated formatter failures;
- Horizon's timer listener ignores the contractually nullable job ID without
  hiding other listener failures;
- Horizon reserved/deleted/released/failed invalid-payload containment;
- Horizon rejects nonarray payloads and missing/nonstring `uuid`/`id` through
  the named Queue exception;
- Horizon mixed migrations retain valid entries;
- Telescope containment and unchanged Sentry/Log Context valid behavior.

## 4. Complete worker lifecycle and operational APIs

### Files

- `src/queue/src/Worker.php`
- `src/queue/src/WorkerOptions.php`
- `src/queue/src/ListenerOptions.php`
- `src/queue/src/WorkerStopReason.php`
- `src/queue/src/QueueManager.php`
- `src/queue/src/Events/Looping.php`
- `src/queue/src/Events/WorkerStopping.php`
- `src/queue/src/Events/JobReleasedAfterException.php`
- new `src/queue/src/Events/WorkerIdle.php`
- Queue console commands and tests

### Timeouts and static controls

Represent a non-expiring running job truthfully:

```php
'expires_at' => ($timeout = $this->timeoutForJob($job, $options)) > 0
    ? $startAt + $timeout
    : null,
```

The monitor skips null expiry. Timeout termination uses
`static::$timeoutExceededExitCode ?? static::EXIT_ERROR` and
`WorkerStopReason::TimedOut`.

Port boot-only:

```php
public static bool $reportJobExceptions = true;
public static bool $stopOnLostConnection = true;
```

Gate reporting and lost-connection shutdown independently, including the
contained poison-payload path. Store a daemon-local lost-connection stop
reason. Extend `Worker::flushState()` so tests restore both flags and every
existing Queue static. The existing `AfterEachTestSubscriber` already calls
`Worker::flushState()`, so no Testing package hook is added.

Do not port Laravel's `$currentJob` singleton or `$resetScope`; concurrent job
coroutines would race on both.

### Idle, quiet stop, and metadata

Add `WorkerIdle(connectionName, queue, workerOptions)` when a poll returns no
job. Add `stopWhenEmptyFor` to `WorkerOptions` at Laravel's position after
`rest`; Hypervel's `concurrency`, `monitorInterval`, and `coroutineContext`
follow it.

`WorkCommand` constructs `WorkerOptions` with named arguments. Its
`--concurrency` option defaults to null so an explicit `1` overrides config.
Convert `ListenerOptions`' parent construction to named arguments too; it is
the only other positional `WorkerOptions` construction.

Track:

- admitted jobs for `maxJobs`;
- completed jobs for `WorkerStopping::$jobsProcessed`;
- monotonic last completion for quiet-period and event metadata;
- current memory at stop.

`stopWhenEmptyFor` starts from the worker start time until the first
completion, then from the last completion, and stops only when no job was
popped and no coroutine remains in flight.

Use one protected `currentTime()` returning `hrtime(true) / 1e9` for worker
start, last completion, quiet-period and max-time checks, and timeout
registration/monitoring. Delete the duplicate `$startedAt` property and the
unread `start_at` entry from running-job state. Absolute `retryUntil` and
cross-process restart timestamps remain wall-clock values. Keep
`WorkerStopping::$lastJobProcessedAt` typed `float|int|null` for Laravel
parity; the private cached completion value is `?float`.

Add `WorkerOptions` to `Looping`; add completed count, last completion time,
and memory to `WorkerStopping`; add the causing exception to
`JobReleasedAfterException`.

### Pause and in-flight handling

Pass the real last-restart timestamp, worker start time, and admission count
through `pauseWorker()` as required parameters. Its sole caller owns and
supplies all three values; defaults can only hide missing lifecycle state, and
a zero start time causes paused workers with `--max-time` to exit immediately.
Apply the same rule to `stopIfNecessary()`: its last-restart timestamp, worker
start time, and admitted-job count are required lifecycle inputs at every
call. Do not retain zero defaults that can silently manufacture invalid
worker state.

Centralize the existing in-flight drain loop in one private/protected helper
used by every stop path. It adds no new timeout because those coroutines are
internally owned.

A job run with `--timeout=0` deliberately has no expiry. If it blocks on an
external peer, a stop path can therefore wait indefinitely for it. Record and
test the no-expiry contract; do not undermine the explicit zero timeout with
an arbitrary drain deadline.

`QueueManager::getPausedQueues()` uses one cache `many()` call and returns
normalized queue names. Worker pop order remains short-circuiting after the
batched read. `queue:pause` fails clearly when `Worker::$pausable` is false.

### Commands and output

- cast maintenance/once `--sleep` before `Worker::sleep()`;
- add `--stop-when-empty-for`;
- preserve integer/string zero values in worker JSON by filtering only null or
  empty optional fields deliberately, not with bare `array_filter()`;
- include connection, queue, and memory in verbose worker output;
- port JSON output for `queue:failed` and `queue:monitor`;
- port current display-name and two-column monitor formatting while retaining
  Hypervel's additional metrics;
- use strict restart timestamp comparison;
- retain command coroutine context, start-time context, and TTY handling.

### Regressions

- timeout zero remains visible and never expires;
- positive timeout uses the configured/default error exit and TimedOut reason;
- report and lost-connection flags vary independently and reset between tests;
- no singleton job state exists;
- WorkerIdle data and dispatch timing;
- quiet-period start, reset, in-flight guard, and QueueEmptyFor reason;
- timeout and quiet-period deadlines share the deterministic monotonic clock;
- admitted and completed counts remain distinct under concurrency;
- Looping/WorkerStopping/JobReleasedAfterException metadata;
- paused `--max-time` uses real elapsed time;
- one/many queue pause reads and priority short-circuit;
- all stop paths drain in-flight work;
- exact `--concurrency=1`, zero-valued JSON, and verbose fields;
- restart timestamps compare strictly.

## 5. Complete middleware, attributes, handlers, and failed providers

### Files

- `src/queue/src/CallQueuedHandler.php`
- `src/queue/src/Attributes/Connection.php`
- `src/queue/src/Attributes/Queue.php`
- `src/queue/src/InteractsWithQueue.php`
- `src/queue/src/Middleware/RateLimited.php`
- `src/queue/src/Middleware/RateLimitedWithRedis.php`
- `src/queue/src/Middleware/ThrottlesExceptions.php`
- `src/queue/src/Middleware/ThrottlesExceptionsWithRedis.php`
- `src/queue/src/Middleware/WithoutOverlapping.php`
- new `src/queue/src/Middleware/Release.php`
- `src/queue/src/Failed/DatabaseFailedJobProvider.php`
- `src/queue/src/Failed/DatabaseUuidFailedJobProvider.php`
- `src/queue/src/Failed/FileFailedJobProvider.php`
- `src/queue/src/QueueServiceProvider.php`
- corresponding Queue and Integration tests

### Exact job and identifier semantics

`CallQueuedHandler` passes its actual framework `Job` to unique-until-processing
cleanup. Do not require the user command to expose `$job`.

Queue/Connection attributes and supported middleware accept
`UnitEnum|string`, normalize once with `enum_value()`, and cast to string. This
preserves integer-backed enum zero.

Both rate-limiter variants distinguish an explicit zero delay from null:

```php
$delay = $this->releaseAfter ?? $this->getTimeUntilNextRetry($key);
```

Include `releaseAfter` in `RateLimited::__sleep()`. Apply enum normalization to
`RateLimitedWithRedis` and `WithoutOverlapping`; treat custom key `"0"` as
present.

Both exception-throttling variants accept `Closure|int` backoff and resolve it
when handling the exception. Port `Release::when()` / `unless()` exactly from
current Laravel.

`InteractsWithQueue::release()` and `assertReleased()` convert
`DateInterval|DateTimeInterface|int` through the same existing time helper.

### Failed providers

Use current database-provider constructor order:

```php
new DatabaseFailedJobProvider($resolver, $database, $table);
```

Expose `getTable()` and filter connection/queue on exact non-null values, so
`"0"` remains valid.

Failed-provider selection is explicit:

```php
return match ($config['driver']) {
    'database' => ...,
    'database-uuids' => ...,
    'file' => ...,
    'null' => new NullFailedJobProvider,
    default => throw new InvalidArgumentException(...),
};
```

Keep `queue.failed` and `queue.batching` as replace-whole settings blocks;
only `queue.connections` is merged by name. File `path` and `limit` are
optional, so omit them from shipped config and let the provider own their
single Laravel-compatible defaults:

```php
$config['path'] ?? $app->storagePath('framework/cache/failed-jobs.json')
$config['limit'] ?? 100
```

Keep the existing public documentation that shows how applications may
configure those optional keys. Use container `make()` rather than array
access.

Keep `FileFailedJobProvider`'s constructor. Inside its existing lock:

- checked `file_get_contents()` rejects false;
- JSON encoding uses `JSON_THROW_ON_ERROR`;
- write to a unique temporary file in the destination directory;
- require the full byte count;
- derive the replacement mode from the current file when present, otherwise
  from `0666 & ~umask()`; mask `fileperms()` to `0777`;
- apply that mode to the complete temporary file before publication, without
  turning unsupported permission metadata into a data-write failure;
- rename over the destination atomically;
- remove an unpublished temporary file in `finally`;
- preserve the primary read/write/rename failure.

No retries, injected filesystem, new storage abstraction, or public hook.
Do not port the unrelated DynamoDB failed-job provider.

### Regressions

- actual job used for unique cleanup, including commands without
  `InteractsWithQueue`;
- int-backed enums and exact zero across attributes/middleware;
- serialized rate-limiter zero delay;
- callable throttling backoff in both stores;
- Release when/unless;
- DateInterval release/assertion;
- constructor order, table accessor, and `"0"` filters;
- every failed driver and missing/unsupported driver;
- named Queue connections merge by name while `failed` and `batching` replace
  wholesale;
- a driver-only file configuration receives the provider-owned default path
  and limit, with no duplicate defaults in shipped config;
- false reads, short/false writes, rename failure, temp cleanup, atomic reader
  visibility, preserved existing/fresh-file modes, and preserved primary file
  failure.

## 6. Port concrete inspection and complete QueueFake

### Files

- new `src/queue/src/Jobs/InspectedJob.php`
- concrete Queue implementations and `QueuePoolProxy`
- `src/redis/src/Operations/SafeScan.php`
- `src/redis/src/Operations/FlushByPattern.php`
- `src/redis/src/RedisConnection.php`
- `src/redis/src/RedisProxy.php`
- `src/support/src/Testing/Fakes/QueueFake.php`
- `src/support/src/Facades/Queue.php`
- `src/boost/docs/redis.md`
- relevant Queue, Support, facade-documenter, Redis, and database tests
- `tests/Redis/Operations/SafeScanTest.php`
- `tests/Redis/Operations/FlushByPatternTest.php`

### Concrete inspection

Port these eager `Collection` methods to concrete implementations, proxy,
fake, and facade metadata only:

```php
pendingJobs($queue = null)
delayedJobs($queue = null)
reservedJobs($queue = null)
allPendingJobs()
allDelayedJobs()
allReservedJobs()
```

Do not add them to `Hypervel\Contracts\Queue\Queue` and do not create a second
capability contract without a typed consumer. Noninspectable drivers return an
empty collection; Failover delegates. Those six delegations intentionally call
optional concrete capabilities through the core Queue contract, so keep one
WHY comment and six identifier-scoped `method.notFound` ignores rather than
distorting the contract or adding runtime capability machinery.

`InspectedJob::fromPayload()` uses the checked Queue exception. Its optional
trailing database identifier is additive:

```php
public static function fromPayload(
    string $payload,
    ?int $attempts = null,
    ?string $queue = null,
    int|string|null $id = null,
): static
```

On invalid payload, the exception always identifies the queue, includes the
database record ID when available, and retains the exact raw payload in
`$value`. Do not invent Redis indexes: Redis removal is value-addressed, so
that raw payload is already its operational removal member.

Database queries pass record queue, attempts, and ID. Redis per-queue methods
use `lrange`/`zRange`; all-queue methods fully consume
`safeScan('queues:*')` inside one
`RedisProxy::withConnection(..., transform: false)` lease, normalize only
primary queue names, and never use `KEYS` or return a generator past the lease.
Only a clustered connection unwraps an outer storage hash tag; a literal
hash-tagged queue name on standalone Redis must remain intact so the scanned
name resolves back to the same key.
Use raw mode for both per-queue and all-queue inspection so
`inspectJobsUsing()` has one explicit connection precondition.

`SafeScan` requires raw phpredis scan results: transformed results have a
different tuple shape and can also make `FlushByPattern` silently delete
nothing. Reject transformed connections in `SafeScan` before the first scan
or deletion attempt with `InvalidRedisConnectionException`. Do not add
transformed-shape compatibility or another scan implementation. Correct the
usage examples and method docblocks on `SafeScan`, `FlushByPattern`,
`RedisConnection`, and `RedisProxy`, and teach the public Redis guide to hold a
raw connection for either operation. `withPinnedConnection()` needs no new
parameter because a nested raw `withConnection()` reuses the pinned lease.

Preserve upstream eager behavior. Do not add limits, pagination, chunks, lazy
results, partial-error results, or configuration.

### Redis command metadata and held transactions

PhpRedis method names are case-insensitive, but `RedisConnection` declares 22
lower/camel-case pairs with conflicting signatures. Reconcile both spellings
to the same truthful signature:

- when a `call*` transformer exists, use the parameters accepted by both the
  transformed and raw paths and the union of their possible returns;
- when no transformer exists, use phpredis's native parameters and returns
  verbatim because both modes call the same native method;
- keep `flushdb`/`flushDB` at the concrete transform-family boundary; and
- rename reverse score-range parameters to `$max, $min` in the preparer,
  transformer, and both annotations without reordering arguments.

`setnx` / `setNx` and `hsetnx` / `hSetNx` accept `mixed` values in phpredis
and in Laravel's public surface. Widen only those value parameters in the
transformers and both case-insensitive annotations; keys and fields remain
strings. Regenerate the Redis facade from the corrected underlying metadata.

Do not add runtime wrappers or a global PHPStan rule.

`RedisConnection` inherits `discard(): void` from the pool connection, so its
magic Redis-command annotation is false. Remove that annotation. Declare
`@method bool|\Redis discard()` on `RedisProxy`, whose magic dispatch routes to
`discardTransaction()`, and make the existing `discardTransaction()` the
documented API for aborting a transaction on a held connection. Its native
command and watch-state cleanup stay unchanged. Update the RESET diagnostic
and Redis guide to distinguish facade-managed operations from methods invoked
on the same held connection; `discard()`, `unwatch()`, or `exec()` on another
surface can borrow or destroy the wrong lease.

Generate `hasHashTag()` onto the Redis facade. Exclude Macroable's internal
`macroCall()` alias, and correct the facade-ignore comment to cover
connection-bound methods, commands unavailable through the pooled facade, and
internal trait aliases. Regenerate and lint the facade from these underlying
declarations rather than editing generated method lines by hand.

### QueueFake

Record pending and delayed pushes once in `$jobs`, with accurate creation
timestamps and a nullable `delay` field. Do not copy upstream's separate
`$delayed` array: its `later()` writes the same job into both arrays, making
delayed jobs appear pending and causing size metrics to double-count them.
Delayed jobs remain in the push history so existing `assertPushed*()`,
`pushedJobs()`, and `hasPushed()` behavior stays intact.

Use one protected operation helper for `push()` and `later()`:

```php
protected function enqueueUsing(
    object|string $job,
    mixed $data,
    ?string $queue,
    DateInterval|DateTimeInterface|int|null $delay,
): mixed
```

Before-pushing callbacks run before fake-state publication or real dispatch;
after-pushing callbacks run only after that operation succeeds. The helper
records a faked job once with its delay, or resolves the selected real queue
through the declared Factory `connection()` method and calls `push()` when the
delay is null or `later()` otherwise. This removes the existing dynamic-call
PHPStan ignore rather than adding another.

`laterOn()` delegates to `later()`. `bulk()` passes each job's delay to the
same helper when `isset($job->delay)`, matching every shipped persistent
driver. A zero delay remains a delayed fake record because the fake records
the operation the caller selected; it does not try to emulate the
backend-dependent time at which a zero-delay job becomes pending.

Pending inspection filters `delay === null`; delayed inspection filters
`delay !== null`; total size is the disjoint pending, delayed, and explicitly
reserved count. Oldest-pending time derives only from pending records. Add
`reserve()` and `clearReserved()` and describe `reserve()` as recording a
reserved job, not mutating a pushed record.

Add ordered instance-owned `beforePushing()` and `afterPushing()` callbacks
around both faked and pass-through operations. They are ordinary fake state;
do not add a static registry or testing lifecycle hook.

### Regressions

- every concrete driver, proxy, Failover, fake, and facade metadata method;
- database status classification, queue, attempts, ID, and invalid diagnostic;
- Redis pending/delayed/reserved and all-queue safeScan exhaustion inside one
  raw lease against real Redis;
- all-queue inspection preserves literal hash-tagged names on standalone Redis
  while normalizing cluster storage tags;
- exact raw Redis removal value for invalid entries;
- transformed SafeScan connections fail before scanning, and
  `FlushByPattern` attempts no deletion;
- all 22 case-insensitive Redis command pairs expose one truthful signature;
- `setnx` / `hsetnx` accept non-string values in transformed mode and both
  casing annotations remain identical;
- reverse score ranges keep native `$max, $min` order in both modes;
- facade `discard()` returns the native command result while a held
  `discardTransaction()` aborts MULTI, supports an ordinary command afterward,
  and returns the wrapper to the pool cleanly;
- RESET diagnostics keep `discard`, `unwatch`, and `exec` on the surface that
  owns the state, and Redis facade metadata regenerates and lints cleanly;
- noninspectable empty results;
- fake pending/delayed/reserved timestamps, reserve/clear, and sizes;
- one immediate, delayed, and reserved fake produces disjoint `1/1/1`
  metrics and total `3`;
- delayed-only and explicit-zero-delay records stay pushed but are not
  pending or eligible for oldest-pending time;
- partial-fake delayed dispatch preserves delay through the default manager,
  a job-specific connection, and `laterOn()`;
- bulk classifies plain and delayed jobs separately;
- before/after order for faked and pass-through jobs and callback exceptions.

## 7. Correct contracts, metadata, binaries, docs, and records

### Cross-package contracts

In `src/contracts/src/Broadcasting/Broadcaster.php`, remove only
`getChannels()`. Keep public methods on the concrete broadcaster,
`BroadcastPoolProxy`, and facade. `ChannelListCommand` keeps Laravel's dynamic
call with one scoped `method.notFound` PHPStan ignore explaining that channel
listing is intentionally a concrete capability; add no capability contract
for one command.

In `src/contracts/src/Notifications/Factory.php`:

```php
public function send(mixed $notifiables, mixed $notification): void;
public function sendNow(mixed $notifiables, mixed $notification): void;
```

Do not add channels to Factory; only Dispatcher owns:

```php
sendNow(mixed $notifiables, mixed $notification, ?array $channels = null)
```

This matches current implementations and removes the false native
`Hypervel\Support\Collection` dependency without adding a Contracts →
Collections cycle.

### Queue package and listener

Declare `hypervel/context` and `hypervel/filesystem` directly in
`src/queue/composer.json`. Filesystem is independently required by all three
table commands' `join_paths()` calls.

Use shared `php_binary()` and `artisan_binary()` helpers in `Listener`. Do not
remove inherited fields merely because this subclass does not read them.

Run split-manifest verification for Queue and every changed package.

### Public documentation

Update `src/boost/docs/queues.md` in its existing Laravel-style, task-first
voice:

- SQS overflow configuration, retention, and dedicated-store clear warning;
- Release middleware;
- WorkerIdle;
- `--stop-when-empty-for`;
- inspection methods;
- JSON failed/monitor output;
- file failed-job `path` and `limit`;
- callable exception-throttle backoff;
- malformed payload terminal behavior, while noting that Horizon must decode a
  raw Redis payload before it can decorate and publish it.

The inspection section gets one operational sentence, not architecture prose:
the methods load every matching job into memory, so applications should avoid
using them against very large backlogs in latency-sensitive code.

Update `src/boost/docs/redis.md` so direct `safeScan()` and
`flushByPattern()` calls on an already-held connection use
`withConnection(..., transform: false)`. Keep the facade examples unchanged;
the proxy already acquires raw connections internally.

Update config comments, facade metadata, documenter snapshots, and
`src/queue/README.md` provenance. Its `Differences From Laravel` section must
record that the protected `enqueueUsing()` callback receives the operation's
queue owner first so deferred pooled work cannot retain a borrowed queue. Do
not copy unrelated Laravel DynamoDB documentation.

### Audit records

After implementation evidence is green:

- add final Queue findings and important rejections to the audit ledger;
- check Queue in the routing plan;
- add dependency-index rows for every completed package changed here;
- amend affected completed-package ledger entries;
- record `redis-21` and revalidate the completed Redis package;
- record the eager inspection characteristic: while a call is live, its
  materialized result competes with unrelated coroutines in the same Swoole
  worker heap; do not call this a Laravel defect or claim FPM creates one
  process per request;
- record that Redis's invalid raw value is its real removal member;
- record one after-commit callback/lease per SQS batch;
- record that `--timeout=0` deliberately leaves both job execution and
  stop-path draining unbounded;
- delete `.tmp/queue-audit.md` after final records are complete.

## Validation plan

### Immediate cadence

Run each changed test file immediately after its source slice. Extend existing
files when they own the behavior; add a file only for a new class or coherent
integration boundary. Use existing Redis/database isolation traits and
channels/handshakes rather than timing sleeps. Port the complete relevant
upstream tests before adding Hypervel ownership and upstream-defect
regressions. Use focused commands for:

- pooled after-commit Queue tests;
- SQS queue/job/connector/retry tests;
- payload/worker/Redis tests;
- Horizon/Telescope/Sentry/Log Context queue tests;
- middleware, failed-provider, command, inspection, and QueueFake tests;
- Broadcasting and Notifications contracts/consumers;
- split-package manifests and facade-documenter snapshots.

Use the local Redis service and existing Queue integration harnesses. Run
database-backed transaction tests against the repository's configured SQLite,
MySQL/MariaDB, and PostgreSQL paths where the test family supports them.

### Required regression matrix

1. lease ownership: immediate, commit, rollback, shared pools, concurrency,
   cleanup failure;
2. SQS: batching, overflow, credentials, FIFO zero, retry, partial/ambiguous
   failures, terminal precedence;
3. payloads: every invalid shape, exact bytes, Redis atomicity, terminal event
   order, failed evidence;
4. worker: timeout, flags, lost connection, idle/quiet stop, pause, in-flight,
   metrics, command exact values;
5. APIs: middleware, enums, DateInterval, providers, inspection, fake hooks,
   contract consumers;
6. docs/metadata: config ownership, split manifests, facade/documenter output,
   audit routing.

### Full gate

After focused tests are green, run exactly:

```bash
composer fix
```

This is the authoritative sequence for formatting, PHPStan, and the full
parallel suite. Do not redundantly run its component-wide fixer or PHPStan
steps immediately beforehand. Inspect every failure and skipped-test change;
do not weaken assertions or classify a failure as unrelated without tracing
it.

## Fresh post-implementation self-review

Review the final diff without trusting this plan or the prior discussion:

- trace every changed caller and callee;
- verify each queue/lease/payload/transaction owner and cleanup transition;
- exercise SQS known rejection versus ambiguous transport failure;
- verify every payload read now sees the cached valid array or exact
  exception;
- verify malformed jobs cannot be lost, retried forever, or delete their only
  failed record;
- trace event ordering and ensure only invalid-payload telemetry is contained;
- inspect worker state under multiple concurrent jobs;
- compare every ported method and test to current Laravel default-branch
  source, preserving approved Hypervel differences;
- inspect every new allocation, lookup, lock, serialization, I/O, yield,
  retry, report, and retained value;
- search for superseded helpers, fallbacks, comments, ignores, TODOs,
  configuration defaults, facade metadata, and docs;
- confirm no generic proxy API, inspection machinery, registry, or new
  abstraction slipped in;
- run `git diff --check`, focused tests affected by review fixes, and
  `composer fix` again if executable code changed.

Then request a complete code review and continue until signoff.

## Expected performance and complexity result

- Ordinary dispatch remains one pool checkout and does not allocate a
  dispatcher closure.
- Real after-commit work adds one necessary checkout at commit and retains no
  lease during the transaction.
- After-commit SQS bulk uses one checkout per batch, not per job.
- Beanstalk bulk remains one checkout per job because it has no batch API.
- SQS batching reduces network calls from one per job to bounded batches.
- Valid payloads decode once instead of repeatedly.
- Redis invalid-shape handling stays in the existing Lua round trip.
- Pause polling uses one `many()` call: this wins for empty/multi-queue scans,
  while a busy first-priority queue may transfer more cached values; pop still
  short-circuits.
- Middleware exactness adds only null comparisons on existing branches.
- Inspection intentionally materializes upstream's eager collections and is
  documented as a cold/operational API; no hot path is changed.
- Redis queue discovery uses the existing incremental SafeScan rather than
  blocking `KEYS`; its one raw-mode guard runs only when a scan operation is
  constructed.
- QueueFake, command formatting, metadata, and documentation add no production
  request cost.
- Beanstalk total size still performs one stats request; QueueFake's single
  history removes the duplicate delayed-job record from test memory.
- The only retained production state added is one dispatcher closure per
  worker-cached logical proxy, one nullable reference on a borrowed queue, and
  bounded worker counters already required by the public lifecycle APIs.

The completed code should read as one deliberate coroutine-safe Queue design:
no borrowed object escapes its lease, no poison payload escapes terminal
handling, no cleanup hides the primary failure, and no speculative machinery
exists beyond verified requirements.

## Completion criteria

- every accepted finding and approved API is implemented at its lowest owner;
- every relevant upstream file/test discovered through the cited PRs is
  accounted for against current default-branch source;
- Hypervel coroutine, pooling, context, queue-route, debounce, and
  interoperability enhancements remain intact;
- all focused tests and `composer fix` pass;
- fresh self-review and code review are signed off;
- audit plan, ledger, dependency index, metadata, docs, and README are current;
- `.tmp/queue-audit.md` is deleted; and
- no stale code, workaround, ignore, fallback, comment, documentation, or
  rejected design remains.
