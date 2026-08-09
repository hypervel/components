# Telescope correctness, current parity, and watcher lifecycles

**Status:** Complete.

## Objective

Complete the Telescope audit by correcting verified storage, dashboard-query, watcher, recording,
configuration, lifecycle, metadata, documentation, and frontend defects while preserving Hypervel's
coroutine-local recording model, worker-safe watcher architecture, passive view observation,
stateless Guzzle AOP integration, enum identifier normalization, Redis behavior, Context telemetry,
lazy monitored-tag loading, and dedicated schedule-storage boundary.

The design adds no request middleware, polling loop, timer, lock, retry, registry, compatibility
wrapper, serializer, eager monitored-tag load, or application hot-path cache. Most changes remove
work, repair cold telemetry paths, or affect only dashboard/maintenance operations. The only new
recurring operations are predicates inside already-enabled watchers; globally disabled Telescope
will register less instrumentation than it does today.

## Evidence baseline

- Hypervel branch baseline: `0.4` at `65ce5f11bd37cbc77f5f70441edae323dc112832`.
- Current Laravel Telescope reference: `examples/laravel/telescope` `5.x` at
  `67edf6caba0f6f9421a92c38bf97e369af268a56`.
- Current Laravel framework reference: `examples/laravel/framework` at
  `8df67f9d176d1d0375a866d8c6780be95ce0336e`.
- Upstream changed-file inventories were checked commit by commit for discovery; implementation
  uses current upstream source and tests as the reference:
  - `d29f257`: ordered deletion in the database repository and its tests;
  - `800431d`: processed-job failure-state cleanup and watcher tests;
  - `035143f`: complete queue-batch detail loading;
  - `ad5fadb`: Composer uninstall action and provider registration;
  - `16ff831`: missing queue-batch handling;
  - `286adc5`: event payload/property extraction and watcher tests;
  - `cbdd61b`: request-size configuration wording and client-request watcher;
  - `2e30b8a` and `571b086`: CSP nonce API and final method placement;
  - `67edf6c`: BatchWatcher documentation wording;
  - `f00f45a`: frontend dependency/source/lock/dist update;
  - `72c69ef`: Sentinel integration, recorded for a separate package port rather than copied now.
- Rejected upstream changes were also inspected at their originating commits: `7c9a3f9` (runtime
  JavaScript newline normalization) and `d89be8f` (behavior-neutral match-expression cleanup).
- Current Hypervel completed work was revalidated before planning: `di-02`, `support-02`,
  `redis-15`, `telescope-01`, `telescope-02`, `telescope-03`, `telescope-04`, and `queue-22`
  remain applicable.
- The pre-consensus `.tmp` findings are discovery evidence only. This plan records the verified
  current-branch design and rejects findings whose proposed remediation did not survive tracing.

## Anti-overengineering rules

The following wording is retained verbatim from the core audit plan. Its principle numbering is
also retained; principles 1–6 remain in the core operating plan. In principle 9, “later in this
plan” refers to the core plan's
[Established remediation vocabulary](2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md#established-remediation-vocabulary)
section.

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

## Retained architecture and API boundaries

- Telescope remains optional and package-discovered. Its storage repository is always available;
  dashboard, watcher, and storage-opportunity bootstrapping remain controlled by the master switch.
- Recording flags, recursion protection, entry/update queues, batch IDs, storage-defer ownership,
  job state, schedule reconciliation, CSP nonce, and monitored-tag cache remain coroutine-local.
- `Factory::observeRendering()` remains the passive worker-lifetime view hook. Telescope does not
  force framework view-event dispatch solely for telemetry.
- Guzzle observation continues to use Hypervel's shared stateless AOP `ProxyDispatcher` and empty
  `ProxyMarker`; no Telescope dispatcher or generated-method registry is introduced.
- Redis observation keeps Hypervel's boot-only tri-state event configuration, recursive safe
  parameter formatting, and case-insensitive `pipeline` / `multi` opener suppression.
- Cache and Redis event forcing remains conditional on active watchers and will no longer activate
  when Telescope is globally disabled.
- Tags and queue names remain normalized string boundaries, including valid identifier `"0"`.
- Request/job Context, Reverb, Guzzle request options, and coroutine context telemetry remain.
- Monitored tags remain lazy per coroutine; upstream eager-loading parameters are not copied.
- Schedule storage opportunities remain owned by the dedicated schedule runner through
  `ListensForStorageOpportunities::manageRecordingStateForCommands()`. They are not registered in
  web workers or for `crontab:run`.
- Public Telescope callbacks, watcher configuration, repository contract, controllers, facade/API
  signatures, extension points, and custom repository support remain Laravel-compatible unless a
  verified bug is corrected internally.

## Findings and final decisions

The final ledger IDs continue after existing `telescope-01` through `telescope-04`.

| Final ID | Audit source | Category | Final decision |
|---|---|---|---|
| `telescope-05` | `audit-01` | Monitored tags | Insert every new unique tag as one row in the existing bulk insert. |
| `telescope-06` | `audit-02` | Recording lifecycle | Clear the recursion guard in `finally` while propagating callback errors. |
| `telescope-07` | `audit-03` | Update serialization | Substitute invalid UTF-8 during update JSON encoding and simplify the guaranteed object decode. |
| `telescope-08` | `audit-04` | Redaction | Mask configured paths by presence, including falsey values. |
| `telescope-09` | `audit-05` | Request bounds | Apply the configured KB limit as an exact byte bound. |
| `telescope-10` | `audit-06` | UUID queries | Apply nullable explicit UUID filters, including the empty-set case. |
| `telescope-11` | `audit-07` | Falsey queries | Preserve tag `"0"` and `beforeSequence=0` with explicit absence checks. |
| `telescope-12` | `audit-08` | Event telemetry | Correct framework prefixes, payload extraction, and listener metadata handling. |
| `telescope-13` | `audit-09` | Dashboard domain | Apply `telescope.domain` as route metadata and delete obsolete request-domain helpers. |
| `telescope-14` | `audit-10` | Deletion ordering | Port current deterministic sequence/tag ordering without locks or retries. |
| `telescope-15` | `audit-11` | Job telemetry | Clear stale exception/failed-tag state after successful processing. |
| `telescope-16` | `audit-12` | Queue details | Load the complete related batch on the detail endpoint. |
| `telescope-17` | `audit-13` | Uninstall lifecycle | Remove the published provider during Composer pre-uninstall. |
| `telescope-18` | `audit-15` | Package metadata | Complete `telescope-04` by declaring every direct runtime requirement and removing unused `hypervel/server`. |
| `telescope-19` | `audit-16` | Provenance/Sentinel | Add canonical docs/provenance and one central future Sentinel integration record. |
| `telescope-20` | `audit-17` | Config/stub parity | Remove redundant null env defaults and type the published gate user. |
| `telescope-21` | `audit-18` | Schedule telemetry | Reconcile Finished→Failed into one queued row without changing Console ordering. |
| `telescope-22` | `audit-19` | View telemetry | Record composer/creator metadata from the existing passive observer. |
| `telescope-23` | `audit-20` | Job batch lookup | Suppress Telescope recursively only around its batch lookup. |
| `telescope-24` | `audit-21` | Repository contract | Keep nullable public updates, narrow the built-in implementation, and handle custom null returns. |
| `telescope-25` | `audit-22` | Reverb bounds | Truncate by bytes without splitting UTF-8. |
| `telescope-26` | `audit-23` | Missing batch | Treat a removed/missing queue batch as absent. |
| `telescope-27` | `audit-24` | Reflection parity | Remove obsolete accessibility and PHP 7.4 compatibility branches. |
| `telescope-28` | `audit-25` | Client host API | Restore protected host-ignore extensibility and strict matching. |
| `telescope-29` | `audit-26` | Stack filtering | Ignore vendor frames according to the configured boolean contract. |
| `telescope-30` | `audit-27` | Disabled mode | Gate Redis, cache, and Guzzle instrumentation behind the master switch. |
| `telescope-31` | `audit-28` | Dump lifecycle | Install one safe worker handler and reset global ownership between test applications. |
| `telescope-32` | `audit-29` | Exception file | Contain suppressed read failure at its checked boundary. |
| `telescope-33` | `audit-30` | Dead comment | Remove the obsolete hostname comment. |
| `telescope-34` | `audit-31` | Public docs | Correct current watcher/configuration documentation without duplicating internals. |
| `telescope-35` | `audit-32` | Frontend | Bring Axios, source, lock, and built dashboard assets to current safe parity. |
| `telescope-36` | `audit-34` | Exception families | Aggregate each chunk deterministically with one count/hide update per family. |
| `telescope-37` | `audit-35` | Native typing | Add only source-proven narrow return/property types. |
| `telescope-38` | `audit-36` | Cache contracts | Type recording/dump controllers to Cache Repository. |
| `telescope-39` | New parity | CSP nonce | Preserve Laravel's method API with coroutine-local nonce storage and concurrent isolation. |
| `telescope-40` | New parity | Batch docs | Port current BatchWatcher parameter wording. |

Rejected `telescope-audit-14` and `telescope-audit-33` are recorded under rejected designs. No final
ledger IDs are assigned to rejected changes.

## Implementation

### 1. Correct repository persistence and query boundaries (`telescope-05`, `07`, `10`, `11`, `14`, `24`, `36`)

Build monitored rows as a list after exact uniqueness/difference filtering:

```php
$tags = array_values(array_diff(array_unique($tags), $this->monitoring()));

$this->table('telescope_monitoring')->insert(
    array_map(static fn (string $tag): array => ['tag' => $tag], $tags),
);
```

Retain one bulk insert. Do not add upserts, per-tag queries, or a lock.

Encode update content with the same invalid-UTF-8 policy as inserts and remove the misleading
fallback that could pass an array into property access:

```php
$content = json_decode($entry->content, true) ?: [];

'content' => json_encode(
    array_merge($content, $update->changes),
    JSON_INVALID_UTF8_SUBSTITUTE,
),
```

Type `EntryQueryOptions::$uuids` as `?array`. Apply it whenever it is non-null so an explicit empty
array correctly produces no rows:

```php
if ($options->uuids !== null) {
    $query->whereIn('uuid', $options->uuids);
}
```

`fromRequest()` already passes the raw value through `uuids(?array)`, so malformed non-array input
already fails at the public setter. The property type introduces no new failure mode and needs no
additional request guard.

Normalize the shipped dashboard's empty query strings to null in `EntryQueryOptions`, then use
explicit absence checks only where the valid domain is falsey. Keep `beforeSequence` typed as
`int|string|null`: internal callers use integers while HTTP query values are strings.

```php
$this->tag = $tag === '' ? null : $tag;
$this->beforeSequence = $id === '' ? null : $id;

if ($options->tag !== null) { /* existing tag predicate */ }
if ($options->beforeSequence !== null) { /* existing sequence predicate */ }
```

Do not mechanically rewrite type, batch, or family conditions with no valid falsey input. Correct
both `EntryQueryOptions` “tor retrieve” typos.

Port current upstream deterministic delete ordering: entry deletion by `sequence`, monitored-tag
deletion by `tag`. Preserve chunking and existing transactions; add no retry or coordinator.

Keep the public `EntriesRepository::update(Collection): ?Collection` contract because custom
repositories may return null. Narrow only the built-in implementation covariantly to `Collection`
and make `ProcessPendingUpdates::handle()` tolerate a custom null result. Keep
`Telescope::executeStore()`'s existing empty-collection fallback.

For exception occurrence updates, aggregate each retrieved chunk by final UUID/family and issue one
persisted count/hide update per family using a local monotonic count. Do not use cross-coroutine
locks: the occurrence counter is observational telemetry. Maintain finite chunks and one bulk
insert/update boundary rather than repeated per-entry writes.

Tests prove multiple/mixed monitored tags, invalid nested UTF-8 updates, UUID exact/empty selection,
tag `"0"`, sequence zero, empty tag/sequence absence, ordered SQL, nullable custom repository
updates, and deterministic same-family aggregation. Each test must fail against the corresponding
pre-fix expression.

### 2. Make recording and watcher updates failure-safe (`telescope-06`, `15`, `23`, `26`, `32`)

Wrap all post-guard recording work in `try/finally`:

```php
CoroutineContext::set(static::IS_RECORDING_CONTEXT_KEY, true);

try {
    // Existing tag, filter, queue, and after-recording work.
} finally {
    CoroutineContext::set(static::IS_RECORDING_CONTEXT_KEY, false);
}
```

Do not catch or report callback errors. Verify throwing tag, filter, and after-recording callbacks
each propagate and cannot suppress a later successful entry in the same coroutine.

Port current processed-job cleanup while retaining Hypervel Context fields and `queue-22`'s narrow
`InvalidPayloadException` containment:

```php
'status' => 'processed',
'exception' => null,
```

Remove the `failed` tag in the same update. A fail-then-process test must pin both content and tags.

Wrap only `JobWatcher::getBatchId()` in `Telescope::withoutRecording()`. Keep missing-model fallback
and allow unrelated failures to propagate. Make queue batch lookup null-safe when the batch was
removed between payload creation and observation.

At the exception-file boundary, retain the suppressed native read only when its exact `false`
result is checked and converted to an empty trace/list. Do not add global warning handling.

### 3. Correct redaction and body bounds (`telescope-08`, `09`, `25`)

Use `Arr::has()` before the existing `Arr::set()` in RequestWatcher and ClientRequestWatcher so
configured nested falsey values are masked. Do not scan unconfigured keys.

Apply request bounds in exact bytes:

```php
return strlen($content) <= $this->options['size_limit'] * 1024;
```

Add `// KB` to the `size_limit` configuration line. For Reverb payloads, compare bytes with
`strlen()` and truncate via `mb_strcut()` before appending the existing suffix so UTF-8 is never
split. Do not decode/re-encode JSON or allocate a recursive preflight structure.

Tests cover nested falsey request/session/header/response values, exact request byte boundaries,
multibyte overage, and Reverb exact/over-limit valid UTF-8.

### 4. Correct event and view metadata (`telescope-12`, `22`, `27`, `40`)

`EventWatcher::recordEvent()` always receives a string event name. Extract object properties only
when the event name is a class and `payload[0]` is an object:

```php
if (class_exists($eventName) && isset($payload[0]) && is_object($payload[0])) {
    return ExtractProperties::from($payload[0]);
}
```

Remove the unreachable object-event-name branch. Match framework event prefixes using one cheap
prefix operation:

```php
return Str::startsWith($eventName, [
    'Hypervel\\', 'eloquent', 'bootstrapped', 'bootstrapping', 'creating', 'composing',
]);
```

Keep `Str::is()` for user-configured wildcard ignore patterns. `Hypervel\\` already covers Scout;
do not add redundant package prefixes.

Capture the concrete event `Dispatcher` once during both EventWatcher and ViewWatcher registration
because listener introspection is not part of the Events contract. EventWatcher must confirm the
class part of a `Class@method` string exists before calling `class_implements()`; formatted Closure
paths may validly contain `@`. This removes its per-event container resolution and local PHPStan
suppression.

Keep `Factory::observeRendering()`, just as rendering observation is not part of the View Factory
contract. Use current upstream's composer/creator reflection and `FormatsClosure` during an
actually recorded view. Guard the nullable event scope and check it against
`Hypervel\Contracts\View\Factory`. Do not resolve the container per view and do not cache reflection
output. Reflect only Dispatcher wrappers whose captured listener is a Closure; direct string and
array event listeners are not View Factory composer metadata. Cover direct/wildcard composer,
creator, no-scope/global, and direct class-array listener cases. Move the modified test fixture
path from system temp and `uniqid()` to `ParallelTesting::tempDir()`.

Remove `ReflectionProperty::setAccessible()` and PHP 7.4 compatibility guards unsupported by this
repository. Port the current BatchWatcher parameter wording only; do not add runtime behavior.

### 5. Correct stack-package filtering (`telescope-29`)

Replace the inverted/empty local mapping with current upstream's exact contract adapted to the
`hypervel` vendor subtree:

```php
protected function ignoredPaths(): array
{
    return array_merge(
        [base_path('vendor' . DIRECTORY_SEPARATOR . $this->ignoredVendorPath())],
        $this->options['ignore_paths'] ?? []
    );
}

protected function ignoredVendorPath(): ?string
{
    return ($this->options['ignore_packages'] ?? true) ? null : 'hypervel';
}
```

| `ignore_packages` | Ignored vendor path |
|---|---|
| `true` (default) | `vendor/` — every package frame |
| `false` | `vendor/hypervel` — framework frames only |

Delete `shouldIgnoredVendorPath()` and its inaccurate duplicated-word docblock. QueryWatcher and
GateWatcher frame regressions must each discriminate both mappings while retaining configured
`ignore_paths`. The stack traversal and path-comparison count remain unchanged.

### 6. Restore dashboard routing and complete detail results (`telescope-13`, `16`)

Apply the documented domain directly to boot-time route metadata:

```php
Route::domain(config('telescope.domain'))
    ->middleware(config('telescope.middleware', []))
    ->prefix(config('telescope.path'))
    ->namespace('Hypervel\Telescope\Http\Controllers')
    ->group(__DIR__.'/../routes/web.php');
```

The namespace call is load-bearing because `routes/web.php` uses string controller actions. The
default domain is null: RouteRegistrar accepts the attribute as `mixed`, and null applies no host
constraint. Hypervel's fluent chain is the deliberate equivalent of upstream's route-group array;
do not add a conditional domain wrapper. Delete uncalled
`handlingApprovedRequest()` and `requestIsToApprovedDomain()` rather than reviving obsolete
request-start filtering. Tests dispatch matching and nonmatching hosts.

Use `EntryQueryOptions::forBatchId(...)->limit(-1)` in QueueController detail results, matching the
other complete related-entry controllers. The index remains bounded; only explicit detail requests
load the full relation.

### 7. Preserve one schedule row across Console's retained ordering (`telescope-21`)

Do not change Console. `console-25` intentionally dispatches `ScheduledTaskFinished` before
classifying/reporting listener and exit-code failures, and existing tests pin that contract.

Store one schedule reconciliation value in CoroutineContext containing the task identity and queued
`IncomingEntry`. On Finished:

1. return for overlap-skipped work;
2. return for a non-null, nonzero exit code because Failed will own the row;
3. otherwise record `status: finished` and `exit_code`;
4. set the reconciliation value only when strict identity is present in
   `Telescope::getEntriesQueue()`.

```php
try {
    Telescope::recordScheduledCommand($entry);
} finally {
    if (in_array($entry, Telescope::getEntriesQueue(), true)) {
        CoroutineContext::set(static::LAST_RECORDED_TASK_CONTEXT_KEY, [
            'task' => $this->taskIdentity($event->task),
            'entry' => $entry,
        ]);
    }
}
```

The identity scan answers whether recording actually queued the entry across all three unqueued
paths (disabled recording, recursion guard, and filtering). It prevents the normal production
“record failures only” filter from losing the later Failed event. The `finally` also captures an
entry queued before a throwing `afterRecording` hook so Console's resulting Failed event can
reconcile it without swallowing the original exception.

On Failed, if the stored task identity matches and the entry is still queued, mutate that queued
entry's outcome fields to `failed`, including exit code and exception class/message. Otherwise
record a normal failed row. Direct mutation is consistent with Telescope's record-then-amend model
and makes `filterBatch` observe the final content. `afterRecording` has already observed the initial
finished content, as it does for existing later updates; document this in the ledger rather than
adding callbacks or replay machinery.

Add a concise WHY comment on the overlap/nonzero early returns naming
`ScheduleRunCommand::runEvent()` and retained `console-25` Finished-then-Failed ordering. Do not
copy Console positional assertions into this work.

The watcher/UI tests cover:

- ordinary success;
- direct failure;
- nonzero Finished→Failed producing one failed row;
- overlap producing no row;
- successful Finished whose `afterRecording` hook throws producing one mutated failed row with the
  hook exception;
- filtered-out Finished followed by Failed still producing the failed row;
- distinct tasks not reconciling with one another;
- finite queue/storage behavior;
- a real `runInBackground` fork where context and deferred storage live in the child coroutine,
  waiting for the child-owned persisted row before asserting because PHPUnit already runs inside a
  coroutine while production `Command::execute()` drains descendants through its outer `run()`.

Display status on the schedule index and status/exit/exception class/message in preview using the
existing payload. Do not introduce a status abstraction.

### 8. Own and reset Symfony dump handling safely (`telescope-31`)

The current one-time cache check at worker boot means `dump()` remains invisible when the dashboard
becomes active later. Install at most one worker-lifetime wrapper when DumpWatcher is enabled:

```php
if (isset($_SERVER['VAR_DUMPER_FORMAT']) || static::$installed) {
    return;
}

$previous = VarDumper::setHandler(null);

if ($previous === null) {
    return;
}

$wrapper = function (mixed $value, ?string $label = null) use ($previous): void {
    // Record while active; otherwise delegate to $previous with the label.
};

VarDumper::setHandler($wrapper);
```

Implement the wrapper so it:

- accepts and preserves Symfony's optional label;
- records/suppresses immediately when `always` is true without resolving cache;
- performs one activity-cache lookup per explicit dump otherwise;
- records/suppresses while active;
- delegates to the captured callable with label while inactive or if cache lookup throws.

The null-previous guard prevents recursion or swallowed normal dumps in environments where
Foundation did not install an owner. Capturing the prior owner before installing Telescope also
prevents an inactive dump from observing a wrapper whose delegate is not initialized yet.
`VAR_DUMPER_FORMAT` remains Symfony-owned; do not replace it.

`flushState()` must call `VarDumper::setHandler(null)` and clear the installed state. It must not
reinstall the previous closure because tests run after the prior application was destroyed and that
closure may retain dead Testbench paths. Add DumpWatcher to the Telescope group in
`AfterEachTestSubscriber` via the existing `callIfExists` pattern. Do not retain a duplicate static
reference to the wrapper; Symfony owns it.

Tests pin always/no-cache, active record, inactive delegation, cache-failure delegation, labels,
explicit environment ownership, null-previous refusal, no stacking, and reset/re-registration.
Do not add middleware, timers, polling, a request registry, or a dump cache.

### 9. Gate disabled instrumentation at boot (`telescope-30`)

Keep storage registration unconditional. Gate Redis/cache event forcing and Guzzle AOP registration
behind both the master enabled flag and their existing individual watcher settings. Redis/cache
already have local checks; add the missing master condition. Add the equivalent gate at the Guzzle
aspect registration boundary. Extract one small predicate only if it removes real duplicated logic;
do not introduce a watcher registry.

Provider console commands/publishing behavior remains. Tests prove globally disabled Telescope
registers no Redis, cache, or Guzzle instrumentation while enabled individual settings still work.
This is a boot/runtime performance improvement, not added overhead.

### 10. Complete public APIs, typing, and configuration (`telescope-18`, `20`, `28`, `37`, `38`, `39`)

Declare direct split-package requirements:

```json
"ext-json": "*",
"ext-mbstring": "*",
"ext-pdo": "*",
"guzzlehttp/guzzle": "^7.15.1",
"hypervel/di": "^0.4",
"nesbot/carbon": "^3.13.1",
"psr/http-message": "^2.0",
"psr/log": "^3.0",
"symfony/console": "^8.1",
"symfony/http-foundation": "^8.1",
"symfony/http-kernel": "^8.1",
"symfony/mime": "^8.1",
"symfony/var-dumper": "^8.1"
```

Remove unused `hypervel/server`. Add a package metadata test listing every direct requirement and
checking root-aligned constraints; do not build an import scanner.

Remove redundant `null` defaults from queue env reads. Import `App\Models\User` and type the
published gate callback like current upstream, with install-output coverage.

Restore `ClientRequestWatcher::shouldIgnoreHost()` as protected and use strict `in_array()` so
subclasses retain the upstream extension point without loose identifier coercion.

Add only these source-proven types:

- database repository table helper: Query Builder;
- `ExtractsMailableTags::registerMailableTagExtractor()`: `void`;
- Clear/Pause/Prune/Publish/Resume command `handle()`: `void`;
- `EntryResult::$tags`: `array`;
- `JobWatcher::$ignoredJobClasses`: `array`.

Type RecordingController and DumpController against Cache Repository rather than Factory; callers
already pass repositories. Keep DumpWatcher's Factory constructor for upstream compatibility, but
read through `store()->get()` rather than relying on CacheManager's magic forwarding. Do not
broaden unrelated type cleanup.

Port the public Laravel CSP method signature and fluent return, but store the nonce in
CoroutineContext rather than a process-static public property:

```php
protected const CSP_NONCE_CONTEXT_KEY = '__telescope.csp_nonce';

public static function cspNonce(string $nonce): static
{
    CoroutineContext::set(static::CSP_NONCE_CONTEXT_KEY, $nonce);

    return new static;
}
```

Place the protected key with Telescope's existing context constants; nothing outside Telescope
references it. Place `cspNonce()` after `scriptVariables()` and before `flushState()`, matching
upstream's final method order. Adapt return construction to the existing static API shape if current
source uses a different fluent implementation. Render ` nonce="..."` on both dashboard style and
module-script tags from the current coroutine only, escaping the nonce with `e()` at the HTML
attribute sink. Do not clear the context key from `Telescope::flushState()`; the authoritative
`CoroutineContext::flush()` cleanup owns coroutine and non-coroutine context after the test
coroutine ends. Concurrent `parallel()` tasks with an interleaving `usleep()` must prove isolation,
and a quote-bearing nonce must prove attribute escaping. The Laravel-facing method API is preserved;
only unsafe process-static ownership is adapted.

### 11. Add uninstall lifecycle support (`telescope-17`)

Port current upstream's Composer event/action shape using Hypervel's current direct helper:

```php
$events->listen(
    'composer_package.hypervel/telescope:pre_uninstall',
    Actions\UninstallAction::class,
);
```

`UninstallAction` calls
`ServiceProvider::removeProviderFromBootstrapFile('TelescopeServiceProvider')`. Do not copy
upstream compatibility guards for older framework versions. Test against Testbench's disposable
application skeleton and prove unrelated providers remain.

### 12. Update frontend and public documentation (`telescope-19`, `33`, `34`, `35`)

Standardize the repository's release-age policy at seven days. npm measures `min-release-age` in
days, while pnpm measures `minimumReleaseAge` in minutes:

- set the defensive root `.npmrc` to `min-release-age=7` and the root pnpm policy to `10080`;
- set Horizon and Telescope's local `.npmrc` files to `min-release-age=7`, add
  `devEngines.packageManager` requiring npm 11.10 or newer, and delete their misleading local pnpm
  workspace files while retaining their npm locks;
- add the same local npm policy and `devEngines` requirement to Foundation's standalone exception
  renderer.

The repeated npm policy is intentional: subtree splits contain only `src/<package>` and do not
receive root configuration. npm 10 silently ignores `min-release-age`; under npm 11.10 or newer,
the previous value `20160` meant 20,160 days and would block every install. Root pnpm previously
used five days while the npm and standalone pnpm files claimed fourteen, so this change also
removes that drift.

Restore Axios to the upstream-compatible `^1.18` range and refresh Telescope's complete dependency
tree with npm 11.10 or newer using plain `npm update`; the seven-day setting is enforced for update
resolution. Verify every changed resolution is within its declared range and old enough, then
rebuild from source while preserving Hypervel Reverb, Context, and branding adaptations. Review
source, lockfile, all four tracked assets (`dist/app.js`, `dist/app.css`, `dist/styles.css`,
`dist/styles-dark.css`), and the query preview's SQL formatting. Do not hand-edit generated output.

Accept the five residual development-tool advisories without overrides or suppression machinery:
Vue 2 has no fix and Telescope ships precompiled templates; Vite/esbuild affect the unused
development server and require a semver-major Vite upgrade; Nano ID 3.3.17 remains inside the
release-age window. Record that Sass and `sql-formatter` are valid in-range Hypervel lock
resolutions newer than upstream's without treating that lockfile difference as a defect.

In Telescope README add `Documentation: https://hypervel.org/docs/telescope`, followed by:

```md
Ported from: https://github.com/laravel/telescope
```

Correct the existing Boost/public guide for `shouldListenUsing`, deferred recording, Reverb KB
units, and the duplicate model-watcher event line. Keep concise Laravel-style user documentation;
do not document watcher internals or duplicate the guide into README.

Remove the temporary Horizon README Sentinel difference now. Add one central `docs/todo.md` record
for the separately approved Sentinel work:

- port `laravel/sentinel` as `hypervel/sentinel`;
- add direct Horizon/Telescope dependencies;
- prepend `SentinelMiddleware:horizon` and `SentinelMiddleware:telescope` while preserving
  configured middleware;
- remove Horizon's temporary source `REMOVED:` comment;
- add security/dashboard integration coverage.

Do not add temporary Telescope source TODOs or claim unsupported Sentinel behavior in user docs.

Remove the dead hostname comment and any documentation made stale by the final implementation.

## Planned file inventory

| Area | Production/documentation files | Focused tests |
|---|---|---|
| Storage/query | `Storage/DatabaseEntriesRepository.php`, `Storage/EntryModel.php`, `Storage/EntryQueryOptions.php`, `Contracts/EntriesRepository.php`, `Jobs/ProcessPendingUpdates.php` | `Storage/DatabaseEntriesRepositoryTest.php`, `Jobs/ProcessPendingUpdatesTest.php`, route/controller query tests |
| Core recording/API | `Telescope.php`, `IncomingEntry.php`, `EntryResult.php`, `ExceptionContext.php`, `ExtractProperties.php`, `ExtractTags.php`, `ExtractsMailableTags.php` | `TelescopeTest.php`, `ExtractTagTest.php`, new CSP HTTP tests, exception watcher tests |
| Watchers | Request, ClientRequest, Event, View, Job, Reverb, Schedule, Dump, Batch, and `FetchesStackTrace` watcher files | Their existing focused watcher test classes |
| Provider/routes/controllers | `TelescopeServiceProvider.php`, new `Actions/UninstallAction.php`, Queue/QueueBatches/Recording/Dump controllers, config and provider stub | Route, install, disabled-watcher, queue/batch, and uninstall tests |
| UI/frontend | Schedule index/preview Vue files, Telescope manifest/npm policy/lock and generated assets; root pnpm/npm policy; Horizon npm policy/manifest; Foundation renderer npm policy/manifest | Asset/CSP HTTP tests plus source/build/policy verification |
| Cross-package records/docs | `src/boost/docs/telescope.md`, Telescope/Horizon READMEs, `docs/todo.md`, Testing's `AfterEachTestSubscriber.php`, core plan and ledger | `AfterEachTestSubscriberTest.php` and affected documentation/record review |
| Types/commands/metadata | Telescope split manifest and the five command classes | New `tests/Telescope/PackageMetadataTest.php` plus existing command tests |

Paths in the table are relative to `src/telescope/src`, `src/telescope`, or `tests/Telescope` as
appropriate. The inventory is a verification boundary, not permission for unrelated edits.

### 13. Complete audit records

Before implementation, update the core routing index to make Telescope the active package, name
this detail plan, and route `di-02`, `support-02`, `redis-15`, `telescope-01`, `telescope-02`,
`telescope-03`, `telescope-04`, and `queue-22` for revalidation. Do not misuse the cross-package
dependency index for unrelated concurrent work.

After implementation and review:

- add one compact `Complete Telescope correctness, current parity, and watcher lifecycles` ledger
  entry covering `telescope-05` through `telescope-40`, the completed extension of `telescope-04`,
  rejected findings, tests, API/performance effects, and routed revalidation;
- mark Telescope complete in the core checklist;
- remove Telescope from the pending consumer wording of routed entries it has revalidated;
- preserve the latest `0.4` active-work pointer and follow-on disposition, currently Permission
  with its later fresh audit still open;
- do not copy conversational history or the ignored `.tmp` audit report into tracked records.

## Test plan

| Surface | Counterfactual verification |
|---|---|
| Repository writes | Multiple monitored rows survive; invalid UTF-8 updates decode; exception families aggregate once. |
| Repository queries | Explicit UUIDs/empty UUIDs, tag `"0"`, and sequence zero produce exact result sets. |
| Recording lifecycle | Each throwing callback releases recursion protection and preserves exception propagation. |
| Redaction and bounds | Nested falsey secrets are masked; request/Reverb byte boundaries and UTF-8 are exact. |
| Event/view metadata | Corrected prefixes, Closure paths containing `@`, and each composer/creator scope are observed through real callbacks; direct class-array listeners cannot break rendering. |
| Dashboard routes/details | Configured host restricts matching and queue detail returns beyond the default page limit. |
| Schedule watcher/UI | Success/failure/overlap/filter/listener/background-fork cases produce the intended single-row payload and display. |
| Dump ownership | Every ownership/delegation/reset branch is pinned, including labels and a dead prior application. |
| Disabled mode | Master-disabled Telescope installs no Redis/cache/Guzzle instrumentation; enabled watcher settings still do. |
| Jobs/batches | Fail-then-process clears exception/tag; batch lookup neither self-records nor hides unrelated failures. |
| Public API/typing | CSP nonce is concurrently isolated; host override, cache contracts, native types, config, and stub compile/use correctly. |
| Package lifecycle | Metadata constraints align; Composer pre-uninstall removes only Telescope's provider. |
| Frontend/docs | npm 10 is rejected, every project enforces the seven-day policy in its owning package-manager config, the eligible tree builds deterministically, and all four generated assets, query formatting, and public guidance match final APIs. |

Run each changed focused test file after its coherent slice. Run the complete Telescope tests after
the package work is integrated. Run directly affected completed-package tests for `di`, `support`,
`redis`, `queue`, and Horizon Sentinel documentation as applicable. Run frontend install/build only
after its source/manifest update. Run `composer fix` once at the final checkpoint; do not repeatedly
run the full parallel suite during implementation.

## API, performance, and complexity assessment

- No Laravel public signature, named argument, route/configuration key, watcher extension point,
  repository contract, callback contract, or controller response shape is removed. CSP nonce and
  host-ignore parity restore public/protected APIs.
- Dashboard-domain routing, UUID filtering, falsey identifiers, complete batch detail, event/view
  metadata, schedule outcomes, redaction, and uninstall behavior correct existing broken contracts.
- Request byte checks replace `mb_strlen()` with cheaper `strlen()`. Reverb adds no extra traversal;
  it uses byte-safe truncation only when over limit.
- Monitored tags keep one bulk insert. Ordered deletion uses indexed ordering. Exception-family
  aggregation reduces writes. Schedule reconciliation mutates the queued object and avoids a
  SELECT plus UPDATE against a just-inserted row.
- DumpWatcher adds no request middleware or polling. Only explicit `dump()` calls perform one cache
  lookup when not configured `always`; inactive/error cases delegate normally.
- Globally disabled Telescope installs less Redis/cache/Guzzle instrumentation. Individual watcher
  checks remain boot-time.
- Event prefix matching uses one direct prefix check instead of wildcard matching for framework
  prefixes. User wildcard configuration retains `Str::is()`, and EventWatcher no longer resolves
  the event dispatcher per recorded event.
- View reflection occurs only for an enabled, recorded view and uses the dispatcher captured once
  at registration; no per-view container lookup or cache is added.
- JSON flags, null checks, strict comparisons, cleanup `finally`, and failure containment add no
  meaningful successful-path overhead or retained memory.
- CSP escaping runs only while rendering dashboard assets with an explicitly configured nonce.
- Frontend, metadata, documentation, config/stub, uninstall, and maintenance-record work adds no
  application runtime cost.

## Rejected designs and non-findings

- **Runtime JavaScript newline normalization / package `.gitattributes`:** no supported runtime
  defect exists. Composer archives carry committed LF; supported source deployments retain LF.
  Reading and rewriting the 1.7 MB asset on every render is wasteful, and a Telescope-only
  attributes file would omit the same CSS/Horizon concern. Whitespace normalization would also
  corrupt the load-bearing space/tab/newline character class in the bundled Highlight.js PHP
  grammar. Preserve existing checked read failure.
- **Match-expression style parity:** behavior-neutral churn with no correctness, API, maintenance,
  or measurable performance benefit.
- **Change Console schedule ordering:** explicitly rejected. `console-25` intentionally preserves
  Finished-before-classification/reporting and tests pin application-listener failure semantics.
- **Always suppress Failed after Finished:** loses failure telemetry when the Finished row was
  filtered out.
- **Use `EntryUpdate` for schedule reconciliation:** adds a SELECT and UPDATE for a row inserted in
  the same pending batch; direct queued-object mutation produces identical persisted content.
- **Record both Finished and Failed:** duplicates one execution in the dashboard.
- **Listen for `ScheduledTaskSkipped`:** a paused `everySecond()` schedule emits 60 skips during one
  minute/run. Recording pause/filter skips would create high-volume, low-information telemetry;
  upstream overlap also intentionally has no schedule row.
- **Dump polling, middleware, timers, or handler registry:** unnecessary; explicit dumps are the
  only events needing the current activity check and one owned wrapper is sufficient.
- **Restore the prior Dump handler during test cleanup:** it may retain the destroyed application;
  reset Symfony's handler slot and allow the next application to install its owner.
- **Eager monitored-tag loading:** conflicts with Hypervel's verified lazy per-coroutine ownership.
- **Lock exception occurrence counters:** the count is observational telemetry; global serialization
  would add hot contention for no contractually required precision.
- **Where-tag flattening or monitored-tag upsert:** current query/index and one-bulk-insert designs
  are adequate; the extra machinery has no demonstrated benefit.
- **Memory-peak sampling/reset:** PHP exposes the worker high-water mark; a sampling subsystem would
  add recurring overhead and still not reset the native value.
- **Repository finalizers/connection release:** the database abstraction owns leases; Telescope's
  terminator only invalidates coroutine-local monitored-tag state.
- **Import Sentinel during this work:** Hypervel has no corresponding package yet. The approved
  package port and Horizon/Telescope integration are recorded centrally for a dedicated session.
- **One root pnpm lock/catalog or dependency hoisting:** subtree splits do not receive root files,
  so catalogs would break standalone Horizon/Telescope installs and a shared lock would remove
  their reproducible asset-build contract. Keep their npm locks and local policy. Do not add split
  generation machinery, `shamefullyHoist`, or a speculative `publicHoistPattern`.
- **Partial dashboard query validation:** the shipped client supplies the typed filter shapes and
  every public setter already fails fast on malformed input. Do not add guards for only two of the
  five raw filter fields without a complete public validation contract.
- **Schedule reconciliation context clearing:** supported tasks run in finite child coroutines and
  remain retained by the Schedule, so task object IDs cannot recycle during reconciliation. The
  context is destroyed with its task coroutine; do not add redundant mutations to every event.
