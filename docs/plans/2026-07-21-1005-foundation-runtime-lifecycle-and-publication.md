# Complete Foundation Runtime Lifecycles and Safe Publication

## Status

Implementation plan for the `foundation` audit work unit. The package investigation and pre-implementation second-opinion loop are complete. This plan records only the settled design, the evidence that remains load-bearing, and the implementation/validation sequence. Discarded designs are listed only where their rejection prevents a future reintroduction of unnecessary machinery.

## Scope

Finish the Foundation package and its directly affected Console, Auth, HTTP, Queue, Database, Testing, and Testbench ownership boundaries so the result reads as one coherent long-lived Swoole framework design:

- teardown is exhaustive and preserves the chronologically earliest failure;
- per-invocation mutable state is coroutine-local and always restored;
- worker-global mutation is limited to boot-time configuration and documented as such;
- maintenance state, caches, generated source, and installer edits are published through checked boundaries without destroying the previous valid artifact first;
- native `false`, unreadable files, malformed JSON, broken links, and concurrent disappearance cannot violate declared contracts or produce false success;
- current Laravel public APIs, tests, and documentation are restored where they fit Hypervel;
- Hypervel-specific coroutine testing and Swoole worker behavior remain explicit and correct;
- split-package metadata declares the dependencies Foundation actually uses;
- obsolete duplicate setup, teardown, helpers, state, imports, comments, and tests are removed as part of the owning correction.

Backward compatibility, churn, and blast radius are not constraints for Hypervel 0.4. They also are not excuses for inventing abstractions. Every mechanism below is tied to a reproduced failure, a complete source trace, or a current Laravel API with real consumers.

## Desired final architecture

| State or resource | Owner and lifetime | Final rule |
|---|---|---|
| Application, configuration, Vite shared configuration | Worker process | Mutate at boot; warn on public mutators that would race if used per request |
| Vite nonce, entry points, preloaded assets | Request/coroutine | Keep existing `CoroutineContext` storage unchanged |
| Dumper recursion state | One dump invocation/coroutine | Use one class-specific context key and clear it in `finally` |
| Exception-renderer query payload | Request/coroutine | Keep context ownership, but cap count and per-query payload |
| Test setup/teardown callbacks | One test | Run once, attempt all independent cleanup, clear all callback state terminally |
| Testbench class callbacks and attribute caches | One test class/worker | Attempt all callbacks, then clear statics even when a callback fails |
| Algolia SDK HTTP client | PHP process, overridden by a test | Capture and restore the exact prior client on setup failure and teardown |
| Carbon test clock | One callback | Always return to real time in `finally` |
| Maintenance state | Configured driver; wrapped by one per-worker refresh cache | Driver commits atomically or throws; the worker cache remains a periodic snapshot |
| Array maintenance state | One PHP process | Explicitly testing/in-process only; never advertised as multiworker coordination |
| Cached config/routes/events and generated source | Filesystem artifact | Build and validate before same-directory atomic replacement; preserve existing mode |
| Environment files | Filesystem artifact | Atomic replacement; new plaintext is `0600`, new encrypted output uses normal umask, overwrite preserves exact existing mode |
| Arbitrary vendor file copies | Filesystem copy operation | Check the existing copy result and never report success after `false`; do not invent partial transaction semantics |
| Console termination | One command invocation | Event, application termination, duration handlers, and timestamp clearing are independent cleanup phases |
| Database test transactions/lazy refresh | Actual test coroutine or non-coroutine test | Install and execute hooks in the lifetime that owns the transaction |

## Finding summary

| Workstream | Category | Severity | Confidence | Owning failure |
|---|---|---|---|---|
| 1b. Deprecation bootstrap guards | Defect | Minor | High | Missing config/null guards dereference unavailable bootstrap state |
| 2. Foundation/Testbench teardown | Defect | Major | High | One failure skips independent resource and static cleanup |
| 3. External-service test ownership | Defect | Major | High | Empty Algolia prefix can target every index; duplicate lifecycle owners and an unrestored SDK client leak test state |
| 4. Test clocks | Defect | Minor | High | Throwing callbacks strand process-global Carbon test time |
| 5. Dumper/source handling | Defect | Major | High | Process-shared recursion state races across coroutines and can remain stuck; read failure violates source resolution |
| 6. Dev command environment | Defect | Minor | High | Exceptional exit strands process-global `COLUMNS` |
| 7. Console termination | Defect | Major | High | One callback failure skips remaining termination and state cleanup |
| 8. Maintenance drivers/middleware | Defect | Major | High | Partial/invalid state can be published, false results ignored, and corruption masked |
| 9. Array maintenance driver | Improvement | Improvement | High | Current Laravel's in-process driver is useful for isolated apps/tests when its process boundary is explicit |
| 10. Maintenance commands | Defect | Major | High | Post-commit event/reload failures skip independent work or misreport a committed transition |
| 11. Application/config boundaries | Defect and userland footgun | Minor | High | Unchecked composer input violates the named exception contract; worker-wide mutators lack lifecycle guidance |
| 12. Vite/ViteFonts | Defect and userland footgun | Major | High | Unchecked reads/hashes/JSON can violate return contracts or cache corruption; shared mutators are easy to race |
| 13. Renderer/Blade | Defect | Major | High | Query data is unbounded within a request and Blade disappearance can replace the original exception |
| 14. Clear/link/native commands | Defect | Major | High | Commands report success without their filesystem postcondition or fail with native `TypeError` |
| 15. Cache publication | Defect | Major | High | Generation failure destroys the previous valid config/route/event cache |
| 16. Environment publication | Defect and security improvement | Major | High | Failed writes can destroy output; new plaintext permissions are too broad by default |
| 17. Generator publication | Defect | Major | High | Shared generator owner ignores write failure and emits false success |
| 18. Publishers/installers | Defect | Major | High | Ignored process/native results continue after failure and report incomplete installation as successful |
| 19. Dispatchable factory seam | Parity defect | Minor | High | Hypervel omits current Laravel's conventional protected construction override |
| 20. Retry-stopping API | Parity defect | Major | High | Applications cannot declare terminal job exceptions through current Laravel's supported API |
| 21. Preferred/health JSON | Parity and correctness defect | Minor | High | Current public content-negotiation APIs and JSON health behavior are absent; an empty exception message can otherwise report a failed health check as healthy |
| 22. HTTP test helpers | Parity defect | Minor | High | Current request helpers are absent and a documented public middleware bypass is protected |
| 23. Route-list paths | Parity and native-boundary defect | Minor | High | Current path output is absent and internal reflection filenames can produce `false` |
| 24. Guest redirects | Parity defect | Major | High | Explicit nullable guest redirects cannot be expressed and a null target reaches redirect construction |
| 25. Database testing | Defect and parity defect | Major | High | Lazy refresh runs in the wrong coroutine/lifetime; runtime opt-out is ignored; current assertion APIs are absent |
| 26. Metadata/provenance | Defect | Major | High | Foundation cannot truthfully load standalone with undeclared direct dependencies |

## Backing research and fixed assumptions

### Current upstream workflow

Foundation is ported from Laravel. Historical pull requests below were used to find every originating source, contract, test, fixture, configuration, metadata, and documentation file. The checked-out current Laravel branch under `examples/laravel/framework` is the implementation reference:

| Surface | Implementation origin | Documentation origin | Current-port result |
|---|---|---|---|
| `Dispatchable::newPendingDispatch()` | `61492a8846` / #54153 | No upstream user-facing documentation; protected seam | Port the protected factory and route every dispatch branch through it |
| Retry-stopping exception API | `797107e681` / #60552 | Laravel docs `f3703da` (`queues.md`) | Port Handler/configuration/facade metadata and Queue Worker behavior; keep the contract PHPDoc-only |
| Preferred JSON responses | `e2ffb2c4af` / #59753 | No corresponding Laravel guide update | Port ApplicationBuilder, HTTP middleware, fixtures/tests, and proportionate Hypervel docs |
| Health JSON response | `59e374600c` / #59710 | Existing Laravel health docs do not describe JSON | Port request negotiation, fix the upstream empty-message failure sentinel, retain HTML presentation, and document both forms in Hypervel |
| HTTP test `query()` helpers | `e613470730` / #60662 | No corresponding Laravel guide update | Port current methods/tests and document the public helpers |
| Route-list path output | `41970e677a` / #59237 | CLI output is self-documenting; no guide update | Port current output/tests and fix upstream's native `false` filename edge |
| Nullable guest redirects | `8df42a2c67`, `a3d7986f4d` / #59505, #59526 | Existing Laravel auth docs omit the null form | Correct the lower Auth owner and all Foundation/facade consumers; document the public null behavior in Hypervel |
| Database assertion additions | `f15ba6dc7f`, `baf1bf38c6`, `3a927ac67a` | Current Laravel `database-testing.md` documents the methods but not every additive shape | Port current multiple-row/iterable forms and document the added call shapes |
| `castAsJson()` connection | `ebd5b6e2e0` / #53256 | No corresponding Laravel guide detail | Port current optional connection behavior and document it with the assertion helpers |
| Lazy refresh on every connection | `2456501c01` / #60359 | Existing Laravel lazy-refresh section | Port current per-connection hooks, then adapt ownership to Hypervel's coroutine lifecycle |
| Array maintenance driver | `715eb7b644` / #60489 | No corresponding Laravel guide update | Port as explicitly in-process/testing-only |
| Refresh options while already down | `a85443fc91` / #58918 | Existing maintenance command docs | Port current `down` behavior |
| Rendered maintenance JSON behavior | `66844c430d` / #60595 | Existing maintenance docs describe rendered HTML only | Apply at Hypervel's middleware owner and document JSON behavior; do not port Laravel's prebootstrap maintenance stub |
| Renderer query bounds | `b3fe63ce19` / #59309 | Internal exception-renderer bound; no guide update | Port bounded payload logic while retaining Hypervel's coroutine-local storage |

Hyperf parity is not a goal for this Laravel-derived package.

### PHPUnit teardown assumption

The installed PHPUnit 13 `TestCase::runBare()` is `final`. Its exact current control flow is load-bearing:

1. `$hasMetRequirements` becomes `true` immediately after requirements pass and before setup hooks execute;
2. setup and test execution catch every `Throwable`;
3. after-test hooks run whenever requirements passed, including after a throwing setup.

`RunTestsInCoroutine` overrides `invokeTestMethod()`, not `runBare()`, so the coroutine wrapper is unaffected. Do not add a nested PHPUnit runner, deliberately failing meta-test, cross-test static recorder, or vendor-internal fixture to test PHPUnit itself. Revalidate this source trace after every PHPUnit major or minor upgrade.

### Existing primitives to reuse

- `Filesystem::replace()` already writes a same-directory temporary file, writes the complete contents before publication, applies the requested mode, atomically renames, and removes an incomplete temp file on failure.
- `WorkerCachedMaintenanceMode` wraps every maintenance driver, periodically refreshes a per-worker snapshot, and flushes that same-process snapshot on `activate()` / `deactivate()`.
- `CoroutineContext` already owns request-local Vite and exception-renderer state.
- `AfterEachTestSubscriber` remains the authoritative framework-static reset registry; throwable resource cleanup stays in the owning test lifecycle.
- `Application::terminate()` and `Http\Kernel::terminate()` already implement the exhaustive earliest-failure pattern and are reference shapes, not redesign targets.

## Implementation order

Implement in this order so lower owners are correct before consumers are changed:

1. deprecation bootstrap guards and exception-backstop preservation;
2. Foundation and Testbench lifecycle cleanup;
3. test-only external-service and clock ownership;
4. dumper and Console invocation state;
5. maintenance drivers, middleware, commands, and array driver;
6. Application/config/Vite/renderer/Blade boundaries;
7. clear/link/cache/environment publication;
8. Console generator owner, Foundation generators, publishers, and installers;
9. Laravel parity APIs and documentation;
10. database-testing lifecycle and current assertion APIs;
11. split-package metadata/provenance;
12. final stale-code sweep, full validation, and audit bookkeeping.

### Touched-file typing and comment discipline

Every modified method is audited under `AGENTS.md` while it is open. Add evidence-backed native types where the current method or test is untyped and PHP or the inherited API permits it; this includes `ConfigClearCommand::handle(): void`, `StubPublishCommand::handle(): void`, and every touched test method in `RouteListCommandHelperTest`. Preserve upstream relative method order and meaningful comments. Remove stale comments and imports made obsolete by the correction, and add a short WHY comment only where the ownership or ordering cannot be understood from the code itself. Do not widen unrelated APIs or reformat untouched files as a side effect.

## 1. Preserve the exception backstop and correct deprecation boundaries

### Files

- `src/foundation/src/Bootstrap/HandleExceptions.php`
- `src/testbench/src/Bootstrap/HandleExceptions.php`
- `tests/Foundation/Bootstrap/HandleExceptionsTest.php`
- `tests/Testbench/Exceptions/DeprecatedExceptionTest.php`

### Merged exception-backstop baseline

Retain the current process-level exception boundary unchanged while adding the deprecation guards:

```php
try {
    $this->getExceptionHandler()->report($e);
} catch (Throwable) {
    $exceptionHandlerFailed = true;

    try {
        error_log((string) $e);
    } catch (Throwable) {
    }
}

// Swoole callbacks own response emission; this global backstop has no native response.
if (! static::$app->runningInConsole()) {
    return;
}

$this->renderForConsole($e);

if ($exceptionHandlerFailed ?? false) {
    exit(1);
}
```

This merged baseline already contains reporter `Throwable` failures, preserves the original exception through a failure-safe `error_log()` fallback, keeps non-console handling report-only, renders console failures through stderr, and preserves the existing console exit behavior after reporter failure. The deleted `renderHttpResponse()` path must not be restored: a process-global exception backstop does not own the request coroutine's native Swoole response, and resolving the shared application's request there could cross coroutine ownership.

### Deprecation bootstrap guards

Port current Laravel's missing-config and null-application guards:

```php
if ($this->shouldIgnoreDeprecationErrors()) {
    return;
}

if (! static::$app->bound('config')) {
    return;
}
```

```php
return ! class_exists(LogManager::class)
    || static::$app === null
    || ! static::$app->hasBeenBootstrapped()
    || (static::$app->runningUnitTests() && ! Env::get('LOG_DEPRECATIONS_WHILE_TESTING'));
```

Apply the same null-app protection to Testbench's `shouldIgnoreDeprecationErrors()` override. The base method dynamically dispatches to that override, so fixing only the base would leave the Testbench path unsafe.

### Tests

- retain the existing non-console report-only regression;
- retain the existing reporter-`Error` fallback regression, including that the original exception—not the reporter failure—is logged;
- retain the existing console-stderr regression;
- null application state ignores deprecations without dereferencing `null` in both Foundation and Testbench implementations;
- an application without a bound config repository returns before logger configuration;
- existing handler restoration and subclass deprecation behavior remain intact.

### Explicit non-change

Do not add stable callback slots, handler identity comparisons, a register-once shutdown flag, a callback wrapper, or WeakReference/process memory tests. Production bootstraps once per worker; Testbench flushes handler stacks; residual unremovable shutdown closures are inert, and the exit-reclaimed test-worker retention is identical to Laravel and causes no meaningful supported harm. This rejected concern does not justify a new process-lifetime callback architecture.

## 2. Make Foundation and Testbench teardown exhaustive

### Files

- `src/foundation/src/Testing/Concerns/InteractsWithTestCaseLifecycle.php`
- `src/testbench/src/TestCase.php`
- `src/testbench/src/Concerns/InteractsWithTestCase.php`
- `src/testbench/src/Concerns/InteractsWithPHPUnit.php`
- `tests/Foundation/Testing/Concerns/InteractsWithTestCaseTest.php`
- `tests/Testbench/TestCaseTest.php`
- new `tests/Testbench/Concerns/InteractsWithTestCaseTest.php`
- `tests/Testbench/Concerns/InteractsWithPHPUnitTest.php`
- focused fixtures local to those Testbench test files for attribute, Pest-wrapper, and class-teardown failure order

### Foundation teardown

Replace sequential teardown with direct fixed `try/catch` phases. Run the existing destruction-callback sweep first, then promote its stored earliest callback failure before attempting each later independent owner in chronological order:

```php
$exception = null;

try {
    $this->runInCoroutine(
        fn () => $this->callBeforeApplicationDestroyedCallbacks()
    );
} catch (Throwable $throwable) {
    $exception ??= $throwable;
}

$exception ??= $this->callbackException;

try {
    // cached database wrapper discard
} catch (Throwable $throwable) {
    $exception ??= $throwable;
}

// pool flush, ParallelTesting callbacks, app flush, HandleExceptions state,
// and local state reset follow as separate fixed blocks.

if ($exception !== null) {
    throw $exception;
}
```

The terminal reset must clear `app`, callback arrays, `callbackException`, and `setUpHasRun` regardless of every earlier failure. Preserve the existing separate-coroutine pool-flush rationale and the `resolved(PoolFactory::class)` gate.

### Testbench wrapper choreography

At setup, call the existing `setUpTheEnvironmentUsingPest()` when the concern is present, then make the ordinary setup sequence a once-only closure. At teardown, call the existing `tearDownTheEnvironmentUsingPest()` before invoking the symmetric wrapper. `WithPest` already owns both resolver methods and needs no source change:

```php
$setupHasRun = false;
$setup = function () use (&$setupHasRun): void {
    if ($setupHasRun) {
        return;
    }

    $setupHasRun = true;
    parent::setUp();
    // Testbench-owned post-parent setup follows here.
};

if ($this->testCaseSetUpCallback !== null) {
    ($this->testCaseSetUpCallback)($setup);
}

if (! $setupHasRun) {
    $setup();
}
```

Mark before executing so a partial failure is not repeated. A wrapper that returns without invoking parent falls back to the once-only closure. A wrapper that invokes parent then throws does not repeat setup.

Teardown uses the symmetric shape, but it must still run parent teardown if the wrapper throws before invoking it:

```php
$exception = null;
$teardownHasRun = false;

$teardown = function () use (&$teardownHasRun): void {
    if ($teardownHasRun) {
        return;
    }

    $teardownHasRun = true;
    // AfterEach attributes, then parent::tearDown().
};

try {
    ($this->testCaseTearDownCallback ?? static fn (Closure $parent) => $parent())($teardown);
} catch (Throwable $throwable) {
    $exception = $throwable;
}

if (! $teardownHasRun) {
    try {
        $teardown();
    } catch (Throwable $throwable) {
        $exception ??= $throwable;
    }
}
```

Clear setup/teardown callback slots terminally. Do not represent this as a registry, closure list, state machine, or reusable finalizer.

### Attribute and class cleanup

- iterate every `AfterEach` and `AfterAll` callback with an individual capture so one cannot skip the rest;
- clear method/class testing features and cached bootstrap/attribute state after callbacks even if one fails;
- make outer Testbench `tearDownAfterClass()` independently attempt TestCase, Pest, and PHPUnit teardown phases in that order, rethrowing the earliest failure;
- retain the existing setup class order unless source tracing proves an ordering defect.

### Tests

Cover every branch directly owned by Hypervel:

- each Foundation teardown phase throwing while every later phase still runs;
- chronologically earliest throwable object is rethrown unchanged;
- stored before-destroy callback failure does not skip DB, pool, parallel, app, handler, or local reset;
- setup wrapper invokes parent once, returns without parent, throws before parent, and invokes parent then throws;
- teardown wrapper covers the same four shapes, with parent teardown guaranteed exactly once;
- each `AfterEach` / `AfterAll` callback is attempted and statics clear despite failures;
- TestCase, Pest, and PHPUnit class teardown phases all run after an earlier phase fails.

No test should intentionally validate PHPUnit's own `runBare()` internals. Retain the pinned source trace and upgrade revalidation note instead.

### Cost

All added control flow is test-only or failure-only. Production request paths are unchanged.

## 3. Give external-service tests one lifecycle owner

### Files

- `src/foundation/src/Testing/Concerns/InteractsWithAlgolia.php`
- `tests/Foundation/Testing/Concerns/ExternalServiceOptInTest.php`
- `tests/Support/AlgoliaIntegrationTestCase.php`
- `tests/Support/MeilisearchIntegrationTestCase.php`
- `tests/Support/TypesenseIntegrationTestCase.php`
- `tests/Integration/Scout/Algolia/AlgoliaScoutIntegrationTestCase.php`
- `tests/Integration/Scout/Algolia/AlgoliaConnectionTest.php`
- `tests/Integration/Scout/Meilisearch/MeilisearchScoutIntegrationTestCase.php`
- `tests/Integration/Scout/Meilisearch/MeilisearchConnectionTest.php`
- `tests/Integration/Scout/Typesense/TypesenseScoutIntegrationTestCase.php`
- `tests/Integration/Scout/Typesense/TypesenseConnectionTest.php`

### Changes

Assign the documented Algolia prefix only when the test has not provided one:

```php
if ($this->algoliaPrefix === '') {
    $token = (string) ($_SERVER['TEST_TOKEN'] ?? '');
    $this->algoliaPrefix = $token === '' ? 'test_' : "test_{$token}_";
}
```

Capture the SDK's exact current client before installing Guzzle:

```php
$this->previousAlgoliaHttpClient = Algolia::getHttpClient();

try {
    Algolia::setHttpClient($this->createAlgoliaHttpClient());
    // service probe / setup
} catch (Throwable $throwable) {
    Algolia::setHttpClient($this->previousAlgoliaHttpClient);
    $this->previousAlgoliaHttpClient = null;

    throw $throwable;
}
```

Terminal teardown restores that same `HttpClientInterface` instance in `finally` and clears trait state. Do not use SDK reset-to-default behavior; exact restoration is required when another test/framework owner installed a client first.

Delete the three support-base `initialize*()` methods and teardown overrides; delete `setUpInCoroutine()` from the three connection tests; remove the initialization call from the three Scout integration bases while retaining engine resolution; and remove their redundant coroutine teardown cleanup. Generic `setUpTraits()` is authoritative. Remove the Meilisearch/Typesense created-resource arrays, Typesense's now-unused create/cleanup helpers, and Meilisearch's duplicate cleanup helper. Keep Meilisearch's `createTestIndex()` because the command test calls it, but remove its obsolete tracking append. Keep `waitForTasks()` because the Scout base uses it. Remove the corresponding imports and lifecycle comments.

### Tests

- empty prefix produces `test_{TEST_TOKEN}_` or `test_`;
- explicit prefix is preserved;
- exact client identity is restored after success and after setup/probe failure;
- every service initializes and cleans up once through generic trait ownership;
- deletion sweep confirms no duplicate initializer/teardown references remain.

This work is test-only and adds no production factory or SDK abstraction.

## 4. Restore test clocks in `finally`

### Files

- `src/foundation/src/Testing/Concerns/InteractsWithTime.php`
- `src/foundation/src/Testing/Wormhole.php`
- `tests/Foundation/FoundationInteractsWithTimeTest.php`
- `tests/Foundation/Testing/WormholeTest.php`

### Changes

`InteractsWithTime::travelTo()` keeps the persistent travel behavior when no callback is supplied. Its callback branch becomes:

```php
try {
    return $callback($date);
} finally {
    Carbon::setTestNow();
}
```

`Wormhole::handleCallback()` uses the same `try/finally` with `$callback()` and likewise does nothing when no callback is supplied.

Move the Wormhole test from raw `PHPUnit\Framework\TestCase` to `Hypervel\Tests\TestCase` when touching it. Preserve the callback's return value and original throwable.

Do not preserve a prior nested clock, add coroutine-local Carbon state, or create a clock manager. The public contract is travel for this callback and then return to real time.

## 5. Isolate dumper recursion and check compiled source reads

### Files

- `src/foundation/src/Console/CliDumper.php`
- `src/foundation/src/Http/HtmlDumper.php`
- `src/foundation/src/Concerns/ResolvesDumpSource.php`
- `tests/Foundation/Console/CliDumperTest.php`
- `tests/Foundation/Http/HtmlDumperTest.php`

### Changes

Remove the process-shared `$dumping` booleans. Each dumper receives its own protected context-key constant and the same nested-safe invocation boundary:

```php
// CliDumper
protected const DUMPING_CONTEXT_KEY = '__foundation.cli_dumper.dumping';

// HtmlDumper
protected const DUMPING_CONTEXT_KEY = '__foundation.html_dumper.dumping';

if (CoroutineContext::has(self::DUMPING_CONTEXT_KEY)) {
    $this->dump($data);

    return;
}

CoroutineContext::set(self::DUMPING_CONTEXT_KEY, true);

try {
    // existing source resolution and decorated output
} finally {
    CoroutineContext::forget(self::DUMPING_CONTEXT_KEY);
}
```

Nested dumps use plain output. Sibling coroutines never observe each other's guard. A thrown formatter/source/output operation cannot strand the guard.

At the compiled-view read boundary:

```php
$contents = @file_get_contents($compiledPath);

if ($contents === false) {
    return $compiledPath;
}
```

Use current Laravel's exact `config('app.editor.base_path') !== false` condition; do not turn other falsy values into an opt-out.

### Tests and cost

- formatter/read/output exceptions clear the guard;
- nested dumping remains non-recursive;
- deterministic concurrent dumps each receive decorated source;
- failed compiled-view reads return the compiled path;
- `app.editor.base_path=false` disables base-path shortening exactly.

The only request-time cost is one context read/write pair while an actual dump is being rendered. No ordinary request path changes.

## 6. Restore `COLUMNS` exactly in `DevCommand`

### Files

- `src/foundation/src/Console/DevCommand.php`
- `tests/Foundation/Console/DevCommandTest.php`

### Changes

Capture prior presence separately from value, then cover every operation after mutation:

```php
$columns = getenv('COLUMNS');

putenv('COLUMNS=' . $this->output->getWidth());

try {
    // output, package-manager resolution, and passthru
} finally {
    $columns === false
        ? putenv('COLUMNS')
        : putenv("COLUMNS={$columns}");
}
```

Use the existing throwing package-manager seam to cover failure after mutation. This native process-owning CLI command is non-coroutine; no lock or context storage is warranted.

## 7. Make Console kernel termination exhaustive

### Files

- `src/foundation/src/Console/Kernel.php`
- `tests/Foundation/Console/KernelTest.php`
- `tests/Foundation/Console/KernelTerminateTest.php`

### Merged console-output baseline

Preserve the current `handle()` fallback that constructs `ConsoleOutput` when no output is supplied and the `renderException()` branch that passes `ConsoleOutputInterface::getErrorOutput()` to the exception handler. The termination rewrite must not route exception output back to stdout or disturb the existing `KernelTest` regressions.

### Changes

Independently attempt:

1. `Terminating` event dispatch;
2. application termination callbacks;
3. every command-duration handler;
4. terminal clearing of `commandStartedAt`.

Keep the start timestamp available to every duration handler even if an earlier handler fails. Then clear it in its own capture block and rethrow the earliest failure:

```php
$exception = null;

try {
    $this->events->dispatch(new Terminating);
} catch (Throwable $throwable) {
    $exception = $throwable;
}

// application and each duration handler follow independently

$this->commandStartedAt = null;

if ($exception !== null) {
    throw $exception;
}
```

The nullable property assignment cannot throw, so it is an unconditional terminal statement rather than an invented cleanup-failure branch.

Use the typed `app.timezone` getter without a dead call-site default. This is a cold CLI termination path; no production HTTP hot-path work is added.

Run both `KernelTest` and `KernelTerminateTest` after the file changes; stderr routing and exhaustive termination are independent contracts on the same class.

## 8. Correct maintenance driver and middleware contracts

### Files

- `src/foundation/src/FileBasedMaintenanceMode.php`
- `src/foundation/src/CacheBasedMaintenanceMode.php`
- `src/foundation/src/WorkerCachedMaintenanceMode.php`
- `src/foundation/src/MaintenanceModeManager.php`
- `src/foundation/src/Http/Middleware/PreventRequestsDuringMaintenance.php`
- `src/foundation/config/app.php`
- `tests/Foundation/FoundationFileBasedMaintenanceModeTest.php`
- `tests/Foundation/FoundationCacheBasedMaintenanceModeTest.php`
- `tests/Foundation/WorkerCachedMaintenanceModeTest.php`
- `tests/Integration/Foundation/MaintenanceModeTest.php`

### File driver

Keep no-argument construction valid while making Filesystem the owner:

```php
public function __construct(protected Filesystem $files = new Filesystem)
{
}
```

Publish validated JSON atomically:

```php
$contents = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
$this->files->replace($this->path(), $contents);
```

Read through the checked Filesystem contract and validate the decoded shape:

```php
$data = json_decode($this->files->get($this->path()), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($data)) {
    throw new RuntimeException('The maintenance mode file does not contain a valid payload.');
}
```

Deletion treats `false` as success only when an immediate second observation proves another owner already removed the file:

```php
if (! $this->files->delete($path) && $this->files->exists($path)) {
    throw new RuntimeException("Unable to remove the maintenance mode file [{$path}].");
}
```

Keep this inline; do not extract a shared delete helper.

### Cache driver and worker cache

Throw when cache `put()` or `forget()` returns `false`. Correct the worker-cache comment that currently claims the two-call snapshot is atomic. Do not redesign the snapshot, add a lock, or add a watcher. Remove the manager's dead driver default because merged config owns the key.

### Middleware

Catch only `FileNotFoundException` across the `active()` / `data()` pair so concurrent deactivation is treated as inactive without masking malformed JSON or other corruption:

```php
try {
    if (! $this->maintenanceMode->active()) {
        return $next($request);
    }

    $data = $this->maintenanceMode->data();
} catch (FileNotFoundException) {
    return $next($request);
}
```

Use stored redirects and rendered templates only for non-JSON requests:

```php
if (isset($data['redirect']) && ! $request->expectsJson()) {
    // preserve the existing redirect behavior
}

if (isset($data['template']) && ! $request->expectsJson()) {
    return response(
        $data['template'],
        $data['status'] ?? 503,
        $this->getHeaders($data)
    );
}
```

### Tests

- atomic file replacement and failed publication preserving the previous payload;
- malformed JSON and non-array JSON surface as named failures;
- active-then-disappeared state proceeds normally;
- delete false with a remaining file fails, while concurrent disappearance succeeds;
- cache put/forget false fails;
- worker cache still refreshes by interval and flushes after same-process activate/deactivate;
- JSON maintenance requests bypass stored redirects and HTML templates and receive the framework JSON maintenance response with the configured headers.

## 9. Add the array maintenance driver at its honest boundary

This current-Laravel parity improvement is explicitly owner-approved with the process-local limitation below.

### Files

- new `src/foundation/src/ArrayMaintenanceMode.php`
- `src/foundation/src/MaintenanceModeManager.php`
- `src/foundation/config/app.php`
- new `tests/Foundation/FoundationArrayMaintenanceModeTest.php`
- `tests/Foundation/WorkerCachedMaintenanceModeTest.php`
- `src/boost/docs/configuration.md`

### Design

Port current Laravel's public driver shape:

```php
class ArrayMaintenanceMode implements MaintenanceMode
{
    protected bool $active = false;

    protected array $payload = [];

    public function activate(array $payload): void
    {
        $this->active = true;
        $this->payload = $payload;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->payload = [];
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function data(): array
    {
        return $this->payload;
    }
}
```

Register it through the manager's normal driver resolution and expose the existing Laravel config shape. Document it as an in-process/testing driver only.

```php
protected function createArrayDriver(): ArrayMaintenanceMode
{
    return new ArrayMaintenanceMode;
}
```

The existing `APP_MAINTENANCE_DRIVER` key selects it with `array`; update the config comment's supported-driver list and make the process-local limitation explicit. No new config key is needed.

The universal `WorkerCachedMaintenanceMode` wrapper does not make array state cross-process: it caches a snapshot from the same process-local driver and flushes it only when that same process calls activate/deactivate. A CLI process and Swoole workers still hold different arrays. This is why the driver is useful for isolated tests/application processes and unsuitable for production multiworker coordination.

Do not add IPC, shared memory, cache synchronization, or a special wrapper bypass.

## 10. Make maintenance commands post-commit failure-safe

### Files

- `src/foundation/src/Console/DownCommand.php`
- `src/foundation/src/Console/UpCommand.php`
- `tests/Integration/Foundation/MaintenanceModeTest.php`

### Changes

After the driver commits state, event dispatch and worker reload are independent side effects:

```php
$exception = null;

try {
    $this->events->dispatch(new MaintenanceModeEnabled);
} catch (Throwable $throwable) {
    $exception = $throwable;
}

try {
    $this->reloadWorkers();
} catch (Throwable $throwable) {
    $exception ??= $throwable;
}

if ($exception !== null) {
    throw $exception;
}
```

Track whether the driver operation committed before entering this post-commit sequence. A driver failure retains the existing `Failed to enter/disable maintenance mode` message; a later event or reload failure must report that the application is already in maintenance mode or already live rather than misreporting the committed transition.

Use the symmetric disabled event for `up`. `reloadWorkers()` performs a checked best-effort PID read:

```php
$contents = @file_get_contents($pidPath);

if ($contents === false) {
    return;
}
```

Absent/unreadable PID state must not turn a successfully committed maintenance transition into failure. Port current Laravel's behavior that updates options when `down` is invoked while already active. At the command boundary catch `Throwable`; a best-effort report failure must never replace the original command failure.

Tests cover both driver failures before commit and event/reload failures after commit, including truthful state-focused output, failure exit codes, independent side-effect attempts, and the resulting maintenance state. Do not attempt `/proc` incarnation validation, PID registries, rollback of committed maintenance state, retries, locks, or a signal state machine. The current PID file cannot prove process identity portably.

## 11. Tighten Application and typed-config boundaries

### Files

- `src/foundation/src/Application.php`
- `src/foundation/src/Bootstrap/RegisterProviders.php`
- `src/foundation/src/Bootstrap/RegisterFacades.php`
- `src/foundation/src/Providers/FoundationServiceProvider.php`
- `src/foundation/src/helpers.php`
- `src/foundation/src/Testing/WithFaker.php`
- `src/foundation/src/Console/Kernel.php`
- `src/foundation/src/Console/DownCommand.php`
- `src/foundation/src/Console/UpCommand.php`
- `src/foundation/src/Http/Kernel.php`
- `tests/Foundation/FoundationApplicationTest.php`
- `tests/Foundation/Bootstrap/RegisterFacadesTest.php`
- `tests/Foundation/Bootstrap/RegisterProvidersTest.php`
- `tests/Foundation/Console/KernelTerminateTest.php`
- `tests/Foundation/Providers/FoundationServiceProviderTest.php`
- `tests/Foundation/Testing/Concerns/InteractsWithDatabaseTest.php`
- `tests/Integration/Foundation/FoundationHelpersTest.php`

### Composer namespace read

Preserve `Application::getNamespace()`'s existing named `RuntimeException`, but make every input truthful:

```php
$contents = @file_get_contents($this->basePath('composer.json'));

if ($contents === false) {
    throw new RuntimeException('Unable to detect application namespace.');
}

try {
    $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    throw new RuntimeException('Unable to detect application namespace.', previous: $exception);
}

if (! is_array($composer)) {
    throw new RuntimeException('Unable to detect application namespace.');
}

$mappings = data_get($composer, 'autoload.psr-4', []);

if (! is_array($mappings) || ! is_string($appPath = realpath($this->path()))) {
    throw new RuntimeException('Unable to detect application namespace.');
}

foreach ($mappings as $namespace => $paths) {
    if (! is_string($namespace) || (! is_string($paths) && ! is_array($paths))) {
        throw new RuntimeException('Unable to detect application namespace.');
    }

    foreach ((array) $paths as $path) {
        if (! is_string($path)) {
            throw new RuntimeException('Unable to detect application namespace.');
        }

        $candidatePath = realpath($this->basePath($path));

        if (is_string($candidatePath) && $candidatePath === $appPath) {
            return $this->namespace = $namespace;
        }
    }
}
```

Retain that existing message exactly. Never compare two failed `realpath()` calls—`false === false` must not identify a missing application path as a namespace match—and do not let malformed PSR-4 values leak a native warning or `TypeError`.

### Typed config

Remove call-site defaults only for keys guaranteed by merged Foundation base config:

- view paths;
- debug mode;
- providers and aliases;
- faker locale;
- maintenance driver and refresh interval;
- application timezone;
- server PID file.

Keep defaults in `LoadConfiguration` before merge, nullable keys, schedule timezone, and connection-specific configuration. Do not mechanically remove every default.

### Worker-lifetime warnings

Add concise `Boot-only.` warnings to public mutators for:

- `useAppPath()`, `useBootstrapPath()`, `useConfigPath()`, `useDatabasePath()`, `useLangPath()`, `usePublicPath()`, and `useStoragePath()`;
- `useEnvironmentPath()` and `loadEnvironmentFrom()`;
- `addAbsoluteCachePathPrefix()`;
- `dontMergeFrameworkConfiguration()`;
- `setFallbackLocale()`.

The second sentence must name worker-wide impact. Do not warn `setLocale()`: its request-safe path is intentionally coroutine-aware. Preserve tested application-alias precedence over package aliases.

### Exception-renderer baseline

Retain the source rationale that Laravel's optional Whoops renderer is omitted in favor of Hypervel's framework-aware renderer. Do not restore the deleted Whoops/Collision classes, dependencies, or tests while changing the provider; applications continue to customize rendering by binding `ExceptionRenderer`.

### Tests and cost

Cover missing/unreadable/malformed `composer.json`, a non-array or invalid PSR-4 map, missing real paths, a valid string mapping, and a valid array-of-paths mapping. Retain existing provider/alias precedence, typed-config, faker, timezone, maintenance, and PID-path assertions. Namespace detection is a lazy application-level lookup cached after success; the extra validation does not affect requests after the namespace is resolved.

No scoped Application clone, request config overlay, config lock, or new mutation registry.

## 12. Check and document Vite/ViteFonts state

### Files

- `src/foundation/src/Vite.php`
- `src/foundation/src/ViteFonts.php`
- `tests/Foundation/FoundationViteTest.php`
- `tests/Foundation/FoundationViteFontsTest.php`

### Checked boundaries

Immediately check hot-file/content/manifest/hash/font-CSS operations. Throw existing `ViteException` on throwing APIs; retain nullable hash APIs as nullable. Decode with `JSON_THROW_ON_ERROR`, require an array, and cache only a validated array:

```php
$contents = @file_get_contents($path);

if ($contents === false) {
    throw new ViteException("Unable to read the Vite manifest at [{$path}].");
}

try {
    $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    throw new ViteException("Unable to parse the Vite manifest at [{$path}].", previous: $exception);
}

if (! is_array($manifest)) {
    throw new ViteException("The Vite manifest at [{$path}] is invalid.");
}

return $this->manifests[$path] = $manifest;
```

`ViteException` inherits `Exception` unchanged, so preserving the `JsonException` as `previous` uses its supported constructor directly.

### Mutator warnings

Add boot-only warnings to `useIntegrityKey()`, `useManifestFilename()`, `useHotFile()`, `useBuildDirectory()`, `useScriptTagAttributes()`, `useStyleTagAttributes()`, `usePreloadTagAttributes()`, `prefetch()`, `useWaterfallPrefetching()`, `useAggressivePrefetching()`, and `usePrefetchStrategy()`. Retain the existing warnings on `createAssetPathsUsing()` and `useFontsManifestFilename()`. Keep nonce, entry points, and preloaded assets unwarned because they already use request-local context.

### Tests and cost

Cover missing/unreadable hot and content files, malformed/non-array manifests, failed hashes, unreadable font CSS, valid-only cache behavior, and existing coroutine isolation. These checks run only during asset resolution/cache misses; no locks, watcher, retry, cache eviction policy, or per-render overlay is added. The existing `vite.md` already tells users to configure the shared Vite instance during provider boot and needs no change.

## 13. Bound renderer query data and make Blade mapping failure-safe

### Files

- `src/foundation/src/Exceptions/Renderer/Listener.php`
- `src/foundation/src/Exceptions/Renderer/Mappers/BladeMapper.php`
- `tests/Foundation/Exceptions/Renderer/ListenerTest.php`
- `tests/Foundation/Exceptions/Renderer/ListenerContextIsolationTest.php`
- `tests/Integration/Foundation/Exceptions/RenderBladeFilesTest.php`

### Query payload

Port current Laravel's bounded logic into the existing coroutine-local owner:

```php
$queries = $this->queries();

if (count($queries) >= 100) {
    return;
}

$sql = strlen($event->sql) <= 2000
    ? $event->sql
    : mb_strcut($event->sql, 0, 2000);
$bindings = $event->connection->prepareBindings($event->bindings);
$placeholderCount = substr_count($sql, '?');

$queries[] = [
    'connectionName' => $event->connectionName,
    'sql' => $sql,
    'bindings' => count($bindings) <= $placeholderCount
        ? $bindings
        : array_slice($bindings, 0, $placeholderCount),
    'time' => $event->time,
];

CoroutineContext::set(self::QUERIES_CONTEXT_KEY, $queries);
```

Prepare bindings once. Do not add an SQL parser, Octane/job reset listeners, or another registry; Hypervel's context dies with the request.

### Blade mapping

Record a known-path pair only if both `realpath()` results are strings. At the later source-read boundary, suppress/check immediate native failure and return the original compiled line number when the source disappeared after mapping:

```php
$source = @file_get_contents($path);

if ($source === false) {
    return $compiledLine;
}
```

### Tests and cost

- exactly 100 records are retained, never 101;
- multibyte SQL is at most 2,000 bytes;
- bindings do not exceed retained placeholders;
- sibling coroutines remain isolated;
- missing originals are skipped during mapping;
- post-mapping disappearance/read failure preserves exception rendering and the compiled line.

The change reduces per-request exception memory. All added checks are exception-rendering-only.

## 14. Make clear, link, and small native commands truthful

### Files

- `src/foundation/src/Console/ConfigClearCommand.php`
- `src/foundation/src/Console/RouteClearCommand.php`
- `src/foundation/src/Console/EventClearCommand.php`
- `src/foundation/src/Console/ClearCompiledCommand.php`
- `src/foundation/src/Console/ViewClearCommand.php`
- `src/foundation/src/Console/StorageLinkCommand.php`
- `src/foundation/src/Console/StorageUnlinkCommand.php`
- `src/foundation/src/Console/AboutCommand.php`
- `src/foundation/src/ComposerScripts.php`
- new `tests/Integration/Foundation/Console/ConfigClearCommandTest.php`
- new `tests/Integration/Foundation/Console/RouteClearCommandTest.php`
- new `tests/Integration/Foundation/Console/ViewClearCommandTest.php`
- new `tests/Foundation/ComposerScriptsTest.php`
- `tests/Integration/Foundation/Console/EventClearCommandTest.php`
- `tests/Integration/Foundation/Console/ClearCompiledCommandTest.php`
- `tests/Integration/Foundation/Console/StorageCommandTest.php`
- `tests/Foundation/Console/AboutCommandTest.php`
- `tests/Integration/Foundation/Console/AboutCommandTest.php`

### Deletion postcondition

At each owner, success means delete returned true or the target is absent on an immediate second observation:

```php
if (! $this->files->delete($path) && $this->files->exists($path)) {
    throw new RuntimeException("Unable to delete [{$path}].");
}
```

Keep the message and existence predicate appropriate to each command. Do not create a generic deletion helper.

### View clear

Treat `glob() === false` as an enumerable failure, attempt every resolved entry, capture the earliest failure, then throw it after the sweep. Do not stop after the first failed compiled view.

### Storage links

- `file_exists() || is_link()` determines whether a destination already exists, so broken symlinks count;
- only `--force` may replace an existing or broken link;
- forced link removal must itself satisfy delete-or-concurrent-absence before link creation continues;
- native link creation fails immediately only on `false` because Windows may return `null`, then verify `file_exists() || is_link()`;
- unlink checks `is_link()` directly so a broken link is removable, and reports success only after delete-or-absence is established.

### Other boundaries

- `ComposerScripts::clearCompiled()` fails if deletion leaves a config/packages cache;
- `AboutCommand::hasPhpFiles()` returns `false` for `glob() === false`, otherwise checks a non-empty result.

Tests cover stale-file false-success, concurrent disappearance, exhaustive view deletion, broken links, Windows-compatible link result handling, Composer cache persistence, and the deterministic overlong-path `glob(false)` case. No filesystem wrapper or multi-file transaction is added.

## 15. Publish config, route, and event caches only after validation

### Files

- `src/foundation/src/Console/ConfigCacheCommand.php`
- `src/foundation/src/Console/RouteCacheCommand.php`
- `src/foundation/src/Console/EventCacheCommand.php`
- `tests/Integration/Foundation/Console/ConfigCacheCommandTest.php`
- `tests/Integration/Foundation/Console/RouteCacheCommandTest.php`
- `tests/Integration/Foundation/Console/EventCacheCommandTest.php`

### Build-before-commit

Never clear the live cache before generation. Config and route subprocesses receive a guaranteed-nonexistent alternate cache path:

```php
$buildPath = $dumpPath . '.cache';

if ($this->files->exists($buildPath)) {
    throw new LogicException("The alternate cache path [{$buildPath}] already exists.");
}

$process = new Process(
    [
        PHP_BINARY,
        $this->hypervel->basePath('artisan'),
        'config:cache',
        '--dump-to=' . $dumpPath,
    ],
    $this->hypervel->basePath(),
    [
        'APP_CONFIG_CACHE' => $buildPath,
        'HYPERVEL_AUTOLOAD_PATH' => $this->resolveSubprocessAutoloadPath(),
    ],
);
$process->setTimeout(null);
```

For config, retain `run()` so the parent can read the child's structured `{ok, message}` payload and preserve the domain-specific serialization error; after validating that payload, throw `ProcessFailedException` if the process failed without a domain failure. Route has no structured failure payload, so use the symmetric `route:cache` argv and `APP_ROUTES_CACHE` with `mustRun()`. `$dumpPath` remains the checked subprocess payload file; `$buildPath` is a distinct checked-nonexistent cache path used only to make the child bootstrap from source. The alternate cache path must not exist, otherwise the child can load the old live cache instead of source state.

Because `tempnam()` creates an empty dump file before the child starts, config must distinguish that untouched file from a real payload before deserialization:

```php
$process->run();

$serialized = $this->files->get($dumpPath);

if ($serialized === '') {
    throw new ProcessFailedException($process);
}

$payload = @unserialize($serialized);

// Validate the payload shape and surface its domain message first.

if (! $process->isSuccessful()) {
    throw new ProcessFailedException($process);
}
```

The route child uses `mustRun()` before reading its dump; a successful child with an empty or non-array dump is a named invalid-payload failure. Suppress `unserialize()` warnings only at these immediately validated, trusted-child payload boundaries.

- Config: build source configuration, generate PHP, and validate the generated PHP result before publication.
- Route: build source routes without consulting the live route cache; if no routes or generation fails, leave the prior live cache untouched.
- Event: discover/listen from source directly rather than clearing then asking the application to reload.

Read the prior live mode when it exists and pass it to `Filesystem::replace()`:

```php
$mode = null;

if ($this->files->exists($livePath)) {
    $permissions = $this->files->chmod($livePath);

    if ($permissions === false) {
        throw new RuntimeException("Unable to determine permissions for [{$livePath}].");
    }

    $mode = octdec($permissions);
}

$this->files->replace($livePath, $contents, $mode);
```

Use the normal default when no prior artifact exists. Any alternate/temp cleanup is best effort while a primary generation/publication failure exists; if cleanup itself is the only failure and the temp remains, surface it.

### Tests and cost

Prove the old live cache survives child bootstrap failure, invalid generated PHP, no-routes result, discovery failure, and publication failure. Prove successful replacement is atomic and mode-preserving, and child builds cannot consume the stale live cache.

This is cold CLI work. Do not add a cache transaction service, rollback registry, shared publication trait, mutex, watcher, or retry.

## 16. Publish encrypted and plaintext environment files safely

### Files

- `src/foundation/src/Console/EnvironmentEncryptCommand.php`
- `src/foundation/src/Console/EnvironmentDecryptCommand.php`
- `tests/Integration/Console/EnvironmentEncryptCommandTest.php`
- `tests/Integration/Console/EnvironmentDecryptCommandTest.php`

### Changes

Use `Filesystem::replace()` and preserve exact mode when a target already exists. For a new target:

- decrypted plaintext environment file: `0600`;
- encrypted artifact: omit an explicit mode so normal umask behavior applies.

These owner-approved defaults protect newly created plaintext without silently changing an existing user-selected mode.

```php
$mode = null;

if ($targetExists) {
    $permissions = $this->files->chmod($target);

    if ($permissions === false) {
        throw new RuntimeException("Unable to determine permissions for [{$target}].");
    }

    $mode = octdec($permissions);
} else {
    $mode = 0600;
}

$this->files->replace($target, $contents, $mode);
```

This snippet is the decrypt path. The encrypt path performs the same existing-mode branch and leaves `$mode` as `null` for a new encrypted artifact. Apply the logic directly in each command; a shared mode trait or single-call helper would add indirection without another consumer. Do not silently tighten an existing plaintext file on `--force`; preserving exact user-selected mode is deliberate and consistent with mode-preserving replacement elsewhere.

Prune succeeds only when delete returns true or the source is concurrently absent. Restore exact prior `$_SERVER['HYPERVEL_ENV_ENCRYPTION_KEY']` presence/value in test `finally` blocks.

Tests cover old-target survival after write failure, new/overwrite modes, prune false with remaining versus vanished source, and exact server-state restoration. Do not add an environment transaction trait or mode helper shared across unrelated commands.

## 17. Put generated-file publication at the Console owner

### Files

- `src/console/src/GeneratorCommand.php`
- `tests/Console/GeneratorCommandTest.php`
- `src/foundation/src/Console/ComponentMakeCommand.php`
- `src/foundation/src/Console/MailMakeCommand.php`
- `src/foundation/src/Console/NotificationMakeCommand.php`
- `tests/Integration/Generators/ComponentMakeCommandTest.php`
- `tests/Integration/Generators/MailMakeCommandTest.php`
- `tests/Integration/Generators/NotificationMakeCommandTest.php`

### Protected helper

Add one protected helper because the base writer and all three view writers need the same non-trivial operation:

```php
protected function replaceFile(string $path, string $contents): void
{
    $mode = null;

    if ($this->files->exists($path)) {
        $permissions = $this->files->chmod($path);

        if ($permissions === false) {
            throw new RuntimeException("Unable to determine the permissions for [{$path}].");
        }

        $mode = octdec($permissions);
    }

    $this->files->replace($path, $contents, $mode);
}
```

The base `handle()` calls this helper before test generation or success output. Component/Mail/Notification use it for their view/Markdown files. Replace raw `file_get_contents()` with checked `Filesystem::get()` and raw directory creation with `ensureDirectoryExists()`.

The helper is protected, not a public Filesystem API. It solves a repeated, concrete publication contract and introduces no generic transaction layer.

### Tests

- base generator write failure emits no success and creates no matching test;
- forced overwrite preserves target mode;
- Component/Mail/Notification view writes fail before success on publication error;
- checked stub reads and directory creation surface their named failures.

## 18. Make Foundation publishers and installers stop on failure

### Files

- `src/foundation/src/Console/ConfigPublishCommand.php`
- `src/foundation/src/Console/LangPublishCommand.php`
- `src/foundation/src/Console/StubPublishCommand.php`
- `src/foundation/src/Console/ApiInstallCommand.php`
- `src/foundation/src/Console/BroadcastingInstallCommand.php`
- `src/foundation/src/Console/VendorPublishCommand.php`
- `src/foundation/src/Console/InteractsWithComposerPackages.php`
- `tests/Integration/Foundation/Console/ConfigPublishCommandTest.php`
- new `tests/Integration/Foundation/Console/LangPublishCommandTest.php`
- new `tests/Integration/Foundation/Console/StubPublishCommandTest.php`
- `tests/Integration/Foundation/Console/ApiInstallCommandTest.php`
- `tests/Integration/Foundation/Console/BroadcastingInstallCommandTest.php`
- `tests/Foundation/Console/VendorPublishCommandTest.php`
- `tests/Testbench/Foundation/Console/VendorPublishCommandTest.php`

### Small files

Config, language, stub, route, bootstrap JS, Echo JS, and internal template files use checked reads, checked directory creation, and atomic mode-preserving replacement through the existing Filesystem API. The ported commands resolve that existing service locally rather than gaining a new constructor shape solely for this correction. Emit status only after the postcondition is established.

Do not retain native `realpath()` values as source keys where `false` can later reach a strict filesystem call. `ConfigPublishCommand::getBaseConfigurationFiles()` uses Symfony Finder's always-string `getPathname()` for both the name and source path. `LangPublishCommand` and `StubPublishCommand` keep their already-known constructed source paths as strings. Missing or unreadable sources then fail at `Filesystem::get()`, the checked owner boundary, rather than being converted into an empty array key and surfacing as an unrelated type error.

### Composer and subprocesses

Make the Composer helper truthful:

```php
protected function requireComposerPackages(string $composer, array $packages): void
{
    // construct the existing argv command
    (new Process($command, $this->hypervel->basePath(), ['COMPOSER_MEMORY_LIMIT' => '-1']))
        ->setTimeout(null)
        ->mustRun(fn (string $type, string $output) => $this->output->write($output));
}
```

Sanctum vendor publishing and Reverb installation use `Process::run([...])->throw()`. Broadcasting stops if `config:publish` returns failure. It checks migration-directory enumeration before examining filenames.

Port current package-manager safety flags:

```text
pnpm add --save-dev --ignore-scripts ...
yarn add --dev --ignore-scripts ...
npm install --save-dev --ignore-scripts ...
```

Bun remains unchanged. Keep node install/build failure as a warning because the command deliberately prints complete manual recovery commands.

Enable TTY for the node subprocess only on non-Windows systems where `PendingProcess::supportsTty()` confirms a usable terminal. Current Laravel enables TTY unconditionally on non-Windows systems, which aborts the installer in supported container and CI environments without a readable/writable `/dev/tty`; this corrects that upstream defect at the caller without weakening the Process contract.

Malformed `package.json` must throw through checked read/JSON decode rather than act as “dependency absent”.

### Vendor publishing

For arbitrary vendor files, check `Filesystem::copy()` and throw before status/event success:

```php
if (! $this->files->copy($from, $to)) {
    throw new RuntimeException("Unable to copy [{$from}] to [{$to}].");
}
```

Do not load arbitrary files into memory or add a bespoke atomic stream copier. The directory path already uses ordinary per-file Flysystem writes and the command cannot provide all-or-nothing multi-file semantics. The correct complete fix is truthful copy failure, not an inconsistent transaction illusion.

`status()` uses the configured path when `realpath()` returns `false` so empty/filtered directory publication cannot TypeError.

### Tests

- Composer/process/config failures stop all later writes and success output;
- source and existing destination survive failed small-file replacement;
- malformed package JSON and failed directory enumeration fail explicitly;
- npm/pnpm/yarn include `--ignore-scripts`, Bun does not;
- node installation reaches the process boundary without a usable TTY and still uses TTY when the existing capability probe supports it;
- node failure still prints manual commands;
- vendor copy false emits no status/event success;
- directory status tolerates missing real paths.

No Composer result wrapper, installer rollback, installer transaction, or generic publisher is added.

## 19. Restore current `Dispatchable` factory customization

### Files

- `src/foundation/src/Bus/Dispatchable.php`
- new `tests/Foundation/Bus/DispatchableTest.php`

### Change

Port current Laravel method order and route all success branches through:

```php
protected static function newPendingDispatch(mixed $job): PendingDispatch
{
    return new PendingDispatch($job);
}
```

`dispatch()`, both `dispatchIf()` branches, and both `dispatchUnless()` branches must call `static::newPendingDispatch(...)`. Add an overriding trait consumer fixture proving the conventional protected seam is honored.

This introduces one protected static call only when a pending dispatch object is constructed. It is the minimum current-Laravel extension seam, not a factory registry or container lookup.

## 20. Port retry-stopping exception configuration end to end

### Files

- `src/contracts/src/Debug/ExceptionHandler.php` PHPDoc only
- `src/foundation/src/Exceptions/Handler.php`
- `src/foundation/src/Configuration/Exceptions.php`
- `src/support/src/Facades/Exceptions.php`
- `src/queue/src/Worker.php`
- `tests/Foundation/FoundationExceptionsHandlerTest.php`
- `tests/Foundation/Configuration/ExceptionsTest.php`
- `tests/Queue/QueueWorkerTest.php`
- `src/boost/docs/queues.md`

### Handler API

Port current `dontRetry`, `dontRetryWhen`, and `shouldStopRetries` state/logic in upstream order. The configuration wrapper forwards boot-time declarations. Add the standard worker-lifetime warning to this shared mutation family.

```php
/** @var array<int, class-string<Throwable>> */
protected array $dontRetry = [];

/** @var array<int, Closure(Throwable): bool> */
protected array $dontRetryCallbacks = [];

public function dontRetry(array|string $exceptions): static
{
    $exceptions = Arr::wrap($exceptions);

    $this->dontRetry = array_values(array_unique(array_merge($this->dontRetry, $exceptions)));

    return $this;
}

public function dontRetryWhen(callable $dontRetryWhen): static
{
    if (! $dontRetryWhen instanceof Closure) {
        $dontRetryWhen = Closure::fromCallable($dontRetryWhen);
    }

    $this->dontRetryCallbacks[] = $dontRetryWhen;

    return $this;
}

public function shouldStopRetries(Throwable $e): bool
{
    if (Arr::first(
        $this->dontRetry,
        static fn (string $type): bool => $e instanceof $type,
    ) !== null) {
        return true;
    }

    return array_any(
        $this->dontRetryCallbacks,
        static fn (Closure $dontRetryCallback): bool => $dontRetryCallback($e) === true,
    );
}
```

Keep `shouldStopRetries` off the native contract because it is not behavior every conforming exception handler must implement; advertise it as `@method bool shouldStopRetries(Throwable $e)` in the contract PHPDoc and add the three public methods to facade metadata.

### Queue decision point

After max-attempt and max-exception checks, before dispatching the job exception event:

```php
if (method_exists($this->exceptions, 'shouldStopRetries')
    && $this->exceptions->shouldStopRetries($e)) {
    $this->failJob($job, $e);
}
```

Match current Laravel's exact ordering and failure-state behavior. Do not return after `failJob()`: failing the job makes the later release guard false, while the job-exception event must still be dispatched. The `method_exists` guard preserves custom handler implementations. It executes only after job failure reaches this decision point; successful jobs gain no work.

### Tests/docs

Cover class-list and callback stopping, non-matches, max-attempt precedence, exception-event ordering, custom handlers without the method, configuration/facade forwarding, and concise usage examples.

Do not add a rule object, registry beyond current arrays/callbacks, contract method, or per-job success-path hook.

## 21. Add preferred JSON responses and health JSON

### Files

- `src/foundation/src/Configuration/ApplicationBuilder.php`
- `src/foundation/src/resources/health-up.blade.php`
- new `src/http/src/Middleware/PrefersJsonResponses.php`
- `src/testbench/src/Workbench/Workbench.php`
- `tests/Foundation/FoundationApplicationBuilderTest.php`
- new `tests/Http/Middleware/PrefersJsonResponsesTest.php`
- `tests/Auth/RequirePasswordMiddlewareTest.php`
- new `tests/Integration/Foundation/Configuration/PrefersJsonTest.php`
- new `tests/Integration/Foundation/Configuration/PrefersJsonDisabledTest.php`
- `tests/Integration/Foundation/ExceptionHandlerTest.php`
- `tests/Integration/Foundation/Support/Providers/RouteServiceProviderHealthTest.php`
- `tests/Testbench/Integrations/RouteServiceProviderHealthTest.php`
- `tests/Testbench/Workbench/DiscoversTest.php`
- `src/boost/docs/middleware.md`
- `src/boost/docs/deployment.md`

### Preferred JSON middleware

Add `ApplicationBuilder::prefersJsonResponses()` and prepend the middleware through the existing idempotent Kernel API. The middleware rewrites only missing/empty or all-wildcard Accept values. Preserve a non-null broad original in `X-Original-Accept` before setting JSON preference.

```php
public function prefersJsonResponses(bool $prefer = true): static
{
    if (! $prefer) {
        return $this;
    }

    $this->app->booted(function (): void {
        $this->app->make(HttpKernel::class)->prependMiddleware(PrefersJsonResponses::class);
    });

    return $this;
}

protected function acceptHeaderIsBroad(?string $accept): bool
{
    if ($accept === null || trim($accept) === '') {
        return true;
    }

    foreach (explode(',', $accept) as $value) {
        $value = strtolower(trim($value));

        if ($value === '') {
            continue;
        }

        $position = strpos($value, ';');

        if ($position !== false) {
            $value = trim(substr($value, 0, $position));
        }

        if (! in_array($value, ['*/*', 'application/*'], true)) {
            return false;
        }
    }

    return true;
}
```

`handle()` captures the original header once, stores every non-null broad value in `X-Original-Accept`, sets `Accept: application/json`, and delegates. Preserve specific non-JSON media types.

### Health route

Accept the current Request, compute the health status once, and negotiate:

```php
Route::get($health, function (Request $request) {
    $exception = null;

    try {
        Event::dispatch(new DiagnosingHealth);
    } catch (Throwable $throwable) {
        if (app()->hasDebugModeEnabled()) {
            throw $throwable;
        }

        report($throwable);
        $exception = $throwable;
    }

    $health = $exception === null ? 'up' : 'down';
    $status = $health === 'up' ? 200 : 500;

    if ($request->expectsJson()) {
        return response()->json([
            'status' => $health,
        ], $status);
    }

    return response(View::file(__DIR__ . '/../resources/health-up.blade.php', [
        'status' => $health,
    ]), status: $status);
});
```

Retaining the throwable as the failure sentinel deliberately fixes a current upstream defect: an exception with an empty message must still produce a down/500 response. The shared Blade derives both its status marker and copy from a strict `$status === 'down'` check and does not receive or expose the exception message.

Testbench's Workbench route is the second caller of the same Blade file. Migrate it to retain the `Throwable`, derive the same `$health` and HTTP `$status`, and pass only `['status' => $health]` to the view. It remains HTML-only; do not add request negotiation to that route.

### Tests/docs

Port the full introduction surface proportionately: missing/empty/wildcard/specific Accept, original header behavior, idempotent prepend, RequirePassword and exception-handler integration, JSON health up/down, empty-message failure status, and HTML preservation. The Foundation integration test owns the JSON and HTML contracts. The Workbench discovery test drives its distinct Blade caller through an empty-message diagnostic failure and asserts the 500 status, down markers, and absence of the healthy copy. Document the builder call and deployment health JSON concisely; do not document internal middleware mechanics as architecture.

No responder abstraction, middleware registry, request-global state, or content-negotiation service.

## 22. Complete HTTP testing APIs

### Files

- `src/foundation/src/Testing/Concerns/MakesHttpRequests.php`
- `tests/Foundation/Testing/Concerns/MakesHttpRequestsTest.php`
- `src/boost/docs/http-tests.md`

### Changes

Port `query()` and `queryJson()` in current upstream order before `json()` with Hypervel's `Stringable|string`, `TestResponse`, and option types. Their names refer to the HTTP `QUERY` method, not the URL query string: `query()` sends form data in the request body and `queryJson()` sends a JSON request body.

```php
public function query(Stringable|string $uri, array $data = [], array $headers = []): TestResponse
{
    $server = $this->transformHeadersToServerVars($headers);
    $cookies = $this->prepareCookiesForRequest();

    return $this->call('QUERY', $uri, $data, $cookies, [], $server);
}

public function queryJson(
    Stringable|string $uri,
    array $data = [],
    array $headers = [],
    int $options = 0,
): TestResponse {
    return $this->json('QUERY', $uri, $data, $headers, $options);
}
```

Change only `withoutMiddleware()` visibility from protected to public; keep implementation unchanged.

Port current Laravel's two request-body tests into the existing Foundation test file. Retain the explicit `QUERY` method assertion and the distinction between `input()` / `post()` body data and an empty `query()` bag. That file already drives the complete Hypervel Testbench, coroutine waiter, request conversion, kernel, request context, and routing path; do not duplicate the same coverage in the integration file. Docs list the new helpers beside existing request methods, clarify the body-bearing HTTP method, and retain the already documented public middleware bypass.

This is test-only. Do not add `QUERY` to `Router::$verbs`, a generic method dispatcher, request builder, or native server test without a verified server-boundary risk.

## 23. Port route-list source paths and fix reflection `false`

### Files

- `src/foundation/src/Console/RouteListCommand.php`
- `tests/Foundation/Console/RouteListCommandTest.php`
- `tests/Foundation/Console/RouteListCommandHelperTest.php`

### Changes

Port current CLI/JSON `path` output and preserve current action formatting. Guard every reachable reflection filename boundary:

```php
$path = (new ReflectionFunction($route->getAction('uses')))->getFileName();

return is_string($path) ? $path : null;
```

```php
$path = $reflection->getFileName();

if (! is_string($path)) {
    return false;
}
```

`formatActionForCli()` compresses vendor actions only when the filename is a string. Internal closures/classes therefore produce null/not-vendor rather than a `TypeError`.

Merge meaningful upstream CLI, JSON, closure, and controller cases into the existing test instead of creating duplicate command suites. Add `: void` to every touched test and fixture method in `RouteListCommandHelperTest` where PHP permits it. No path resolver abstraction.

## 24. Make guest redirects genuinely nullable

### Files

- `src/auth/src/AuthenticationRedirects.php`
- `src/auth/src/AuthManager.php`
- `src/support/src/Facades/Auth.php`
- `src/foundation/src/Configuration/Middleware.php`
- `src/foundation/src/Exceptions/Handler.php`
- `tests/Auth/AuthManagerTest.php`
- `tests/Auth/RequirePasswordMiddlewareTest.php`
- `tests/Foundation/Configuration/MiddlewareTest.php`
- `tests/Foundation/FoundationExceptionsHandlerTest.php`
- `src/boost/docs/authentication.md`

### Changes

At the lower owner, distinguish the two null contracts:

```php
public static function redirectGuestsTo(callable|string|null $redirect): void
{
    $redirect = $redirect === null
        ? static fn () => null
        : $redirect;

    self::redirectTo(guests: $redirect);
}
```

`redirectTo(null)` remains a no-op that leaves the side unchanged. Expose the nullable guest path through AuthManager, facade metadata, and Foundation's middleware configurator. Preserve ApplicationBuilder's default login callback.

In Handler, a falsy resolved target yields a no-content 401 rather than `redirect()->guest(null)`:

```php
$redirectTo = $exception->redirectTo($request);

if (! $redirectTo) {
    return response()->noContent(401);
}

return redirect()->guest($redirectTo);
```

Match current source's JSON branch and exact helper calls.

Tests cover explicit null, callback null, unchanged `redirectTo(null)`, default login behavior, manager/facade/config forwarding, JSON 401, no-content non-JSON 401, and normal redirect. Document the public nullable behavior concisely.

No new redirect service or request state is added.

## 25. Correct coroutine-aware database testing and port current assertions

### Files

- `src/foundation/src/Testing/Concerns/RunTestsInCoroutine.php`
- `src/foundation/src/Testing/RefreshDatabase.php`
- `src/foundation/src/Testing/LazilyRefreshDatabase.php`
- `src/foundation/src/Testing/Concerns/InteractsWithDatabase.php`
- `tests/Foundation/FoundationInteractsWithDatabaseTest.php`
- `tests/Foundation/Testing/Concerns/InteractsWithDatabaseTest.php`
- `tests/Foundation/Testing/RefreshDatabaseTest.php`
- new `tests/Foundation/Testing/LazilyRefreshDatabaseTest.php`
- `tests/Integration/Database/DbPoolTeardownLifecycleTest.php`
- `tests/Integration/Database/EloquentTransactionWithAfterCommitUsingRefreshDatabaseTest.php`
- `tests/Integration/Database/EloquentTransactionWithAfterCommitUsingRefreshDatabaseOnMultipleConnectionsTest.php`
- `tests/Testbench/Databases/RefreshDatabaseTest.php`
- `tests/Testbench/Databases/MigrateWithHypervelMigrationsTest.php`
- `tests/Testbench/Databases/MigrateWithHypervelMigrationsUsingRefreshDatabaseTest.php`
- `src/boost/docs/database-testing.md`

### Runtime coroutine query

Expose one protected truth source and use it both for test invocation and DB trait decisions:

```php
protected function runsTestsInCoroutine(): bool
{
    return $this->runTestsInCoroutine;
}
```

Do not infer runtime behavior from trait presence.

### Lazy refresh ownership

Alias base coroutine setup/teardown methods in `LazilyRefreshDatabase`:

```php
use RefreshDatabase {
    refreshDatabase as baseRefreshDatabase;
    setUpRefreshDatabaseInCoroutine as baseSetUpRefreshDatabaseInCoroutine;
    tearDownRefreshDatabaseInCoroutine as baseTearDownRefreshDatabaseInCoroutine;
}
```

Override the inherited `setUpRefreshDatabaseInCoroutine()` and `tearDownRefreshDatabaseInCoroutine()` hooks as no-ops so generic trait iteration cannot begin a transaction and trigger refresh eagerly. `setUpLazilyRefreshDatabaseInCoroutine()` registers lazy hooks on every configured connection in the actual test coroutine; `tearDownLazilyRefreshDatabaseInCoroutine()` calls the aliased base teardown only when refresh completed. In opt-out mode, `refreshDatabase()` registers the hooks immediately in the normal execution context.

The first lazy callback:

1. marks refresh state before migration to prevent recursive hooks;
2. restores state if refresh fails;
3. toggles `mockConsoleOutput` in `try/finally`;
4. invokes the base refresh;
5. in coroutine mode invokes the aliased base coroutine transaction setup;
6. marks transaction teardown eligible only after success.

The callback uses this control flow:

```php
if (RefreshDatabaseState::$lazilyRefreshed) {
    return;
}

RefreshDatabaseState::$lazilyRefreshed = true;
$hasMockConsoleOutput = property_exists($this, 'mockConsoleOutput');
$shouldMockOutput = $hasMockConsoleOutput ? $this->mockConsoleOutput : null;

try {
    if ($hasMockConsoleOutput) {
        $this->mockConsoleOutput = false;
    }

    $this->baseRefreshDatabase();

    if ($this->runsTestsInCoroutine()) {
        $this->baseSetUpRefreshDatabaseInCoroutine();
    }
} catch (Throwable $throwable) {
    RefreshDatabaseState::$lazilyRefreshed = false;

    throw $throwable;
} finally {
    if ($hasMockConsoleOutput) {
        $this->mockConsoleOutput = $shouldMockOutput;
    }
}
```

Implement with the repository's actual properties and existing trait alias syntax. Teardown invokes the aliased base coroutine teardown only after a successful refresh. Make ordinary `RefreshDatabase` mock-output restoration exception-safe too.

### Current Laravel assertion APIs

Port current source/tests for:

- multiple row conditions in `assertDatabaseHas()` / `assertDatabaseMissing()`;
- multiple rows in `assertSoftDeleted()` / `assertNotSoftDeleted()`;
- iterable tables/models in `assertDatabaseEmpty()`;
- optional connection in `castAsJson()`;
- lazy-refresh hooks on every `connectionsToTransact()` connection.

### Tests

- no query means no migration;
- first query migrates exactly once in the actual test coroutine;
- non-default connections trigger refresh;
- `pool.testing_enabled=true` retains hooks through the owning test coroutine;
- the first user transaction has correct nesting;
- `$runTestsInCoroutine=false` works for RefreshDatabase and LazilyRefreshDatabase;
- migration failure restores lazy state and exact mock-output state;
- teardown runs only after successful refresh;
- current multiple-row, iterable-empty, and non-default JSON-cast assertions pass.

Document additive assertion shapes and lazy behavior proportionately. Do not change pool ownership, add a hook registry, resolver callback manager, or new test lifecycle abstraction.

Remove `RefreshDatabase::withoutModelEvents()`. A repository-wide caller search finds no consumer, current Laravel has no matching protected extension point, and the method's save/callback/restore body is itself exception-unsafe. Retaining or repairing a dead, non-upstream helper would add stale surface rather than capability. Use the typed config getter for `database.default` while this trait is being corrected.

## 26. Correct split-package metadata and provenance

### Files

- `src/foundation/composer.json`
- new `tests/Foundation/PackageMetadataTest.php`
- `src/foundation/README.md`

### Changes

Add sorted direct requirements for:

- `hypervel/cache` — exception throttling;
- `hypervel/concurrency` — Composer uninstall handling;
- `hypervel/encryption` — registered commands and Composer scripts;
- `hypervel/events` — unconditionally registered by Application.

Correct the existing `hypervel/core` ordering. Hand-edit this split-package manifest because it has no lockfile. Do not add indirect Mail/Notifications/Translation/Redis dependencies that Foundation does not directly use.

Add a focused metadata test mirroring the existing Contracts package test. Assert that `hypervel/cache`, `hypervel/concurrency`, `hypervel/encryption`, and `hypervel/events` are present in `require` with the repository's `^0.4` split-package constraint. Do not create a generic manifest-testing framework or attempt to rediscover all Composer dependencies at runtime.

Add:

```md
Ported from: https://github.com/laravel/framework
```

to the README in the established package style.

Retain the existing `Differences From Laravel` entry explaining the intentional Whoops omission and the custom `ExceptionRenderer` extension point. Do not reintroduce Whoops, Collision, their removed metadata, or replacement compatibility machinery.

## Documentation plan

Update user-facing documentation only where a public API, supported call shape, or important runtime limitation changes:

- `authentication.md`: nullable guest redirects and no-content fallback;
- `database-testing.md`: multiple-row/iterable assertions, connection-aware JSON casts, and lazy refresh behavior;
- `deployment.md`: JSON health checks;
- `http-tests.md`: `query()`, `queryJson()`, public `withoutMiddleware()`;
- `middleware.md`: `prefersJsonResponses()` in the existing global middleware configuration section;
- `queues.md`: retry-stopping exception configuration;
- `configuration.md`: JSON maintenance responses bypass stored redirects and templates, and the array driver's in-process/testing-only boundary.

Do not change `vite.md`: its existing provider-boot guidance already describes the correct lifecycle for the shared Vite instance.

Write in the existing task-first Laravel-style documentation voice. Prefer examples and public behavior over internal architecture. Do not document implementation details such as atomic temp files, context keys, teardown choreography, or cache-build subprocess paths.

## Required deletions and stale-state sweep

Implementation is incomplete until all superseded material is removed:

- duplicate Scout/connection external-service setup and teardown;
- dead Meilisearch/Typesense tracking arrays and no-caller helpers;
- dumper instance recursion booleans;
- dead typed-config call-site defaults for merged keys;
- false Worker maintenance “atomic” comment;
- ignored bool return handling and stale success paths in changed commands;
- raw read/write/copy/mkdir calls superseded by the checked owning boundary;
- `realpath()` results retained as publisher source keys where `false` can reach a strict filesystem API;
- missing native return types in every touched method and test where PHP or the inherited API permits the type;
- obsolete imports, properties, comments, fixtures, and tests that asserted old failures or duplicate ownership;
- any proposed generic finalizer/publication/delete/SDK/PID abstractions accidentally introduced during implementation.

Run broad `rg` searches across all `src/` and `tests/` for every removed property, helper, duplicate initializer, and old raw boundary. Do not leave compatibility wrappers or deprecated aliases.

## Testing and validation plan

### Per-file cadence

Work one file at a time with `apply_patch`. After each changed/new test file, run it immediately:

```bash
./vendor/bin/phpunit --no-progress path/to/TestClass.php
```

When a changed source file has multiple direct test owners, run the smallest relevant set before continuing. Unexpected source bugs or design contradictions return to a focused second-opinion loop before further editing.

### Focused groups

At minimum, run all changed files plus the complete affected groups:

- `tests/Foundation/Bootstrap`
- `tests/Foundation/Testing` and `tests/Foundation/FoundationInteractsWith*`
- `tests/Testbench` through the package-mode suite
- `tests/Foundation/Console` and `tests/Integration/Foundation/Console`
- Foundation maintenance, Application, Vite, renderer, and Blade integration tests
- Auth tests affected by redirects
- HTTP middleware/request tests affected by JSON preference
- Queue Worker/retry tests
- Database testing, `DbPoolTeardownLifecycleTest`, and both Eloquent after-commit RefreshDatabase integration tests
- Console generator tests and Foundation generator/publisher/install tests.

Run external-service integration tests when their service credentials are configured; otherwise verify the deterministic opt-in/lifecycle unit coverage and inspect normal skips.

### Static/style/full gates

After focused tests are green:

```bash
composer fix
git diff --check
```

`composer fix` is the authoritative final gate because it runs formatting, both PHPStan configurations, the complete parallel suite, Testbench package mode, and dogfood coverage in repository order. Do not duplicate those full gates immediately before invoking the aggregate command.

### Failure-injection matrix

The final suite must prove these classes of failure rather than only success:

| Boundary | Injected failure | Required invariant |
|---|---|---|
| Teardown | each independent phase throws | all later phases run; earliest throwable identity survives |
| Testbench wrapper | return/throw before or after parent | parent phase runs exactly once |
| Algolia setup | client install/probe throws | exact prior SDK client restored |
| Clock/dumper/env | callback/output/resolver throws | exact state restored in `finally` |
| Maintenance | failed write/decode/delete/cache operation | old valid state survives or named failure surfaces |
| Cache publication | child build/validation/write fails | previous live cache remains byte-for-byte and mode-correct |
| Generator/installer | read/write/copy/process fails | no false success; prior destination retained where atomic replacement applies |
| Publisher discovery | Finder/constructed source cannot be read | string path reaches the checked filesystem owner; no `realpath(false)` key or unrelated `TypeError` |
| Native reflection/file mapping | filename/read returns `false` | nullable/fallback contract, never `TypeError` |
| Namespace discovery | malformed PSR-4 values or missing real paths | existing named `RuntimeException`, never a false-to-false match |
| Health endpoint | diagnostic throws with an empty message | JSON and HTML both remain down/500 |
| Lazy DB refresh | first migration/hook fails | refresh state and mock output restored; no invalid teardown |

## Fresh self-review checklist

After implementation and `composer fix`, review the diff without trusting this plan:

1. trace every changed caller and callee across `src/` and `tests/`;
2. verify all Laravel additions against current default-branch source, tests, fixtures, contracts, metadata, and docs—not the historical PR diff;
3. confirm every process-global mutator is boot-only or restored exactly;
4. confirm every coroutine-local key has class-specific ownership, nested behavior, and `finally` cleanup;
5. confirm all teardown phases preserve chronological failure precedence and execute exactly once;
6. confirm no native `false` can reach a strict string/array/count/reflection API;
7. confirm publication never deletes the prior valid artifact before the replacement validates;
8. confirm existing mode is preserved where specified and new plaintext alone defaults to `0600`;
9. confirm all success output/events occur only after their postcondition;
10. confirm ArrayMaintenance documentation cannot be mistaken for Swoole multiworker coordination;
11. confirm successful request/job hot paths gained no container lookup, lock, allocation-heavy wrapper, retry, poll, logging, or yield;
12. confirm the only successful-dispatch cost is the required protected `newPendingDispatch()` call;
13. confirm query payload memory is bounded and no Octane/reset machinery was copied;
14. confirm no generic lifecycle/publication/deletion/PID/SDK/SQL abstraction was introduced;
15. confirm no stale properties, helpers, tests, comments, imports, or docs describe replaced behavior;
16. verify the master audit still contains the complete 72-package set, including unchecked `grpc`, then update the Foundation ledger entry and audit routing/checklist only after implementation, validation, self-review, and code-review sign-off.

## Performance and overengineering assessment

The plan changes no ordinary HTTP hot path except already-required public behavior:

- maintenance middleware adds only failure-path handling around calls it already makes;
- Vite checks occur on file access/cache misses;
- renderer changes reduce retained request memory;
- dumper context work occurs only during dumps;
- retry stopping adds one `method_exists` check only after a job failure;
- `newPendingDispatch()` adds one protected static call during dispatch construction;
- JSON preference middleware runs only when explicitly enabled;
- health failure tracking retains the already-thrown object only for the duration of a health request;
- namespace-shape validation occurs only on the lazy lookup that is cached after success;
- all atomic publication and mode work is cold CLI/test work;
- exhaustive teardown is test or termination work.

The design deliberately rejects request-scoped Application/Vite clones, locks, registries, state machines, retry loops, watchers, PID incarnation tracking, shared transaction/publication/delete helpers, a streaming vendor-copy transaction, an SQL parser, PHPUnit meta-harnesses, and coroutine-local Carbon clocks. None solve a verified problem more cleanly than the existing primitive at the owning boundary.

The result is complete rather than minimal-churn: shared owners such as `GeneratorCommand`, Auth redirects, and the runtime coroutine flag are corrected once and every consumer follows. It is restrained rather than incomplete: every new mechanism has a concrete job, and every accepted native/failure path receives regression coverage.

## Completion criteria

This plan is implemented only when:

- every accepted workstream above is complete at its lowest owner;
- every affected source, contract, facade, config, test, fixture, metadata, comment, and doc surface is consistent;
- every required deletion/stale-code sweep is clean;
- focused tests and `composer fix` pass;
- a fresh full diff review finds no lifecycle, parity, native-boundary, performance, or overengineering issue;
- independent code review signs off;
- the companion audit ledger records the final implemented result, validation, public API/config outcome, and pending cross-package revalidation;
- the master audit checklist still matches all 72 first-level `src/` packages, including the unchecked `grpc` work unit;
- the owner reviews the signed-off summary and explicitly approves commits.
