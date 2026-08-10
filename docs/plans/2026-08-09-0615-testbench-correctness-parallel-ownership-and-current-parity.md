# Testbench Correctness, Parallel Ownership, and Current Parity

**Status:** Implementation-ready

## Objective

Correct the verified Testbench lifecycle, configuration, filesystem, migration, and metadata defects while preserving its public Laravel/Orchestra-shaped APIs and Hypervel's parallel test architecture.

The finished design must:

- keep the committed skeleton immutable and use one disposable runtime clone per PHPUnit worker;
- preserve `TEST_TOKEN`, `TESTBENCH_BASE_PATH`, worker-local paths, and external-service isolation;
- make partial setup transactional and teardown exhaustive without adding registries, retries, locks, or filesystem transaction abstractions;
- preserve the earliest failure while still running independent cleanup;
- reject unsupported file identifiers before performing filesystem operations;
- retain current Hypervel Testbench and Orchestra-shaped APIs unless an approved omission is recorded;
- add no application hot-path work: every change is in Testbench bootstrap, CLI, the Foundation test lifecycle, the Testing parallel runner, tests, or package metadata.

Primary references:

- `AGENTS.md`
- [`2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md`](2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md)
- [`2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md`](2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md)
- [Orchestra Testbench Core 11.x at `632dc14` (11.4.0)](https://github.com/orchestral/testbench-core/tree/632dc141b25414804ceb1de09be7a9eaf8d61663), checked out locally at repo-root-relative `../../../_tmp/orchestral-testbench-core`

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

## Retained architecture and contracts

- The committed `src/testbench/hypervel` skeleton remains read-only. Tests continue using the runtime clone selected through `BASE_PATH` and `TESTBENCH_BASE_PATH`.
- Runtime clone names retain worker identity and PID. Stale foreign clones remain a serialized, exhaustive best-effort sweep; no retry loop or cleanup exception is added.
- `Hypervel\Testbench\TestCase` and `Hypervel\Tests\TestCase` retain their inherited coroutine behavior. No test gains a parallel helper, lock, new context state, or package-specific base class.
- `ParallelTesting::tempDir()` remains the scratch-directory API. Existing service traits continue to own per-worker external-service isolation.
- Testbench bootstrap and commands remain development-only work. No production application request, command, database, or package runtime path gains work from this plan.
- `WithConfig` remains eager so configuration is finalized before providers register. Orchestra's additive deferred mode is intentionally omitted because it conflicts with Hypervel's worker-startup configuration model.
- Public Testbench and Orchestra APIs otherwise remain compatible. The approved broken bundled Pest integration is removed because it was never loaded and the real integration belongs to the separate Pest plugin.
- Testbench cleanup remains explicit ownership, not a generic filesystem transaction framework.

## Finding map

| ID | Final disposition |
|---|---|
| `testbench-07` | Require environment-copy success, create the runtime clone root with mode `0700`, and verify creation. Keep stale deletion best-effort. |
| `testbench-08` | Restore exact process globals, make every owned Testbench application terminate-then-flush, and keep teardown sound after failed replacement. |
| `testbench-09` | Normalize empty/null YAML mappings and reject malformed roots with a named exception. |
| `testbench-10` | Restrict the private `src/testbench/testbench.yaml` fallback to the `hypervel/components` monorepo root. |
| `testbench-11` | Preserve top-level default configuration keys by collapsing filtered maps. |
| `testbench-12` | Recognize SQLite memory and URI identifiers before selecting the fallback connection. |
| `testbench-13` | Make SQLite file swaps lossless, restore global config after purge failures, and remove dead absolute-path cleanup bookkeeping. |
| `testbench-14` | Let attribute construction, reflection, target, and resolver failures propagate; remove invalid Fortify placements. |
| `testbench-15` | Preserve eager worker-startup `WithConfig` timing and record the intentional omission of Orchestra's deferred mode. |
| `testbench-16` | Remove the unused bundled Pest hook implementation and its dead branches. |
| `testbench-17` | Make terminating callbacks detached, exhaustive, non-replaying, and first-failure preserving. |
| `testbench-18` | Check CLI copy/delete ownership and preserve user files across failed synchronization. |
| `testbench-19` | Publish Workbench symlinks through a verified staged replacement and pin safe backup-cleanup failure behavior. |
| `testbench-20` | Keep vendor-link actions from disposing caller-owned applications and roll back owned links before discovery returns. |
| `testbench-21` | Return failure from purge/install/seeder paths when their work fails. |
| `testbench-22` | Own only migration batches created by the current setup, honor migration status, and verify exact rollback. |
| `testbench-23` | Use atomic route-file replacement and clean exact worker route caches after failed reloads. |
| `testbench-24` | Make published-file cleanup exhaustive and failure-reporting without declaring a conflicting trait property. |
| `testbench-25` | Reuse the shared path joiner and safely quote environment values, preserving the string `"0"`. |
| `testbench-26` | Correct split-package dependencies and add proportionate split metadata coverage. |
| `testbench-27` | Record that purge shutdown registration was already removed by Testbench cleanup work. |
| `testbench-28` | Remove dead annotations/branches and complete Testbench test method typing. |
| `testbench-29` | Reject invalid configured seeder classes instead of silently dropping them. |
| `testing-18` | Terminate then flush each parallel-runner application while retaining token and failure ownership. |

## Implementation

### 1. Runtime clone and application lifecycle

Files:

- `src/testbench/src/Bootstrapper.php`
- `src/testbench/src/TestCase.php`
- `src/testbench/src/Concerns/CreatesApplication.php`
- `src/testbench/src/Concerns/InteractsWithTestCase.php`
- `src/testbench/src/Foundation/Application.php`
- `src/foundation/src/Testing/Concerns/InteractsWithTestCaseLifecycle.php`
- `src/testing/src/Concerns/RunsInParallel.php`
- `phpstan.neon.dist`
- focused tests under `tests/Testbench/Bootstrapper*`, `tests/Testbench/CreatesApplicationTest.php`, `tests/Testbench/TestCaseTest.php`, and `tests/Testbench/TimezoneTest.php`
- `tests/Foundation/Testing/Concerns/InteractsWithTestCaseTest.php`
- `tests/Testing/ParallelRunnerTest.php`
- `tests/Sentry/SentryTestCase.php`
- `tests/Sentry/Console/AboutCommandIntegrationTest.php`
- `tests/Sentry/Features/RedisIntegrationTest.php`
- `tests/Testbench/Concerns/WithCachedStateTest.php`

`src/testbench/src/Console/Commander.php` participates in temporary-application ownership and is specified in section 4.

#### Runtime clone ownership (`testbench-07`)

Keep the existing foreign-path collision diagnostic and active-owned-path reuse. For a new path, pre-create the unique worker/PID clone root with owner-only permissions through the warning-suppressing Filesystem branch, then copy into it. An already active path is overlaid as it is today rather than recreated. Fail immediately if root creation, directory copying, or the required environment-file copy fails. If later setup fails, remove only the clone created by this attempt and preserve the setup exception.

```php
$reusesActivePath = $runtimePath === static::$runtimePath
    && $filesystem->isDirectory($runtimePath);

if (! $reusesActivePath && $filesystem->exists($runtimePath)) {
    throw new RuntimeException(
        "Unable to create the Testbench runtime copy because [{$runtimePath}] already exists."
    );
}

if (! $reusesActivePath
    && ! $filesystem->makeDirectory($runtimePath, 0700, recursive: true, force: true)) {
    throw new RuntimeException("Unable to create runtime path [{$runtimePath}].");
}

if (! $filesystem->copyDirectory($sourcePath, $runtimePath)) {
    throw new RuntimeException(
        "Unable to create the Testbench runtime copy at [{$runtimePath}]."
    );
}

if (Env::has('TESTBENCH_PACKAGE_TESTER')) {
    static::copyPackageEnvironmentFile($filesystem, $runtimePath, $workingPath);
}

$startIdentity = static::processStartIdentity($pid);

if ($startIdentity !== null) {
    $filesystem->replace(
        join_paths($runtimePath, static::RUNTIME_PROCESS_MARKER),
        json_encode([
            'pid' => $pid,
            'started_at' => $startIdentity,
        ], JSON_THROW_ON_ERROR),
        0600,
    );
}
```

Inside `copyPackageEnvironmentFile()`, preserve the nullable source boundary and check only a real copy attempt:

```php
if ($environmentFile !== null
    && ! $filesystem->copy($environmentFile, join_paths($runtimePath, '.env'))) {
    throw new RuntimeException('Unable to copy the Testbench environment file.');
}
```

Creation rollback must use the same ownership gate. A failed overlay did not create the active path and must not delete it:

```php
} catch (Throwable $exception) {
    if (! $reusesActivePath) {
        try {
            static::deleteRuntimeDirectory($runtimePath);
        } catch (Throwable) {
            // Preserve the runtime-creation failure when rollback also fails.
        }
    }

    throw $exception;
}
```

Do not change `Filesystem::copyDirectory()`, retry removal, or make foreign stale cleanup fatal. The root mode blocks group traversal even when copied descendants retain their source modes.

Tests must prove a new root is `0700`, an active owned path is reused without a second creation attempt, a failed overlay leaves that active path and its existing contents intact, a false environment copy aborts setup, failed partial creation rolls back its own path, and stale cleanup keeps its existing worker/PID serialization behavior.

Runtime-copy regressions use at most one creation per `withRuntimeCopyEnvironment()` block. A test that replaces `Bootstrapper::$runtimePath` before creation must use `#[PreserveGlobalState(false)]` and `#[RunInSeparateProcess]`: `BootstrapperTest` extends the non-application base and `tests/bootstrap.php` does not boot Testbench, so the child starts without a worker clone for the same-PID stale sweep to expose. Document both invariants on the shared helper; keep test-local comments for distinct isolation reasons.

#### Exact process-global restoration and application ownership (`testbench-08`)

Capture the timezone before mutation. Register one idempotent restoration closure before changing it, invoke that closure from PHPUnit destruction or standalone application termination as appropriate, and clean every partially created application if any later bootstrap or resolving callback throws.

```php
$timezone = date_default_timezone_get();
$restored = false;
$restore = static function () use (&$restored, $timezone): void {
    if (! $restored) {
        date_default_timezone_set($timezone);
        $restored = true;
    }
};
```

Once `CreatesApplication::resolveApplication()` returns, wrap every remaining bootstrap step, including cached-state preparation. On failure, terminate the partial application and then flush it even when termination throws. Preserve the bootstrap failure over cleanup failures; without a primary failure, preserve the first cleanup failure. `Foundation\Application::createApplication()` applies the same rule when its resolving callback throws.

Define one `protected static` helper on `CreatesApplication` to serve the trait bootstrap catch, the standalone resolving-callback catch, and both vendor-symlink wrappers. Import `Throwable`. The helper attempts both operations and returns the first cleanup throwable so each caller can apply its own precedence rule. Do not expose it publicly or create a cross-package lifecycle abstraction.

```php
protected static function terminateAndFlushApplication(ApplicationContract $app): ?Throwable
{
    $failure = null;

    try {
        $app->terminate();
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }

    try {
        $app->flush();
    } catch (Throwable $throwable) {
        $failure ??= $throwable;
    }

    return $failure;
}
```

The order is required: standalone Testbench setup disables `putenv` and registers `Env::enablePutenv()` only as an application terminating callback. `flush()` clears that callback without running it, while `terminate()` runs it without clearing it. Terminate-then-flush restores the process global and prevents callback replay.

Foundation test teardown must execute and clear `beforeApplicationDestroyed` callbacks even when `$this->app` is already `null`; only database resolver cleanup, pool cleanup, parallel-test callbacks, and application flush remain gated by a live application. This preserves inherited `APP_ENV`, `#[WithEnv]`, user cleanup, and route cleanup after application creation or reload fails. Preserve `callbackException`, clear all lifecycle arrays, and keep the earliest failure.

Application construction installs the replacement as the global container before boot completes, while `refreshApplication()` assigns `$this->app` only after creation succeeds. A failed replacement can therefore leave `$this->app` null or pointing at the outgoing application while the global container points at the flushed partial replacement. In `InteractsWithTestCase::tearDownTheTestEnvironmentUsingTestCase()`, invoke `AfterEach` attributes only when `$this->app` is live; always clear `static::$testCaseMethodTestingFeatures` outside that gate. Keep `setUpTheTestEnvironmentUsingTestCase()` fail-fast through `hypervel_or_fail()`: it runs only after successful application creation, so null there is a framework defect rather than a cleanup state.

The null-app teardown gate is safe because setup is equally application-gated. `WithImmutableDates` and `DefineDatabase` cannot have run when initial creation failed, and an outgoing application's teardown has already run them before a failed reload. `UsesVendor` is the only `AfterEach` implementation that consumes the application. A future attribute that must clean up without an application requires a different contract; teardown must not pass a stale or empty container to satisfy the current signature.

The three Sentry mid-test replacement sites must call `reloadApplication()` rather than raw `refreshApplication()`, so the outgoing application is torn down before the replacement and the new application receives normal setup. Verify the Redis integration path because full reload closes Mockery before the test continues. Keep raw `refreshApplication()` in `WithCachedStateTest`: full reload resets its cached-state traits, changes `[false, true]` observations to `[false, false]`, and defines routes twice. Add a concise WHY comment at that call. This retained low-level refresh is safe because the second boot is deterministic and the test owns no database, pool, or external service; do not add a disposal workaround.

Do not register test-duration restoration with `$app->terminating()`: the HTTP test kernel invokes application termination after every request. `beforeApplicationDestroyed` remains the test-lifetime owner, so `#[WithEnv]` and inherited `APP_ENV` survive multiple requests and restore at teardown.

Keep the shared `PHPUnitTestCase` plus `method_exists(..., 'beforeApplicationDestroyed')` capability guard. `CreatesApplication` is an open trait consumed by both PHPUnit test cases and the standalone Testbench application, so replacing the guard with a concrete consumer type would break valid composition. PHPStan specializes the trait per consumer and reports the `beforeApplicationDestroyed` capability guard in `createApplication()` as already narrowed in those consumer contexts, where an inline directive in the trait cannot bind. Remove that inert inline directive and use the owner-approved message-plus-identifier-plus-path exception in `phpstan.neon.dist`:

```neon
- message: "#^Call to function method_exists\\(\\) with .+ and 'beforeApplicationDe[^']*' will always evaluate to true\\.$#"
  identifier: function.alreadyNarrowedType
  path: src/testbench/src/Concerns/CreatesApplication.php
```

The rule intentionally covers this capability name throughout the one trait file, including identical direct-analysis guards. Retain the four active line-specific directives around environment loading because they suppress at the narrowest source location and remain correct if the configuration exception is later removed. Do not add `@phpstan-impure`, a helper, an interface, or a different lifecycle route merely to alter PHPStan's model.

Current Orchestra 11.4.0 adds `@phpstan-ignore method.notFound` to the dynamic package-bootstrapper call in `resolveApplicationBootstrappers()`. Hypervel's type analysis accepts its corresponding call without suppression, so do not add the unused annotation. That site is independent of both the capability-guard exception and the four environment-loading directives.

Tests must prove:

- reachable failures after application resolution terminate then flush the partial application, using `defineEnvironment()` and standalone resolving callbacks as the injection points;
- termination failure cannot skip flush, and the bootstrap/resolving failure remains primary;
- null-application Foundation teardown runs and clears destruction callbacks while retaining existing live-application cleanup ordering;
- failed application replacement does not run application-bound `AfterEach` callbacks against a stale or flushed container, cannot mask the bootstrap failure, and still clears parsed method features;
- the three Sentry replacement sites perform complete teardown/setup, including the existing Redis Mockery path;
- `WithCachedStateTest` retains its single route definition and `[false, true]` cache observations through deliberate raw refresh;
- `loadEnvironmentVariables = false` restores exact `APP_ENV` presence and values in `$_SERVER`, `$_ENV`, and `getenv()` after `defineEnvironment()` throws;
- `#[WithEnv]` remains active through two HTTP requests and restores only at test teardown;
- timezone and `putenv` restoration remain exact and idempotent.

Retain the existing direct restoration closures; do not add a registry or nesting counter.

Keep cached-state preparation inside the same catch boundary, but do not manufacture a failure test for its total, non-callback operations.

#### Parallel runner application cleanup (`testing-18`)

`RunsInParallel::forProcess()` creates a fresh application for each attempted parent-process token. Preserve the existing token boundary and order while completing application ownership:

1. create the application before installing the token resolver;
2. install the per-process token and invoke the callback;
3. clear the token resolver;
4. terminate the application;
5. flush it even when termination throws;
6. preserve the callback failure over cleanup failures, otherwise throw the first cleanup failure.

The resolver remains cleared before both cleanup operations, matching the ambient state in which the application was constructed. Testbench's terminating callback only restores `Env::enablePutenv()` and does not need the process token. Implement this single Testing-owned site inline; do not share Testbench helpers across packages.

Extend `tests/Testing/ParallelRunnerTest.php` to prove termination precedes flush, the ambient/null resolver is visible during termination, termination failure cannot skip flush, and callback failure remains primary. Retain every current attempted-token, application-freshness, teardown-exhaustion, and resolver-restoration assertion. This adds bounded parent-process cleanup once per attempted token, not worker-test, clone, service-isolation, coroutine, or application hot-path work.

### 2. Configuration and attribute boundaries

Files:

- `src/testbench/src/Foundation/Config.php`
- `src/testbench/src/Bootstrapper.php`
- `src/testbench/src/Foundation/Bootstrap/EnsuresDefaultConfiguration.php`
- `src/testbench/src/PHPUnit/AttributeParser.php`
- `src/testbench/src/Concerns/InteractsWithPHPUnit.php`
- `src/testbench/src/Attributes/WithConfig.php`
- `src/boost/docs/testbench.md`
- `src/testbench/README.md`
- `tests/Fortify/TwoFactorAuthenticationControllerTest.php`
- matching `tests/Testbench/Foundation/ConfigTest.php`, `tests/Testbench/DefaultConfigurationTest.php`, `tests/Testbench/PHPUnit/AttributeParserTest.php`, `tests/Testbench/Concerns/InteractsWithPHPUnitTest.php`, and `tests/Testbench/Attributes/WithConfigTest.php`

#### YAML shape and fallback (`testbench-09`, `testbench-10`)

Treat an empty YAML document as the supplied defaults, normalize explicit-null `purge` and `workbench` mappings to `[]`, and reject scalar or non-empty list roots with `InvalidArgumentException` naming the invalid root shape. PHP arrays cannot distinguish an explicit empty YAML mapping from an explicit empty list, and both are harmless empty configuration, so accept either empty form. Do not recursively validate nested settings; their typed consumers already own those errors.

```php
$parsed = Yaml::parseFile($filename);

if ($parsed === null) {
    $config = $defaults;
} elseif (! is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
    throw new InvalidArgumentException(
        'The Testbench configuration root must be a mapping.'
    );
} else {
    $config = $parsed;
}
```

After the file-found branch and immediately before constructing `Config`, validate those two documented mapping boundaries. This makes the invariant identical for parsed files, empty documents, no file, and directly supplied defaults without recursively validating their contents:

```php
foreach (['purge', 'workbench'] as $key) {
    $config[$key] ??= [];

    if (! is_array($config[$key])) {
        throw new InvalidArgumentException(
            "The Testbench [{$key}] configuration must be a mapping."
        );
    }
}
```

Use Composer's existing `InstalledVersions::getRootPackage()` dependency to permit the private fallback only when the root package is `hypervel/components`:

```php
if (static::hasConfigurationFile($workingPath)) {
    return $workingPath;
}

return InstalledVersions::getRootPackage()['name'] === 'hypervel/components'
    ? testbench_path()
    : $workingPath;
```

The monorepo regression must pin the Workbench provider and `dont-discover` entry loaded from `src/testbench/testbench.yaml`. Separate tests cover a split root whose YAML is found directly and a normal consumer without YAML receiving defaults rather than private fixtures. Do not add a redundant `hypervel/testbench` fallback arm.

#### Defaults and attributes (`testbench-11`, `testbench-14`)

Replace the key-destroying `values()` pipeline with `collapse()` after filtering configuration maps. Remove fixture configuration that masks the numeric-key regression.

```php
return $configurations
    ->filter(/* applicable configuration */)
    ->collapse()
    ->all();
```

Remove `rescue()` around attribute instantiation, reflection, resolver execution, and the outer attribute caches. Only an explicit resolver result of `null` omits an attribute. Constructor, target, reflection, and resolver failures must reach the test author unchanged.

`ResetRefreshDatabaseState` is class-only. Remove its four method placements from `TwoFactorAuthenticationControllerTest`; they were inert while `rescue()` swallowed PHP's wrong-target `Error`. Do not widen the target. A repository-wide target/repeatability sweep found no other invalid placements. Add a class-only fixture used on a method and assert `AttributeParser::forMethod()` propagates the target error; throwing-constructor and resolver tests do not exercise this PHP boundary.

#### Eager worker-startup config (`testbench-15`)

Keep Hypervel's original two-argument `WithConfig` implementation. It runs after configuration loading and before provider registration, so providers, route files, guards, watchers, and worker-lifetime services all observe one finalized startup configuration. Orchestra's additive deferred `defer` parameter runs only after every provider has booted and before the test body; it offers no valid Hypervel lifecycle that an explicit `config()->set()` in the test body cannot express more clearly.

Remove the branch's entire deferred mechanism: `$defer`, `FRAMEWORK_CONFIGURATION`, the `Str` import, `isFrameworkConfiguration()`, the framework-config inventory test, and the provider fixture that exists only to pin deferred behavior. Do not retain an ignored compatibility parameter.

After restoring the eager runtime code, add the required intentional-omission record immediately before the constructor:

```php
// Orchestra's deferred `defer` parameter is intentionally not ported: Hypervel
// configuration is process-global worker-startup state, so values must be applied
// before providers register. A test needing a post-boot value sets it in the test body.
/**
 * Create a new attribute instance.
 */
public function __construct(
    public readonly string $key,
    public readonly mixed $value,
) {
}
```

Replace the deferred tests with a counterfactual provider that records the configured value in both `register()` and `boot()`. The test must apply a package key through `#[WithConfig]` and assert both observations equal the attribute value; it must fail if application is delayed until `booted()`.

Record the closed difference in the other two required places:

- add an entry under the existing `Differences From Orchestra Testbench` README heading explaining the eager timing and explicit test-body alternative;
- add a `REMOVED:` comment where Orchestra's deferred tests would otherwise sit, naming the worker-startup conflict.

Restore both Boost documentation statements to eager timing. Do not retain `defer: false`, framework-prefix, nested-array replacement, or post-provider application guidance; those exist only for the rejected design.

### 3. SQLite path and file ownership

Files:

- `src/testbench/src/Bootstrap/LoadConfiguration.php`
- `src/testbench/src/Concerns/Database/InteractsWithSqliteDatabaseFile.php`
- existing SQLite/Commander tests plus focused failure-injection tests under `tests/Testbench/Concerns/Database/`

#### Identifier classification (`testbench-12`)

Use `SQLiteDatabase::isInMemory()` and `SQLiteDatabase::isUri()`. Select the `testing` fallback only for a missing ordinary local file.

```php
$usesLocalFile = ! SQLiteDatabase::isInMemory($database)
    && ! SQLiteDatabase::isUri($database);

if ($default === 'sqlite' && $usesLocalFile && ! is_file($database)) {
    // Existing fallback rewrite.
}
```

#### Lossless file swaps (`testbench-13`)

The helper owns filesystem files, so reject memory and URI identifiers before deriving backup names. Check all four moves and both copies. Assign backup ownership only after success, purge the SQLite connection before each swap, restore in `finally`, run independent restorations even after one fails, and preserve the original or earliest failure.

Resolve the stateless `Filesystem` through the current test application in both swap methods. This keeps ownership local while allowing the focused tests to inject false-return failures without adding a filesystem abstraction.

```php
$backup = "{$database}.backup-{$time}";

if (! $filesystem->move($database, $backup)) {
    throw new RuntimeException("Unable to back up SQLite database [{$database}].");
}

$ownedBackup = $backup;
```

Remove the absolute backup paths from `$this->files`; `InteractsWithPublishedFiles` rebases entries and cannot own them. Keep the existing crash-recovery glob hook. Do not introduce another cleanup collection.

`useActiveSqliteDatabasePath()` mutates worker-global configuration. Move the initial purge inside the protected region and nest final purge under config restoration so either purge may fail without leaving the active path installed:

```php
$config->set('database.connections.sqlite.database', $activeDatabase);

try {
    $this->purgeSqliteConnection();
    value($callback);
} finally {
    try {
        $this->purgeSqliteConnection();
    } finally {
        $config->set('database.connections.sqlite.database', $originalDatabase);
    }
}
```

Tests must inject failed backup, creation-copy, and restore operations; prove originals survive; cover distinct base/active paths; cover memory and URI rejection; and prove connection purges surround swaps. Separate regressions make the initial and final purge throw and assert the exact original config value is restored. Use the existing overridable purge method; add no new cleanup owner or filesystem operation.

Revalidate the current Database boundaries rather than duplicating them: `SQLiteDatabase::isInMemory()` and `isUri()` remain the identifier owners; migration repository and Migrator behavior remain unchanged; and `DatabaseConnectionResolver` may discard an unknown physical session connection. Testbench's explicit `DB::purge('sqlite')` before each file swap is stronger, remains required, and needs no second unknown-session cleanup path.

### 4. Console teardown and file actions

Files:

- `src/testbench/src/Foundation/Console/TerminatingConsole.php`
- `src/testbench/src/Console/Commander.php`
- `src/testbench/src/Foundation/Actions/CreateVendorSymlink.php`
- `src/testbench/src/Foundation/Bootstrap/CreateVendorSymlink.php`
- `src/testbench/src/Foundation/Console/Actions/{EnsureDirectoryExists,GeneratesFile,DeleteFiles,DeleteDirectories}.php`
- `src/testbench/src/Foundation/Console/Concerns/CopyTestbenchFiles.php`
- `src/testbench/src/Foundation/Console/{PurgeSkeletonCommand,InstallCommand}.php`
- `src/testbench/src/Foundation/Bootstrap/LoadMigrationsFromArray.php`
- `src/filesystem/src/Filesystem.php`
- `tests/Testbench/CommanderTest.php`, `tests/Testbench/Foundation/ApplicationTest.php`, and the corresponding tests under `tests/Testbench/Foundation/Console/`
- `tests/Testbench/Foundation/Bootstrap/LoadMigrationsFromArrayTest.php`
- `tests/Filesystem/FilesystemTest.php`

#### Exhaustive termination (`testbench-17`)

Detach the current LIFO callback list before invoking it. Run every detached callback, retain the first throwable, and clear callbacks registered reentrantly before returning or throwing so nothing replays.

```php
$callbacks = self::$beforeTerminatingCallbacks;
self::$beforeTerminatingCallbacks = [];
$failure = null;

foreach ($callbacks as $callback) {
    try {
        $callback();
    } catch (Throwable $throwable) {
        $failure ??= $throwable;
    }
}

self::$beforeTerminatingCallbacks = [];

if ($failure !== null) {
    throw $failure;
}
```

Commander must independently execute terminating callbacks, Workbench cleanup, and signal unregistration, preserving the first failure. Keep callback LIFO order because synchronized files depend on delete-then-restore.

#### Temporary application ownership (`testbench-08`, `testbench-20`, `testbench-29`)

`Foundation\Application::createVendorSymlink()` and `deleteVendorSymlink()` keep their upstream-shaped application return contracts. Each static wrapper owns its application until it returns successfully: if its action fails, terminate then flush locally and preserve the action failure.

`CreateVendorSymlink::handle()` must not flush its application. The action does not own the caller's lifecycle, and the existing inner flush clears terminating callbacks before the wrappers can terminate. More seriously, the documented `Foundation\Bootstrap\CreateVendorSymlink` extension passes the real application after providers boot and before the testing database resolver and kernel finish bootstrap; an action-owned flush destroys that live application mid-bootstrap. Remove the flush at the action boundary rather than compensating in each caller.

After a successful return, Commander owns that throwaway application. Wrap the complete `tap()` body that copies configuration and dotenv files, then terminate and flush on success or failure. The terminating-console callback must likewise dispose the application returned by `deleteVendorSymlink()`. Commander must use one private helper for these two sites; it attempts both operations and returns the first cleanup failure so the caller can preserve an earlier file/action/termination failure. The main `$this->app` remains command-process-owned and follows its existing lifetime.

This two-layer transfer is required because a failing static action never returns an application for Commander to clean, while a successfully returned application is no longer owned by the wrapper. The standalone resolving-callback boundary in section 1 separately covers failures exposed by seeder validation in Commander. Do not change the public return types or add a generic resource owner.

Keep the `UsesVendor` clone. Its container arrays are copied by value, so the action's `TESTBENCH_VENDOR_SYMLINK` marker remains isolated from the live test application while the attribute reads it to decide whether `afterEach` owns deletion. Returning a boolean from the action would create needless upstream API drift.

Tests must cover action failure before transfer, configuration/dotenv copy failure after transfer, delete cleanup inside the terminating callback, normal success, termination failure followed by flush, and primary-operation failure precedence over cleanup failures. Add a real application/bootstrapper regression proving the action leaves the application usable and preserves its terminating callbacks; caller-only tracking doubles cannot detect an inner flush.

#### File action postconditions (`testbench-18`)

- `EnsureDirectoryExists`: rely on the framework exception for directory creation; check only `.gitkeep` copy.
- `GeneratesFile`: require source copy success before deleting destination `.gitkeep` or reporting generation.
- `DeleteFiles` deletes only files and symlinks. Skip real directories with a distinct diagnostic; attempt every admitted item, collect failures, then throw one named exception.
- `DeleteDirectories` admits real directories and symlinks, including broken links; attempt every item, collect failures, then throw one named exception.
- `CopyTestbenchFiles`: register restore only after a successful backup; check publish/delete/restore results; retain LIFO ordering; preserve the primary failure.

Do not add a filesystem transaction object. Tests use small `Filesystem` doubles to force each false-return branch and prove later cleanup still executes.

The shared `Filesystem::deleteDirectory()` boundary must not traverse a top-level symlink. When `preserve` is false, unlink the named symlink before the directory check; this also makes `deleteDirectories()` safe because its enumerator can return linked children. Keep `preserve: true` unchanged: `cleanDirectory()` intentionally empties the directory the supplied path resolves to. Do not add Windows junction handling to one branch when Windows/Swoole is unsupported and the existing child-link branch also uses `delete()`.

Direct Filesystem tests must prove ordinary directory deletion is unchanged, live and broken top-level links are removed without touching their targets, `deleteDirectories()` cannot traverse a linked child, and `cleanDirectory()` retains its follow-link behavior. Action and purge tests must prove live and broken links reach the safe shared boundary, real directories passed as files are skipped rather than failed, checked deletion failures still fail, retained skeleton directories survive, and later cleanup continues.

#### Command status (`testbench-21`)

Purge runs all four clear commands and cleanup actions, records any failure, and returns the standard `Command::FAILURE`; individual details remain in command output. Implement file-action failures first so purge can observe them.

Install stops after a failed `package:create-sqlite-db` and neither dumps autoloads nor reports success. A nonzero seeder listener result throws a named failure.

```php
$failed = false;

foreach (['config:clear', 'event:clear', 'route:clear', 'view:clear'] as $command) {
    if ($this->call($command) !== self::SUCCESS) {
        $failed = true;
    }
}

return $failed ? self::FAILURE : self::SUCCESS;
```

Do not propagate heterogeneous child exit codes or create a status value object.

#### Seeder configuration and status (`testbench-21`, `testbench-29`)

In `LoadMigrationsFromArray::bootstrapSeeders()`, validate every configured value instead of filtering invalid values away. Widen the constructor PHPDoc to `array<int, mixed>|bool|string` because YAML is an untrusted runtime boundary; narrow values inside the listener. Non-string values and missing classes throw `InvalidArgumentException`; valid classes are dispatched and every nonzero status throws a named runtime failure. Import `Hypervel\Console\Command`, `InvalidArgumentException`, and `RuntimeException` explicitly.

```php
if ($this->seeders === true) {
    if ($app->make(ConsoleKernel::class)->call('db:seed') !== Command::SUCCESS) {
        throw new RuntimeException('Default seeder failed.');
    }

    return;
}

foreach (Collection::wrap($this->seeders)->flatten() as $seederClass) {
    if (! is_string($seederClass)) {
        throw new InvalidArgumentException(
            'Testbench seeders must be existing class strings.'
        );
    }

    if (! class_exists($seederClass)) {
        throw new InvalidArgumentException("Seeder class [{$seederClass}] does not exist.");
    }

    if ($app->make(ConsoleKernel::class)->call('db:seed', ['--class' => $seederClass]) !== Command::SUCCESS) {
        throw new RuntimeException("Seeder [{$seederClass}] failed.");
    }
}
```

Collapse the existing nested boolean check to the exact `true` branch above. `bootstrap()` excludes `false` before registering the listener, and the readonly property cannot change, so another boolean case is unreachable. Tests cover the default seeder, an explicit valid class, a missing class, a non-string runtime config value, and a nonzero command result.

### 5. Symlink, route, and published-file ownership

Files:

- `src/testbench/src/Workbench/Actions/AddAssetSymlinkFolders.php`
- `src/testbench/src/Workbench/Actions/RemoveAssetSymlinkFolders.php`
- `src/testbench/src/Foundation/Actions/{CreateVendorSymlink,DeleteVendorSymlink,RefreshPackageDiscovery}.php`
- `src/testbench/src/Concerns/HandlesRoutes.php`
- `src/testbench/src/Foundation/Bootstrap/SyncTestbenchCachedRoutes.php`
- `src/testbench/src/Concerns/InteractsWithPublishedFiles.php`
- `tests/Testbench/Workbench/ActionsTest.php`, `tests/Testbench/Foundation/Bootstrap/CreateVendorSymlinkTest.php`, published-file tests, and route coverage in `tests/Testbench/Concerns/{DefineCacheRoutesTest,HandlesRoutesTest,WithCachedStateTest}.php`, `tests/Testbench/Integrations/ApplicationProvidersWithDisabledServicesTest.php`, and `tests/Testbench/WithWorkbenchTest.php`

#### Workbench symlinks (`testbench-19`)

Create and verify a staged sibling symlink before moving an existing destination to a backup. Before staging, remove a same-name symlink left by a crashed owner and verify it is absent; if that path is a real file or directory, fail without deleting it. Publish the staged link, verify it, restore the original on publication failure, and delete the backup only after a successful publish. Cleanup removes only the exact owned link and verifies removal. Use Testbench's cross-platform `is_symlink()` helper and `dirname($to)`.

```php
$directory = dirname($to);
$staged = join_paths($directory, '.'.basename($to).'.staged');

if (is_symlink($staged)) {
    windows_os() ? @rmdir($staged) : $filesystem->delete($staged);
}

clearstatcache(false, $staged);

if (is_symlink($staged) || $filesystem->exists($staged)) {
    throw new RuntimeException("Unable to clear staged symlink [{$staged}].");
}

$filesystem->link($from, $staged);

if (! is_symlink($staged) || realpath($staged) !== realpath($from)) {
    throw new RuntimeException("Unable to stage symlink [{$staged}].");
}
```

Apply the same link-and-target verification after moving the stage to its final path.

If final publication succeeds but backup deletion fails, keep the valid published link and preserved original backup, then propagate the existing named failure. Rolling back a successful publication would prioritize cleanup over the required destination and could lose both paths if restoration failed. Do not automatically remove a pre-existing `.backup` on the next run because no durable ownership marker distinguishes it from user data. Change the private method title from the inaccurate “without exposing a missing destination” claim to a precise publication/backup description. Add a failure-injection regression asserting the exception, live expected link, and preserved original backup; no production behavior changes for this retained safe state.

#### Vendor symlink (`testbench-20`)

Put link creation, exact link/target verification, and package discovery inside one ownership-aware `try`. Mark the link owned only after verification and before discovery. If discovery fails, remove only that verified link and preserve the discovery failure. A normal failed `symlink()` leaves no residue; a mismatched link would require concurrent mutation of the worker-local clone, so do not add dependency injection or a synthetic validation-failure branch solely to force that state.

Verify discovery-manifest deletion before rebuild. A pre-existing real vendor directory remains untouched. Remove empty catches and suppressed `unlink`/`rmdir` calls. Application disposal remains owned by section 4; this action never flushes its caller.

#### Route and published files (`testbench-23`, `testbench-24`)

Keep route ownership recorded before writing, then use `Filesystem::replace()` without an explicit mode or local fallback. Filesystem owns ordinary non-executable mode-less replacement. Replacement is atomic, so the destination retains either its old or new content; recording ownership before the attempt keeps cleanup correct in either state. `replace()` stages through `tempnam(dirname, basename)`, whose generated name does not end in `.php`, so failed publication cannot create a partial file matched by the `routes/testbench-*.php` loaders or purge glob. Keep the route-cache existence check after `remote('route:cache')->mustRun()`.

Resolve the exact worker-specific cached-route path once in `defineCacheRoutes()` after `configureParallelCachePaths()` and before registering cleanup, using the current application-or-environment path rules. Pass that local string to both initial and post-reload calls to `registerTestbenchRouteCleanup(Filesystem $files, string $cachedRoutesPath)`. The callback captures the path instead of consulting `$this->app`, so a failed reload still deletes the exact cache after the shared Foundation teardown runs callbacks with a null application. Do not retain another property, registry, or cleanup path.

Preserve the required loader closure scope in `SyncTestbenchCachedRoutes`: both `$app` and `$router` captures and both narrow `closure.unusedUse` suppressions remain. Current Orchestra 11.4.0 removed those suppressions, but Hypervel's analysis reports the captures as unused even though required route files consume them dynamically from the closure scope. The serialized closure-stash stub consumes `$router`, while literal route files inherit the established `$app` scope. Add one concise WHY comment at the require boundary. In `setUpApplicationRoutes()`, resolve the router with `$app->make(Router::class)` and remove the local `@var`; this changes neither resolution nor lifetime.

Retain these current route-lifecycle invariants while editing the same seams:

- `refreshApplication()` defines routes before cached-state traits capture them;
- cached-route reload always resets `$syncTestbenchRoutesHasRun` and re-registers cleanup;
- `RouteServiceProvider` stays after application providers and before package providers, with existing override and disable support.

Route regressions must prove atomic publication, checked cleanup, and that a failed reload leaves neither the exact worker cache nor any owned `routes/testbench-*.php` file visible to the loader. Preserve the reload failure as primary. Existing repeated-boot, stash-route, provider-order, override, and disabled-provider tests remain the specification for the retained lifecycle.

Route-file cleanup keeps the aggregate `delete(array)` behavior, checks its boolean result, and reports paths that still exist.

Published-file cleanup likewise keeps aggregate deletion, independently attempts ordinary and migration cleanup, preserves the first failure, and names survivors. Keep snapshots and `.gitkeep`/`.gitignore` exclusions. Do not declare `$files` in `InteractsWithPublishedFiles`, because consumers and `InteractsWithSqliteDatabaseFile` already provide that implicit property contract and a trait declaration can conflict.

Within the two cleanup methods changed by this plan, import `Hypervel\Filesystem\Filesystem` and resolve it once with `$this->app->make(Filesystem::class)`; replace every container array access in those methods with that local. Apply the same rule to `setUpApplicationRoutes()` as specified above. Leave methods this plan does not change untouched.

### 6. Exact migration batch ownership (`testbench-22`)

Files:

- `src/testbench/src/Database/MigrateProcessor.php`
- `src/testbench/src/Concerns/InteractsWithMigrations.php`
- `src/testbench/src/Concerns/WithHypervelMigrations.php`
- focused migration processor tests and the existing migration concern tests, including `tests/Testbench/Databases/MigrateWithHypervelTest.php`

Register Hypervel and package migration paths on the Migrator instead of creating a `MigrateProcessor` whenever a later same-connection database owner will run `migrate:fresh`. `DatabaseMigrations` always refreshes. `RefreshDatabase` and `LazilyRefreshDatabase` refresh when the database has not yet been migrated or the test declares a truthy `$migrateRefresh` property. Mirror Foundation's exact coercion in both Testbench call sites:

```php
$migrateRefresh = property_exists($this, 'migrateRefresh')
    && (bool) $this->migrateRefresh;
$refreshesDatabase = static::usesTestingConcern(DatabaseMigrations::class)
    || (
        static::usesRefreshDatabaseTestingConcern()
        && (
            $migrateRefresh
            || (
                RefreshDatabaseState::$migrated === false
                && RefreshDatabaseState::$lazilyRefreshed === false
            )
        )
    );
```

Use this predicate in `WithHypervelMigrations` and for the string/list branch of `loadMigrationsFrom()`. Keep it local to those two sites; a new Foundation API or Testbench helper would add machinery for a concise condition with only two callers. Associative option arrays remain processor-owned because their connection and command semantics cannot safely be reduced to path registration.

Inject `Hypervel\Database\Migrations\Migrator` directly into `MigrateProcessor` after `TestCase` and before `$options`. At all three construction sites in `InteractsWithMigrations`, resolve it with `$app->make(Migrator::class)`. The canonical alias resolves the same singleton used by the migration commands, and `getRepository()` supplies the repository whose batches the processor owns. Do not inject the application as a second way to resolve the same dependency.

```php
public function __construct(
    protected readonly TestCase $testbench,
    protected readonly Migrator $migrator,
    protected readonly array $options = [],
) {
}
```

Store two integer bounds on the processor, initialized to zero. Capture the repository's last batch before `migrate:up` (zero when the repository does not exist) and again after success or failure. Own the inclusive range `before + 1` through `after`.

The command applies `--database` only inside its own `Migrator::usingConnection()` scope, so the processor must put both repository reads and the command inside an outer scope using the same raw option. The command's nested scope restores to this outer scope, leaving the intended repository connection active for the after-read. Use the injected Migrator's existing primitive; do not mutate the worker-global default connection or add another connection owner.

```php
/** @var null|string $connection */
$connection = $this->options['--database'] ?? null;
$repository = $this->migrator->getRepository();

$this->migrator->usingConnection($connection, function () use ($repository): void {
    $this->beforeBatch = $repository->repositoryExists()
        ? $repository->getNextBatchNumber() - 1
        : 0;

    try {
        if ($this->dispatch('migrate') !== Command::SUCCESS) {
            throw new RuntimeException('Unable to run migrations.');
        }
    } finally {
        $this->afterBatch = $repository->repositoryExists()
            ? $repository->getNextBatchNumber() - 1
            : $this->beforeBatch;
    }
});
```

Use `getNextBatchNumber() - 1` rather than the concrete repository's `getLastBatchNumber()`: `Migrator::getRepository()` exposes `MigrationRepositoryInterface`, whose existing `getNextBatchNumber()` contract provides the same integer without a concrete cast, interface expansion, migration-map allocation, or PHPStan suppression.

The migrate-up status check is a consistency correction, not a reproduced defect: the first-party command's only nonthrowing failure result is its production confirmation path, while Testbench fixes environment detection to `testing`. `dispatch()` now exposes an integer and rollback already honors it, so success must not be reported for an explicit nonzero child result. Exercise it with the existing protected-dispatch test subclass; add no production seam.

Rollback owned batches in descending order with exact `--batch`, retaining the original database and path context. Remove incompatible migrate-only options, including `--step`. Wrap the complete rollback loop and each repository postcondition in one outer `usingConnection($connection, ...)` scope; the nested rollback commands restore to it, so `getMigrationsByBatch()` checks the same connection. Treat either a nonzero rollback status or a repository postcondition showing the batch still present as failure. Continue every owned rollback and preserve the first throwable.

`InteractsWithMigrations` detaches and clears processors before LIFO cleanup so no processor replays. When setup fails, compensate only batches created by that setup and preserve the setup failure.

Tests must cover:

- a pre-existing batch surviving cleanup;
- a nonzero migrate-up result throwing while the `finally` capture still lets setup compensation roll back any newly created owned batch;
- one successful new batch followed by a failing migration;
- `--step` producing multiple owned batches;
- no new batch;
- descending LIFO, exhaustive cleanup, no replay, and first-failure preservation;
- a rollback command returning success while the owned batch remains, proving the Testbench postcondition;
- `loadHypervelMigrations('secondary')` applying migrations to a distinct SQLite connection and exact cleanup emptying that connection's migration batch, proving secondary-connection ownership without adding a test-only getter.
- `WithHypervelMigrations` with `DatabaseMigrations` registering its path rather than caching a processor;
- `loadMigrationsFrom()` with `DatabaseMigrations` making the package table available in the test body and caching no processor;
- `WithHypervelMigrations` with `RefreshDatabase` and `$migrateRefresh` registering its path after static migrated state is already true and caching no processor.

Do not change Database's upstream-compatible rollback command or add DDL transaction machinery. The migrations table and unlogged DDL from a migration that throws do not form identifiable successful batch ownership.

The design adds one bounded outer connection scope per processor setup or cleanup in Testbench only. It adds no production application work and is the minimum use of the existing connection primitive needed for correct secondary-connection ownership.

`MigrateProcessor` is `@internal`; current Orchestra exposes the same original constructor, and every Hypervel construction site is owned by `InteractsWithMigrations`. This internal signature correction therefore changes no supported API.

### 7. Shared helpers, metadata, and dead integration

Files:

- `src/testbench/src/functions.php`
- `src/testbench/src/Attributes/WithEnv.php`
- `src/testbench/src/Concerns/HandlesAssertions.php`
- `src/testbench/src/Concerns/WithWorkbench.php`
- `src/testbench/src/Features/TestingFeature.php`
- `src/testbench/src/Foundation/Console/TestCommand.php`
- `src/testbench/src/Foundation/Env.php`
- `tests/Testbench/Functions/{PackagePathTest,ParseEnvironmentVariablesTest}.php`
- `tests/Testbench/Attributes/WithEnvTest.php`
- `tests/Testbench/Foundation/EnvTest.php`
- `tests/Testbench/Workbench/HelpersTest.php`
- `dogfood/testbench-package/tests/PackageRuntimeTest.php`
- `src/testbench/composer.json` plus metadata/split tests
- `phpstan.neon.dist`
- Testbench Pest files and their consumers
- touched Testbench tests and support contracts

#### Path and environment helpers (`testbench-25`)

Delegate Testbench's path joining to `Hypervel\Filesystem\join_paths`. Map only the exact empty string to dotenv's `(empty)` sentinel; preserve `"0"`. Use one shared quoting boundary for backslash, double quote, dollar, newline, carriage return, and control characters across YAML/array and attribute environment loading.

```php
use function Hypervel\Filesystem\join_paths as filesystem_join_paths;

function join_paths(?string $basePath, string ...$paths): string
{
    return filesystem_join_paths($basePath, ...$paths);
}

$value = $value === '' ? '(empty)' : quote_environment_value($value);
```

In `testbench_path()`, `package_path()`, and `workbench_path()`, pass the first supplied segment as the joiner's nullable base. This preserves `./` for the existing relative-path decision instead of converting it to `/./` before the check:

```php
$paths = Arr::wrap($argumentCount > 1 ? func_get_args() : $path);
$path = join_paths(array_shift($paths), ...$paths);
```

Retain the upstream single-string `./` branch. Remove the now-redundant exact-`DIRECTORY_SEPARATOR` branch and the paired final `ltrim($path, DIRECTORY_SEPARATOR)` from all three helpers. Route `workbench_relative_path()` through `workbench_path()` before stripping the package root, so a consuming package's selected workbench—not Testbench's own fallback fixtures—owns the result.

Correct all six pseudo-variadic path-helper PHPDocs to `array<int, string>|string`, restore the omitted variadic marker on `default_skeleton_path()`, and add `@no-named-arguments`. Do not widen the shared joiner for unsupported null array elements or port upstream's inaccurate conditional return annotation.

Keep `Foundation\Console\TestCommand::basePath()` on the natural `package_path(...$paths)` variadic call. Revert the branch's equivalent array-form call; it adds no behavior and creates needless port drift.

Path regressions must use real on-disk targets so `realpath()` succeeds: the current Testbench and package fixtures for the three absolute helpers and `testbench_relative_path()`, plus the dogfood package's existing `workbench/config/dogfood.php` for `workbench_relative_path()`. In the dogfood suite, assert ordinary and `./` forms separately so consumer-workbench selection and relative normalization each have a counterfactual. Round-trip environment tests must parse emitted dotenv values, not merely compare generated text.

#### Package metadata (`testbench-26`)

Add direct split requirements for `composer/semver` and `hypervel/di` in `src/testbench/composer.json`. The root provides `composer/semver` through `require-dev` and `hypervel/di` through `replace` with `self.version`, which is sufficient for monorepo development; verify those entries rather than changing the root dependency graph. The split package must require both directly. Do not add a duplicate ParaTest suggestion; `hypervel/testing` owns that integration. Keep `Composer\Config` optional because guarded conditional interop is not a package feature dependency.

Add a Testbench package-metadata test and split bootstrap/helper smoke coverage. `ext-posix` is already present; no further action is needed.

#### Remove dead Pest machinery (`testbench-16`)

Delete:

- `src/testbench/src/Pest/Autoload.php`
- `src/testbench/src/Pest/Hook.php`
- `src/testbench/src/Pest/WithPest.php`

Remove these consumers and their synthetic tests:

- the `WithPest` import and four setup/teardown branches in `TestCase.php`;
- the named `pest:` callback in `Concerns/HandlesRoutes.php`;
- both named `pest:` callbacks in `Concerns/HandlesDatabases.php`;
- the named `pest:` callback in `Concerns/CreatesApplication.php`;
- the deleted `Pest/Autoload.php` exclusion in `phpstan.neon.dist`.

Retain `Concerns\InteractsWithPest` only because current upstream Testbench core still exposes that detector. The separate Pest plugin remains the correct integration point, and this package adds no Pest dependency.

#### Dead code and typing (`testbench-28`)

Remove the unused `annotation` parameter from internal `Features\TestingFeature::run()`, the dead Pest-only route capture, redundant Workbench seeder assignment, and `@coversNothing` metadata. Preserve the route-loader captures and suppressions specified in section 5. A repository-wide caller scan must first confirm no caller passes `annotation:`; the class is marked `@internal`, so removing its dead public parameter does not change a supported API. Correct assertion callback docs to `Closure(): bool`. Add `: void` to Testbench test methods that lack it, preserving data providers and PHPUnit lifecycle signatures.

Run the repository-wide missing-return-type scan against the final merged tree before implementation so new base changes are included; treat any remaining Testbench methods as this finding, not a second typing scope.

These edits are compile-time/test maintenance only. Do not change runtime behavior to satisfy PHPStan, and do not pass source or test paths to `phpstan.types.neon.dist`.

### 8. Documentation and completion records

Files:

- `src/boost/docs/testbench.md`
- `src/testbench/README.md`
- the core audit plan and ledger, including their routing index and package checklist
- this plan

Restore both public `WithConfig` timing statements in `src/boost/docs/testbench.md`: every attribute value is applied after configuration loads and before providers register. Explain that this timing models Hypervel's process-global worker-startup configuration and that explicit test-body mutation is the visible option for a genuinely post-boot value. Add the intentional deferred-mode omission under the existing `Differences From Orchestra Testbench` README heading. The current docs contain no bundled Pest claim to remove. Do not add README differences for the other correctness fixes, internal ownership, or upstream bugs.

Add `testbench-07` through `testbench-29` and `testing-18` to the ledger with exact evidence, tests, compatibility, performance, and counterfactual coverage. Classify defects, consistency corrections, and retained non-defects accurately. Record `testbench-27` as already resolved by Testbench's serialized stale-runtime cleanup change; no code action remains. Revalidate current `testbench-05` through `testbench-06`, `foundation-19`, and `filesystem-17` without overwriting their existing records.

Revalidate the core index entries already routed to this audit: `testbench-01` through `testbench-06`, `concurrency-01` through `concurrency-03`, `support-02`, `foundation-06`, `database-03`, `database-08`, `cache-04`, and `view-37`. Add `testing-18` under Testing with Testbench revalidation. Update routing dispositions rather than duplicating findings, and retain every existing owner and cross-package route. Mark Testbench complete only after implementation, full verification, self-review, code-review signoff, and records review.

## Verification plan

### Incremental cadence

1. Before each implementation item, reread its source, callers, tests, matching Orchestra source/tests, and the relevant plan section.
2. Edit one file at a time with targeted patches.
3. Run each changed or new test file immediately:

```bash
./vendor/bin/phpunit --no-progress tests/Testbench/path/to/ChangedTest.php
```

4. After each coherent slice, run the focused Testbench directory or command tests affected by the slice.
5. Immediately after restoring eager `WithConfig`, run the Testbench attribute test and the complete Fortify, Passkeys, and Telescope consumer directories. Shared test infrastructure is not complete when only its own unit tests pass.
6. After the section-7 path-helper changes, run the real consumer-package suite (its script installs the dogfood package first):

```bash
composer test:dogfood
```

7. Run package mode after all Testbench source and tests are complete:

```bash
composer test:testbench
```

8. Clear PHPStan's result cache before verifying the owner-approved `CreatesApplication` exception; a warm per-file cache can omit its trait-in-context reports.
9. Run one final full gate:

```bash
composer fix
```

`composer fix` supplies formatting, PHPStan, the parallel suite, and Testbench package mode. Do not run duplicate full suites at the same checkpoint.

### Required counterfactual coverage

- Every regression must fail against the corresponding broken implementation, not only pass against the fix.
- Failure-injection doubles return `false` at the exact `Filesystem` operation whose ignored result caused the defect, with positive controls proving the path was reached.
- The empty-document defaults test calls `Config::loadFromYaml()` directly with non-empty defaults so it cannot pass through the ordinary default-`[]` callers.
- Cleanup tests assert later independent callbacks/actions still run and the first failure remains primary.
- Partial-application tests assert terminate-before-flush on every owning exit; null-application teardown still runs test-lifetime destruction callbacks.
- `WithConfig` coverage proves both provider registration and boot observe the attribute value; a unit test that merely observes a later overwrite is insufficient.
- Wrong-target attribute coverage reaches PHP's `ReflectionAttribute::newInstance()` error rather than substituting a throwing constructor.
- Failed replacement coverage asserts parsed method state is cleared without invoking application-bound callbacks against a stale or flushed container.
- Vendor bootstrapper coverage uses a real application and pins continued application usability plus terminating-callback preservation; outer tracking doubles are not sufficient.
- SQLite active-path coverage throws from both purge positions and verifies exact worker-global config restoration.
- Workbench backup-cleanup coverage pins the retained safe state: the correct link remains live, the displaced original remains backed up, and the named failure propagates.
- Parallel-runner cleanup tests assert the token resolver is cleared before termination, flush still follows a failed termination, and callback failures remain primary.
- Failed cached-route reload tests assert both the exact worker cache and every owned loader-visible route file are removed while the reload failure remains primary.
- Runtime clone tests retain real worker/PID path formation and exercise the actual serialized stale-sweep boundary.
- Runtime tests that fabricate the active clone identity run in a child with no Testbench application or inherited static state; do not encode an ordering-dependent cross-file reproduction.
- Parallel tests continue using the normal worker-owned clone and `ParallelTesting::tempDir()` conventions; do not invent concurrent tasks for code whose ownership is per CLI/test lifecycle rather than coroutine-shared.
- Migration tests inspect the migration repository after rollback, so a command that reports success without removing the batch cannot pass.
- Migration-up status coverage uses the protected test override and proves after-batch capture still enables compensation; do not add a production injection seam.
- Secondary-connection migration coverage proves both ownership reads and rollback postconditions stay on the configured connection.
- Environment serialization tests parse the emitted dotenv content to prove semantic round trips.
- Split tests exercise the package using its own metadata rather than relying on root-only dependencies.

### Final self-review

After the full gate passes:

- trace every modified source method through all callers and cleanup paths;
- recheck partial setup, terminate-before-flush ordering, primary-failure preservation, LIFO ordering, idempotence, and no replay;
- verify only owned files, links, clones, and migration batches are removed;
- confirm worker identity, runtime clone sharing, external-service traits, and `ParallelTesting::tempDir()` remain unchanged;
- map every process-global mutation through configuration load, provider registration, provider boot, worker-ready state, test body, and teardown;
- compare public signatures, named arguments, protected extension points, and docs with current Orchestra Testbench, while preserving the recorded deferred-config omission;
- rerun the repository-wide `WithConfig` consumer and attribute-target scans against the final tree;
- inspect every new guard for a demonstrated failure and remove redundant checks;
- verify no application hot path, container state, coroutine context, lock, retry, cache, or retained worker memory was added;
- remove dead imports, stale comments, superseded tests, and temporary fixtures;
- review the final diff and records before requesting code review.

## Explicit non-findings and retained decisions

- `@testbench` paths remain supported by `transform_relative_path()`; no loader branch is needed.
- Route publication and package-manifest preservation use Filesystem's mode-less atomic replacement with the ordinary non-executable file default. The runtime process-identity marker intentionally passes `0600`; do not remove its explicit owner-only mode for consistency.
- `SyncTestbenchCachedRoutes` retains both closure captures and their narrow PHPStan suppressions because required route files execute in that scope. Current Orchestra 11.4.0 omits the suppressions, but removing them fails Hypervel's analysis of the dynamic consumption.
- Test-duration restoration remains on Foundation destruction callbacks, not application termination callbacks that run after every HTTP test request.
- `InteractsWithPublishedFiles` keeps its implicit `$files` consumer contract to avoid trait-property collisions.
- `Composer\Config` remains optional guarded interoperability, not a direct dependency.
- Database's rollback command keeps upstream success-on-no-work semantics; Testbench owns exact-batch verification.
- `loadHypervelMigrations()` retains upstream's harmless double `resolveHypervelMigrationsOptions()` call; collapsing it has no behavioral benefit and would create gratuitous port drift.
- Path helpers retain upstream-compatible `../` behavior. Both the single-string and general relative decisions deliberately recognize only `./`; adding `../` normalization would create unsupported divergence without fixing a demonstrated failure.
- Associative migration option arrays remain processor-owned. A distinct `--database` is not invalidated by a default-connection refresh; same-connection options that must run after a refresh use `defineDatabaseMigrationsAfterDatabaseRefreshed()`. Do not add connection inference or option-shape machinery.
- The runtime clone stale sweep remains exhaustive best-effort. A failed foreign deletion is recoverable by a later serialized sweep.
- Existing static caches, worker token selection, clone-per-worker behavior, service isolation traits, and parallel temp-directory ownership remain unchanged.
- `WithConfig` does not expose Orchestra's deferred mode. Post-boot config mutation remains explicit in the test body rather than hidden in an attribute.
- `WithCachedStateTest` deliberately uses raw `refreshApplication()` because full reload resets the cached-state traits and invalidates the lifecycle under test. Its deterministic boot owns no database, pool, or external service, so no disposal machinery is added.
- A Workbench backup-delete failure retains the verified published link and preserved backup while throwing. Automatic rollback or next-run deletion would risk losing the correct destination or unowned user data.
- The migrate-up status check is a consistency correction; current Testbench environment detection makes the first-party command's nonthrowing production-confirmation failure unreachable.
- Vendor link validation remains direct. Do not add constructor injection or a synthetic concurrent-mutation test for a mismatch that has no supported worker-local path.
- Pest integration is removed from this package rather than repaired here; the real current upstream integration is a separate plugin and can be ported independently if Hypervel chooses to support it.
