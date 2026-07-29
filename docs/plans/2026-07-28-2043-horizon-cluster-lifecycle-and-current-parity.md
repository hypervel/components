# Complete Horizon Cluster, Process, and Current-Parity Lifecycles

## Status

The package audit, source research, owner gates, implementation, validation,
review follow-ups, and final audit records are complete. This document records
the resulting design.

## Scope

Complete Horizon as one coherent work unit, including the lowest owning Queue,
Redis, Foundation, Filesystem, Reverb, Telescope, Fortify, Boost,
configuration, metadata, and test boundaries required by verified findings.

The final code must:

- make `horizon:work` executable with the current Queue worker contract;
- make Horizon metadata and Queue bulk publication work on phpredis Cluster;
- preserve exact key, delay, identifier, and exit-status semantics;
- make metrics, locks, transient context, and process termination truthful;
- use checked atomic publication for every touched installer rewrite;
- add current supported Horizon APIs, configuration, docs, and Boost resources;
- retain Hypervel's pooled Redis, coroutine workers, Watcher integration,
  immutable dates, and no-`exit()` process model;
- remove superseded fields, ignores, duplicate cleanup, stale warnings,
  undeclared dependencies, and dead helpers; and
- add no retry, registry, state machine, compatibility layer, or hot-path
  synchronization.

## Post-compaction recovery and anti-overengineering rules

After compaction, read `AGENTS.md` and this plan in full before resuming. Do not
reread the framework-wide audit plan; this section carries its applicable
anti-overengineering rules.

Read only these companion-ledger entries when their completed context is
needed: `Normalize framework enum identifiers at string boundaries`,
`Make Watcher drivers and managed processes lifecycle-safe`,
`Complete Redis pooling, subscriber transport, topology, parity, and lifecycle
safety`, and `Complete Queue pooling, payload durability, and current Laravel
parity`.

- Require a supported, realistic path and meaningful harm before treating a
  concern as a defect. A merely conceivable state does not justify machinery.
- Trace the exact owner, callers, callees, commit point, cleanup, siblings,
  tests, and upstream behavior before changing code. Upstream difference is not
  proof of a bug, and upstream parity is not proof of correctness.
- Fix verified failures completely at the lowest inconsistent owner. Do not
  compensate in callers, retain a partial fix to reduce churn, or defer a
  same-family defect exposed by the change.
- Prefer an existing Laravel or Hypervel API, PHP feature, database guarantee,
  Redis primitive, Filesystem boundary, or coroutine-context lifecycle. Do not
  duplicate framework behavior locally.
- Add no abstraction, registry, retry, backoff, configurable timeout, state
  machine, lock, context slot, cache, token system, or extension point unless a
  verified requirement below cannot be completed without it or it deletes
  greater complexity.
- Do not add enforcement for deliberate escape hatches or unsupported misuse.
  Do not make invariants survive raw Redis access, corrupt key types, disabled
  listeners, or direct process mutation unless the public contract promises it.
- For coroutine or worker-state work, name the shared state, realistic
  interleaving, and harm before adding isolation or cleanup. Use
  `CoroutineContext` only for real invocation-scoped handoff.
- A clearly needed capability may be added before its first use only when the
  requirement and design are already understood. Do not add generic future
  flexibility.
- Backward compatibility does not preserve flawed Hypervel-only internals, but
  current Laravel public APIs, config structure, named arguments, and extension
  conventions remain intact unless an approved Hypervel constraint requires a
  difference.
- Any newly discovered Laravel-facing divergence or source-proven hot-path
  regression returns to owner review before implementation.
- Account for allocations, container/config lookups, hashing, serialization,
  locks, network calls, yields, retries, logging, retained memory, and cache
  invalidation. Do not implement performance changes whose practical effect is
  only measurement noise.
- Bound waits only when progress depends on an external owner that can
  disappear. Do not add arbitrary timeouts to internally owned completion.
- Do not make source awkward or slower for PHPStan. Correct truthful types
  first, then use local narrowing or a precise line ignore. Do not add a global
  ignore or runtime branch for an analysis limitation.
- Test supported public behavior, meaningful failure paths, ownership, and
  deterministic interleavings. Do not add production seams or exhaustive
  internal call-site tests merely to make tests possible.
- Remove every superseded property, helper, import, ignore, fallback, comment,
  test assertion, config key, and documentation statement in the same change.
  Avoiding overengineering never permits stale code or an incomplete fix.
- If implementation exposes an unexpected defect or contradiction, stop that
  edit, complete read-only investigation, obtain focused second-opinion
  consensus, replace the affected plan text with the final design, then resume.

## Architecture and references

### Runtime ownership

| Surface | Final owner and lifetime |
|---|---|
| Horizon Redis metadata | Named pooled `RedisProxy` connection `horizon` |
| Multi-command metadata write | Pipeline on standalone; transaction on Cluster |
| Horizon queue key | Base Queue's hash-tag-aware `getQueueRedisKey()` |
| Worker/supervisor processes | Existing master, supervisor, pool, and process owners |
| Terminal process status | Protected nullable field on the two monitor-loop owners |
| Pending signals/commands | Consumed by their owning monitor loop |
| Metrics stopwatch entry | `UpdateJobMetrics` after it consumes the timer |
| Request CSP nonce | `CoroutineContext`, set from request middleware |
| Last pushed job and listener event | Consume-once coroutine context |
| Horizon locks | Existing Redis command or Cache `RedisLock`, depending on API |
| Installer rewrite | Existing `Filesystem::replace()` atomic publication |
| Test statics | `AfterEachTestSubscriber::flushHorizonState()` |
| Redis integration resources | `InteractsWithRedis` |

Horizon's configured prefix is hash-tagged when its Redis connection is a
Cluster. Consequently, a Cluster transaction containing Horizon metadata keys
targets one slot and remains a valid atomic batch.

### Upstream research

Current implementation references:

- Laravel Horizon `2ebe3cb25ab6461b53a4e3ef42e167edeafe7932`;
- Laravel Framework `9f27fa054af628015e7ada84b0571e7b86cea03e`;
- Laravel documentation `946622229fa1d90052b7d51614a4a14a7156b9b0`;
- Laravel Boost `9f7d7b754af7df5260c6d5feb667cac86e66b945`.

Historical commits are discovery evidence only; port the current checked-out
source and tests:

| Change | Origin/follow-up |
|---|---|
| Horizon Redis Cluster support | Horizon `bfea968`, PR #1760 |
| Development command registration | Horizon `a1f16ca` and `47e07a2`, PRs #1786/#1789 |
| CSP nonce API | Horizon `b9d256c`, PR #1792 |
| Horizon Boost skill | Horizon `637e065`, `70e4a30`, `80c4de7`, `b2b32e3` |
| Worker option and metrics docs | Laravel docs `08c23986`, PR #11297 |
| CSP nonce docs | Laravel docs `2887d9c5`, PR #11298 |
| Axios 1.18 synchronization | Horizon `60e9d13`, PR #1796 |

The Axios advisories affecting the old development lock target Node adapters.
The committed browser bundle contains the XHR adapter and not
`follow-redirects` or `proxy-from-env`; synchronizing package metadata and the
lock is correct, but no browser-runtime remediation or `dist` rebuild is
claimed.

## Finding summary

| ID | Category | Severity | Failure or gap | Final owner |
|---|---|---:|---|---|
| `horizon-02` | Defect | Critical | `horizon:work` omits an inherited option and narrows two reachable null returns | Horizon Work signature/return |
| `horizon-03` | Defect | Major | Direct pipelines and an untagged ready key fail on Redis Cluster | Horizon batching trait and queue |
| `queue-40` | Defect/parity | Major | Redis bulk enters unsupported Cluster pipeline; bulk ignores `#[Delay]` | Queue Redis/Database bulk |
| `horizon-04` | Defect | Major | Rankings, clearing, snapshot locks, and stopwatch ownership are incorrect | Metrics and Lock owners |
| `horizon-05` | Defect | Major | Batch search is invalid SQL on MySQL/MariaDB and silently returns no rows | Batches controller |
| `horizon-06` | Defect | Major | Termination status is lost and a terminated owner can resume work | Monitor-loop owners |
| `horizon-07` | Defect/upstream defect | Major | Lock acquisition is non-atomic and callback release can delete a successor | Horizon Lock |
| `horizon-08` | Current parity | Improvement | Dev command and CSP nonce APIs are absent | Horizon/provider |
| `horizon-09` | Defect | Major | Pushed-job and listener-event context leaks into later work | RedisQueue/Tags |
| `horizon-10` | Parity defect | Minor | Delayed Horizon payload omits delay metadata and gives payload hooks an unresolved queue | RedisQueue |
| `horizon-11` | Defect/type correction | Minor | Exact values and strict typed comparisons are mishandled | Local identifier/type owners |
| `horizon-12` | Config defect | Minor | Defaults drift and two supported keys are undeclared | Horizon config/callers |
| `horizon-13` | Publication defect | Major | Provider namespace rewrites can publish partial PHP | Horizon installer |
| `telescope-03` | Publication defect | Major | Telescope has the same one-file installer rewrite | Telescope installer |
| `fortify-01` | Publication defect | Major | Fortify has the same defect across six independent files | Fortify installer |
| `horizon-14` | Docs/dependency sync | Minor | Public guidance/resources and frontend lock lag current behavior | README/docs/Boost/npm |
| `horizon-15` | Metadata defect | Major | Split install omits hard runtime requirements and retains stale Carbon | Horizon manifest |
| `horizon-16` | Test/maintenance defect | Minor | Integration cleanup is duplicated; reset literals can drift | Test owners/default constants |
| `horizon-17` | Convention correction | Minor | Connector registration uses untyped container array access | Provider |
| `horizon-18` | Test lifecycle defect | Minor | The isolated Horizon command can be signaled before its handler exists | Persisted-master readiness barrier |
| `horizon-19` | Test defect | Minor | Installer read-failure tests assume mode bits always make files unreadable | Installer regressions |
| `filesystem-13` | Cross-package test defect | Minor | Filesystem write-failure guards can throw when `whoami` is absent or shell execution is disabled | Filesystem regressions |
| `horizon-20` | Documentation defect and upstream defect | Major | The CSP example reuses a static nonce and omits the matching response header | Public Horizon guide |
| `horizon-21` | Test dependency defect | Minor | The niceness regression trims nullable shell output even though required `ext-pcntl` exposes the value natively | Supervisor command regression |
| `redis-23` | Type-contract defect | Minor | MultiExec conflates callback results with no-callback clients | Redis MultiExec |
| `reverb-06` | Cross-package test lifecycle defect | Minor | A child can exit after the parent's final pipe read and lose its buffered result | Reverb process harness |

## Approved Laravel-facing result

The owner approved all Improvement and public/configuration gates in this
plan. Current Laravel call shapes remain compatible:

- add `Horizon::cspNonce(string): static`,
  `Horizon::registerDevCommands(): void`, and the missing worker option;
- align `Horizon\Console\WorkCommand::handle(): ?int` with its Queue parent;
- keep `Terminable::terminate(int $status = 0): void`;
- preserve upstream public supervisor properties and methods;
- remove only Hypervel's accidental public `shouldExitLoop` implementation
  field, replacing it with protected terminal state;
- retain explicit force-style `Horizon\Lock::release()`;
- add only `horizon.proxy_path` and `horizon.metrics.snapshot_lock`;
- omit deprecated `horizon:publish`, deprecated `Horizon::night()`, and
  Laravel-Sentinel-only integration, with required difference markers; and
- retain Hypervel's pooled, phpredis-only, coroutine-safe internals.

## 1. Restore the Horizon worker signature (`horizon-02`)

`Horizon\Console\WorkCommand::$signature` replaces rather than extends the
Queue command definition. The inherited `gatherWorkerOptions()` always reads
`stop-when-empty-for`; without the option Symfony throws before the worker
starts.

Add exactly:

```php
{--stop-when-empty-for=0 : Stop when the queue has been empty for the given number of seconds}
```

The wording deliberately matches Hypervel's base Queue command rather than
upstream Horizon's drifted description. Do not add Horizon-owned timer logic;
the Queue worker already implements the option.

Also widen the override to its parent's truthful return:

```php
public function handle(): ?int
{
    if (config('horizon.fast_termination')) {
        ignore_user_abort(true);
    }

    return parent::handle();
}
```

The parent's `runNextJob()` and maintenance-sleep paths both return `null`;
the current `: int` override would turn either into a `TypeError` as soon as
the missing option no longer fails first.

Tests in a new `tests/Horizon/Console/WorkCommandTest.php`:

- use Testbench and instance-bind a `Worker` double that captures the supplied
  `WorkerOptions` without connecting to a queue;
- execute the real command with `--once` and prove the options reach the
  worker while its `null` result is accepted;
- prove the previous missing-option exception no longer occurs; and
- compare Queue and Horizon option names, allowing only Horizon's
  `--supervisor` addition.

The signature test is cheap drift protection; the executed command is the
load-bearing regression.

## 2. Make Redis batching and Queue bulk Cluster-safe (`horizon-03`, `queue-40`)

### Horizon batching

Port `src/horizon/src/Repositories/UsesClusterAwarePipeline.php`, retaining the
upstream name and location:

```php
protected function pipeline(callable $callback): array
{
    $connection = $this->connection();

    // Horizon hash-tags its Cluster prefix, so the transaction remains a
    // single-slot atomic batch.
    // Horizon never issues WATCH, so EXEC cannot abort this transaction.
    /** @var array<int, mixed> $result */
    $result = $connection->isCluster()
        ? $connection->transaction($callback)
        : $connection->pipeline($callback);

    return $result;
}
```

Use the trait in:

- `RedisHorizonCommandQueue`;
- `RedisJobRepository`;
- `RedisMasterSupervisorRepository`;
- `RedisProcessRepository`;
- `RedisSupervisorRepository`; and
- `RedisTagRepository`.

Replace their 17 direct `connection()->pipeline(...)` calls with
`$this->pipeline(...)`. Do not extract another topology or batching service.

`Horizon\RedisQueue::readyNow()` must read the actual storage key:

```php
return $this->getConnection()->lLen($this->getQueueRedisKey($queue));
```

### Queue bulk

In `Queue\RedisQueue::bulk()`, branch once on the existing proxy topology:

```php
$connection = $this->getConnection();

$callback = function () use ($jobs, $data, $queue): void {
    foreach ($jobs as $job) {
        $delay = is_object($job)
            ? $this->getAttributeValue($job, Delay::class, 'delay')
            : null;

        if ($delay !== null) {
            $this->later($delay, $job, $data, $queue);
        } else {
            $this->push($job, $data, $queue);
        }
    }
};

if ($connection->isCluster()) {
    $connection->transaction($callback);
} else {
    $connection->pipeline(
        fn () => $connection->transaction($callback)
    );
}
```

Use the same existing `getAttributeValue()` delay lookup in
`DatabaseQueue::bulk()`. Preserve a public `$job->delay` property and current
`#[Delay]` behavior; do not add reflection or metadata machinery beyond the
already-cached Queue attribute reader.

Tests:

- one trait test proves standalone pipeline and Cluster transaction selection;
- one representative repository path per topology proves callback results;
- existing Redis prefix/queue integration proves Cluster-tagged keys;
- exact `readyNow()` key regression;
- `QueueRedisQueueTest` covers both bulk branches and property/attribute delay;
- `QueueDatabaseQueueUnitTest` covers attribute delay.

Do not duplicate the same branch assertion at all 17 Horizon call sites.

## 3. Correct metrics ownership and clearing (`horizon-04`)

### Rankings and snapshot lock

Both maximum methods must request the latest one-element range:

```php
$this->connection()->zRange($key, -1, -1);
```

Declare:

```php
'metrics' => [
    'trim_snapshots' => [
        'job' => 24,
        'queue' => 24,
    ],
    'snapshot_lock' => 300,
],
```

`SnapshotCommand` passes the configured duration minus 30:

```php
$seconds = config()->integer('horizon.metrics.snapshot_lock', 300) - 30;

if ($lock->get('metrics:snapshot', $seconds)) {
    // existing snapshot behavior
}
```

The Lock owner rejects a non-positive result, so invalid configuration cannot
silently produce an ineffective or permanent lock.

### One-lease clear

Replace proxy-per-key scanning with one raw lease:

```php
$this->connection()->withConnection(
    function (RedisConnection $connection): void {
        $connection->del(
            'last_snapshot_at',
            'measured_jobs',
            'measured_queues',
            'metrics:snapshot',
        );

        foreach (['queue:*', 'job:*', 'snapshot:*'] as $pattern) {
            $connection->flushByPattern($pattern);
        }
    },
    transform: false,
);
```

`flushByPattern()` already handles prefixing, scans all Cluster masters, and
batches deletion. Remove the manual cursor loop and stale imports.
Retain `RedisMetricsRepository::forget()`: the clear rewrite removes its last
internal caller, but it remains part of Laravel's public `MetricsRepository`
contract.

### Stopwatch release and snapshot typing

The listener that consumes a timer owns its release:

```php
$time = $this->watch->check($id = $event->payload->id()) ?: 0;

try {
    $this->metrics->incrementQueue($event->job->getQueue(), $time);
    $this->metrics->incrementJob($event->payload->displayName(), $time);
} finally {
    $this->watch->forget($id);
}
```

`ForgetJobTimer` remains the failure-path net when this listener never runs.
It is not authoritative after a metrics failure because Queue max-exception
cache I/O can fail before `JobExceptionOccurred` is dispatched.

Use truthful field types:

```php
/**
 * @return array{throughput: false|string, runtime: false|string}
 */
protected function baseSnapshotData(string $key): array
{
    // Horizon never issues WATCH, so EXEC cannot abort this transaction.
    /** @var array{0: array{throughput: false|string, runtime: false|string}} $responses */
    $responses = $this->connection()->transaction(/* existing callback */);

    return $responses[0];
}
```

Do not port upstream's fabricated null fallback. A wrong-type command member
must still fail loudly against the native array contract.

Tests in `MetricsTest` and focused listener/command tests:

- at least three snapshots distinguish `-1, -1` from the old empty range;
- Snapshot passes exactly 270 under the default config and when a
  replace-whole application `metrics` array omits the new key;
- clear uses one lease and removes all exact/pattern families;
- Cluster-shaped clear covers all masters through the raw primitive;
- first and second increment failures both forget the timer and preserve the
  increment exception; and
- missing hashes retain `false|string` fields without a fabricated record.

## 4. Make batch search SQL portable (`horizon-05`)

Replace the backslash escape with a portable explicit character:

```php
$pattern = '%' . str_replace(
    ['!', '%', '_'],
    ['!!', '!%', '!_'],
    $request->query('query')
) . '%';

$query->whereRaw("lower(name) like lower(?) escape '!'", [$pattern])
    ->orWhereRaw("lower(id) like lower(?) escape '!'", [$pattern]);
```

Preserve current case-insensitive matching, the existing exact
`before_id !== null && !== ''` boundary, and the controller's response shape.

Coverage:

- retain the existing SQLite controller search/wildcard/zero-cursor tests;
- add a Redis-free Testbench test under
  `tests/Integration/Horizon/Database/MySql/`;
- register Horizon, configure only Queue batching, and create only
  `job_batches`;
- prove plain and literal `%`/`_` search against MySQL.

Do not inherit Horizon's Redis integration base, add Redis to database CI, or
duplicate the parser regression under MariaDB.

## 5. Preserve process exit status and terminal ownership (`horizon-06`)

Replace both public `shouldExitLoop` fields with:

```php
protected ?int $exitStatus = null;
```

No public reader is added. This is Hypervel-internal state replacing upstream's
direct `exit()`, not a Laravel extension seam.

At the successful terminal end of both `terminate()` methods:

```php
$this->pendingSignals = [];
$this->exitStatus = $status;
```

Keep these six terminal placements:

1. the two terminal assignments above;
2. in both `processPendingCommands()` loops, return before processing the next
   command when `exitStatus` is non-null;
3. in both `loop()` methods, return immediately before the working/monitoring
   block when `exitStatus` is non-null.

The loop guard skips auto-scaling/monitoring, persistence, and loop events.
The command guard suppresses Scale, Balance, Continue, Restart, and
AddSupervisor commands already drained behind Terminate. Clearing signals
prevents queued Continue/Restart from reopening work.

Both monitors return the recorded integer:

```php
public function monitor(): int
{
    // existing setup

    while (true) {
        sleep(1);
        $this->loop();

        if ($this->exitStatus !== null) {
            return $this->exitStatus;
        }
    }
}
```

Move the master check after `loop()` to match Supervisor. Propagate the return
from `HorizonCommand` and `SupervisorCommand`; the SIGINT handler calls
`terminate()` without returning its void expression. Remove the stale ignore.

`HorizonCommand::handle(): int` returns `self::SUCCESS` after warning that a
master is already running, preserving upstream's successful idempotent
early-exit behavior, and otherwise returns `$master->monitor()`.

`SupervisorCommand` must likewise return the monitor status instead of
discarding it:

```php
public function handle(SupervisorFactory $factory): int
{
    // existing duplicate-name branch still returns 13

    return $this->start($supervisor);
}

protected function start(Supervisor $supervisor): int
{
    // existing setup

    return $supervisor->monitor();
}
```

`SupervisorWithFakeMonitor::monitor(): int` returns `0`; type its monitoring
flag and type `FakeSupervisorFactory::$supervisor`.

Tests:

- a `#[RunInSeparateProcess]` command-driven monitor test pushes Terminate with
  a non-zero status followed by mutating commands, then proves returned status,
  repository removal, no later process work/event/persist;
- the isolated `horizon` command test polls the master repository for at most
  ten seconds before sending `SIGINT`, always sends the signal so failure cannot
  leave the command blocked, and asserts that registration was observed;
- direct termination assertions use repository/process behavior, not protected
  state;
- master and supervisor command paths propagate clean and non-zero statuses;
- `SupervisorProcess` reprovisions after memory exit `12` and restart exit `1`;
- exit `0`, `2`, and `13` remains terminal; and
- existing process-monitor tests remain green.

Process isolation is required because a completed monitor installs
process-global signal handlers, unblocks signals, and retains the monitor
instance in closures. Recreating/restoring that entire process state inside a
shared PHPUnit worker would be more complex and less reliable.

The four trait-managed async signals only enqueue pending work, so the terminal
loop guard prevents them from reopening the discarded owner. SIGINT is
different: `HorizonCommand` invokes `terminate()` inline. If it arrives after
the master loop's terminal guard but before `persist()`, that iteration can
republish the removed master record. The stale record expires through the
existing TTL and does not revive work; masking SIGINT or adding another guard
for this narrow residual window would be disproportionate.

## 6. Make Horizon locks atomic and owner-safe (`horizon-07`)

Reject invalid TTLs through one private owner used by both public acquisition
paths:

```php
private function assertPositiveLifetime(string $key, int $seconds): void
{
    if ($seconds <= 0) {
        throw new InvalidArgumentException(
            "Horizon lock [{$key}] requires a positive lifetime; {$seconds} given."
        );
    }
}
```

Then acquire positive locks atomically:

```php
$this->assertPositiveLifetime($key, $seconds);

return $this->connection()->set($key, '1', 'EX', $seconds, 'NX') === true;
```

Delegate callback ownership:

```php
public function with(string $key, Closure $callback, int $seconds = 60): void
{
    $this->assertPositiveLifetime($key, $seconds);

    (new RedisLock($this->connection(), $key, $seconds))->get($callback);
}
```

This preserves Horizon's void callback API and uses Cache's owner token plus
owner-checked Lua release. Direct `release()` remains an explicit force delete;
do not add an owner registry or coroutine context.

Tests:

- `get()` emits one atomic command, and both acquisition APIs reject
  zero/negative TTL;
- a successor B acquired after A's TTL cannot be deleted by A;
- callback failure remains primary if owned release also fails; and
- direct `release()` remains force-style.

## 7. Port dev commands and request-scoped CSP (`horizon-08`)

Port the current upstream method using Hypervel Foundation:

```php
/**
 * Register the Horizon development commands.
 *
 * Boot-only. The registrations persist for the worker lifetime and affect
 * every subsequent development command invocation.
 */
public static function registerDevCommands(): void
{
    DevCommands::artisan('horizon', 'horizon');
    DevCommands::except('queue');
}
```

Call it from `HorizonServiceProvider::register()` after configuration, services,
and connector registration, matching current upstream placement.

Add `Horizon::cspNonce(string): static`, but store the rendered attribute in
`CoroutineContext`:

```php
protected const CSP_NONCE_CONTEXT_KEY = '__horizon.csp_nonce';

public static function cspNonce(string $nonce): static
{
    CoroutineContext::set(
        self::CSP_NONCE_CONTEXT_KEY,
        ' nonce="' . $nonce . '"',
    );

    return new static;
}
```

The docblock must state request/middleware use. A boot-time non-coroutine value
is deliberately not inherited by request coroutines. Read the context once in
`css()` and once in `js()`, applying it to all three style tags and the script.

Tests:

- dev registration includes `horizon` and excludes generic `queue`;
- no nonce renders no attribute;
- CSS and JS include the exact nonce;
- replacement within one coroutine uses the latest value; and
- concurrent coroutines render only their own nonce.

## 8. Consume transient context exactly once (`horizon-09`)

In `RedisQueue::pushRaw()`, consume and forget before preparing:

```php
$job = CoroutineContext::get(static::LAST_PUSHED_CONTEXT_KEY);
CoroutineContext::forget(static::LAST_PUSHED_CONTEXT_KEY);

$payload = (new JobPayload($payload))->prepare($job);
```

Delete `getLastPushed()` after its last caller disappears. Keep the one-line
setter; it has a real cross-method handoff.

In `Tags::tagsForListener()`:

```php
$event = static::extractEvent($job);
static::setEvent($event);

try {
    return collect([static::extractListener($job), $event])
        ->map(fn ($job) => static::for($job))
        ->collapse()
        ->unique()
        ->toArray();
} finally {
    static::flushEventState();
}
```

`flushEventState()` uses `CoroutineContext::forget()`, not a retained null
entry.

Tests:

- `push()` followed by direct `pushRaw()` in one coroutine does not inherit the
  earlier job;
- payload preparation failure still consumes the handoff; and
- a userland `tags()` failure followed by another extraction sees no stale
  event.

No stack, static reset, registry, or shared helper is needed.

## 9. Forward delayed payload metadata and the resolved queue (`horizon-10`)

Match the immediate path by passing both the resolved queue and existing delay:

```php
$payload = (new JobPayload(
    $this->createPayload($job, $this->getQueue($queue), $data, $delay)
))->prepare($job)->value;
```

Assert the payload's delay field and prove immediate and delayed payload hooks
receive the same resolved queue. This changes only the existing JSON input; it
adds no serialization layer or Redis call.

## 10. Correct exact and typed local boundaries (`horizon-11`)

Apply only these source-proven corrections:

```php
// HorizonServiceProvider
if (($name = $config->get('horizon.name')) === null || $name === '') {
    $config->set('horizon.name', $config->string('app.name'));
}

// MasterSupervisor
return $name === null || $name === ''
    ? static::commandQueue()
    : 'master:' . $name;

// FailedJobsController
$tag = $request->query('tag');
$hasTag = $tag !== null && $tag !== '';

// SupervisorOptions
return in_array($this->balance, ['simple', 'auto'], true);

// SupervisorCommand
$balance = $this->option('balance') ?? 'off';

// AutoScaler
if ($timeToClearAll === 0.0 && $supervisor->options->autoScaling()) {
    // existing branch
}

// SupervisorProcess
if (in_array($exitCode, $this->dontRestartOn, true)) {
    return;
}
```

`FailedJobsController` reuses the hoisted tag for both pagination and count.
Preserve empty tag as unfiltered and literal `"0"` as a valid user tag.

Do not:

- special-case unsupported boolean `true` balancing;
- change dashboard title/name `"0"`;
- change the intentional `starting_at = 0` first-page sentinel; or
- add a shared exact-value helper.

Focused tests cover name/tag/queue `"0"` and empty boundaries, null balance,
the float zero branch, and nullable process exit codes.

## 11. Make configuration single-owner and reads truthful (`horizon-12`)

Add top-level:

```php
'proxy_path' => '',
```

Add `metrics.snapshot_lock` as shown in section 3. Correct
`StoreTagsForFailedJob`'s fallback from `2880` to `10080`.

Use typed getters without duplicated fallbacks only for shipped non-null
top-level keys:

- `horizon.path`;
- `horizon.use`;
- `horizon.prefix`;
- `horizon.middleware`;
- `horizon.fast_termination`;
- `horizon.memory_limit`; and
- `horizon.proxy_path`.

Retain fallbacks or nullable reads for:

- nested `trim`, `metrics`, `waits`, `defaults`, and environment values because
  application config can replace those arrays as a whole;
- nullable `domain`, `name`, `watch`, batching, and environment overrides;
- Queue connection defaults; and
- `horizon.env`, which remains an optional undeclared override rather than new
  speculative config surface.

Do not add config objects, recursive merging, validation services, or duplicate
call-site defaults. Extend the currently one-test `HorizonConfigTest` with
declared-key and replace-whole nested-config regressions; the focused
`SnapshotCommand` test owns the `snapshot_lock` call-site fallback.

## 12. Publish installers atomically and test permissions by capability (`horizon-13`, `telescope-03`, `fortify-01`, `horizon-19`, `filesystem-13`)

At each command, resolve the existing `Filesystem` service through
`$this->hypervel->make(Filesystem::class)`, check the current file and mode,
then replace:

```php
if (! is_file($path)) {
    // emit the command's existing owner-specific not-published error
    return false;
}

$contents = @file_get_contents($path);
$permissions = @fileperms($path);

if ($contents === false || $permissions === false) {
    // emit the command's existing owner-specific unable-to-read error
    return false;
}

try {
    $filesystem->replace(
        $path,
        $updatedContents,
        $permissions & 0777,
    );
} catch (RuntimeException) {
    // emit the command's existing owner-specific update error
    return false;
}
```

Drop the redundant `is_readable()` metadata check. The native content and mode
operations remain the checked boundaries while the distinct missing-file and
read-failure diagnostics stay intact.

Apply to:

- Horizon's published provider;
- Telescope's published provider; and
- each of Fortify's six independent published PHP files.

Continue processing Fortify files only while each prior rewrite succeeds.
Provider registration remains after all rewrites and therefore cannot run
after failure.

Do not add a shared installer abstraction, lock, backup, retry, or multi-file
transaction. Independent files can be published atomically one at a time.

Extend Horizon's and Telescope's existing install-command tests. Add
`tests/Fortify/Console/InstallCommandTest.php`; Fortify has no current
install-command test. Each command covers success, a missing file, preserved
mode, read/mode failure, replacement failure, and no provider registration
after failure. Fortify additionally proves a later file is untouched after an
earlier failure.

Permission regressions must test the capability they depend on. After denying
access, the three installer tests skip only when a native content read still
succeeds, while the two Filesystem adapter tests skip only when the file remains
writable. Keep restoration in `finally`, and create failure expectations only
after the capability probe succeeds.

## 13. Complete docs, Boost resources, and frontend dependency sync (`horizon-14`, `horizon-20`)

### Package README and intentional omissions

Add:

```md
Ported from: https://github.com/laravel/horizon
```

Under `Differences From Laravel`, record only actionable omissions:

- deprecated `horizon:publish` is not ported; use `horizon:install`;
- Laravel Sentinel integration has no Hypervel consumer and is omitted.

Add concise `REMOVED:` markers at their natural source and matching upstream
test insertion points. Do not list internal Cluster, pooling, date, process, or
coroutine adaptations. Do not port deprecated `Horizon::night()`.

### Public Horizon guide

Update `src/boost/docs/horizon.md` in its existing Laravel-style prose:

- remove the Redis Cluster incompatibility warning after section 2 is complete;
- document middleware/request-scoped `Horizon::cspNonce()` with one fresh
  `Str::random(40)` value shared by the Horizon tags and a response
  `Content-Security-Policy` header covering both `script-src` and `style-src`;
- explain `metrics.trim_snapshots` as a count whose time span depends on
  snapshot frequency; and
- document `memory`, `maxJobs`, `maxTime`, `sleep`, `rest`, and `nice`.

Do not expose internal lifecycle choreography.

### Package-owned Boost skill

Port the current files to
`src/horizon/resources/boost/skills/configure-horizon/`:

- `resources/boost/skills/configure-horizon/SKILL.blade.php`;
- `references/metrics.md`;
- `references/notifications.md`;
- `references/tags.md`; and
- `references/supervisors.md`.

Keep the upstream front-matter name `configuring-horizon`, change the metadata
author and `GuidelineAssist` namespace to Hypervel, remove the unsupported Sail
clause, and adapt Laravel names, commands, config, and Cluster guidance. Keep
the final SMS API and guidance intact so the later first-party Vonage package
port does not require reversing temporary documentation. Delete only the
obsolete commented Nexmo lines from `LongWaitDetected` and
`SendNotification`; do not add a partial `vonage` implementation or a
regression that makes the temporary missing transport part of Horizon's
contract. Update the existing `docs/todo.md` entry to own the dedicated
`hypervel/vonage-notification-channel` port, current-name Horizon wiring, and
functional SMS coverage. Add no extra pages and do not duplicate the public
guide.

### Axios

Update `src/horizon/package.json` and lockfile from `^1.8.2`/1.8.2 to current
upstream `^1.18.0`/1.18.0. Do not independently upgrade other direct tools or
rebuild `dist`.

Validate the lock, documentation anchors, Boost template/reference links, and
`git diff --check`.

## 14. Correct split metadata (`horizon-15`)

Add direct requirements to `src/horizon/composer.json`:

```json
"ext-mbstring": "*",
"ext-redis": "^6.1",
"hypervel/routing": "^0.4",
"symfony/http-foundation": "^8.1",
"symfony/http-kernel": "^8.1"
```

Remove direct `nesbot/carbon`; Horizon imports Hypervel's Support date surface.
Keep `ext-redis` on Horizon rather than forcing it on every `hypervel/redis`
consumer. Horizon is Redis-only; the Redis package still has extension-free
subscriber/static surfaces.

Extend `PackageMetadataTest` with exact presence/non-empty assertions and an
explicit Carbon absence assertion. The root aggregate already carries the new
requirements and needs no dependency command.

## 15. Remove duplicate test cleanup and external process dependencies (`horizon-16`, `horizon-21`)

From `tests/Integration/Horizon/IntegrationTestCase` remove:

- its `beforeApplicationDestroyed` static resets;
- `setUpInCoroutine()` and its deferred pool flushes; and
- the outer default-pool flush in `tearDown()`.

Retain exact Queue configuration restoration before `parent::tearDown()`.
`AfterEachTestSubscriber` already owns Horizon statics, and
`InteractsWithRedis` already owns every Redis pool. This also removes a LIFO
ordering hazard where a deferred pool flush can precede a proxy's deferred
connection release.

In `SystemProcessCounter`, `WorkerCommandString`, and
`SupervisorCommandString`, define one protected `DEFAULT_COMMAND` and use it
for initialization and `flushState()`:

```php
protected const DEFAULT_COMMAND = '...';

public static string $command = self::DEFAULT_COMMAND;

public static function flushState(): void
{
    static::$command = self::DEFAULT_COMMAND;
}
```

Extend `StaticStateTest` so all three mutable commands are changed then reset.
Add `: void` to every new or touched test method.

Assert the supervisor's process niceness with `pcntl_getpriority()` instead of
shelling out to `ps`. Horizon already requires `ext-pcntl`.

Run the complete Horizon integration group against live Redis to prove the
authoritative cleanup owners are sufficient under real pooling.

## 16. Use the typed container convention (`horizon-17`)

Keep the canonical Redis service key and narrow its runtime contract locally.
PHPStan otherwise mistakes the lowercase key for phpredis's global `Redis`
class:

```php
/** @var RedisFactory $redis */
$redis = $this->app->make('redis');

return new RedisConnector($redis);
```

Apply the same local factory narrow to the remaining unannotated
`make('redis')` calls in `RedisServiceProvider`,
`RedisConnectionLifecycleListener`, and `ReverbServiceProvider`. Preserve each
canonical service key and existing runtime guard. Do not add a binding, inject
a new dependency, change unrelated service resolution, or add a static-analysis
ignore.

At the two Queue connector registrations touched by this work, replace plain
container array access with `make('db')` and `make('redis')`, narrowing the
resolved locals to `ConnectionResolverInterface` and `RedisFactory`
respectively before constructing their connectors. Do not expand this into the
heterogeneous framework-wide array-access conversion tracked separately in
`docs/todo.md`.

## 17. Correct Redis MultiExec return contracts (`redis-23`)

In `Redis\Traits\MultiExec`, make callback-sensitive docs truthful:

```php
/**
 * @return ($callback is null ? Redis : array<int, mixed>|false)
 */
public function pipeline(?callable $callback = null)

/**
 * @return ($callback is null ? Redis|RedisCluster : array<int, mixed>|false)
 */
public function transaction(?callable $callback = null)

/**
 * @return ($callback is null
 *     ? ($command is 'pipeline' ? Redis : Redis|RedisCluster)
 *     : array<int, mixed>|false)
 */
private function executeMultiExec(string $command, ?callable $callback = null)
```

Use a form PHPStan accepts without changing runtime code. Let analysis reveal
consumers needing element-shape narrowing. Update only the affected local
result annotations in `RedisSupervisorRepository` and
`RedisMetricsRepository`; retain `RedisMasterSupervisorRepository`'s unrelated
higher-order Collection proxy ignore. Do not add runtime guards, casts, or a
neon ignore.

`tests/Redis/MultiExecTest` covers callback/no-callback returns for pipeline and
transaction, including callback `false`.

## 18. Complete audit records

After implementation and review:

- add the Horizon work-unit block to the companion ledger with every finding,
  rejected concern, test/gate result, API result, and performance result;
- record the later permission-test and CSP defects as `horizon-19`,
  `filesystem-13`, and `horizon-20`, plus the native niceness correction as
  `horizon-21`, without duplicating the completed Filesystem entry or recording
  rejected bot suggestions;
- close the carried `horizon-01` revalidation against the final Cluster
  connection and prefix behavior;
- close `queue-22` against Horizon's malformed-payload telemetry boundary and
  `support-02` against Horizon's final identifier handling;
- add cross-package dependency rows for `queue-40`, `redis-23`,
  `telescope-03`, `fortify-01`, and `reverb-06`;
- amend the completed Queue and Redis ledger entries with their source changes
  and revalidation;
- record Horizon's intentional omission of upstream phpredis/Predis runtime
  guards because the split package requires `ext-redis` and has no Predis
  transport;
- route the core plan to the next package only after Horizon is complete; and
- check Horizon off only after the owner-approved bookkeeping commit.

## Rejected concerns

Do not implement:

- an HMGET null/corruption fallback;
- monitoring-lock loser backoff;
- retries, renewal, signal masking, process registries, or lifecycle state
  machines;
- generic Cluster batching/topology or installer abstractions;
- deprecated `horizon:publish`, deprecated `Horizon::night()`, or Sentinel
  integration;
- direct `exit()`;
- support for boolean `true` balance;
- dashboard title `"0"` churn;
- `horizon.env` declaration;
- event guards without a harmful construction/dispatch path;
- a `dist` rebuild or browser-security claim for Node-only Axios adapters;
- Redis service expansion in database CI;
- duplicate MySQL/MariaDB parser tests;
- exhaustive tests for every pipeline call site;
- changes to class-string listeners, nullable Bus batch config,
  `findFailed()`'s supported transformed shape, by-reference workload
  accumulation, or `loadRoutesFrom()`.

Keep the harmless terminal async-signal window documented only in this plan; it
does not warrant source comments or machinery.

## Implementation order

1. Restore `horizon:work` and split metadata so the package's executable floor
   is valid.
2. Correct Redis MultiExec typing, then Cluster batching, Queue bulk, and delay
   semantics.
3. Correct metrics, Lock, batch SQL, exact values, and config ownership.
4. Implement terminal process status and its isolated regressions.
5. Add CSP/dev APIs, context cleanup, delayed metadata, and provider convention.
6. Make all three installers atomic.
7. Remove duplicate test cleanup and reset literal drift.
8. Update README, public docs, Boost resources, npm metadata/lock, and the
   records in section 18.

Work one file at a time. Run each changed/new test file immediately after its
source slice.

## Verification plan

### Focused tests

Run affected files as each slice lands, then the complete groups:

```bash
./vendor/bin/phpunit --no-progress tests/Horizon
./vendor/bin/phpunit --no-progress tests/Queue
./vendor/bin/phpunit --no-progress tests/Redis
./vendor/bin/phpunit --no-progress tests/Telescope
./vendor/bin/phpunit --no-progress tests/Fortify
```

Run live Horizon/Redis coverage with the configured local Redis service:

```bash
./vendor/bin/phpunit --no-progress tests/Integration/Horizon
```

Run the MySQL regression with the repository's configured MySQL environment:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=password \
./vendor/bin/phpunit --no-progress tests/Integration/Horizon/Database/MySql
```

Do not add environment assumptions beyond existing integration conventions.

### Static, metadata, and documentation checks

- run package JSON/lock validation;
- run the exact Horizon split-metadata test;
- manually inspect Boost skill/reference links and public-doc anchors; no
  repository command validates package-owned Boost resources;
- search for direct Horizon `pipeline()` owners left outside the trait;
- search for `shouldExitLoop`, stale command literals, raw installer
  `file_put_contents`, old Cluster warnings, stale ignored return types,
  deleted context helpers, and test-owned `trim(shell_exec(...))` calls;
- confirm every new/touched test method is `: void`;
- run `git diff --check`.

### Authoritative gate

Run only after focused tests are green:

```bash
composer fix
```

This runs formatting, both PHPStan configurations, the complete parallel
components suite, and both Testbench suites. Do not separately rerun fixer or
PHPStan immediately before it.

## Fresh self-review

After the gate passes, review the entire diff without trusting this plan:

- trace all six pipeline owners and both Queue bulk branches;
- trace one lease through metrics clear and every Lock success/failure path;
- trace terminal status from signal/command/parent loss through monitor and
  command exit, including commands already drained behind Terminate;
- trace request nonce and both consume-once context values across exceptions and
  concurrent coroutines;
- trace every installer read, permission read, replacement, failure report, and
  provider-registration boundary;
- verify the public CSP example uses one fresh request nonce in both generated
  tags and both response-header directives;
- verify all config fallbacks against top-level versus replace-whole nested
  ownership;
- compare public source/docs/Boost resources to current upstream while retaining
  approved Hypervel adaptations;
- inspect allocations, config/container reads, network calls, retained state,
  and removed cleanup;
- verify no workaround, dead code, duplicated docs, stale comment, or
  speculative mechanism remains.

Unexpected non-trivial findings return to focused second-opinion consensus and
replace this plan's affected text before implementation continues.

## Performance and complexity result

No application request hot path gains network I/O, locking, retries, logging,
or retained state. Dashboard rendering adds one coroutine-context read per
asset method. Standalone Redis batching remains the same; Cluster changes a
fatal pipeline call into its valid transaction. Lock acquisition drops from two
Redis round trips to one. Metrics clear drops from one checkout per key to one
lease for the sweep. Queue bulk's delay lookup uses the existing cached
attribute reader. Termination checks run only in once-per-second process-control
loops. Installer, metadata, docs, and npm work is cold.

The final implementation adds no registry, generic abstraction, retry queue,
state machine, compatibility shim, process-wide request state, or hot-path
synchronization.
