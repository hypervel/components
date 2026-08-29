# Worker Lifecycle and Observability Remediation

## Goal

Resolve audit findings 24–25, 33, 48–55, and 103, and prevent command observability from persisting value-bearing console input by default, without changing Laravel-facing APIs unnecessarily or adding work to request hot paths that do not need it. The resulting state must be safe when HTTP requests, jobs, and commands overlap in a long-lived worker, while retaining ordinary non-coroutine CLI behavior.

The separate upstream Sentry SDK proposal is documented at the monorepo root in `_tmp/plans/2026-08-29-0348-sentry-php-concurrent-runtime-contexts.md`. This plan contains only components-repository work.

## Verified problems

1. `Mail\Transport\ArrayTransport` keeps messages in a singleton object's collection. Concurrent executions using the same transport can observe or flush one another's mail.
2. `Notifications\ChannelManager::deliverVia()` and `locale()` always write execution context. Values configured while the application boots disappear before later requests and jobs.
3. `Support\Number::useLocale()` and `useCurrency()` have the same baseline-versus-execution ambiguity. The current `with*` restoration calls the lifecycle-aware setters and therefore cannot restore an exact prior context state.
4. Both Sentry log handlers combine Monolog 3 `LogRecord` objects with Sentry's array-oriented compatibility trait. Batch level filtering compares incompatible types, immutable record enrichment is lost, and exception-context stripping does not affect the emitted record.
5. `Sentry\Hub` has no mutable bootstrap scope. Service-provider boot configuration is applied to a temporary execution scope and is absent from later execution clones.
6. `sentry:test` dereferences nullable internal frame file data.
7. Sentry command integration listens to Symfony command events outside Hypervel's command coroutine. It therefore mutates the wrong execution scope and performs a blocking transport drain at command completion.
8. `HttpPoolTransport` starts sends asynchronously even outside a coroutine, allowing short-lived CLI teardown to outlive an accepted send. Conversely, its blocking drain cannot run directly from the worker-exit callback at coroutine ID `-1`.
9. Sentry SDK 4.21 and later own Hub, log, and trace-metric runtime state under one process key. Sentry Logs and trace metrics are therefore not execution-isolated and are currently unsupported in Hypervel. There is no public SDK seam that components can use to correct this safely.
10. Five Telescope sites JSON-normalize arbitrary observed data with `JSON_THROW_ON_ERROR`. Invalid UTF-8 and non-finite numbers can make observation escape into application code.
11. Telescope invokes `afterStoring` hooks with `Collection::every`; a conventional `void` hook returns `null` and prevents all later hooks from running.
12. `DumpWatcher` consumes dumps whenever its own switch is enabled, even when Telescope is not recording the current execution.
13. Telescope labels process-lifetime `memory_get_peak_usage(true)` as request memory usage.
14. Telescope command recording calls coroutine-only deferred storage from ordinary non-coroutine command paths and has no nesting ownership for `Artisan::call()`.
15. Eloquent `Model::offsetExists()` temporarily disables strict missing-attribute access in a process-global static. If attribute access yields, a sibling execution can bypass strict mode.
16. Telescope stores bound command arguments and options without redaction, while Sentry command breadcrumbs include the raw `ArgvInput` string even when default PII collection is disabled. Credentials supplied through positional arguments or custom value-bearing options can therefore be persisted locally or sent remotely.
17. Telescope's schedule watcher stores the complete opaque command string, including compiled arguments. Shell commands have no bound definition that can classify their values safely.
18. `Schedule::command()` writes an empty Symfony command description after pending attributes have been merged. Undescribed command classes therefore render with an empty summary, erase pending group descriptions, and serialize `""` instead of retaining the event's natural `null` state.
19. Sentry command ownership records only that a command pushed a scope. Another Sentry feature can leave a newer scope on the shared Hub stack, causing command completion to pop a frame it does not own and leave its own scope behind.
20. Sentry scheduled-task tracing finishes its span through `maybeFinishSpan()` but then calls the scope-gated `maybePopScope()` even though this feature never pushes a scope. Sentry Logs and trace metrics are therefore not flushed at each completed scheduled task.

## Design decisions

### 1. Distinguish worker baselines from execution overrides

Laravel-style configuration setters for notifications and numbers remain unchanged at the public API. Their storage target depends on application lifecycle:

- while a concrete `Application` exists and has not finished booting, setters update the worker baseline;
- after boot, setters update the current execution's `CoroutineContext` override;
- standalone use without an `Application` updates the baseline, preserving deterministic CLI and unit use.

Reads use `CoroutineContext::get($key, $baseline)`. There is no coroutine-presence branch and no package-specific boot flag. Hypervel already owns context teardown, so packages do not clear execution overrides from `flushState()`.

This preserves Laravel's effective request behavior in a long-lived worker; it is not a new configuration API.

The baseline window is deliberately service-provider `register()` and `boot()`. `Application::boot()` marks the application booted before it fires application-level `booted()` callbacks, so setters in those callbacks are execution overrides rather than baselines. Document the supported provider boundary instead of adding another application lifecycle flag for that narrow callback window.

### 2. Keep the array mail transport local to transport identity and execution

Store a package-owned message-store object under one `CoroutineContext` key. It contains a `WeakMap<ArrayTransport, Collection>` so:

- messages remain scoped to the exact transport object;
- separate array transports do not collide;
- dead transports are not retained by the non-coroutine fallback context;
- `send()`, `messages()`, and `flush()` use one code path in every runtime.

The store implements `ReplicableContext`. When parent context is copied into a child execution, replication creates a new weak map and clones each collection. The child starts with the parent's snapshot, but subsequent sends and flushes remain isolated, matching Hypervel's array-cache context behavior.

Do not keep the collection on `ArrayTransport`, branch on coroutine presence, use `spl_object_id()`, or introduce a container lifetime marker.

Use `MESSAGE_STORE_CONTEXT_KEY = '__mail.array_transport_messages'` for the store.

Representative shape:

```php
class ArrayTransportMessageStore implements ReplicableContext
{
    /** @var WeakMap<ArrayTransport, Collection> */
    private WeakMap $messages;

    public function messagesFor(ArrayTransport $transport): Collection;

    public function flush(ArrayTransport $transport): Collection;

    public function replicate(): static;
}
```

### 3. Restore Number scopes exactly

Add explicit default constants for `en` and `USD`. `withLocale()` and `withCurrency()` must capture both context-key presence and value, install a temporary override, and in `finally` either restore that exact value or forget the key. They must not restore through `useLocale()` or `useCurrency()`, because those methods intentionally choose baseline versus override from application lifecycle.

`flushState()` resets the static defaults and macros only. Formatting retains its existing direct formatter calls and adds no work beyond one context lookup already performed today.

### 4. Use native Monolog 3 records throughout Sentry handlers

The package requires Monolog 3, so remove `CompatibilityProcessingHandlerTrait` from `SentryHandler` and `LogsHandler`. Preserve public constructors, `handleBatch()`, batch formatter accessors, and the protected `doWrite` extension seam, but make the types native:

```php
public function __construct(
    HubInterface $hub,
    int|string|Level $level = Level::Debug,
    bool $bubble = true,
) { /* ... */ }

protected function write(LogRecord $record): void
{
    $this->doWrite($record);
}

protected function doWrite(LogRecord $record): void;
```

Batch handling must:

1. retain records accepted by `isHandling()`;
2. derive the highest accepted `Level` without int/object comparison;
3. enrich records through `LogRecord::with()`;
4. remove exception data from a local context copy rather than mutating the original;
5. format and submit the batch using the existing formatter contract.

The protected `SentryHandler::getLogLevel()` return type changes from `int` to `Level` because Monolog 3's record model requires it. Existing records remain immutable. Report the batch defect to `sentry/sentry-laravel`, but do not copy upstream SDK work into this plan.

### 5. Give the Hypervel Sentry Hub an explicit bootstrap baseline

Keep the existing Hub constructor and public interface. Add one non-null mutable baseline `Scope`. `configureScope()` applies bootstrap configuration to that baseline before `Application::isBooted()` and applies runtime configuration to the current cloned context scope after boot. Standalone use configures the baseline.

Do not add a Sentry-specific boot flag, configuration replay list, or scope-recloning layer. The baseline remains necessary even if the upstream SDK later gains concurrent runtime-context storage. Reassess custom Hub removal only against a released upstream API that owns all of Hypervel's current scope semantics.

### 6. Align Sentry command and transport ownership with Hypervel execution boundaries

#### Commands

Listen to Hypervel `BeforeHandle` and `AfterExecute`, which run inside the command execution boundary, instead of Symfony `CommandStarting` and `CommandFinished`.

Preserve ordinary handled-command breadcrumb data by adding optional input data to `BeforeHandle` and optional input and final exit-code data to `AfterExecute`. Existing event constructor arguments and listeners remain compatible. `Command` already owns these values at the dispatch sites. Its public IO accessors cannot reproduce the existing `(string) ArgvInput` breadcrumb, so carrying the original input avoids retaining a second pair of outer Symfony listeners solely for metadata. Normalize the exit code with the same valid-range rule used by `Command::execute()` before placing it on `AfterExecute`, preserving today's `ConsoleTerminateEvent` value for out-of-range command returns.

Command breadcrumb input follows Sentry's existing `send_default_pii` policy. Omit the `input` metadata member unless PII collection is enabled and the event carries an `ArgvInput`; opted-in applications retain the existing raw string shape. Do not add a console-wide sanitizer or a second Sentry configuration switch.

When `AfterExecute` carries a throwable, the Sentry listener derives the breadcrumb exit code with Symfony's default `ConsoleErrorEvent` rule: use a non-zero integer throwable code, otherwise `Command::FAILURE`. The runtime integer guard is required because PHP exceptions can carry string codes even though static-analysis stubs narrow them. This preserves the ordinary throwing-command result without putting Symfony error-event policy into Hypervel's event payload.

Normalize the early return used when an isolated command cannot acquire its mutex through the same valid-range rule as every other command result. The event constructors remain backwards compatible: their added execution metadata stays optional because existing callers can construct these public Hypervel events with only the original arguments.

Accept the narrower inner-event boundary deliberately. A command disabled by an outer `ConsoleEvents::COMMAND` listener and an input-binding or other failure before `Command::execute()` produce no Sentry command breadcrumb because neither inner event fires. Later modifications by an outer `ConsoleEvents::ERROR` listener are likewise unavailable to the inner listener. These failures remain exception-reportable. Do not retain duplicate outer listeners or cross-boundary state solely to recreate breadcrumbs for commands whose execution scope never started.

The inner events identify the command execution boundary, not an unconditional coroutine boundary. Commands with `$coroutine = false` dispatch them without a coroutine, so listeners must guard coroutine-only APIs.

Reuse `TracksPushedScopesAndSpans`:

- `BeforeHandle` pushes a scope, sets the command tag, and records the starting breadcrumb with its input;
- after pushing, `BeforeHandle` stores the exact returned scope against the command object in an execution-local `WeakMap<Command, Scope>`;
- `AfterExecute` records the completion breadcrumb from the command input, normalized exit code, and throwable, but removes ownership and performs the non-blocking event flush and pop through `maybePopScope()` only when that exact scope remains current;
- nested `Artisan::call()` receives balanced nested scopes;
- cleanup defers are registered only in a coroutine, while push/pop remains available to ordinary CLI commands.

Keep the empty weak map in context rather than counting and forgetting it on every completion. Weak keys do not retain abandoned command objects, coroutine context ends with its execution, and the non-coroutine fallback belongs to the one-shot CLI process. Recursively executing the same active `Command` object remains unsupported because `Command::run()` overwrites its mutable input, output, components, and exit-code state.

The Hub layer stack is shared by every Sentry feature in one execution, while the tracking trait's counters are feature-local. Command ownership must therefore use scope object identity rather than command nesting depth. The `Scope` returned by `HubInterface::pushScope()` remains the same object stored on its `Layer`; `isCurrentScope()` reads the current frame directly through `HubInterface::configureScope()`, not the integration-gated wrapper. Hypervel commands run after application boot, when that callback receives the current execution frame; an earlier call fails closed by observing the bootstrap baseline. A mismatch keeps ownership and does not flush or pop, allowing a later matching terminal or existing coroutine cleanup to settle the frame without disturbing another feature's scope. Completion breadcrumb behavior remains unchanged.

Change the shared tracking trait only to return the `Scope` already returned by the Hub from `pushScope()`. Queue and scheduling integrations continue using its existing aggregate cleanup semantics.

An unmatched terminal event still records its truthful completion breadcrumb on the current scope. It must not pop a scope owned by another command. This includes an unnamed command and a named command whose earlier `BeforeHandle` listener stopped propagation before Sentry's listener ran.

Command completion performs the non-blocking SDK flush through `maybePopScope()`. Coroutine command execution waits for child sends when its `run()` boundary closes, while non-coroutine sends complete inline in `HttpPoolTransport::createCoroutine()`, so a second bounded drain at command completion is unnecessary.

#### Scheduled tasks

When a scheduled-task terminal event successfully finishes the span owned by `ConsoleSchedulingFeature`, call `Integration::flushEvents()` directly. The non-null `maybeFinishSpan()` result prevents duplicate terminal events from flushing twice. Do not push a scope solely to reuse `maybePopScope()` or add another ownership counter.

This non-blocking flush drains the Sentry Logs and trace-metric aggregators; client events are dispatched immediately and do not wait on this boundary. Both failure sequences flush at the span terminal, which precedes `ScheduleRunCommand`'s own report of the task exception.

#### Transport scheduling

Keep asynchronous scheduling when already in a coroutine. Outside one, run the send owner in `Hypervel\Coroutine\run($callback, Runtime::getHookFlags())` so a yielding pooled HTTP send finishes before a short-lived process tears down:

```php
protected function createCoroutine(callable $callback): void
{
    if (Coroutine::inCoroutine()) {
        Coroutine::create($callback);
        return;
    }

    run($callback, Runtime::getHookFlags());
}
```

The pool checkout remains valid outside a coroutine; do not add a second pool or a synchronous transport implementation.

#### Worker shutdown

An `OnWorkerExit` listener cannot block on a channel at coroutine ID `-1`, and directly wrapping the whole listener in `run()` can consume `max_wait_time` because framework timers remain active until worker-exit dispatch completes.

Create one child coroutine that first waits for `CoordinatorManager::until(Constants::WORKER_EXIT)`. The listener returns, the framework dispatches all remaining worker-exit listeners and resumes that coordinator, then the child performs the bounded event drain and closes the pool in `finally`. This covers telemetry accepted up to the drain beginning. Timer callbacks released by the same coordinator can race with that boundary; do not add settle sleeps, retries, or generation polling.

Report a drain or shutdown failure through Hypervel's exception handler after the pool-close attempt. Do not silently swallow teardown failures; the close remains protected by `finally`.

Hypervel's pooled mail transports remain unchanged. Their object pools isolate coroutine-unsafe Symfony mailer instances and are distinct from the Sentry HTTP transport's send-lifetime correction.

### 7. Mark Sentry Logs and trace metrics unsupported

Keep the existing Sentry Logs channel, handlers, SDK configuration, and buffer settlement wired and tested so the Laravel/Sentry APIs work and can adopt an upstream fix without another API change. This does not make their SDK-owned buffers safe: Sentry Logs and trace metrics are currently unsupported in Hypervel because `RuntimeContextManager` hard-codes one process context.

Add prominent warnings to `src/docs/sentry.md` and beside the existing Logs and metrics options in `src/sentry/config/sentry.php`. State the limitation without describing Hypervel's execution model as optional. Normal Sentry errors, events, breadcrumbs, and transaction tracing remain supported. Keep the existing config keys wired, but default trace metrics to disabled while their aggregator is unsafe. Explicit opt-in remains available under the existing unsupported-feature warning. The Logs-only threshold and channel-level options must be labeled accordingly.

Do not recommend a flush threshold of `1`, add a metric-threshold option, or present any other local workaround. Do not add reflection, locks, global-function shims, custom SDK internals, or a second aggregation layer.

The upstream API design and contribution are wholly specified by `_tmp/plans/2026-08-29-0348-sentry-php-concurrent-runtime-contexts.md` and are not implementation work in this plan.

### 8. Normalize only Telescope's unsafe JSON boundaries

Add one package-local JSON normalizer and use it at the five verified arbitrary-value round trips:

- `Watchers/EventWatcher.php`;
- `ExtractProperties.php` at both encoding sites;
- `Watchers/RequestWatcher.php` at both encoding sites.

Encode with `JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR` and `Json::MAXIMUM_NESTING_DEPTH`, decode successful JSON through the same declared limit, and return Telescope's canonical purged value if serialization or decoding fails. This includes excessive nesting and exceptions thrown by `JsonSerializable` values. Add `Telescope::PURGED_VALUE` as the single source for the exact `Purged By Telescope` sentinel. The normalizer returns that constant to consumers such as `ExtractProperties`; replace existing source-code literals only in `RequestWatcher`, `ClientRequestWatcher`, and `DatabaseEntriesRepository`, with prefixed exception variants concatenating the same constant. Leave the explanatory configuration and documentation prose literal. Do not build a recursive object-graph serializer.

Valid payload shapes remain unchanged. Partial native output for values such as `NAN` is accepted because observation must not throw into application code.

### 9. Make Telescope storage and command ownership explicit

Replace `Collection::every` for `afterStoring` hooks with an ordered `foreach`. `void`, `null`, and `false` returns do not stop later independent hooks. A thrown exception retains the existing outer catch/report behavior and stops later hooks.

For command recording, maintain an execution-local approved-command depth:

- approved `BeforeHandle` increments depth and starts recording at the outer transition;
- matching `AfterExecute` decrements it;
- the outer transition to zero stores entries and then stops recording;
- nested `Artisan::call()` cannot prematurely store or stop its parent command;
- listener registration order must allow `CommandWatcher` to record the outer command's `AfterExecute` entry before storage. `TelescopeServiceProvider::boot()` establishes this by registering watchers before storage opportunities; pin that fact with a behavior test and a focused comment.
- `telescope:clear` remains a built-in ignored command so its terminal event cannot repopulate the storage it just cleared. Other Telescope commands remain recordable; in particular, a new `telescope:prune` entry does not restore any stale entry that the command removed.

Store the depth under `COMMAND_DEPTH_CONTEXT_KEY = '__telescope.command_depth'`.

Before storing a command entry, redact structured input by slot rather than by name:

- replace every non-null positional argument with `Telescope::REDACTED_VALUE`;
- replace every non-null option value when its current command definition accepts a value;
- replace arrays with one marker rather than retaining contents or element counts;
- preserve null, value-less flags, and negatable option state;
- treat an option absent from the current definition as value-bearing so observation cannot expose it or fail the command.

This deliberately redacts declared defaults as well as supplied values because command definitions may construct defaults from configuration or environment data, and Symfony's bound input arrays do not distinguish the two. Keep the real event input unchanged. Use the same public redaction constant in Telescope's existing request, client-request, and cache masking paths, and move the byte-identical request `hideParameters()` implementations to the shared watcher base.

Scheduled command strings are opaque and cannot be parsed safely. Keep the description as its own field. The command field uses `Closure` for callback events and `Scheduled command` for every non-callback event. Never persist `Event::$command` in Telescope.

Guard `Coroutine::defer()` in `Telescope::record()` and `Telescope::store()` with `Coroutine::inCoroutine()`. Non-coroutine `store()` calls `executeStore()` directly. Remove the per-call fallback default from `config()->boolean('telescope.defer', true)` because the shipped configuration key is required. Coroutine command defers remain terminal safety and find an empty queue after the normal terminal event.

The shipped config comment must describe `telescope.defer` as required rather than promising behavior when the key is omitted. Scheduler coverage keeps the distinct-batch assertion with the configured `true` value instead of deleting the required key.

### 10. Delegate dumps unless Telescope will actually record them

The dump handler first checks `Telescope::isRecording()`, then checks the watcher switch. If either fails, call the previous handler. Only serialize and consume a dump when Telescope records it. The recording check comes first to avoid an unnecessary cache query in ignored executions.

### 11. Describe Telescope memory as process telemetry

Keep the `memory` payload key and `memory_get_peak_usage(true)`. Change the UI and documentation label to `Worker memory peak`. Do not add request-start snapshots or deltas: concurrent allocations make those values neither request peaks nor reliably attributable.

Rebuild the committed Telescope distribution from the package's documented frontend command and include only generated artifacts caused by the label change.

### 12. Suppress strict missing-attribute checks only in the current execution

When strict missing-attribute access is disabled, `Model::offsetExists()` remains the current direct `getAttribute()` path with no context work.

When enabled, save the execution-local suppression boolean, set it, call `getAttribute()`, and restore the exact prior value in `finally`. This is the same nesting-safe pattern used by `withoutBroadcasting()` and `withoutTouching()`. `HasAttributes::throwMissingAttributeExceptionIfApplicable()` consults the boolean only after its existing early exits and before the exceptional missing-attribute branch:

```php
if (! static::preventsAccessingMissingAttributes()) {
    return ! is_null($this->getAttribute($offset));
}

$wasSuppressed = CoroutineContext::get(self::MISSING_ATTRIBUTE_ACCESS_SUPPRESSED_CONTEXT_KEY, false);
CoroutineContext::set(self::MISSING_ATTRIBUTE_ACCESS_SUPPRESSED_CONTEXT_KEY, true);

try {
    return ! is_null($this->getAttribute($offset));
} finally {
    CoroutineContext::set(self::MISSING_ATTRIBUTE_ACCESS_SUPPRESSED_CONTEXT_KEY, $wasSuppressed);
}
```

Application boot state is irrelevant: this is temporary execution state, not a configurable default.

Use `MISSING_ATTRIBUTE_ACCESS_SUPPRESSED_CONTEXT_KEY = '__database.model.missing_attribute_access_suppressed'` for the model flag.

### 13. Preserve natural descriptions for scheduled command classes

When `Schedule::command()` resolves a command class, apply its description only when `getDescription()` is non-empty. This preserves the event's natural `null` description and pending group description for undescribed commands while retaining today's precedence for described command classes. Explicit userland `Event::description('')` remains supported. No mutex or email behavior changes: command-event mutexes exclude descriptions, and email subjects already treat `null` and an empty string identically.

## Implementation sequence

1. Add the array mail message store, migrate `ArrayTransport`, and cover identity, flush, replication, and concurrent isolation.
2. Make notification defaults and Number defaults lifecycle-aware; add restoration and execution-isolation coverage.
3. Port both Sentry handlers to native Monolog 3 records and correct `sentry:test` nullable-frame formatting.
4. Add Hub baseline behavior, align command scope ownership with the exact current Hub frame, and flush scheduled-task aggregators when an owned span finishes.
5. Correct non-coroutine send lifetime and coordinator-aware worker shutdown.
6. Add the Telescope JSON normalizer and canonical purged sentinel.
7. Correct Telescope hook iteration, command storage and redaction, scheduled-command disclosure, dump delegation, and memory labeling; rebuild frontend assets.
8. Preserve natural descriptions and pending group descriptions for scheduled command classes.
9. Replace Eloquent's process-global strictness toggle with context-local suppression.
10. Update only the user documentation needed to explain the resulting behavior.

Each slice is tested before proceeding. If implementation exposes a non-trivial unplanned invariant, stop edits, trace the complete slice, obtain a second opinion, and replace the affected plan wording before resuming.

## Test plan

### Mail

- Same transport: messages and flush are isolated between forced concurrent executions.
- Separate transports in one execution retain separate collections.
- Non-coroutine behavior remains deterministic.
- Replicated child context sees the initial snapshot, then child and parent sends/flushes diverge.
- Releasing a transport does not leave it retained by the weak map.

### Notifications and Number

- Provider register/boot defaults reach later request and job executions.
- Runtime overrides do not leak to siblings or later executions.
- Explicit per-notification locale still wins.
- Standalone setters update the baseline.
- Nested `withLocale` and `withCurrency` restore absent and present keys after success and exceptions.
- `Number::flushState()` resets its baselines and macros without clearing unrelated context; a newly constructed notification manager starts from its declared defaults while `ChannelManager::flushState()` remains macro-only.

### Console

- Undescribed command classes keep a `null` event description, fall back to their command summary, and retain pending group descriptions; non-empty command descriptions continue to override pending group descriptions.

### Sentry

- Native single-record and batch filtering, highest handled level, immutable enrichment, exception extraction, and formatter accessors for both handlers.
- Sentry Logs and trace metrics remain wired and mechanically covered while their unsupported status is documented without changing config behavior.
- Bootstrap Hub scope survives into cloned execution scopes; sibling mutations, nested pushes, and pops remain isolated.
- `sentry:test` handles internal frames with null file information.
- Inner command events preserve their existing constructor usage, carry the original input, and expose the same normalized status returned by `Command::execute()`, including isolated-command mutex rejection; Sentry applies Symfony's default throwable-code rule for integer and non-integer exception codes. Scope/breadcrumb ownership covers success, failure, ordinary nesting, duplicate terminal events, direct no-push completion, an unnamed nested completion, and a named completion whose `BeforeHandle` propagation stopped before Sentry, including the non-coroutine unnamed path.
- A command terminal cannot pop a newer scope left by another Sentry feature. The completion breadcrumb remains on the current scope, ownership is retained on mismatch, and the same command can settle its preserved scope once it becomes current.
- Scheduled-task success and failure each flush buffered Logs and trace metrics once after finishing their span; duplicate completion and the real Finished-then-Failed sequence do not flush twice. The isolated client emits no transaction event while tracing is disabled.
- Command breadcrumbs omit raw input while default PII collection is disabled and retain the existing `ArgvInput` string only after explicit PII opt-in.
- Disabled commands, pre-execute failures, and outer Symfony error-listener rewrites stay outside the inner breadcrumb boundary without duplicate outer listeners.
- Command completion does not wait for an unrelated accepted send.
- Non-coroutine send completes before `send()` returns; coroutine send remains asynchronous; pool release/discard and wait-group accounting remain balanced on success and failure.
- Worker-exit listener returns before core coordinator release, then drains and closes from its child; flush failure still closes the pool and is reported; later worker-exit listeners can emit telemetry before the drain begins.
- Keep focused tests for pooled Sentry mail transport ownership unchanged.

### Telescope

- Invalid UTF-8, non-finite numbers, valid values, excessive depth, and throwing serializers at the shared JSON boundary.
- Canonical purged sentinel is used consistently.
- Multiple `afterStoring` hooks run in order after `void` and `false`; thrown-hook reporting remains pinned.
- Dumps delegate when not recording or disabled and record exactly once when active; ignored executions avoid the cache lookup.
- Non-coroutine commands store without calling coroutine defer; nested commands store only on the outer terminal event; the command entry is included; `CommandWatcher` consumes the event payload instead of closure-rebinding the command for the same data.
- Command entries retain names, exit codes, nulls, and non-value flag state while redacting positional values, defaults, arrays, custom value-bearing options, and unknown option keys with the canonical marker.
- Scheduled entries never persist the opaque command line; explicit descriptions remain visible and undescribed commands use the stable generic label.
- `telescope:clear` leaves storage empty after its terminal event.
- Coroutine command terminal safety does not duplicate storage.
- Request UI and source docs identify the value as worker peak memory; rebuilt assets contain the same label.

### Eloquent

- Disabled strict mode takes the direct path.
- `isset()` suppresses only its own missing-attribute check.
- Forced concurrent interleavings cannot suppress a sibling's violation or leave suppression behind.
- Nested `isset()`, yielding relation/accessor paths, exceptions, and custom missing-attribute callbacks restore the exact prior boolean.

### Repository verification

- Run focused PHPUnit suites while developing each slice.
- Run package-local frontend checks and rebuild Telescope assets after the UI change.
- Run `composer fix` from the components root and retain its formatter, static-analysis, and parallel-test result.
- After green checks, trace every changed caller and callee and review for stale code, unnecessary branches, and hot-path regressions before code review.

## Documentation

- `src/docs/helpers.md`: explain Number defaults configured from provider `register()`/`boot()`, execution-local overrides, and why application `booted()` callbacks are too late to set a worker default.
- `src/docs/notifications.md`: explain delivery/locale defaults configured from provider `register()`/`boot()`, execution-local overrides, and why application `booted()` callbacks are too late to set a worker default.
- `src/docs/mail.md`: explain that array-transport messages are scoped to the current execution and become an isolated snapshot when parent context is copied into a child execution.
- `src/docs/artisan.md`: document the original input and normalized exit-code data carried by the inner command events.
- `src/docs/sentry.md`: document command and worker flushing semantics, explain that command input requires explicit PII opt-in, and prominently warn that Sentry Logs and trace metrics are currently unsupported in Hypervel; distinguish them from supported errors, events, breadcrumbs, and transaction tracing, and state that the upstream-compatible metrics default must be disabled explicitly.
- `src/docs/telescope.md`: call request telemetry `Worker memory peak`, state that command input values are redacted, remove the Command Watcher's false output claim, and describe scheduled-task fields without exposing opaque command lines.

Do not add porting-guide entries for Number or notifications: their APIs and observable application behavior remain Laravel-compatible. Likewise, do not add internal Telescope or Sentry implementation details that require no application action.

## Expected files

Source changes are expected in:

- `src/mail/src/Transport/ArrayTransport.php` and one mail-owned message-store class;
- `src/notifications/src/ChannelManager.php`;
- `src/support/src/Number.php`;
- `src/console/src/Command.php`, `Events/BeforeHandle.php`, `Events/AfterExecute.php`, and `Scheduling/Schedule.php`;
- Sentry handlers, both Sentry `LogChannel` factories, config, Hub, console integration, console scheduling feature, queue feature, transport, worker listener, shared tracking trait, and test command;
- Telescope core, watcher base, lifecycle trait, `CommandWatcher`, `ScheduleWatcher`, JSON-observing watchers including `ClientRequestWatcher`, `Storage/DatabaseEntriesRepository`, dump watcher, request and schedule UI, and generated distribution;
- `src/database/src/Eloquent/Model.php` and `Concerns/HasAttributes.php`.

Tests remain in the owning package suites under `tests/Mail`, `tests/Notifications`, `tests/Support`, `tests/Sentry`, `tests/Telescope`, and `tests/Database`. Documentation is limited to the files listed above. Exact generated Telescope asset names are determined by the package build.

## Completion criteria

- Every identified leak, lost callback, unsafe boundary, or misleading label has behavior coverage.
- Value-bearing command input is absent from default Sentry breadcrumbs and Telescope command input fields; opaque command lines are absent from Telescope schedule entries.
- Laravel-facing method names, arguments, return values, and ordinary behavior remain compatible except for the documented long-lived-worker semantics and the protected Monolog 3 level type.
- Request hot paths remain direct where no isolation is needed; required context work is constant-time.
- No reflection, polling, sleeps, process-global locks, framework-specific SDK patch, recursive serializer, or duplicate lifecycle registry is introduced.
- `composer fix` passes and the final review finds no dead plan residue, stale implementation path, or duplicated workaround.
