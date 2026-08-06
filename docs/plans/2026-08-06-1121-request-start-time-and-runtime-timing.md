# Request Start Time and Runtime Timing Correctness

## Scope

Replace the process-entry `HYPERVEL_START` constant with request-owned timing, expose one small Hypervel-native request API, migrate every semantic first-party consumer, repair the health view, and remove the stale command-lifecycle assumptions uncovered by the same audit. The implementation spans this components worktree and the separate `contrib/hypervel/hypervel` application skeleton repository.

This plan is the implementation source of truth. Before implementation, reread `AGENTS.md` and this file in full. Work from the components worktree root unless a step explicitly names the application skeleton repository.

## Desired outcome

- Every `Hypervel\Http\Request` has an immutable-by-value start instant that belongs to that request rather than to its worker process.
- Developers retrieve that instant through `$request->startedAt()` as a `Hypervel\Support\CarbonImmutable`.
- `REQUEST_TIME_FLOAT` and `REQUEST_TIME` remain available through the existing server bag, with PHP/Symfony-style uppercase names.
- The HTML health response always reports the current request's render duration accurately in long-lived workers and in Testbench.
- Telescope and Sentry consume the request API rather than reading transport details or a global constant.
- `HYPERVEL_START` disappears from executable framework/application behavior without a deprecated alias, fallback, or replacement global.
- Related Telescope command decisions use dispatched command identity rather than positional process arguments, scheduled tasks execute in finite task-owned coroutines with task-local observability state, and all three shipped CLI entrypoints use one shared Symfony-definition-backed resolver instead of unbound input scans or raw `argv[1]` for their pre-bootstrap server-mode check.
- No unrelated time API changes: `now()`, the Date facade, Carbon, scheduler clocks, and the existing kernel/console lifecycle timers continue to behave as they do now.

## Goals and invariants

- Store request-specific state on the request object. Never put it in a global, constant, static, singleton property, or new coroutine-context slot.
- Preserve Laravel's familiar Carbon-facing style while adapting to Hypervel's long-lived Swoole runtime.
- Preserve `Request::server()` and `$request->server->all()` as the complete server-metadata API. Do not expose the raw Swoole request or duplicate its metadata behind another abstraction.
- Normalize absent standard request-time server keys at the request boundary, but preserve values supplied by a supported transport or test.
- Keep `Request::startedAt()` distinct from `Http\Kernel::requestStartedAt()`: the former is transport/request creation time and remains readable for the request object's lifetime; the latter is the later kernel-handle time used by lifecycle threshold callbacks and is cleared during termination.
- Keep `Console\Kernel::commandStartedAt()` behavior unchanged. It is command-kernel lifecycle state, not an HTTP request clock or a per-scheduled-task clock.
- Keep WebSocket semantics honest: the Request object and its start time describe the HTTP upgrade/handshake, not each later WebSocket frame.
- Preserve the existing RequestBridge uppercase conversion for all server values and `HTTP_` header normalization.
- Remove dead branches, imports, configuration, comments, tests, and documentation made obsolete by the final design.
- Preserve the unrelated FacadeDocumenter fixture whose intentionally awkward class/constant name is `HYPERVEL_START`; it tests PHP import grammar and is not the framework constant.

## Anti-overengineering and performance rules

- Add exactly one public request-timing API: `Request::startedAt()`. Do not add a request-start service, facade, contract method, raw timestamp accessor, middleware, event, DTO, transport wrapper, or metadata registry.
- Keep one protected float on each Request. Do not eagerly allocate Carbon for every HTTP, gRPC, WebSocket, and Reverb request; construct it only when the accessor is called.
- Do not add caching merely to promise Carbon object identity. The contract is the instant's value, not whether repeated calls return the same object.
- Do not add a `REQUEST_TIME` integer fallback. Supported Swoole HTTP/1 and HTTP/2 paths and Symfony's synthetic-request factory provide `REQUEST_TIME_FLOAT`; direct construction needs only the precise `microtime(true)` fallback. Falling back to integer seconds is an unreachable production branch that destroys useful duration precision.
- Do not validate, clamp, reconcile, or invent policy for deliberately malformed server values. Cast the supported `REQUEST_TIME_FLOAT` input to float at the owning boundary.
- Preserve a supplied server-bag value with `??=` rather than rewriting it merely to normalize its PHP scalar type. The Request-owned property is always the canonical float; supported Swoole and Symfony inputs are floats already.
- Do not synchronize the stored instant after arbitrary later mutation of the public server bag. The instant is captured during initialization and is stable for that Request object's lifecycle.
- Do not special-case a caller that deliberately removes the server timestamp before `createFrom()` / `createFromBase()`. Ordinary conversions naturally propagate the normalized server values; contrived stripping gets normal fallback behavior.
- Do not add real network/Swoole timing tests. The bridge contract and application-backed health tests cover the owned boundaries deterministically; Swoole's own timing placement is source-proven.
- Keep scheduler-owned interruption, pause, and maintenance gates in the scheduler coroutine. Give user filters and each actual scheduled-task invocation a finite coroutine boundary, including foreground execution and background dispatch. Preserve sequential foreground behavior and existing background concurrency; do not add a scheduled-task timing API, reset framework, subprocess wrapper, or Telescope-specific scheduler hook.
- Keep the long-lived `schedule:run` command coroutine outside Telescope recording. Start observability inside each task coroutine so queues, defers, spans, and batch identity share the task's real lifecycle.
- The coroutine and channel allocation per due scheduled invocation is deliberate lifecycle ownership on a low-frequency scheduler path, not request hot-path overhead. Do not add pooling or reuse machinery.
- Do not add an entry to `docs/ai/differences-vs-laravel.md`; that file explicitly says it is queued for deletion and forbids new entries.
- Resolve pre-bootstrap command names by binding Symfony's authoritative default application definition plus Hypervel's global `--env` option, following Symfony's own `Application::doRun()` flow. Do not hand-build a parallel option definition, special-case argv tokens, or cache mutable definitions.
- Churn and compatibility with pre-0.4 code do not justify retaining an inferior shape. Conversely, do not expand the work beyond verified request/command lifecycle defects discovered by this audit.

## Research and settled facts

### Repositories and baselines

- Components implementation baseline: this worktree's `feature/request-start-time` branch, created from components `0.4` at `457fa11af`.
- Application skeleton: `contrib/hypervel/hypervel`, current `0.4`; `artisan:9` is the only semantic skeleton definition of `HYPERVEL_START`.
- Laravel framework reference: `examples/laravel/framework`, current local `13.x`.
- Laravel application skeleton reference: `examples/laravel/laravel`; both `public/index.php:6` and `artisan:7` define `LARAVEL_START` at entry-script startup.
- Swoole reference: upstream `swoole/swoole-src` tag `v6.2.2`, commit `8e8c49915ca5`, cloned at `/tmp/hypervel-swoole-source.w8U8GW` for this audit.
- Broad searches found no semantic `HYPERVEL_START` use in `packages/hypervel`, `packages/hypervel-dev`, or their applications.

### What Laravel's constant means

Laravel's `LARAVEL_START` is an entry-script timestamp. Under ordinary PHP-FPM, `public/index.php` runs for each request, so that process-entry timestamp is also a useful approximation of that request's application start. `artisan` similarly records the start of one conventional Artisan invocation. The constant is not a Carbon/global clock implementation and does not power `now()`, the Date facade, Carbon parsing, or PHP time functions.

Laravel does not expose a Request start-time accessor. Its current health template still reads `LARAVEL_START`, guarded for runtimes where it is absent. Framework PRs `#50012` and `#50018` added and corrected that guard for Octane; they did not create a per-request replacement. Laravel Telescope PR `#664` added a `REQUEST_TIME_FLOAT` fallback specifically for long-lived/custom entry points. Laravel framework PR `#44122` added the separate kernel request-lifecycle duration hook that Hypervel already ports.

The correct conclusion is not to copy Laravel's entry constant into each Swoole request. The Carbon return type and method naming are Laravel-like; owning the timestamp on Request is a Hypervel enhancement required by its runtime model.

### Why `HYPERVEL_START` is wrong in Hypervel

The skeleton added the constant in unreleased 0.4 commit `2915dce`; it is absent from `v0.3.19`. The health template was ported earlier in components commit `193db2a30`, while Testbench later defined the constant solely to make its health assertion pass.

Hypervel's `artisan` process can remain alive while `serve`, `watch`, or the default `schedule:run` loop handles later work. One process-entry float therefore measures worker/scheduler uptime, not a request or task. In a server process, every later health response and any fallback consumer sees an ever-growing duration. Removing the constant loses no valid per-request capability because it never had request ownership in this architecture.

The existing timers remain valid at their narrower boundaries:

- `Foundation\Http\Kernel::handle()` writes a coroutine-local Carbon start for kernel lifecycle duration handlers. It begins after the Request/bridge boundary and is cleared by `terminate()`.
- `Foundation\Console\Kernel::handle()` stores command-kernel start time and clears it during console termination.
- `Console\Commands\ScheduleRunCommand` owns its own loop/task clocks. Scheduled Artisan events call `Kernel::call()` in-process, so an outer process/command constant is not a per-task clock.

### What Swoole supplies and when

Swoole's server array includes, as applicable, `request_method`, `request_uri`, `path_info`, `request_time`, `request_time_float`, `server_protocol`, `server_port`, `server_addr`, `remote_port`, `remote_addr`, `master_time`, and `query_string`; request headers are exposed separately.

The timing fields do not all mean the same thing:

- HTTP/1 adds integer `request_time` and floating `request_time_float` in `ext-src/swoole_http_request.cc:471-472`, when the worker parses the already-dispatched request buffer and reaches headers-complete.
- The reactor waits for the complete fixed-length or chunked body before dispatching that buffer (`src/server/port.cc`, the body-length/EOF checks before `dispatch_request`). A slow upload therefore is not included in the HTTP/1 start timestamp.
- HTTP/2 adds both request times immediately before the request callback in `ext-src/swoole_http2_server.cc:294-295`.
- `master_time` is `(zend_long) conn->last_recv_time`, a connection-level integer-second value. It is neither precise enough nor semantically correct as a request-start replacement.

For every valid supported Swoole HTTP/1 or HTTP/2 callback, `request_time_float` is present. The fallback in Request exists for direct `new Request(...)`, custom/test construction, and defensive completeness of the public constructor—not because normal Swoole traffic is expected to omit the field.

### Why uppercase server names are correct

`HttpServer\RequestBridge::transformServerParams()` uppercases every Swoole server key and maps headers to PHP/Symfony `$_SERVER` conventions. Thus Swoole's `request_time_float` correctly becomes `REQUEST_TIME_FLOAT`, just as `remote_addr` becomes `REMOTE_ADDR`. Special content headers remain `CONTENT_TYPE` / `CONTENT_LENGTH`.

The common bridge feeds four first-party server paths:

- `src/http-server/src/Server.php`
- `src/grpc/src/Server/Server.php`
- `src/websocket-server/src/Server.php`
- `src/reverb/src/Servers/Hypervel/HttpServer.php`

All normalized Swoole fields already remain available through `$request->server('MASTER_TIME')` or `$request->server()` / `$request->server->all()`. A second metadata API would be redundant and transport-coupled.

### Current consumers and failures

| Consumer | Current behavior | Final owner |
|---|---|---|
| Foundation health Blade | Reads process constant when defined; otherwise hides duration | Inject the current Request and call `startedAt()` |
| Testbench Workbench health route | Defines the process constant in the test class | Inject/pass current Request; delete fixture constant |
| Telescope RequestWatcher | Reads `REQUEST_TIME_FLOAT` and has a nullable/positive fallback branch | Call non-null `Request::startedAt()` |
| Sentry tracing middleware | Reads server value, then process constant, then `microtime(true)` | Call `Request::startedAt()` and convert precisely for Sentry |
| Boost collections guide | Uses process start to create a scheduled-task deadline | Use `now()->plus(minutes: 14)` at task invocation |
| Application skeleton `artisan` | Defines `HYPERVEL_START` once for a possibly long-lived process | Delete the definition |

### Why tests did not catch the health defect

- The main application-backed health test asserts only status/text such as `Application up`; with no constant, the Blade guard silently omits the duration line.
- `tests/Testbench/Workbench/DiscoversTest.php` defines `HYPERVEL_START` once in `setUp()` and only asserts that `Response rendered in` exists. It neither verifies the number nor issues discriminating requests with different start times.
- The application skeleton is a separate repository, so components tests do not run through its real long-lived `artisan serve` process.
- Telescope reads the correct server field already, while Sentry's fallback masks missing/incorrect setup. No test asserts either consumer's exact start timestamp.

The new tests must assert exact deterministic values and consecutive-request independence, not merely the presence of timing text.

### Related console, Telescope, scheduler, and Sentry findings

The same audit found several live command-classification defects, stale ignore entries, and one stale config surface:

- `ScheduleWatcher::register()` decides at provider boot from `$_SERVER['argv'][1]`, recognizes nonexistent `crontab:run`, and misses programmatic/global-option-safe command identity.
- `Telescope::runningApprovedArtisanCommand()` also reads `argv[1]` even though its `BeforeHandle` listener receives the resolved `Command` instance.
- The ignored list contains nonexistent/unreachable `start` and `serve`. `ServerStartCommand` extends Symfony Command directly and does not dispatch Hypervel's `BeforeHandle`; `WatchCommand` does dispatch it and must remain ignored.
- The live Composer command `package:discover` is absent from Telescope's default ignore paths.
- Sentry publishes `sentry.ignore_commands`, but no source in current Hypervel or v0.3 consumes it. It is dead configuration, not an incomplete feature contract.
- Both the canonical and Testbench artisan templates use raw `$_SERVER['argv'][1]` to enter HTTP mode for `serve` / `watch`, although both already create `ArgvInput`. Supported forms such as `--env=production serve`, `-v serve`, and `--ansi watch` therefore miss the mode switch and hit the misleading `APP_RUNNING_IN_CONSOLE is true` server guard.
- Testbench's separate `bin/testbench` entrypoint creates one unbound `ArgvInput` in `Console\Commander`, classifies `serve` before application boot, and then passes the same input to the Kernel. Its direct `getFirstArgument()` check likewise mistakes the separated `--env` value for the command.

These are included because they are verified manifestations of the same process-entry/argv lifecycle assumption. Do not generalize this into a console subsystem rewrite.

The same positional defect exists on the public `Foundation\Application::runningConsoleCommand()` / `App` facade API. It is a faithful Laravel port but misclassifies a supported invocation such as `artisan --env=production migrate`. The maintainer approved correcting that behavior as an intentional Hypervel improvement. `src/testing/src/Console/TestCommandBase.php` separately slices argv from offset two to forward test-runner options; that is not command classification and is not part of this work.

A source/test search found no first-party production caller of `runningConsoleCommand()`: only its public contract declaration, facade annotation, implementation, and focused tests exist. The correction therefore has no internal framework call-site blast radius and improves only the behavior promised by the existing public contract.

The implementation audit then found five scheduler-observability defects behind the old ScheduleWatcher gate:

- `Dispatcher::listen()` is boot-only worker state. Telescope can register the scheduled-task listeners unconditionally when the enabled watcher boots, as Sentry already does.
- `ScheduleWatcher` force-starts recording on `ScheduledTaskStarting`, overriding an explicit `telescope.ignore_commands: ['schedule:run']`. Conversely, ordinary `BeforeHandle` starts recording in the never-ending scheduler command, so cache polling and any other observed daemon work accumulate until process exit.
- Telescope's default deferred store runs at coroutine exit. Foreground tasks currently execute inline in the daemon coroutine, so task entries are not persisted while the daemon runs and its queue/defer state grows indefinitely.
- `repeatEvents()` bypasses the background branch used by `runEvents()`. A `--once` sub-minute event configured for background execution therefore runs its first invocation in the background and later invocations synchronously.
- The scheduler dispatches `ScheduledTaskFinished` before converting a non-zero exit code to `ScheduledTaskFailed`. ScheduleWatcher records both events identically, while Sentry finalizes the transaction as successful on the first event and cannot correct the already-finished span on the second.

The root correction is a finite coroutine around each task's user filters and execution, a non-recording daemon, task-local recording from the storage-opportunity layer, one shared foreground/background dispatch method, duplicate outcome suppression in ScheduleWatcher, and finalized-exit-code status in Sentry. Scheduler-owned pause and maintenance gates remain in the scheduler coroutine. This is not a Swoole defect and requires no workaround.

The implementation gate exposed why that ownership split is required. Testbench's default `array` cache stores values in coroutine context, so a task child copying only Log Context cannot observe a pause flag written in its scheduler parent. Cache-backed maintenance mode has a separate mechanism: `WorkerCachedMaintenanceMode` clears a worker-wide static snapshot after the parent writes its coroutine-local array store, and whichever coroutine reads next repopulates that shared snapshot. A task child reading first can publish a false local result to the whole worker. Production cache-backed maintenance mode requires a store accessible by every server, so no maintenance-specific workaround or broader context copy belongs in the scheduler; the framework test's array store remains useful for proving that scheduler control checks stay with their owner.

The same audit found that `Event::shouldRepeatNow()` passes null into `abs()` before a repeatable event has ever reached `filtersPass()`. Under strict types this throws a `TypeError`; `schedule:run --once` reaches it when the schedule is already paused and a repeatable event is due. The public owning method must return false until `lastChecked` exists. Paused repeatable events must also advance `lastChecked` when skipped, so their public skipped event follows the configured repeat cadence and a resumed schedule continues at the next natural interval. Keep `runEvents()`'s separate truthy-`lastChecked` guard: it deliberately allows the first evaluation through even though `shouldRepeatNow()` returns false for a never-checked event.

`Console\Kernel::commandStartedAt()` remains deliberately unchanged. It is the start of the top-level command the Kernel is handling, so a long-running `schedule:run` or `queue:work` correctly retains that lifecycle timestamp; nested `Kernel::call()` / `$this->call()` invocations do not establish another Kernel handle/terminate lifecycle in Laravel or Hypervel. Laravel scheduled commands happen to run in subprocesses and therefore trigger separate command-duration lifecycle handlers. Hypervel cannot reproduce that by calling `Kernel::terminate()` per task because termination tears down application state still owned by the long-lived daemon and concurrent coroutines. Scheduled-task events, including the runtime on `ScheduledTaskFinished`, own per-task observation instead. This settled architectural difference is reported in the final handoff rather than deferred as a todo or obscured behind a partial lifecycle emulation.

## Final design

### 1. Request owns the start instant

Add one protected float and one public accessor to `Hypervel\Http\Request`:

```php
/**
 * The timestamp when the server started processing the request.
 */
protected float $startedAtTimestamp;

/**
 * Get when the server started processing the request.
 */
public function startedAt(): CarbonImmutable
{
    return CarbonImmutable::createFromTimestamp($this->startedAtTimestamp);
}
```

Capture and normalize at the top of `initialize()`, before the parent creates its ServerBag:

```php
$this->startedAtTimestamp = (float) ($server['REQUEST_TIME_FLOAT'] ?? microtime(true));

$server['REQUEST_TIME_FLOAT'] ??= $this->startedAtTimestamp;
$server['REQUEST_TIME'] ??= (int) $this->startedAtTimestamp;

parent::initialize($query, $request, $attributes, $cookies, $files, $server, $content);
```

Import `Hypervel\Support\CarbonImmutable` directly. The HTTP package already depends on Carbon and `hypervel/support`; no Composer change is required.

`CarbonImmutable::createFromTimestamp()` creates the value at zero offset by default (Carbon reports the zone name as `+00:00`, not necessarily the named zone `UTC`). Preserve that dependency behavior rather than resolving application configuration from the HTTP value object; epoch equality and duration comparison are timezone-independent, and callers may use ordinary Carbon timezone conversion for display. Do not assert a timezone name in the Request contract tests.

This shape gives every construction route a non-null value:

- Swoole bridge input keeps its exact supplied float.
- Symfony `Request::create()` already supplies both standard time keys; Hypervel preserves them.
- Direct construction captures `microtime(true)` once and exposes that same value through the accessor and server bag.
- Explicit `initialize()` starts a new Request lifecycle and recaptures/replaces the property.
- `createFrom()` and `createFromBase()` naturally propagate the normalized server bag and therefore the instant.
- A direct clone and Symfony `duplicate()` preserve the source Request's property because they represent clones/subrequests of that logical request. Even `duplicate(server: [...])` does not redefine object identity by replacing a public parameter bag.
- Later mutation/removal of `REQUEST_TIME_FLOAT` from the ServerBag does not rewrite the stored start instant.

Do not add the method to an HTTP contract: the concrete Request is the existing public request-information API, and no contract currently promises its full convenience surface.

### 2. Keep server metadata on the existing API

Make no production change to `RequestBridge`. Extend its existing uppercase test with a precise `request_time_float` value and prove both:

```php
$request->server('REQUEST_TIME_FLOAT');
$request->startedAt();
```

represent the same microsecond instant. Keep exact type/precision for the float. The public request guide should mention `server()` with and without a key so developers know how to retrieve the rest of Swoole's normalized metadata.

Do not promise that the timestamp includes socket acceptance, queueing, or body upload. Describe it as Swoole's worker-side request time before Hypervel's bridge/kernel processing.

### 3. Migrate timing consumers

#### Telescope request duration

Delete its direct server read and nullable guard. Derive milliseconds from the request-owned Carbon value and Carbon's current clock:

```php
'duration' => floor($event->request->startedAt()->diffInMilliseconds()),
```

The accessor is non-null, so a `> 0 ? ... : null` branch would be dead code. Freeze Carbon and seed `REQUEST_TIME_FLOAT` in the watcher test to assert an exact duration.

#### Sentry transaction start

Delete the `REQUEST_TIME_FLOAT` / `HYPERVEL_START` / `microtime(true)` fallback chain. Sentry requires epoch seconds as a float, so preserve Carbon's microseconds explicitly:

```php
$context->setStartTimestamp(
    $request->startedAt()->getPreciseTimestamp(6) / 1_000_000
);
```

Do not add a second raw-float Request method solely for this consumer. A Carbon-to-float conversion happens only when tracing starts, while every untraced request avoids eager Carbon allocation.

#### Kernel lifecycle

Do not replace `Kernel::requestStartedAt()` with the Request accessor. Its start boundary, timezone conversion, duration callbacks, coroutine cleanup, and nullable post-termination behavior are different and valid. Add a small assertion to existing kernel lifecycle coverage that the Request accessor remains stable after kernel termination while the kernel getter becomes null.

### 4. Repair both health routes

The main `ApplicationBuilder` route already injects `Request $request`; pass it explicitly to the view:

```php
return response(View::file($path, [
    'request' => $request,
    'status' => $health,
]), status: $status);
```

Change the Testbench Workbench route closure to inject `Request $request`, import the class, and pass the same explicit view data. Do not call the global `request()` helper inside the Blade template: without RequestContext, `HttpServiceProvider` deliberately returns a throwaway synthetic Request, which would fabricate a near-zero duration.

Replace the guarded constant branch with unconditional request timing:

```blade
Response rendered in {{ round($request->startedAt()->diffInMilliseconds()) }}ms.
```

JSON health responses remain exactly `{"status":"up|down"}` and do not gain timing fields.

Use Carbon's frozen current time plus seeded server variables to assert deterministic `5000ms` output. In the main health integration test, send two sequential requests in the same application process with different seeded starts and assert their distinct durations. This proves request ownership rather than worker uptime without sleeping.

In Testbench, remove the entire `setUp()` override that defines the constant and remove `Override` if no other use remains. Make the existing health assertion exact instead of merely checking the phrase.

### 5. Remove `HYPERVEL_START` and resolve entrypoint command names

Add `Hypervel\Console\Application::resolveCommandName(ArgvInput $input)` as the shared command-classification boundary used before the real console application boots. It obtains a fresh Symfony default application definition, adds a fresh Hypervel environment option, binds the caller-owned input inside Symfony's `ExceptionInterface` catch pattern, and then calls `getFirstArgument()`. The catch permits command-specific options that cannot be validated until the command is known; real command execution rebinds the same input and reports invalid options normally.

Keep `ArgvInput` as the parameter type because its token scan is the behavior being prepared. Use one private static environment-option factory from both the resolver and the existing Laravel-compatible protected `getEnvironmentOption()` method. Do not change that protected method's signature or visibility, unify the static and instance definition paths, cache definitions, or add shared state.

The resolver must construct a Symfony application because Symfony exposes its authoritative default definition through the application instance. Before bootstrap, no Hypervel console application exists and application-specific `getEnvironmentOption()` overrides cannot participate. Symfony application construction enables async PCNTL signals when supported; the real console application does the same immediately after entrypoint classification. Record both facts in one concise source comment rather than adding signal save/restore machinery.

- Delete `define('HYPERVEL_START', microtime(true));` from the separate application skeleton's `artisan` entry point.
- In that same entrypoint, instantiate `ArgvInput` once before the HTTP-bootstrap check, classify it with the shared resolver, and pass the same input to `Application::handleCommand()`:

```php
$input = new ArgvInput();

if (in_array(ConsoleApplication::resolveCommandName($input), ['serve', 'watch'], true)) {
    // Existing environment assignments.
}

$status = $app->handleCommand($input);
```

- Make the equivalent change in `src/testbench/hypervel/artisan`: import every referenced class coherently, construct `ArgvInput` once, classify through the resolver, capture the input in the immediately invoked closure, and remove the inner duplicate construction.
- Make the equivalent classifier change in Testbench's `Console\Commander`, which owns the shipped `bin/testbench` entrypoint. Its sole caller creates an `ArgvInput`, so narrow the protected preparation method from generic `InputInterface` to `ArgvInput`, pass the same object onward, and cover `testbench --env production serve` in the existing focused environment test. Rename Commander's existing Symfony Application alias to avoid colliding with Hypervel's `ConsoleApplication` alias.
- Declare `hypervel/console` directly in Testbench's standalone package manifest because Commander now imports its Application class; do not rely on Foundation's transitive dependency.
- The resolver covers valueless global options, attached values, and separated optional values, including `-v serve`, `--ansi watch`, `--env=production serve`, and `--env production serve`. A later command-specific option may make the preliminary bind throw, but Symfony's catch pattern still leaves the command name available and final command binding remains authoritative.
- In Commander, resolver construction enables async PCNTL signals before `prepareCommandSignals()` snapshots the flag. That method therefore records `true`, and cleanup restores `true` immediately before `handle()` exits; no live caller can observe the restoration. Keep the existing order so process signal handlers are not installed before application bootstrap.
- Replace the collections scheduled-task example with the already-established local idiom:

```php
Invoice::pending()->cursor()
    ->takeUntilTimeout(now()->plus(minutes: 14))
    ->each(fn (Invoice $invoice) => $invoice->submit());
```

- Remove that snippet's now-unused `CarbonImmutable` import.
- Do not retain a deprecated constant, `defined()` branch, compatibility shim, migration note, changelog entry, or upgrade guide. The constant has no released 0.3 contract, and compatibility/churn is not a design constraint for this work.
- Leave the FacadeDocumenter import-resolution fixture untouched. Final stale searches must distinguish that fixture from semantic uses.

### 6. Correct Telescope command and schedule lifecycle detection

#### Recording-state classifier

Rename the protected argv-dependent classifier to reflect its actual input and accept the resolved command name. This is a deliberate divergence from upstream Telescope's `runningApprovedArtisanCommand($app)`: Hypervel no longer inspects process state here and the honest method name prevents a future upstream merge from restoring the wrong runtime assumption.

```php
protected static function commandIsApproved(?string $command): bool
{
    return ! in_array($command, array_merge([
        // 'migrate',
        'migrate:rollback',
        'migrate:fresh',
        // 'migrate:refresh',
        'migrate:reset',
        'migrate:install',
        'package:discover',
        'queue:listen',
        'queue:work',
        'horizon',
        'horizon:work',
        'horizon:supervisor',
        'watch',
    ], config('telescope.ignore_commands', [])), true);
}
```

Keep the two upstream commented migration entries according to the repository's port rules. Remove `start` and `serve`; keep `watch`; add `package:discover`.

Update `manageRecordingStateForCommands()` to receive `BeforeHandle $event` and pass `$event->command->getName()` to this classifier. Do not retain an argv fallback or `runningInConsole()` guard: `BeforeHandle` is already a Hypervel-console event carrying authoritative resolved identity.

Do not start recording when that resolved command is `schedule:run`. Unlike the other long-running commands, it is not added to the default ignored list because the same classifier must decide whether a user explicitly allows task-local recording. Leave one concise WHY comment at this branch.

At the same boot-time storage-opportunity boundary, listen for `ScheduledTaskStarting`. Start recording in that task coroutine only when `shouldListen()` and `commandIsApproved('schedule:run')` are both true. This respects `telescope.ignore_commands: ['schedule:run']`, keeps the daemon coroutine clean, and lets every enabled watcher observe the task even if ScheduleWatcher itself is disabled. Do not add recording-state save/restore machinery: a finite programmatic `schedule:run --once` invoked from an already-recording outer operation may remain part of that operation's recording.

#### CommandWatcher

Its default `shouldIgnore()` list becomes:

```php
[
    'schedule:run',
    'package:discover',
]
```

Delete nonexistent `crontab:run`. This preserves upstream's relative order after omitting Hypervel's nonexistent `schedule:finish`. Configuration-provided ignores remain merged as today, and command-name membership uses strict comparison at both classifier boundaries.

#### ScheduleWatcher

Register `ScheduledTaskFinished` and `ScheduledTaskFailed` listeners unconditionally when the enabled watcher boots. Do not inspect argv, listen for `CommandStarting`, register listeners at runtime, retain a boolean guard, or listen for `ScheduledTaskStarting`; recording state belongs to the storage-opportunity layer above.

Keep only the non-null Application field needed to retrieve task output. Delete the `EntriesRepository` property/resolution and explicit `Telescope::store()` call: `Telescope::record()` already installs one deferred store per coroutine through `HAS_STORED_CONTEXT_KEY`, and each real task now has a finite coroutine lifetime.

The scheduler may dispatch Finished and then Failed for one non-zero task. Store the last recorded task object's ID in one ScheduleWatcher-owned coroutine-context key. Return when the same task reaches a second terminal event; otherwise publish the ID before recording. This is constant-space invocation state, not a registry, and it also prevents duplicate dispatch/listener delivery while allowing a different task in the same synthetic test coroutine.

Do not add `ScheduledTaskSkipped`: upstream Telescope does not record it and no verified requirement exists.

#### Scheduler task coroutine boundary

Extend `Waiter::wait()` and the global `wait()` helper with the same trailing `bool|array $copyContext = false` option already used by `Parallel`, `co()`, and `go()`. `false` preserves a fresh child context; `true` or an empty array copies all keys; a non-empty array copies only those keys. Internally choose `Coroutine::create()` or `Coroutine::fork()` and retain Waiter's existing result, original-exception, timeout, cancellation, defer, and join semantics. Document and test the additive public option.

The additive base capability makes `Foundation\Testing\Coroutine\Waiter` redundant and its old override fatally incompatible. Delete that one-method subclass and its duplicate test file. Type `MakesHttpRequests`'s protected waiter property/getter to the base Waiter and pass `copyContext: true` at the single synthetic-request boundary; update `RequestContextSynchronizerTest` to use the base Waiter explicitly. Move the historical `ThrowingReplicableContext` regression into the base Waiter suite before deletion so replication failures remain proven to surface directly instead of becoming timeouts. This is the minimum resolution of the inheritance fatal, not a new testing abstraction.

In `ScheduleRunCommand`, keep scheduler-owned gates in the scheduler coroutine: `runEvents()` reads pause state once before its event loop; `repeatEvents()` reads pause state once per while iteration and checks sticky maintenance state per due event. A paused event is marked checked and dispatches `ScheduledTaskSkipped` in the scheduler coroutine; a maintenance-blocked event remains silent, matching the existing public behavior.

Run each event's user filters and either foreground execution or background spawn in a synchronously waited child coroutine with no timeout and only `ContextRepository::CONTEXT_KEY` copied. A filter rejection dispatches `ScheduledTaskSkipped` in that task child. The two skipped-event contexts are deliberately different because pause is scheduler-owned while filters are user task code. No first-party listener consumes skipped events, and synchronous userland listeners run in the context that owns the decision; do not add an event-owned coroutine or deferred-work wrapper.

Framework `Coroutine::afterCreated` hooks continue to propagate their own explicitly owned observability state; arbitrary parent CoroutineContext is not copied. Foreground scheduled tasks receive an independent replicated Log Context, so their log-context mutations do not leak back to the scheduler or later tasks. Also copy log Context into the existing outer `runOnce()` Waiter so `--once` no longer drops it before task dispatch.

Fix `Event::shouldRepeatNow()` at its public boundary by requiring a non-null `lastChecked` before computing the absolute elapsed seconds. Preserve the separate first-evaluation guard in `runEvents()`. When a repeatable event is skipped because the schedule is paused, set `lastChecked` to the current scheduler time before dispatching the skipped event. This prevents both the never-checked crash and repeated skipped-event delivery on every 100-millisecond poll without adding state or another coroutine.

Extract the foreground/background dispatch branch shared by `runEvents()` and `repeatEvents()`. Foreground work stays sequential because the parent waits for the invocation child. Background work still forks through the existing bounded `Concurrent`, returns after the spawn, and dispatches `ScheduledBackgroundTaskFinished` in that background child. This fixes sub-minute `--once` repeats bypassing background execution without adding another concurrency mechanism.

The normal scheduler daemon never starts Telescope recording and therefore has no batch ID. Each task child initially receives an absent/null batch key and `ScheduledTaskStarting` generates a fresh UUID, so consecutive tasks store distinct batches without a reset or special-case batch API.

Keep `onOneServer` unchanged. `Schedule::serverShouldRun()` caches the elected result on the shared Schedule object by event mutex name and minute, so later sub-minute task children reuse the first result without consulting a coroutine-local cache store. Existing shared-store requirements continue to govern election across workers and servers.

#### Sentry scheduled-task outcome

Type `ConsoleSchedulingFeature::handleScheduledTaskFinished()` with `ScheduledTaskFinished` and derive the final status from the task's already-published coroutine-local `exitCode()`: null or zero is `ok`, non-zero is `internalError`. Finish and pop exactly once on Finished. A subsequent Failed event is harmless because the span stack is empty; a task that throws before Finished still reaches Failed with its span open and is finalized as `internalError` there. Do not duplicate scheduler failure rules or defer outcome resolution.

### 7. Fix public application command classification

`Hypervel\Foundation\Application::runningConsoleCommand()` is public, facade-exposed, and intentionally mirrors Laravel, but its `$_SERVER['argv'][1]` implementation has the same verified option-prefix defect:

```php
$_SERVER['argv'] = ['artisan', '--env=production', 'migrate'];

$app->runningConsoleCommand('migrate'); // currently false
```

Preserve the method name, parameters, return type, facade annotation, contract declaration, and ordinary Laravel behavior while delegating a fresh `ArgvInput` to `Console\Application::resolveCommandName()`. Import both owning classes directly. Add focused `tests/Foundation/ApplicationRunningInConsoleTest.php` coverage for `--env=production migrate`, `--env production migrate`, `-v queue:work`, and the existing direct forms.

Do not cache the parsed command or add a console-input service: argv can vary in tests and programmatic environments, the method has no first-party hot-path caller, and fresh local input/definition objects avoid shared mutation. This is an intentional additive Hypervel API absent from Laravel; every existing Laravel method signature, contract, facade annotation, and protected extension point remains unchanged.

### 8. Remove dead Sentry command configuration

Delete the `ignore_commands` key and explanatory block from `src/sentry/config/sentry.php`, and remove it from `SentryServiceProvider::HYPERVEL_SPECIFIC_OPTIONS`. Do not implement a command-tracing filter just to preserve dead configuration, and do not add a compatibility `unset` path.

Keep all live Sentry tracing, feature, pool, breadcrumb, and SDK configuration unchanged.

### 9. Public documentation

Add `Request Start Time and Server Metadata` to `src/boost/docs/requests.md` under “Interacting With The Request” and its table of contents. Keep it concise and task-oriented:

```php
$startedAt = $request->startedAt();

$requestTime = $request->server('REQUEST_TIME_FLOAT');
$server = $request->server();
```

State that:

- `startedAt()` returns `Hypervel\Support\CarbonImmutable`;
- it represents Swoole's worker-side start for this request, before Hypervel bridge/kernel handling;
- all normalized server values use PHP/Symfony uppercase names;
- the Request start remains available after termination, while the kernel lifecycle getter is a separate, later timing boundary;
- for WebSocket handling it describes the HTTP handshake, not individual messages.

Do not enumerate every Swoole key as a guaranteed cross-version public contract in the user guide; show the generic `server()` access and use request time as the stable example. Do not claim that `server('REQUEST_TIME_FLOAT')` and `startedAt()` are universally interchangeable: they agree on the ordinary transport/conversion path, while the accessor deliberately remains stable across later ServerBag mutation and `duplicate(server: [...])`, and a caller-supplied numeric string remains a string in the bag. Keep the audited key list and those edge semantics in this plan.

Do not edit package READMEs or `docs/ai/differences-vs-laravel.md`. This is a documented Hypervel API, not an omitted Laravel feature requiring the triple-record convention, and the AI differences file expressly rejects new entries. `AGENTS.md` still names that file as a documentation destination, which contradicts the file's own first-line prohibition; do not resolve that governance conflict opportunistically in this work, but report it to the maintainer in the final handoff.

### 10. Keep the settled Kernel lifecycle boundary

Do not change `Console\Kernel::commandStartedAt()` or command lifecycle duration handlers. They describe the top-level command passed through Kernel `handle()` / `terminate()`, including a long-running `schedule:run`; nested `Kernel::call()` and `$this->call()` do not establish a second Kernel lifecycle in Laravel or Hypervel.

Keep `Console\Kernel::handle()`'s generic `InputInterface::getFirstArgument()` check for `env:encrypt` / `env:decrypt` unchanged. All three shipped `ArgvInput` entrypoints now bind the global definition through the resolver before the Kernel sees the same input, while programmatic `ArrayInput` callers read the command from their parameter map directly. Do not add an `ArgvInput` type branch to the generic Kernel boundary or duplicate pre-bootstrap resolution there.

Do not add a scheduler todo. Hypervel's in-process scheduled command intentionally uses `Kernel::call()` and task events rather than a subprocess. Calling `Kernel::terminate()` per task would dispatch application termination and tear down state still owned by the daemon and concurrent coroutines. The new task coroutine supplies invocation-local cleanup, while `ScheduledTaskFinished::$runtime` supplies task duration. Report the narrower Laravel difference—top-level command lifecycle duration handlers do not fire per Hypervel scheduled task—in the final maintainer handoff; do not implement a partial termination lifecycle or a second timing API.

## File-by-file implementation map

### Components worktree

| File | Change |
|---|---|
| `src/http/src/Request.php` | Add float ownership, initialize normalization, Carbon accessor. |
| `src/support/src/Facades/Request.php` | Regenerate the facade docblock so the new Request accessor is exposed to static analysis. |
| `tests/Http/HttpRequestTest.php` | Add construction, normalization, stability, conversion, clone/duplicate, and reinitialize coverage. |
| `tests/HttpServer/RequestBridgeTest.php` | Pin lowercase-to-uppercase float transport and accessor precision. |
| `src/console/src/Application.php` | Add the shared Symfony-definition-backed command-name resolver and one environment-option factory. |
| `tests/Console/ConsoleApplicationCommandNameTest.php` | Cover direct/global-option resolution and preliminary command-option binding failures. |
| `src/foundation/src/Application.php` | Delegate public console-command classification to the shared resolver. |
| `tests/Foundation/ApplicationRunningInConsoleTest.php` | Preserve direct forms and cover attached/separated option-prefixed command classification. |
| `tests/Foundation/Console/KernelTest.php` | Prove a pre-bound input is rebound and its command-specific options execute correctly. |
| `src/foundation/src/Configuration/ApplicationBuilder.php` | Pass the already-injected Request into health view data. |
| `src/testbench/src/Workbench/Workbench.php` | Inject and pass Request into Workbench health view. |
| `src/testbench/hypervel/artisan` | Import all classes coherently, reuse one ArgvInput, and classify server mode through the shared resolver. |
| `src/testbench/src/Console/Commander.php` | Classify the shipped Testbench CLI's ArgvInput through the shared resolver while preserving signal-handler setup order. |
| `src/testbench/composer.json` | Declare the directly used `hypervel/console` package. |
| `tests/Testbench/CommanderEnvironmentTest.php` | Cover separated `--env` command classification through the shared resolver. |
| `src/foundation/src/resources/health-up.blade.php` | Remove constant guard and render request-owned deterministic duration. |
| `tests/Integration/Foundation/Support/Providers/RouteServiceProviderHealthTest.php` | Add exact and sequential HTML timing regressions; preserve JSON/error cases. |
| `tests/Testbench/Workbench/DiscoversTest.php` | Delete constant setup and assert exact request duration. |
| `src/telescope/src/Watchers/RequestWatcher.php` | Use `startedAt()`; delete nullable server fallback. |
| `tests/Telescope/Watchers/RequestWatchersTest.php` | Assert exact deterministic request duration. |
| `src/telescope/src/Telescope.php` | Classify the supplied resolved command and correct default ignore names. |
| `src/telescope/src/ListensForStorageOpportunities.php` | Classify BeforeHandle's resolved command, keep the scheduler daemon unrecorded, and start approved task-local recording on `ScheduledTaskStarting`. |
| `tests/Telescope/Telescope/TelescopeTest.php` | Cover ordinary, ignored, resolved-name, scheduler-daemon, task-local, batch, and deferred-storage recording behavior. |
| `src/telescope/src/Watchers/CommandWatcher.php` | Ignore live `package:discover`/`schedule:run`; delete `crontab:run`. |
| `tests/Telescope/Watchers/CommandWatcherTest.php` | Prove package discovery is not recorded even if recording is already active. |
| `src/telescope/src/Watchers/ScheduleWatcher.php` | Boot-register terminal task listeners, rely on coroutine-deferred storage, and suppress a duplicate terminal event for the same task. |
| `tests/Telescope/Watchers/ScheduleWatcherTest.php` | Cover boot registration, successful/failed task entries, duplicate terminal suppression, output, and finite-coroutine persistence. |
| `src/coroutine/src/Waiter.php` | Add the existing selective context-copy contract to finite waited child coroutines. |
| `src/coroutine/src/functions.php` | Expose Waiter's additive `copyContext` option through the global `wait()` helper. |
| `tests/Coroutine/WaiterTest.php` | Cover fresh/default, all-key, selected-key, exception, defer, timeout, and cancellation behavior. |
| `src/boost/docs/coroutines.md` | Document `wait()` context-copy semantics alongside `go()`, `co()`, and `parallel()`. |
| `src/foundation/src/Testing/Coroutine/Waiter.php` | Delete the wrapper made redundant by the base context-copy API. |
| `tests/Foundation/Testing/Coroutine/WaiterTest.php` | Delete after moving its unique replication-failure regression to the base suite. |
| `src/foundation/src/Testing/Concerns/MakesHttpRequests.php` | Type the protected waiter extension points to the base class and explicitly copy all context for synthetic test requests. |
| `tests/Foundation/Testing/RequestContextSynchronizerTest.php` | Use the base Waiter with explicit full-context copying. |
| `src/console/src/Scheduling/Event.php` | Make a never-checked repeatable event report not-ready instead of passing null into `abs()`. |
| `tests/Console/Scheduling/EventTest.php` | Cover the public never-checked repeat predicate. |
| `src/console/src/Commands/ScheduleRunCommand.php` | Keep scheduler gates in the parent, give user filters/task execution a waited child, advance paused repeat cadence, copy log context, and share foreground/background dispatch across first and repeated invocations. |
| `tests/Console/Scheduling/ScheduleRunCommandTest.php` | Cover finite invocation boundaries, filters/defers, log-context isolation, sequential foreground work, and repeated background dispatch. |
| `tests/Console/Scheduling/ScheduleRunContextPropagationTest.php` | Replace shared foreground context expectations with the independent replicated Log Context contract. |
| `tests/Integration/Console/Scheduling/SubMinuteSchedulingTest.php` | Cover pre-paused repeat safety, natural skipped-event cadence, maintenance, and `evenWhenPaused()` behavior. |
| `src/sentry/src/Tracing/Middleware.php` | Use accessor and exact Carbon-to-epoch conversion. |
| `tests/Sentry/Tracing/MiddlewareTest.php` | Assert captured transaction's exact microsecond start timestamp. |
| `src/sentry/src/Features/ConsoleSchedulingFeature.php` | Finalize scheduled transactions from the published task exit code exactly once and document the three task handlers. |
| `tests/Sentry/Features/ConsoleSchedulingIntegrationTest.php` | Prove successful and non-zero scheduled commands publish one transaction with the correct final status. |
| `src/sentry/config/sentry.php` | Remove never-consumed `ignore_commands`. |
| `src/sentry/src/SentryServiceProvider.php` | Stop filtering the deleted non-SDK option. |
| `tests/Foundation/Http/KernelTest.php` | Pin Request start persistence versus kernel timing cleanup in existing lifecycle coverage. |
| `src/boost/docs/requests.md` | Document accessor and existing server metadata API. |
| `src/boost/docs/collections.md` | Use invocation-local `now()` deadline and remove unused import. |

### Separate application skeleton

| File | Change |
|---|---|
| `contrib/hypervel/hypervel/artisan` | Delete the process-entry constant, reuse one ArgvInput, and classify server mode through the shared resolver. |

No private package or application file changes are expected; repeat the broad search before completion in case the repositories move during implementation.

## Detailed test plan

Every new or modified test method declares `: void`. Preserve the established test base and file location for each package; do not create a production seam solely to make timing controllable.

### Request unit contract

Use fixed microsecond timestamps; compare exact epoch/microsecond values rather than formatted approximate seconds.

1. Explicit `REQUEST_TIME_FLOAT` accepts Swoole's float and a numeric-string form via the boundary cast, preserves the caller's supplied server-bag scalar, populates a missing integer `REQUEST_TIME`, and returns the exact zero-offset Carbon instant from the canonical float property without asserting the timezone's display name.
2. Direct construction with neither time key captures one fallback between before/after `microtime(true)` bounds and writes matching float/integer server values. Do not sleep or add a clock-injection seam.
3. Repeated `startedAt()` calls compare equal by timestamp. Do not assert Carbon object identity.
4. Mutating/removing `REQUEST_TIME_FLOAT` after initialization does not alter the stored instant.
5. Direct clone and `duplicate()` preserve the instant; `duplicate(server: [...])` still preserves the logical source Request instant even though its replacement ServerBag may differ.
6. `createFrom()` and `createFromBase()` preserve ordinary normalized timestamps.
7. Calling `initialize()` with a new precise timestamp resets the instant and both normalized server fields for the new lifecycle.
8. Do not add an integer-only fallback test; the deliberate behavior is precise fallback capture when the float is absent.

### Transport and consumer regressions

- RequestBridge: a lowercase Swoole `request_time_float` emerges as uppercase `REQUEST_TIME_FLOAT` with exact float type/precision, and `startedAt()` matches. This single shared-bridge test covers the contract used by HTTP, gRPC, WebSocket, and Reverb servers.
- Health application route: freeze Carbon, seed five seconds earlier, assert `Response rendered in 5000ms.`; then issue a second request with a different start and assert its own value rather than accumulated worker time.
- Health Testbench route: the same deterministic five-second assertion works without defining any constant.
- Health JSON and diagnosing-error tests remain unchanged in shape/status.
- Telescope RequestWatcher: fixed current Carbon time plus seeded start produces the exact floored millisecond duration and never null.
- Sentry middleware: the captured transaction's `getStartTimestamp()` exactly equals a six-decimal request timestamp.
- Kernel: after `terminate()`, `Kernel::requestStartedAt()` is null while the same Request's `startedAt()` value remains available.

### Telescope console regressions

- `BeforeHandle` for an ordinary resolved command starts recording.
- `BeforeHandle` for `package:discover`, `watch`, and a configured ignored command does not start recording. Keep this table focused; do not test removed nonexistent command names.
- `BeforeHandle` for `schedule:run` does not start recording in the long-lived daemon coroutine.
- CommandWatcher records an ordinary command and omits `package:discover` while recording is already active.
- `ScheduledTaskStarting` starts task-local recording when `schedule:run` is approved, independent of ScheduleWatcher being enabled.
- Configuring `telescope.ignore_commands: ['schedule:run']` leaves task recording disabled and produces no completed-task entry.
- ScheduleWatcher's terminal listeners exist at watcher boot without argv or a preceding command event.
- A successful task records one entry, and a thrown task records one entry.
- `ScheduledTaskFinished` followed by `ScheduledTaskFailed` for the same non-zero task records one entry, while a different task in the same coroutine still records normally.
- Two finite task coroutines receive distinct Telescope batch IDs without reset machinery.
- With Telescope's normal deferred storage enabled, a completed task entry is persisted before its long-lived parent coroutine exits.
- Scheduler cache polling and other daemon work produce no Telescope entries.
- Existing scheduled-task fields and output assertions remain intact.

### Coroutine and scheduler regressions

- Waiter starts with a fresh context by default, can copy all context, and can copy only named keys through both `Waiter::wait()` and `wait()`.
- Waiter preserves its original result, exception, timeout, cancellation, join, and defer-completion semantics for every context-copy mode.
- A `ReplicableContext` failure while copying through Waiter surfaces as the original exception before child execution rather than as a wait timeout.
- Synthetic HTTP test requests and RequestContextSynchronizer regressions continue to inherit parent context explicitly through the base API after the Foundation wrapper is removed.
- A repeatable Event with no `lastChecked` value returns false from `shouldRepeatNow()` rather than throwing.
- Scheduler interruption, pause, and maintenance checks stay in the scheduler coroutine. User filters and foreground execution share one distinct waited task child, and invocation defers complete before the next foreground event begins.
- A schedule paused before `schedule:run --once` starts completes safely with a due repeatable event instead of reaching `abs(null)`.
- A repeatable event paused at second 30 publishes exactly its 30 natural skipped occurrences through seconds 30–59, not one event per 100-millisecond scheduler poll. An `evenWhenPaused()` event still runs all 60 occurrences.
- Only logging `ContextRepository::CONTEXT_KEY` is copied from the scheduler parent; unrelated arbitrary context is absent.
- A foreground task sees the parent's initial Log Context through an independent replica; changed and child-only values do not leak back to the scheduler parent.
- Foreground events remain sequential.
- Background events remain non-blocking and bounded by the existing `Concurrent`; repeated sub-minute `--once` invocations use the same background path and complete through the existing background-finished event.

### Sentry scheduled-transaction regressions

- A successful scheduled task finishes exactly one captured transaction with `ok` status.
- A task that publishes a non-zero exit code through Finished then Failed finishes exactly one captured transaction with `internal_error` status.
- A task that throws before Finished is finalized as `internal_error` by Failed, with no open transaction left behind.

### Public application command classification

- Existing direct command-name, array/variadic, non-console, missing-argv, `serve`, and `watch` cases retain their behavior.
- `--env=production migrate`, `--env production migrate`, and `-v queue:work` resolve the actual command name and match only the requested command.

### Pre-bootstrap command-name resolution

- Direct commands, `--env=production migrate`, `--env production migrate`, `-v queue:work`, and `--ansi watch` resolve through a successful global-definition bind.
- `serve --host 0.0.0.0` and `--env production serve --host=0.0.0.0` resolve `serve` after the preliminary bind rejects command-specific options. Removing the catch must fail these regressions.
- An `ArgvInput` first classified by the resolver can be passed to the real console kernel, rebound against a registered command, and execute with the command-specific option value intact.
- Do not add an `-e` case; Hypervel's global `--env` option has no shortcut, so that invocation is invalid.

### CLI entrypoint checks

- With components' installed Symfony Console, verify the shared resolver returns `serve` / `watch` for `--env=production serve`, `--env production serve`, `-v serve`, and `--ansi watch`, including command-specific options after the command.
- Inspect all three entrypoints to ensure the same ArgvInput instance resolved before bootstrap is later passed to the command kernel/application; do not retain a second construction, raw `argv[1]` check, or direct unbound `getFirstArgument()` classification.
- Run `tests/Testbench/CommanderEnvironmentTest.php` to prove the Testbench CLI wiring accepts a separated `--env` value. The shared resolver suite, rather than this wiring test, owns the remaining global-option matrix.
- Run `php -l src/testbench/hypervel/artisan` and the Testbench suites. The canonical skeleton has no installed `vendor/`, so its real local gate is `php -l artisan`; do not run `composer test` there unless dependencies are installed for some independent reason.

### Negative/stale checks

- No semantic `HYPERVEL_START` remains in components, the application skeleton, private Hypervel packages, or applications. The five expected matches in `tests/FacadeDocumenter/ImportResolutionTest.php` remain and are manually verified as grammar fixtures.
- No `sentry.ignore_commands`, Sentry `ignore_commands` config entry, Telescope `crontab:run`, or Telescope default `start`/`serve` ignore remains.
- Sweep raw argv use rather than checking only known files: run `grep -rn '\$_SERVER.*argv' src/` in components and inspect the canonical skeleton `artisan` separately. The only permitted components production matches are whole-vector environment detection in `Application::detectEnvironment()` and argument forwarding in `src/testing/src/Console/TestCommandBase.php`. No positional `$_SERVER['argv'][1]` production match may remain. Confirm that result with the narrower `grep -rn '\$_SERVER.*argv.*\[1\]' src/` sweep and the separate skeleton inspection.
- No direct `REQUEST_TIME_FLOAT` read remains in first-party consumers outside Request itself, bridge/tests, and public documentation.
- The generated Request facade docblock contains `@method static \Hypervel\Support\CarbonImmutable startedAt()` and its lint test passes.
- No dead imports (`Override`, `CarbonImmutable`, event aliases) or obsolete comments remain.

## Implementation sequence

Work one file at a time with `apply_patch`, preserving unrelated worktree changes. Run the named focused test immediately after each coherent source/test pair.

1. Add focused command-name resolver coverage, implement `Console\Application::resolveCommandName()` with Symfony's definition-bind/catch flow and the shared environment-option factory, then run the new test file. Delegate `Foundation\Application::runningConsoleCommand()` to it and cover both attached and separated `--env` values. Add the real kernel rebind regression and run both changed Foundation test files.
2. Add Request unit regressions, implement `Request::$startedAtTimestamp`, initialization normalization, and `startedAt()`, then run `tests/Http/HttpRequestTest.php`.
3. Extend RequestBridge coverage and run `tests/HttpServer/RequestBridgeTest.php`. Do not change RequestBridge production normalization unless the counterfactual test disproves the audited behavior.
4. Update both health route owners and the Blade template; add deterministic application health coverage and run that test file.
5. Update Testbench Workbench and its health test, then run `tests/Testbench/Workbench/DiscoversTest.php` and `composer test:testbench`.
6. Migrate Telescope RequestWatcher and its exact-duration test; run the watcher test file.
7. Migrate Sentry middleware and its exact transaction test; remove dead Sentry command configuration; run `tests/Sentry/Tracing/MiddlewareTest.php`, `tests/Sentry/ConfigTest.php`, and `tests/Sentry/ContainerConfigOptionsTest.php`.
8. Correct Telescope's resolved-command classifier and CommandWatcher with their tests. Keep `schedule:run` excluded from daemon recording and add task-local recording at the storage-opportunity boundary.
9. Add Waiter's selective context-copy option, its focused tests (including the moved replication-failure regression), the matching global helper, and coroutine documentation. Replace the now-redundant Foundation testing wrapper with explicit base-Waiter use in `MakesHttpRequests` and `RequestContextSynchronizerTest`, delete the wrapper/source tests, and run both focused test files plus Testbench.
10. Fix `Event::shouldRepeatNow()` at its public null boundary and run `tests/Console/Scheduling/EventTest.php`. Refactor `ScheduleRunCommand` so scheduler-owned gates remain in the parent while user filters and task execution use one child; advance paused repeat cadence and retain one shared foreground/background dispatch path. Update scheduler context and sub-minute regressions, then run `tests/Console/Scheduling/ScheduleRunCommandTest.php`, `tests/Console/Scheduling/ScheduleRunContextPropagationTest.php`, and `tests/Integration/Console/Scheduling/SubMinuteSchedulingTest.php`.
11. Boot-register ScheduleWatcher terminal listeners, remove explicit storage ownership, add duplicate terminal suppression, and update its focused tests. Complete the Telescope recording, distinct-batch, daemon-silence, and deferred-persistence regressions, then run `tests/Telescope/Watchers/ScheduleWatcherTest.php` and `tests/Telescope/Telescope/TelescopeTest.php`.
12. Correct Sentry's scheduled-task final status and exactly-once completion, add the integration regressions, and run `tests/Sentry/Features/ConsoleSchedulingIntegrationTest.php`.
13. Add the small HTTP kernel lifecycle assertion and run `tests/Foundation/Http/KernelTest.php`. Keep console kernel lifecycle behavior unchanged.
14. Regenerate the Request facade docblock, then update the request, coroutine, and collections documentation. Do not create a scheduler todo, edit the forbidden AI differences file, or edit package READMEs.
15. Update `src/testbench/hypervel/artisan` to import all class references, classify its one ArgvInput through the shared resolver, and pass that same input onward. Update Testbench `Console\Commander` to use the resolver for its own one ArgvInput, declare the direct console-package dependency, preserve its signal setup order, and add the focused environment regression. Run all three CLI entrypoint checks and focused Testbench coverage, then run `composer test:testbench`.
16. In `contrib/hypervel/hypervel`, remove the skeleton constant, classify its one ArgvInput through the shared resolver, pass that input to command handling, and run `php -l artisan`. The repository currently has no `vendor/`; do not install dependencies or attempt `composer test` solely for this entry-script edit.
17. Run package-focused groups, the final validation gates, stale searches, and the complete fresh review below.

## Validation cadence

From the components worktree:

1. Run every changed test file immediately as listed above with `vendor/bin/phpunit <path>`.
2. Run the focused groups with `vendor/bin/phpunit tests/Http tests/HttpServer`, `vendor/bin/phpunit tests/Foundation/Http/KernelTest.php tests/Integration/Foundation/Support/Providers/RouteServiceProviderHealthTest.php`, `vendor/bin/phpunit tests/Coroutine/WaiterTest.php tests/Console/Scheduling/EventTest.php tests/Console/Scheduling/ScheduleRunCommandTest.php tests/Console/Scheduling/ScheduleRunContextPropagationTest.php tests/Integration/Console/Scheduling/SubMinuteSchedulingTest.php`, `vendor/bin/phpunit tests/Telescope`, and `vendor/bin/phpunit tests/Sentry`.
3. Run `composer test:testbench` after all Testbench changes.
4. Run `composer fix` once the coherent implementation is complete. It owns formatting, both PHPStan configurations, parallel components tests, Testbench, and dogfood tests.
5. Run `git diff --check`.
6. Run the semantic stale searches from the test plan, including the separate skeleton and private packages.
7. Inspect `git status --short` independently in the components worktree and the application skeleton repository so changes are attributed to the correct repository.

Do not run redundant full formatter/PHPStan/test passes immediately before `composer fix`; focused tests remain required while editing.

## Fresh review and completion criteria

Before requesting code review:

- Reread `AGENTS.md`, this plan, the full diff, and every changed caller/callee.
- Re-trace all Request construction paths through Symfony constructor/`initialize()`, `create()`, `createFrom()`, `createFromBase()`, clone, `duplicate()`, RequestBridge, and the four bridge consumers.
- Confirm every supported runtime has exactly one request-owned capture and no per-request eager Carbon allocation unless the accessor is used.
- Recheck Swoole v6.2.2 source placement so documentation does not claim socket-accept/body-upload timing.
- Recheck Laravel framework/skeleton and Telescope references; distinguish parity evidence from Hypervel's approved runtime-specific enhancement.
- Verify health views receive the actual routed Request explicitly and cannot resolve a throwaway helper request.
- Verify deterministic health/Telescope/Sentry assertions fail against the old behavior for the intended reason and use no sleeps.
- Verify Request and kernel timing retain their different boundaries and cleanup contracts.
- Trace Telescope events end to end: Hypervel BeforeHandle in the command coroutine, the deliberate `schedule:run` daemon exclusion, task-local `ScheduledTaskStarting`, terminal task events, recording state, finite-coroutine deferred storage, and distinct task batch IDs.
- Confirm ScheduleWatcher's terminal listeners are boot-registered once, explicit repository/store ownership is gone, and its constant-space task-ID marker suppresses only a duplicate terminal event for the same task.
- Trace scheduler-owned interruption/pause/maintenance gates in the parent separately from user filter/skip/execution in the task child. Confirm paused repeats advance at their natural cadence, pre-paused repeats cannot reach `abs(null)`, and `evenWhenPaused()` remains unaffected.
- Trace foreground/background dispatch, repeated sub-minute execution, independent Log Context propagation, task-local observability hooks, and child completion. Confirm foreground order and existing bounded background concurrency are unchanged.
- Confirm `Schedule::serverShouldRun()` still caches one election result per event/minute on the shared Schedule object, so task children do not change `onOneServer` behavior.
- Confirm Waiter's additive context-copy option preserves the fresh default and every existing result/error/cleanup contract.
- Confirm no Foundation-specific Waiter wrapper or empty tracked directory remains, and synthetic test HTTP requests explicitly retain their all-context-copy policy through the base API.
- Confirm Sentry reads the published exit code on Finished, records non-zero commands as `internal_error`, and finishes/pops each scheduled transaction exactly once.
- Confirm dead Sentry config is removed rather than silently ignored or newly implemented.
- Confirm the application skeleton and components no longer rely on the same commit/repository lifecycle.
- Inspect hot paths for added object allocations, container/config reads, statics, context writes, locks, or callbacks; the intended runtime cost is one float property/capture per constructed Request and Carbon only for accessor consumers.
- Remove dead code, stale comments, obsolete tests, unused imports, compatibility branches, and superseded documentation.
- Confirm the generated Request facade exposes `startedAt()` and the facade-docblock lint is current.
- Confirm no second metadata API, raw Swoole exposure, raw timestamp method, request contract expansion, recording-state stack, batch-reset mechanism, alternate scheduler concurrency layer, or forbidden AI-difference entry slipped in.
- Confirm all three shipped CLI entrypoints use the shared Symfony-definition-backed resolver, support separated `--env` values, pass the same input onward, and contain no partial custom option parser.
- Confirm Testbench Commander keeps signal-handler installation after application bootstrap preparation and the generic Kernel contains no duplicate ArgvInput-specific resolver branch.
- Confirm `Console\Kernel::commandStartedAt()` and its lifecycle handlers are unchanged: they still describe only the top-level Kernel `handle()` / `terminate()` lifecycle.
- Include the narrower Laravel difference in the final handoff rather than source or todo documentation: scheduled Laravel subprocesses establish their own command lifecycle, while Hypervel's intentional in-process `Kernel::call()` does not and cannot safely simulate `terminate()` without tearing down the long-lived application.
- Report the `AGENTS.md` versus `docs/ai/differences-vs-laravel.md` instruction conflict to the maintainer without changing either file in this work.

Request independent review of this complete plan before implementation and loop until sign-off. Implementation is complete only when both repositories contain the intended clean design, every focused regression passes, `composer fix` is green, stale searches have only the explicitly preserved grammar fixture, and the final diff passes a second independent review.
