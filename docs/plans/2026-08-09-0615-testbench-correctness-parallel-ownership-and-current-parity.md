# Testbench Correctness, Parallel Ownership, and Current Parity

**Status:** Approved — implementation-ready

## Objective

Correct the verified Testbench lifecycle, configuration, filesystem, migration, and metadata defects while preserving its public Laravel/Orchestra-shaped APIs and Hypervel's parallel test architecture.

The finished design must:

- keep the committed skeleton immutable and use one disposable runtime clone per PHPUnit worker;
- preserve `TEST_TOKEN`, `TESTBENCH_BASE_PATH`, worker-local paths, and external-service isolation;
- make partial setup transactional and teardown exhaustive without adding registries, retries, locks, or filesystem transaction abstractions;
- preserve the earliest failure while still running independent cleanup;
- reject unsupported file identifiers before performing filesystem operations;
- retain current Testbench and Laravel APIs unless an approved removal is recorded;
- add no application hot-path work: every change is in Testbench bootstrap, CLI, tests, or package metadata.

Primary references:

- `AGENTS.md`
- [`2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md`](2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md)
- [`2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md`](2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md)
- `examples/orchestra/testbench-core`

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
- Public Testbench and Orchestra APIs remain compatible, including `WithConfig`; the approved broken bundled Pest integration is removed because it was never loaded and the real integration belongs to the separate Pest plugin.
- Testbench cleanup remains explicit ownership, not a generic filesystem transaction framework.

## Finding map

| ID | Final disposition |
|---|---|
| `testbench-05` | Require environment-copy success, create the runtime clone root with mode `0700`, and verify creation. Keep stale deletion best-effort. |
| `testbench-06` | Restore exact timezone and terminate partially bootstrapped applications on failure. |
| `testbench-07` | Normalize empty/null YAML mappings and reject malformed roots with a named exception. |
| `testbench-08` | Restrict the private `src/testbench/testbench.yaml` fallback to the `hypervel/components` monorepo root. |
| `testbench-09` | Preserve top-level default configuration keys by collapsing filtered maps. |
| `testbench-10` | Recognize SQLite memory and URI identifiers before selecting the fallback connection. |
| `testbench-11` | Make SQLite file swaps lossless and remove dead absolute-path cleanup bookkeeping. |
| `testbench-12` | Let attribute construction, reflection, and resolver failures propagate. |
| `testbench-13` | Port deferred package `WithConfig` handling while keeping framework config eager. |
| `testbench-14` | Remove the unused bundled Pest hook implementation and its dead branches. |
| `testbench-15` | Make terminating callbacks detached, exhaustive, non-replaying, and first-failure preserving. |
| `testbench-16` | Check CLI copy/delete ownership and preserve user files across failed synchronization. |
| `testbench-17` | Publish Workbench symlinks through a verified staged replacement. |
| `testbench-18` | Own vendor symlinks before discovery and roll back failed creation. |
| `testbench-19` | Return failure from purge/install/seeder paths when their work fails. |
| `testbench-20` | Own only migration batches created by the current setup and verify exact rollback. |
| `testbench-21` | Use atomic route-file replacement and report surviving owned paths after cleanup failure. |
| `testbench-22` | Make published-file cleanup exhaustive and failure-reporting without declaring a conflicting trait property. |
| `testbench-23` | Reuse the shared path joiner and safely quote environment values, preserving the string `"0"`. |
| `testbench-24` | Correct split-package dependencies and add proportionate split metadata coverage. |
| `testbench-25` | Record that purge shutdown registration was already removed by Testbench cleanup work. |
| `testbench-26` | Remove dead annotations/branches and complete Testbench test method typing. |
| `testbench-27` | Reject invalid configured seeder classes instead of silently dropping them. |

## Implementation

### 1. Runtime clone and application lifecycle

Files:

- `src/testbench/src/Bootstrapper.php`
- `src/testbench/src/TestCase.php`
- `src/testbench/src/Concerns/CreatesApplication.php`
- focused tests under `tests/Testbench/Bootstrapper*`, `tests/Testbench/CreatesApplicationTest.php`, `tests/Testbench/TestCaseTest.php`, and `tests/Testbench/TimezoneTest.php`

#### Runtime clone ownership (`testbench-05`)

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

#### Exact process-global restoration (`testbench-06`)

Capture the timezone before mutation. Register one idempotent restoration closure before changing it, invoke that closure from both PHPUnit destruction and standalone application termination, and terminate a partially created application if any later bootstrap or resolving callback throws.

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

Preserve the primary bootstrap failure if termination also fails. Retain existing `putenv` restoration and avoid a registry or nesting counter.

### 2. Configuration and attribute boundaries

Files:

- `src/testbench/src/Foundation/Config.php`
- `src/testbench/src/Bootstrapper.php`
- `src/testbench/src/Foundation/Bootstrap/EnsuresDefaultConfiguration.php`
- `src/testbench/src/PHPUnit/AttributeParser.php`
- `src/testbench/src/Concerns/InteractsWithPHPUnit.php`
- `src/testbench/src/Attributes/WithConfig.php`
- `src/boost/docs/testbench.md`
- matching `tests/Testbench/Foundation/ConfigTest.php`, `DefaultConfigurationTest.php`, `PHPUnit/AttributeParserTest.php`, `Concerns/InteractsWithPHPUnitTest.php`, and `Attributes/WithConfigTest.php`

#### YAML shape and fallback (`testbench-07`, `testbench-08`)

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

#### Defaults and attributes (`testbench-09`, `testbench-12`)

Replace the key-destroying `values()` pipeline with `collapse()` after filtering configuration maps. Remove fixture configuration that masks the numeric-key regression.

```php
return $configurations
    ->filter(/* applicable configuration */)
    ->collapse()
    ->all();
```

Remove `rescue()` around attribute instantiation, reflection, resolver execution, and the outer attribute caches. Only an explicit resolver result of `null` omits an attribute. Constructor and reflection failures must reach the test author unchanged.

#### Deferred package config (`testbench-13`)

Port current Orchestra's additive `bool $defer = true` parameter. Apply framework configuration eagerly; defer package configuration through `booted()` unless the caller passes `defer: false`.

Keep the framework configuration prefix set as a protected constant:

```php
protected const FRAMEWORK_CONFIGURATION = [
    'app', 'auth', 'broadcasting', 'cache', 'concurrency', 'cors', 'database',
    'filesystems', 'hashing', 'logging', 'mail', 'queue', 'rate-limiter',
    'server', 'session', 'signal', 'view',
];
```

Extract the top-level segment so the constant stays directly comparable with the framework config basenames while retaining upstream's dotted-key behavior:

```php
$prefix = Str::before($this->key, '.');
$isFrameworkConfiguration = $prefix !== $this->key
    && in_array($prefix, self::FRAMEWORK_CONFIGURATION, true);
```

A derived test must compare it with the basenames under `src/foundation/config/`, preventing drift without runtime filesystem work. Behavioral tests must prove framework values are available before providers, deferred package overrides survive provider config merging, and `defer: false` preserves eager application. Update the Testbench documentation so it no longer claims every `WithConfig` value is applied before providers.

### 3. SQLite path and file ownership

Files:

- `src/testbench/src/Bootstrap/LoadConfiguration.php`
- `src/testbench/src/Concerns/Database/InteractsWithSqliteDatabaseFile.php`
- existing SQLite/Commander tests plus focused failure-injection tests under `tests/Testbench/Concerns/Database/`

#### Identifier classification (`testbench-10`)

Use `SQLiteDatabase::isInMemory()` and `SQLiteDatabase::isUri()`. Select the `testing` fallback only for a missing ordinary local file.

```php
$usesLocalFile = ! SQLiteDatabase::isInMemory($database)
    && ! SQLiteDatabase::isUri($database);

if ($default === 'sqlite' && $usesLocalFile && ! is_file($database)) {
    // Existing fallback rewrite.
}
```

#### Lossless file swaps (`testbench-11`)

The helper owns filesystem files, so reject memory and URI identifiers before deriving backup names. Check all four moves and both copies. Assign backup ownership only after success, purge the SQLite connection before each swap, restore in `finally`, run independent restorations even after one fails, and preserve the original or earliest failure.

```php
$backup = "{$database}.backup-{$time}";

if (! $filesystem->move($database, $backup)) {
    throw new RuntimeException("Unable to back up SQLite database [{$database}].");
}

$ownedBackup = $backup;
```

Remove the absolute backup paths from `$this->files`; `InteractsWithPublishedFiles` rebases entries and cannot own them. Keep the existing crash-recovery glob hook. Do not introduce another cleanup collection.

Tests must inject failed backup, creation-copy, and restore operations; prove originals survive; cover distinct base/active paths; cover memory and URI rejection; and prove connection purges surround swaps.

### 4. Console teardown and file actions

Files:

- `src/testbench/src/Foundation/Console/TerminatingConsole.php`
- `src/testbench/src/Console/Commander.php`
- `src/testbench/src/Foundation/Console/Actions/{EnsureDirectoryExists,GeneratesFile,DeleteFiles,DeleteDirectories}.php`
- `src/testbench/src/Foundation/Console/Concerns/CopyTestbenchFiles.php`
- `src/testbench/src/Foundation/Console/{PurgeSkeletonCommand,InstallCommand}.php`
- `src/testbench/src/Foundation/Bootstrap/LoadMigrationsFromArray.php`
- the corresponding tests under `tests/Testbench/Foundation/Console/`

#### Exhaustive termination (`testbench-15`)

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

#### File action postconditions (`testbench-16`)

- `EnsureDirectoryExists`: rely on the framework exception for directory creation; check only `.gitkeep` copy.
- `GeneratesFile`: require source copy success before deleting destination `.gitkeep` or reporting generation.
- `DeleteFiles` and `DeleteDirectories`: attempt every item, collect failures, then throw one named exception.
- `CopyTestbenchFiles`: register restore only after a successful backup; check publish/delete/restore results; retain LIFO ordering; preserve the primary failure.

Do not add a filesystem transaction object. Tests use small `Filesystem` doubles to force each false-return branch and prove later cleanup still executes.

#### Command status (`testbench-19`)

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

#### Seeder configuration and status (`testbench-19`, `testbench-27`)

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
- `src/testbench/src/Concerns/InteractsWithPublishedFiles.php`
- `tests/Testbench/Workbench/ActionsTest.php`, `tests/Testbench/Foundation/Bootstrap/CreateVendorSymlinkTest.php`, route tests, and published-file tests

#### Workbench symlinks (`testbench-17`)

Create and verify a staged sibling symlink before moving an existing destination to a backup. Before staging, remove a same-name symlink left by a crashed owner and verify it is absent; if that path is a real file or directory, fail without deleting it. Publish the staged link, verify it, restore the original on failure, and delete the backup only after a successful publish. Cleanup removes only the exact owned link and verifies removal. Use Testbench's cross-platform `is_symlink()` helper and `dirname($to)`.

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

#### Vendor symlink (`testbench-18`)

Mark the link owned immediately after exact creation and before package discovery. If discovery fails, remove only that link and preserve the discovery failure. Verify discovery-manifest deletion before rebuild. A pre-existing real vendor directory remains untouched. Remove empty catches and suppressed `unlink`/`rmdir` calls.

#### Route and published files (`testbench-21`, `testbench-22`)

Keep route ownership recorded before writing, then use `Filesystem::replace()`. Replacement is atomic, so the destination retains either its old or new content; recording ownership before the attempt keeps cleanup correct in either state. Cleanup keeps the existing aggregate `delete(array)` behavior, checks its boolean result, and reports paths that still exist.

Published-file cleanup likewise keeps aggregate deletion, independently attempts ordinary and migration cleanup, preserves the first failure, and names survivors. Keep snapshots and `.gitkeep`/`.gitignore` exclusions. Do not declare `$files` in `InteractsWithPublishedFiles`, because consumers and `InteractsWithSqliteDatabaseFile` already provide that implicit property contract and a trait declaration can conflict.

### 6. Exact migration batch ownership (`testbench-20`)

Files:

- `src/testbench/src/Database/MigrateProcessor.php`
- `src/testbench/src/Concerns/InteractsWithMigrations.php`
- focused migration processor tests and the existing migration concern tests

Give `MigrateProcessor` the owning application/migrator. Capture the repository's last batch before `migrate:up` (zero when the repository does not exist) and again after success or failure. Own the inclusive range `before + 1` through `after`.

```php
$before = $repository->repositoryExists()
    ? $repository->getLastBatchNumber()
    : 0;

try {
    $this->runMigration($options);
} finally {
    $after = $repository->repositoryExists()
        ? $repository->getLastBatchNumber()
        : $before;
}
```

Rollback owned batches in descending order with exact `--batch`, retaining the original database and path context. Remove incompatible migrate-only options, including `--step`. Treat either a nonzero rollback status or a repository postcondition showing the batch still present as failure. Continue every owned rollback and preserve the first throwable.

`InteractsWithMigrations` detaches and clears processors before LIFO cleanup so no processor replays. When setup fails, compensate only batches created by that setup and preserve the setup failure.

Tests must cover:

- a pre-existing batch surviving cleanup;
- one successful new batch followed by a failing migration;
- `--step` producing multiple owned batches;
- no new batch;
- descending LIFO, exhaustive cleanup, no replay, and first-failure preservation;
- a rollback command returning success while the owned batch remains, proving the Testbench postcondition.

Do not change Database's upstream-compatible rollback command or add DDL transaction machinery. The migrations table and unlogged DDL from a migration that throws do not form identifiable successful batch ownership.

### 7. Shared helpers, metadata, and dead integration

Files:

- `src/testbench/src/functions.php`
- `tests/Testbench/Functions/ParseEnvironmentVariablesTest.php` and path-helper tests
- `src/testbench/composer.json` plus metadata/split tests
- Testbench Pest files and their consumers
- touched Testbench tests and support contracts

#### Path and environment helpers (`testbench-23`)

Delegate Testbench's path joining to `Hypervel\Filesystem\join_paths`. Map only the exact empty string to dotenv's `(empty)` sentinel; preserve `"0"`. Use one shared quoting boundary for backslash, double quote, dollar, newline, carriage return, and control characters across YAML/array and attribute environment loading.

```php
use function Hypervel\Filesystem\join_paths as filesystem_join_paths;

function join_paths(?string $basePath, string ...$paths): string
{
    return filesystem_join_paths($basePath, ...$paths);
}

$value = $value === '' ? '(empty)' : quote_environment_value($value);
```

Round-trip tests must parse emitted dotenv values, not merely compare generated text.

#### Package metadata (`testbench-24`)

Add direct split requirements for `composer/semver` and `hypervel/di` in `src/testbench/composer.json`. The root already requires both, so verify rather than edit its dependency graph. Do not add a duplicate ParaTest suggestion; `hypervel/testing` owns that integration. Keep `Composer\Config` optional because guarded conditional interop is not a package feature dependency.

Add a Testbench package-metadata test and split bootstrap/helper smoke coverage. `ext-posix` is already present; no further action is needed.

#### Remove dead Pest machinery (`testbench-14`)

Delete:

- `src/testbench/src/Pest/Autoload.php`
- `src/testbench/src/Pest/Hook.php`
- `src/testbench/src/Pest/WithPest.php`

Remove `WithPest` imports and branches from `TestCase`, plus named `pest:` callbacks in route, application, and database concerns and their synthetic tests. Retain `Concerns\InteractsWithPest` only because current upstream Testbench core still exposes that detector. The separate Pest plugin remains the correct integration point, and this package adds no Pest dependency.

#### Dead code and typing (`testbench-26`)

Remove the unused `annotation` parameter from internal `Features\TestingFeature::run()`, stale route capture and suppressions, redundant Workbench seeder assignment, and `@coversNothing` metadata. A repository-wide caller scan must first confirm no caller passes `annotation:`; the class is marked `@internal`, so removing its dead public parameter does not change a supported API. Correct assertion callback docs to `Closure(): bool`. Add `: void` to Testbench test methods that lack it, preserving data providers and PHPUnit lifecycle signatures.

These edits are compile-time/test maintenance only. Do not change runtime behavior to satisfy PHPStan, and do not pass source or test paths to `phpstan.types.neon.dist`.

### 8. Documentation and completion records

Files:

- `src/boost/docs/testbench.md`
- the core audit plan and ledger, including their routing index and package checklist
- this plan

Update both public `WithConfig` timing statements in `src/boost/docs/testbench.md`: the lifecycle sequence near the configuration-loading overview and the provider-boot guidance in the environment-definition section. Framework keys remain eager; package keys defer by default and can opt out with `defer: false`. The current docs contain no bundled Pest claim to remove. Do not add README differences for correctness fixes, internal ownership, or upstream bugs.

Add `testbench-05` through `testbench-27` to the ledger with exact evidence, tests, compatibility, performance, and counterfactual coverage. Record `testbench-25` as already resolved by Testbench's serialized stale-runtime cleanup change; no code action remains. Revalidate the core index entries routed to this audit: `testbench-01` through `testbench-04`, `concurrency-01` through `concurrency-03`, `support-02`, `foundation-06`, `database-03`, `database-08`, `cache-04`, and `view-37`. Update their routing dispositions rather than duplicating their findings. Mark Testbench complete only after implementation, full verification, self-review, code-review signoff, and records review.

## Verification plan

### Incremental cadence

1. Before each implementation item, reread its source, callers, tests, matching Orchestra source/tests, and the relevant plan section.
2. Edit one file at a time with targeted patches.
3. Run each changed or new test file immediately:

```bash
./vendor/bin/phpunit --no-progress tests/Testbench/path/to/ChangedTest.php
```

4. After each coherent slice, run the focused Testbench directory or command tests affected by the slice.
5. Run package mode after all Testbench source and tests are complete:

```bash
composer test:testbench
```

6. Run one final full gate:

```bash
composer fix
```

`composer fix` supplies formatting, PHPStan, the parallel suite, and Testbench package mode. Do not run duplicate full suites at the same checkpoint.

### Required counterfactual coverage

- Every regression must fail against the corresponding broken implementation, not only pass against the fix.
- Failure-injection doubles return `false` at the exact `Filesystem` operation whose ignored result caused the defect, with positive controls proving the path was reached.
- The empty-document defaults test calls `Config::loadFromYaml()` directly with non-empty defaults so it cannot pass through the ordinary default-`[]` callers.
- Cleanup tests assert later independent callbacks/actions still run and the first failure remains primary.
- Runtime clone tests retain real worker/PID path formation and exercise the actual serialized stale-sweep boundary.
- Parallel tests continue using the normal worker-owned clone and `ParallelTesting::tempDir()` conventions; do not invent concurrent tasks for code whose ownership is per CLI/test lifecycle rather than coroutine-shared.
- Migration tests inspect the migration repository after rollback, so a command that reports success without removing the batch cannot pass.
- Environment serialization tests parse the emitted dotenv content to prove semantic round trips.
- Split tests exercise the package using its own metadata rather than relying on root-only dependencies.

### Final self-review

After the full gate passes:

- trace every modified source method through all callers and cleanup paths;
- recheck partial setup, primary-failure preservation, LIFO ordering, idempotence, and no replay;
- verify only owned files, links, clones, and migration batches are removed;
- confirm worker identity, runtime clone sharing, external-service traits, and `ParallelTesting::tempDir()` remain unchanged;
- compare public signatures, named arguments, protected extension points, and docs with current Orchestra Testbench;
- inspect every new guard for a demonstrated failure and remove redundant checks;
- verify no application hot path, container state, coroutine context, lock, retry, cache, or retained worker memory was added;
- remove dead imports, stale comments, superseded tests, and temporary fixtures;
- review the final diff and records before requesting code review.

## Explicit non-findings and retained decisions

- `@testbench` paths remain supported by `transform_relative_path()`; no loader branch is needed.
- `Filesystem::replace()` mode arithmetic is equivalent for octal umasks; no shared Filesystem change is warranted.
- `InteractsWithPublishedFiles` keeps its implicit `$files` consumer contract to avoid trait-property collisions.
- `Composer\Config` remains optional guarded interoperability, not a direct dependency.
- Database's rollback command keeps upstream success-on-no-work semantics; Testbench owns exact-batch verification.
- The runtime clone stale sweep remains exhaustive best-effort. A failed foreign deletion is recoverable by a later serialized sweep.
- Existing static caches, worker token selection, clone-per-worker behavior, service isolation traits, and parallel temp-directory ownership remain unchanged.
- Pest integration is removed from this package rather than repaired here; the real current upstream integration is a separate plugin and can be ported independently if Hypervel chooses to support it.
