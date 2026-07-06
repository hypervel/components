# Parallel Redis Runtime Isolation

## Goal

Replace the components-only Redis preflight wrapper with one runtime Redis isolation model that works for framework tests, applications, Testbench packages, and components-style package monorepos.

The final state should be boring to reason about:

- `composer test:parallel` runs the components framework suite through raw ParaTest.
- Testbench package-mode behavior is covered by a scoped `test:testbench` script.
- No command or wrapper connects to Redis before the test suite starts.
- Redis is touched only by tests that actually use Hypervel's Redis testing support.
- Tests that use `InteractsWithRedis` receive a real Redis logical database per parallel worker.
- Tests that need `select()` across a second Redis database opt in with `REDIS_TEST_SECONDARY_DB`.
- Command-level `.env` parsing is not added. Package and app env loading stays in PHPUnit/Testbench/application bootstrap and the Testbench runtime clone.
- The Testbench command does not leak its temporary CLI application environment into PHPUnit or ParaTest subprocesses.

Churn and backwards compatibility are not constraints. The final codebase should read as if this was the original testing design.

## Current State

`composer test:parallel` currently runs a repository-local wrapper:

```json
{
    "scripts": {
        "test:parallel": "php bin/paratest"
    }
}
```

`bin/paratest` currently:

- loads the components `.env` file
- connects to Redis before ParaTest starts
- probes `CONFIG GET databases`
- caps worker count from Redis logical database count
- flushes the base Redis database before workers start
- shells out to `vendor/bin/paratest`
- sets `HYPERVEL_PARALLEL_TESTING=1`

That design works for the components repo, but it has the wrong owner. Redis setup belongs to tests that need Redis, not to a parent test-runner process.

The current Testbench binary also has a separate correctness gap that becomes important once `package:test` is covered as a first-class contract. Upstream Orchestra defines `TESTBENCH_CORE` in its binary:

```php
define('TESTBENCH_CORE', true);
```

Hypervel's `src/testbench/bin/testbench` does not. `is_testbench_cli()` checks that constant, so package commands are hidden from `testbench list`, and `PackageManifest::providersFromTestbench()` does not merge the package-under-test `extra.hypervel` metadata while running from the CLI. This should be fixed as part of making the Testbench binary the canonical path.

The normal command path already exists:

```php
// Hypervel\Testing\Console\TestCommandBase
$process = (new Process(
    command: array_merge(
        $this->binary(),
        $parallel ? $this->paratestArguments($options) : $this->phpunitArguments($options),
    ),
    env: $parallel ? $this->paratestEnvironmentVariables() : $this->phpunitEnvironmentVariables(),
))->setTimeout(null);
```

For parallel runs it already injects:

```php
[
    'HYPERVEL_PARALLEL_TESTING' => 1,
    'HYPERVEL_PARALLEL_TESTING_RECREATE_DATABASES' => $this->option('recreate-databases'),
    'HYPERVEL_PARALLEL_TESTING_DROP_DATABASES' => $this->option('drop-databases'),
    'HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES' => $this->option('without-databases'),
    'HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE' => $this->option('without-cache'),
]
```

`Hypervel\Testbench\Foundation\Console\TestCommand` extends that command and uses the package root plus the Testbench parallel runner:

```php
protected function parallelRunner(): string
{
    return ParallelRunner::class;
}

protected function basePath(string ...$paths): string
{
    return package_path(...$paths);
}
```

So `vendor/bin/testbench package:test --parallel` is the correct shared command path for package-style suites. It should not be used as the full components framework-suite gate because it intentionally activates package-tester semantics, including Testbench skeleton env/config defaults.

The command path still passes the Testbench CLI application's runtime environment to workers through `defined_environment_variables()` and then adds `HYPERVEL_PARALLEL_TESTING`, `TESTBENCH_PACKAGE_TESTER`, and `TESTBENCH_WORKING_PATH`. That broad forwarding is the wrong ownership boundary. The command should forward declared package-test channels, not every value the temporary CLI app happened to load. Package and app env loading belongs in PHPUnit, Testbench application bootstrap, and the per-worker runtime clone.

The shared test command already tries to prevent parent app env leakage before spawning child processes:

```php
protected function clearEnv(): void
{
    if ($this->option('env')) {
        return;
    }

    $path = $this->hypervel->environmentPath();

    if (! is_string($path)) {
        return;
    }

    $variables = self::getEnvironmentVariables($path, $this->hypervel->environmentFile());
    $repository = Env::getRepository();

    foreach ($variables as $name) {
        $repository->clear($name);
    }
}
```

In Hypervel this does not work reliably. `TestCommandBase::handle()` calls `Env::enablePutenv()` before `clearEnv()`, which resets the cached repository. The fresh immutable dotenv writer did not load the keys from the parent runtime `.env`, so `Repository::clear()` treats those keys as externally defined and refuses to delete them. Since Hypervel enables putenv support by default, those values can remain in `$_ENV`, `$_SERVER`, and the real process environment. Symfony Process then inherits them even when the command passes an explicit env array.

The fix is to use Hypervel's direct adapter deletion helper:

```php
$variables = self::getEnvironmentVariables($path, $this->hypervel->environmentFile());

Env::getRepository();
Env::deleteMany($variables);
```

`Env::getRepository()` rebuilds the adapter list after `Env::enablePutenv()`. `Env::deleteMany()` deletes from `$_ENV`, `$_SERVER`, and all tracked adapters directly, including the putenv adapter. This fixes the shared `TestCommandBase`, so both app `artisan test` and Testbench `package:test` get the corrected behavior.

`ParallelTesting::token()` is the framework runtime API for the worker token:

```php
public function token(): string|false
{
    return $this->tokenResolver
        ? call_user_func($this->tokenResolver)
        : ($_SERVER['TEST_TOKEN'] ?? false);
}
```

Redis worker allocation should use this API rather than reading `env('TEST_TOKEN')` directly so tests and framework code that install a token resolver continue to work.

## Research Notes

### Redis tests that need a secondary database

Only a small number of tests need cross-database `select()` behavior:

- `tests/Integration/Redis/RedisProxyIntegrationTest.php`
  - `testPipelineCallbackAndSelect`
  - `testPipelineCallbackAndPipeline`
  - `testSelectIsolationAcrossCoroutines`
- `tests/Integration/Redis/RedisConnectionIntegrationTest.php`
  - verifies direct phpredis `select()` works on the current primary test database
- `tests/Foundation/Testing/Concerns/InteractsWithRedisParallelTest.php`
  - currently tests helper calculations

Many Redis tests need real database isolation because they use `flushdb()`, scans, keys, raw Redis commands, or low-level Redis behavior. Prefix-only isolation is not enough for those tests. A Redis logical database per worker is the right default for Hypervel's Redis testing helpers.

### Current `InteractsWithRedis`

`InteractsWithRedis` currently computes:

```php
protected function getBaseRedisDb(): int
{
    return (int) env('REDIS_DB', 0);
}

protected function getParallelRedisDb(): int
{
    $token = env('TEST_TOKEN');

    return $this->getBaseRedisDb() + ($token !== null ? (int) $token : 0);
}
```

It also reserves `REDIS_DB` as a shared secondary database during parallel runs, then uses `REDIS_DB + TEST_TOKEN` for workers. Sequential runs use `REDIS_DB` as primary and `REDIS_DB + 1` as secondary.

That makes every sequential Redis test implicitly use two logical Redis databases when the secondary helper is called, even though only a few tests need that behavior.

The full raw ParaTest run also exposed `tests/Integration/Horizon/worker.php` as a Redis allocation consumer. Horizon's integration tests spawn that executable bootstrap in a real subprocess so the worker can process queued jobs while the parent test process supervises it. The bootstrap had its own copy of the old `REDIS_DB + TEST_TOKEN` formula. After the parent test app moved to the new min/max allocator, token `1` pushed jobs to database `1` while the subprocess watched database `2`, leaving jobs `pending`. This is duplicated allocation logic drifting across consumers, so the allocation math should live in one testing helper used by both `InteractsWithRedis` and the Horizon worker bootstrap.

### Why not command-level env parsing

The abandoned preflight design needed the command to parse package `.env` and PHPUnit XML because it tried to connect to Redis before PHPUnit/Testbench booted the suite.

Removing Redis preflight removes that need.

Env loading should stay in the normal runtime path:

- shell/process env and PHPUnit `<server>` / `<env>` values are handled by PHPUnit and process inheritance
- package/workbench `.env` values are copied into each Testbench runtime clone and loaded by Testbench application bootstrap
- Testbench YAML `env:` values are explicit package-test configuration and are forwarded by `package:test`
- Hypervel application env is handled by the framework bootstrap
- plain tests are not forced to inherit app-like `.env` values from command-level `.env` parsing or broad parent runtime env forwarding

This avoids command-level leakage across mixed suites containing raw PHPUnit tests, framework tests, and Testbench application tests.

Direct `vendor/bin/phpunit` does not apply Testbench YAML `env:` because there is no Testbench CLI app. That matches Orchestra's CLI-only behavior for the YAML `env` key.

If a key exists in both package/workbench `.env` and Testbench YAML `env:`, the explicit YAML forward is process env for the child and therefore wins over the runtime clone `.env` file. This matches dotenv's immutable precedence: shell/CI env and explicit process env are stronger than files loaded later by the child application.

The final package-test env contract is:

| Consumer | Gets env from |
| --- | --- |
| Raw PHPUnit, plain tests | shell/CI env, PHPUnit XML, and suite bootstrap |
| Raw PHPUnit, Testbench tests | shell/CI env, PHPUnit XML, suite bootstrap, and a real `.env` already present in the skeleton source copied by `copyDirectory()`; raw mode never gets package/workbench `.env` or skeleton `.env.example` through Bootstrapper |
| `package:test`, Testbench tests | shell/CI env, PHPUnit XML, suite bootstrap, command-owned package-test vars, explicit Testbench YAML `env:`, and package/workbench `.env` or skeleton `.env.example` through the `TESTBENCH_PACKAGE_TESTER`-gated runtime clone copy |
| `package:test`, plain tests | shell/CI env, PHPUnit XML, suite bootstrap, command-owned package-test vars, and explicit Testbench YAML `env:`; plain tests do not get app/skeleton env files |
| `artisan test` in apps | unchanged Laravel-style app semantics; `clearEnv()` now actually removes parent app dotenv values before spawning children, and `.env.testing` is loaded by the child application bootstrap |

The old components wrapper never set `TESTBENCH_PACKAGE_TESTER`, so the package-tester branch of upstream mode-keyed Testbench assertions was not exercised in the components repo. A scoped `test:testbench` script now makes that contract live without forcing the entire framework suite into package-tester semantics.

Defining `TESTBENCH_CORE` in the Testbench binary also makes root package provider discovery active in Testbench CLI children, which is the intended Orchestra-compatible behavior for real packages. In the components repo, the root `extra.hypervel.providers` list is a monorepo development aggregate, not the package under test for Testbench's own contract suite. A remote Testbench CLI child can create the runtime clone vendor symlink, run package discovery, and rebuild the shared clone's `bootstrap/cache/packages.php` while `TESTBENCH_CORE` is defined. That persists the components root aggregate providers into the clone. Later WithWorkbench test apps read the same file and, unless the root package is filtered at read time, can boot providers such as Fortify. Fortify then registers the named `login` route, which breaks the upstream Slim skeleton contract that the route is absent until the test defines it. The fix is to add `dont-discover: ['hypervel/components']` to Testbench's own `src/testbench/testbench.yaml`, keeping real package discovery behavior unchanged while preventing the components root aggregate from acting as the Testbench package under test.

The scoped package-mode run also exposed a route-cache lifecycle bug. `defineCacheRoutes()` is intentionally supported before `parent::setUp()`, but the old worker-specific route cache path was configured only during application creation. In pre-`setUp()` cached-route tests, the parent and remote `route:cache` child could fall back to the shared `bootstrap/cache/routes-v7.php` path before the worker-specific `APP_ROUTES_CACHE` value existed. This caused isolated parallel cached-route tests to fail and could let one worker's cached routes make another worker skip route setup. The fix is to make cache path setup idempotent and call it both from application creation and from `defineCacheRoutes()` itself, deriving the ParaTest worker token directly from the runtime arrays because this path must work before the container exists.

The scoped package-mode run also exposed shared route-file leakage. `defineCacheRoutes()` wrote route files as `routes/testbench-{time}.php`, and `SyncTestbenchCachedRoutes` intentionally loads every `routes/testbench-*.php` file in the worker clone so remote child processes can see stashed route definitions. The one-second filename resolution made collisions possible, and the old teardown deleted every matching route file rather than only the files owned by the current test instance. The fix is to write collision-proof route file names using the worker token, process ID, and monotonic timestamp; track the exact files written by the test instance; and delete only those owned files plus the current app's route cache file during teardown.

The scoped run also exposed a Testbench runtime clone cleanup race. Remote Testbench children with the same ParaTest token can bootstrap concurrently and both try to purge the same stale runtime copy left behind by a killed serve child. The shared `Filesystem::deleteDirectory()` behavior stays Laravel-identical; Testbench runtime-copy cleanup handles this local temp-directory race by retrying once and then accepting only the postcondition that the runtime directory is gone. If the directory still exists, the filesystem exception is rethrown.

The raw ParaTest full suite also exposed an AOP proxy source-map bug. `GenerateProxies` writes generated proxy paths back into Composer's runtime class map. Later app bootstraps then harvested that mutated map as if it still contained source paths. While the generated proxy file still existed, the bug was masked because `ProxyManager::isModified()` compared the proxy file against itself and skipped regeneration. After `cache:clear` / `optimize:clear` deleted `storage/framework/aop`, or after `package:purge-skeleton` purged the same directory from the Testbench runtime clone, a later app bootstrap tried to read the deleted proxy file as source and failed. The fix is for `GenerateProxies` to keep a worker-lifetime source class map, merge Composer's current class map into it on each proxy-generation boot while filtering generated `.proxy.php` paths, and pass explicit source file paths into `Ast` during proxy generation. Exact-rule `findFile()` resolutions must also reject generated proxy paths, because test-only `flushState()` can clear the captured source map while Composer's loader still contains a proxy entry for a previously proxied PSR-4 class. `ProxyManager` must track which source path generated each existing proxy file, because a later class-map override can point a class to a different source file whose mtime is older than the stale proxy. In that case, source-path drift must force regeneration even when the mtime check would otherwise skip it. Do not solve this by restoring Composer's loader from test cleanup; proxy generation should be self-sufficient and should not depend on per-test cleanup mutating the autoloader back into shape.

An earlier version of this plan said the full components suite should use `package:test`. That was wrong. Full-suite diagnostics proved the user's original mixed-suite concern was correct:

- `php src/testbench/bin/testbench package:test --parallel --without-tty` produced `119` errors and `37` failures across `21236` tests.
- Temporarily removing only `SESSION_DRIVER=cookie` and `CACHE_STORE=database` from the Testbench skeleton `.env.example` reduced that to `4` errors and `29` failures.
- The removed failures were the database-cache, cache-lock, cookie-session, and response-500 clusters.
- The remaining failures were still package-mode collisions: `APP_NAME=Testbench` from `src/testbench/testbench.yaml`, `APP_DEBUG=true` from the skeleton env, and mode-keyed Testbench defaults that specifically expect package mode.

A framework suite that tests env loading, config loading, exception rendering, and other bootstrap behavior cannot run inside package-tester semantics without fighting the mode it is trying to test. The components full-suite gate should therefore use raw ParaTest, matching the way Laravel's framework suite and Orchestra's own raw suite are run. Package-mode behavior remains covered by a scoped Testbench contract run.

Hypervel's skeleton config files carry only intentional differences from the framework base configuration. `LoadConfiguration` merges the framework config into the application config by default, and `cache.stores` is deep-merged. Do not copy full Orchestra-style skeleton config files into Hypervel; trace Hypervel's config merge first and keep only the overrides that differ from the framework defaults.

## Decisions

### Delete the components Redis preflight wrapper

Remove `bin/paratest`.

Change the components full-suite script to use raw ParaTest:

```json
{
    "scripts": {
        "test:parallel": "paratest"
    }
}
```

Add the parallel-testing flag to `phpunit.xml.dist`:

```xml
<server name="HYPERVEL_PARALLEL_TESTING" value="1"/>
```

This is safe for sequential `phpunit` runs because `ParallelTesting::inParallel()` requires both `HYPERVEL_PARALLEL_TESTING` and a ParaTest `TEST_TOKEN`.

Add a scoped package-mode contract script:

```json
{
    "scripts": {
        "test:testbench": "php src/testbench/bin/testbench package:test --parallel tests/Testbench"
    }
}
```

`test:parallel` verifies the full components framework suite with raw framework semantics. `test:testbench` verifies Testbench package-mode behavior with real `package:test` semantics.

Because ParaTest defaults to the machine CPU count, users must ensure the Redis worker range can cover the selected process count when Redis tests run. The old wrapper capped processes from Redis capacity; the new model fails clearly when the configured range is too small. Document that users can pass `--processes` / `-p` to choose a process count that fits their Redis test range.

### Fix the Testbench binary CLI marker

Add the Testbench CLI marker to `src/testbench/bin/testbench`:

```php
define('TESTBENCH_CORE', true);
```

Place it after the autoloader is loaded and before Testbench functions such as `is_testbench_cli()` can be used through the command path. This matches Orchestra's binary and makes `is_testbench_cli()` true when the Testbench CLI is actually running.

Verify:

- `php src/testbench/bin/testbench list` shows package commands, including `package:test`
- `PackageManifest::providersFromTestbench()` can merge the root package `extra.hypervel` metadata while running through the Testbench CLI
- the components root metadata merge does not produce duplicate or surprising providers during `test:testbench`

### Fix Testbench subprocess env ownership

The Testbench package command should not forward the temporary CLI application's full runtime env to PHPUnit or ParaTest children. Only command-owned values and declared Testbench YAML `env:` values belong in the explicit env array:

```php
return (new Collection($this->configurationEnvironmentVariables()))->merge(parent::baseEnvironmentVariables())->merge([
    'TESTBENCH_PACKAGE_TESTER' => '(true)',
    'TESTBENCH_WORKING_PATH' => package_path(),
]);
```

Package/workbench env files should reach Testbench application tests through the child runtime clone, not through parent process leakage. Extract the shared env-file resolution policy to a small internal Testbench foundation class. It should have two explicit resolution methods so callers choose whether they need only package/workbench env files or the full Testbench skeleton fallback behavior:

```php
namespace Hypervel\Testbench\Foundation;

use Hypervel\Filesystem\Filesystem;

class EnvironmentFile
{
    public function __construct(
        protected Filesystem $filesystem
    ) {
    }

    public function package(string $workingPath, string $filename = '.env'): ?string
    {
        // Resolve only package/workbench env candidates.
    }

    public function packageOrSkeletonFallback(string $workingPath, string $appBasePath, string $filename = '.env'): ?string
    {
        return $this->package($workingPath, $filename)
            ?? $this->skeletonFallback($appBasePath);
    }
}
```

The implementation should use the existing candidate order from `CopyTestbenchFiles::copyTestbenchDotEnvFile()`:

```text
<testbench env filename>
<testbench env filename>.example
<testbench env filename>.dist
.env
.env.example
.env.dist
```

`TESTBENCH_ENVIRONMENT_FILENAME` must still be respected. `CopyTestbenchFiles::copyTestbenchDotEnvFile()` should stay as a thin delegate for future Orchestra merges, but source resolution should come from the shared class. `Bootstrapper::createRuntimeCopy()` should copy the resolved package/workbench env file or skeleton `.env.example` fallback into `$runtimePath/.env` after copying the skeleton directory only when `TESTBENCH_PACKAGE_TESTER` is present. Remote child processes reuse the already-created runtime copy.

The skeleton fallback is preserved in the child runtime app so existing Testbench expectations continue to hold. The bug was not the fallback itself; it was loading that fallback into the parent CLI app and then leaking it to child processes before PHPUnit could load the package suite's own env.

Fix `TestCommandBase::clearEnv()` to delete parent app env keys from all env adapters:

```php
$variables = self::getEnvironmentVariables($path, $this->hypervel->environmentFile());

Env::getRepository();
Env::deleteMany($variables);
```

Add a short source comment explaining why `deleteMany()` is used instead of the repository's `clear()` method. The reason is the immutable dotenv writer: after `Env::enablePutenv()` rebuilds the repository, the writer did not load the parent keys and refuses to clear them as externally defined.

### Keep Redis isolation inside `InteractsWithRedis`

Redis worker selection should happen when a test uses `InteractsWithRedis`, after the application/test runtime exists.

This preserves the current useful behavior:

- non-Redis tests do not connect to Redis
- Redis tests skip when the default fallback Redis service is unavailable
- explicit Redis misconfiguration still fails
- `flushdb()` is safe because each worker owns a logical database
- low-level tests can use raw Redis commands without prefix caveats

### Non-parallel `InteractsWithRedis` tests use `REDIS_DB`

Sequential tests using `InteractsWithRedis` should not unexpectedly reserve or use a second database.

With:

```env
REDIS_DB=3
```

`phpunit` or `php artisan test` without `--parallel` uses:

```text
primary Redis database = 3
```

`REDIS_TEST_DB_MIN` and `REDIS_TEST_DB_MAX` are parallel-worker allocation settings. They do not change sequential primary database selection.

### Parallel `InteractsWithRedis` tests use a configured worker range

Parallel Redis worker databases are allocated from:

```env
REDIS_TEST_DB_MIN=...
REDIS_TEST_DB_MAX=...
```

Defaults:

```text
REDIS_TEST_DB_MIN = REDIS_DB, default 0
REDIS_TEST_DB_MAX = 15
```

Without a secondary database:

```text
REDIS_DB=1

worker 1 -> database 1
worker 2 -> database 2
...
worker 15 -> database 15
```

With an explicit range:

```env
REDIS_TEST_DB_MIN=4
REDIS_TEST_DB_MAX=8
```

```text
worker 1 -> database 4
worker 2 -> database 5
worker 3 -> database 6
worker 4 -> database 7
worker 5 -> database 8
```

If a worker cannot be assigned a database from the configured range, the test fails with a clear exception. It should not skip. A range that cannot support the requested process count is a test environment error.

### Secondary Redis database is explicit

`getSecondaryRedisDb()` should require:

```env
REDIS_TEST_SECONDARY_DB=...
```

There is no fallback to `REDIS_TEST_DB_MAX`.

That keeps the behavior honest:

- normal apps and packages use one Redis database per test worker
- advanced tests opt into a second database only when needed
- framework tests that assert `select()` behavior keep their coverage

If `REDIS_TEST_SECONDARY_DB` is configured, it is reserved. Worker allocation skips it when it falls inside the worker range.

Example:

```env
REDIS_TEST_DB_MIN=1
REDIS_TEST_DB_MAX=15
REDIS_TEST_SECONDARY_DB=15
```

```text
worker 1 -> database 1
worker 2 -> database 2
...
worker 14 -> database 14
database 15 -> secondary database
worker 15 -> fail, because no worker database is available
```

If the secondary database is outside the worker range, no worker skip is needed:

```env
REDIS_TEST_DB_MIN=1
REDIS_TEST_DB_MAX=14
REDIS_TEST_SECONDARY_DB=15
```

```text
worker databases = 1..14
secondary database = 15
```

### Do not probe Redis database capacity

Do not call `CONFIG GET databases`.

Managed Redis services can restrict that command, and a parent process cannot know whether Redis will actually be used by the test suite. The configured range is the contract. If the range is too large for the test Redis server, the actual Redis operation fails and points at the test environment configuration.

### Components env files configure the framework test range

The components repo runs low-level Redis tests that need a secondary database. Update both `.env` and `.env.example`:

```env
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=password
REDIS_DB=1
REDIS_TEST_DB_MIN=1
REDIS_TEST_DB_MAX=14
REDIS_TEST_SECONDARY_DB=15
```

The example should keep the same commented style as the existing Redis entries.

### Documentation belongs in Boost testing docs

`src/boost/docs/testing.md` already has a `Running Tests in Parallel` section with database, hook, and token subsections. Add a Redis subsection there, near `Parallel Testing and Databases`.

The docs update must match the current Boost documentation style and voice:

- same heading depth and anchor format
- Laravel-like user-facing prose
- concise explanation
- no implementation chatter
- no agent-oriented wording

`src/boost/docs/testbench.md` should get a short note that package tests use the same parallel Redis testing behavior as application tests.

## Implementation Plan

### 1. Replace the components parallel scripts

Update `composer.json`:

```json
"test:parallel": "paratest",
"test:testbench": "php src/testbench/bin/testbench package:test --parallel tests/Testbench"
```

Delete `bin/paratest`.

Update `phpunit.xml.dist`:

```xml
<server name="HYPERVEL_PARALLEL_TESTING" value="1"/>
```

Place it in the existing `<php>` block with the other suite-level settings.

Update the app skeleton `contrib/hypervel/hypervel/phpunit.xml` the same way. App tests normally use `php artisan test --parallel`, which already injects the flag, but the skeleton should also make raw `vendor/bin/paratest` work correctly. Plain `phpunit` still runs normally because ParaTest is the process that supplies `TEST_TOKEN`.

Wire `test:testbench` into `check` and `fix` so the normal quality gates cover both the framework suite and the package-mode Testbench contract.

Update `src/testbench/bin/testbench` to define `TESTBENCH_CORE` before the command is built:

```php
define('TESTBENCH_CORE', true);
```

Update `AGENTS.md` so it says `composer test:parallel` uses raw ParaTest for the full components suite and `composer test:testbench` uses the Testbench package test command for package-mode contracts.

Search active source/docs for stale references:

```shell
grep -R -n "bin/paratest\\|custom wrapper\\|preflight\\|pre-flight" AGENTS.md composer.json src tests --include='*.md' --include='*.php' --include='composer.json'
```

Historical plan documents may still describe their own historical work. Active docs, source comments, and command help must not reference the deleted wrapper as current behavior.

### 2. Fix Testbench env ownership

Create `src/testbench/src/Foundation/EnvironmentFile.php`.

The class should centralize the existing Testbench env-file resolution policy and expose explicit methods for the two real callers:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation;

use Hypervel\Filesystem\Filesystem;

class EnvironmentFile
{
    /**
     * Create a new environment file resolver.
     */
    public function __construct(
        protected Filesystem $filesystem
    ) {
    }

    /**
     * Resolve the package or workbench environment file.
     */
    public function package(string $workingPath, string $filename = '.env'): ?string
    {
        $sourcePath = $this->sourcePath($workingPath);
        $environmentPath = $this->filesystem->isDirectory(join_paths($sourcePath, 'workbench'))
            ? join_paths($sourcePath, 'workbench')
            : $sourcePath;

        return $this->firstExisting($environmentPath, $this->candidateNames($filename));
    }

    /**
     * Resolve the package or workbench environment file, falling back to the skeleton example.
     */
    public function packageOrSkeletonFallback(string $workingPath, string $appBasePath, string $filename = '.env'): ?string
    {
        return $this->package($workingPath, $filename)
            ?? $this->skeletonFallback($appBasePath);
    }

    /**
     * Resolve the source path for testbench config and workbench fixtures.
     */
    public function sourcePath(string $workingPath): string
    {
        foreach (['testbench.yaml', 'testbench.yaml.example', 'testbench.yaml.dist'] as $configurationFile) {
            if ($this->filesystem->isFile(join_paths($workingPath, $configurationFile))) {
                return $workingPath;
            }
        }

        if ($this->filesystem->isDirectory(join_paths($workingPath, 'workbench'))) {
            return $workingPath;
        }

        return testbench_path();
    }

    /**
     * Get the ordered environment file candidate names.
     *
     * @return array<int, string>
     */
    protected function candidateNames(string $filename): array
    {
        return array_values(array_unique([
            $filename,
            "{$filename}.example",
            "{$filename}.dist",
            '.env',
            '.env.example',
            '.env.dist',
        ]));
    }
}
```

The final implementation should also include the private helpers needed by the snippet, such as `firstExisting()` and `skeletonFallback()`, with method docblocks matching the package style. Import `join_paths()` and `testbench_path()` from the Testbench functions namespace.

Update `src/testbench/src/Foundation/Console/Concerns/CopyTestbenchFiles.php`:

- keep `copyTestbenchDotEnvFile()` as a thin delegate
- resolve the configuration source path through `EnvironmentFile::sourcePath()`
- resolve the env source through `EnvironmentFile::packageOrSkeletonFallback()`
- keep the existing backup and terminating cleanup behavior unchanged
- remove local duplicated candidate-order logic from the trait

Update `src/testbench/src/Bootstrapper.php`:

- pass the resolved Testbench working path through `resolveRuntimeBasePath()` and `createRuntimeCopy()`
- after copying the skeleton directory to `$runtimePath`, copy package-test env files only when `Env::has('TESTBENCH_PACKAGE_TESTER')`
- when package-tester mode is active, resolve `EnvironmentFile::packageOrSkeletonFallback($workingPath, $runtimePath, $environmentFilename)` and copy the resolved env file to `join_paths($runtimePath, '.env')`
- when package-tester mode is not active, do not copy package/workbench `.env` or skeleton `.env.example`; raw PHPUnit Testbench mode must preserve Orchestra's behavior and only load a real `.env` that already exists in the skeleton source copied by `copyDirectory()`
- do not copy anything in the remote-process branch, because that branch reuses the runtime copy created by the parent process

The environment filename should come from `TESTBENCH_ENVIRONMENT_FILENAME` when set, otherwise `.env`.

Update `src/testing/src/Console/TestCommandBase.php`:

```php
$variables = self::getEnvironmentVariables($path, $this->hypervel->environmentFile());

Env::getRepository();
Env::deleteMany($variables);
```

Add a concise source comment above the `Env::getRepository()` / `Env::deleteMany()` calls explaining that the immutable repository writer cannot clear keys it did not load, so test commands must delete directly from the adapters after rebuilding the adapter list.

Update `src/testbench/src/Foundation/Console/TestCommand.php` so `baseEnvironmentVariables()` no longer merges `defined_environment_variables()`:

```php
return (new Collection($this->configurationEnvironmentVariables()))->merge(parent::baseEnvironmentVariables())->merge([
    'TESTBENCH_PACKAGE_TESTER' => '(true)',
    'TESTBENCH_WORKING_PATH' => package_path(),
]);
```

The command should not import `defined_environment_variables()` after this change.

Add an internal helper that forwards only declared Testbench YAML `env:` values. Use the same dotenv `Parser` and `StringStore` semantics as `LoadEnvironmentVariablesFromArray`, and merge these values before parent command vars and command-owned package-test vars so `APP_ENV`, `TESTBENCH_PACKAGE_TESTER`, and `TESTBENCH_WORKING_PATH` keep winning.

Suggested shape:

```php
use Dotenv\Parser\Entry;
use Dotenv\Parser\Parser;
use Dotenv\Store\StringStore;

/**
 * Get configured Testbench environment variables.
 *
 * @return array<string, string>
 */
protected function configurationEnvironmentVariables(): array
{
    $environmentVariables = Bootstrapper::getConfiguration()['env'] ?? [];

    if (! is_array($environmentVariables) || $environmentVariables === []) {
        return [];
    }

    $store = new StringStore(implode(PHP_EOL, $environmentVariables));
    $parser = new Parser;
    $variables = [];

    foreach ($parser->parse($store->read()) as $entry) {
        if ($entry->getValue()->isDefined()) {
            $variables[$entry->getName()] = $entry->getValue()->get()->getChars();
        }
    }

    return $variables;
}
```

This preserves the intended package-test YAML env channel without forwarding arbitrary parent CLI runtime env or skeleton `.env` values.

### 3. Refactor `InteractsWithRedis`

Remove wrapper-era state:

```php
private static bool $noRedisDbAvailable = false;
private function isRedisDbAvailable(int $db): bool
```

The range is now configured by env; Redis capacity probing is gone.

Keep the existing default-Redis skip behavior:

```php
if ($host === '127.0.0.1' && $port === 6379 && env('REDIS_HOST') === null) {
    static::$connectionFailedOnceWithDefaultsSkip = true;
    $this->markTestSkipped(...);
}
```

Explicit Redis configuration failures should continue to throw.

Create a small `Hypervel\Foundation\Testing\RedisTestDatabases` helper for the pure allocation math. The trait still owns test-case lifecycle, Redis flushing, config mutation, and resolver-aware token lookup; the helper only maps env values and tokens to database numbers. This keeps executable bootstraps such as Horizon's `worker.php` from copying trait internals.

```php
use RuntimeException;

class RedisTestDatabases
{
    public static function baseDatabase(): int;

    public static function minimumDatabase(): int;

    public static function maximumDatabase(): int;

    public static function configuredSecondaryDatabase(): ?int;

    public static function primaryDatabase(string|false $token): int;

    public static function databaseForToken(string $token): int;

    public static function secondaryDatabase(string|false $token): int;

    /** @return array<int, int> */
    public static function workerDatabases(): array;
}
```

Use full variable names in implementation (`$database`, `$databases`, `$workerIndex`, `$token`) rather than abbreviations.

Parallel database allocation should be deterministic and should skip the configured secondary database:

```php
protected function getParallelRedisDb(): int
{
    return RedisTestDatabases::primaryDatabase($this->parallelTestingToken());
}

protected function redisDatabaseForParallelToken(string $token): int
{
    return RedisTestDatabases::databaseForToken($token);
}
```

Resolve the token through the framework API:

```php
protected function parallelTestingToken(): string|false
{
    return $this->app->make(ParallelTesting::class)->token();
}
```

This preserves support for `ParallelTesting::resolveTokenUsing()` while keeping standard ParaTest behavior unchanged.

Token parsing should fail clearly if a malformed token is supplied:

```php
public static function workerIndex(string $token): int
{
    if (! ctype_digit($token) || (int) $token < 1) {
        throw new RuntimeException('TEST_TOKEN must be a positive integer for Redis parallel testing.');
    }

    return (int) $token - 1;
}
```

Env integer parsing should also fail clearly for invalid Redis database settings:

```php
public static function integerEnvironment(string $key, int $default): int
{
    $value = env($key);

    if ($value === null) {
        return $default;
    }

    return static::integerEnvironmentValue($key, $value);
}

public static function integerEnvironmentValue(string $key, mixed $value): int
{
    if (is_int($value)) {
        if ($value < 0) {
            throw new RuntimeException("{$key} must be a non-negative integer.");
        }

        return $value;
    }

    if (is_string($value) && ctype_digit($value)) {
        return (int) $value;
    }

    throw new RuntimeException("{$key} must be a non-negative integer.");
}
```

The implementation should also reject negative database numbers. If the final helper accepts only `ctype_digit()` strings and non-negative integers, negative strings are already rejected.

Update `configureParallelRedisDb()`:

```php
private function configureParallelRedisDb(): void
{
    if ($this->parallelTestingToken() === false) {
        return;
    }

    $database = $this->getParallelRedisDb();

    $this->app->make('config')->set('database.redis.default.database', $database);
}
```

Keep `createRedisConnectionWithPrefix()` using `getParallelRedisDb()` so named Redis connections also inherit the worker database.

Update `tests/Integration/Horizon/worker.php` so its subprocess bootstrap uses the same allocator:

```php
$token = getenv('TEST_TOKEN');

if (is_string($token)) {
    $config->set('database.redis.default.database', RedisTestDatabases::databaseForToken($token));
}
```

Update the trait docblock to describe the new env vars:

```text
REDIS_DB
REDIS_TEST_DB_MIN
REDIS_TEST_DB_MAX
REDIS_TEST_SECONDARY_DB
```

Remove comments that claim the base database is always reserved as the secondary database.

The updated docblock should keep the existing guidance that the secondary database is shared by tests that explicitly request it. Tests should use unique keys and delete those keys; they should not call `flushdb()` on the secondary database.

### 4. Update framework Redis tests

Update helper tests in `tests/Foundation/Testing/Concerns/InteractsWithRedisParallelTest.php`.

The existing sequential tests for implicit `base + 1` secondary behavior should be replaced. New coverage should include:

- non-parallel `getParallelRedisDb()` returns `REDIS_DB`
- parallel worker token maps to the configured min-based database
- `REDIS_TEST_DB_MIN` defaults to `REDIS_DB`
- `REDIS_TEST_DB_MAX` defaults to `15`
- overflow fails instead of skipping
- `REDIS_TEST_SECONDARY_DB` is required by `getSecondaryRedisDb()`
- configured secondary database is returned
- configured secondary database is skipped during worker allocation
- secondary database matching the current primary database fails
- invalid min/max range fails
- invalid database env values fail
- invalid `TEST_TOKEN` fails

These tests should avoid requiring a live Redis service. They should exercise helper methods through a local harness, like the current file does.

Do not keep the current skip pattern for sequential assertions. The test file must run deterministically in both normal PHPUnit and ParaTest workers. Use controlled state per test:

- control token-dependent cases with `ParallelTesting::resolveTokenUsing(fn () => '3')`
- reset the token resolver with `ParallelTesting::resolveTokenUsing(null)` in `tearDown()`
- set `REDIS_DB`, `REDIS_TEST_DB_MIN`, `REDIS_TEST_DB_MAX`, and `REDIS_TEST_SECONDARY_DB` through `$_SERVER` and/or the Env repository
- capture original `$_SERVER`, `$_ENV`, and process env values before mutation
- restore all changed values in `tearDown()`
- flush the Env repository after changes when needed so `env()` reads the intended values

Also add coverage proving a custom `ParallelTesting::resolveTokenUsing()` value is honored by `InteractsWithRedis`.

Update integration tests that need the secondary database only if required by the new helper contract. Since components `.env` will set `REDIS_TEST_SECONDARY_DB`, the actual integration tests should continue to call `getSecondaryRedisDb()` directly.

Update `RedisProxyIntegrationTest::testPipelineCallbackAndPipeline` so the `pipeline_select_junk_*` key written to the secondary database is deleted. The old wrapper flushed the shared secondary database before each run; that flush is going away, so secondary database tests must clean up their own keys.

Run the changed test file immediately:

```shell
./vendor/bin/phpunit tests/Foundation/Testing/Concerns/InteractsWithRedisParallelTest.php
```

Run relevant Redis integration tests after the helper changes:

```shell
./vendor/bin/phpunit tests/Integration/Redis/RedisProxyIntegrationTest.php
./vendor/bin/phpunit tests/Integration/Redis/RedisConnectionIntegrationTest.php
```

### 5. Update command and Testbench env tests

`tests/Testbench/Foundation/Console/TestCommandTest.php` already verifies package command arguments and env variables.

Verify the existing coverage still proves the package command builds ParaTest arguments with:

```text
--runner=Hypervel\Testbench\Features\ParallelRunner
```

and still forwards normal PHPUnit filters. Existing coverage already checks this, so do not add duplicate assertions unless a real gap appears during implementation.

Add or adjust coverage for the components script indirectly by checking `composer.json` or through a narrow command test if there is an existing pattern for script assertions. Do not add brittle shell assertions if a simple source check is enough.

Add focused coverage for the Testbench binary marker if there is an appropriate command/testbench test location. At minimum, verify manually during implementation that `php src/testbench/bin/testbench list` shows `package:test`.

Add package command env ownership coverage:

- parent runtime env keys such as `APP_NAME` and `REDIS_PASSWORD` are not included in package command env variables
- `TESTBENCH_PACKAGE_TESTER` and `TESTBENCH_WORKING_PATH` are still included
- `TESTBENCH_APP_BASE_PATH` is not included; `APP_BASE_PATH` remains the explicit user override
- declared Testbench YAML `env:` values are included in package command env variables
- command-owned package-test vars override colliding YAML env values

Add shared command `clearEnv()` coverage in `tests/Testing/Console/TestCommandTest.php`:

- create an isolated env file that names a few variables
- set those variables in `$_SERVER`, `$_ENV`, and with `putenv()`
- call the command harness method that exposes `clearEnv()`
- assert every named key is absent from `$_SERVER`, `$_ENV`, and `getenv()`

Add runtime clone env-file coverage in `tests/Testbench/BootstrapperTest.php`:

- in package-tester mode, a package/workbench env file is copied to the runtime clone as `.env`
- in package-tester mode, the skeleton `.env.example` is copied to the runtime clone as `.env` when no package/workbench env file resolves
- outside package-tester mode, no package/workbench env file and no skeleton `.env.example` fallback is copied to the runtime clone
- the test restores Bootstrapper static properties, `TESTBENCH_PACKAGE_TESTER` in `$_SERVER`, `$_ENV`, and process env, and removes temporary directories in `finally`

### 6. Complete package-test skeleton config parity

The real `package:test` runner sets `TESTBENCH_PACKAGE_TESTER`, so upstream mode-keyed Testbench assertions expect the skeleton `.env.example` package defaults to be active. Update `src/testbench/hypervel/.env.example`:

```env
SESSION_DRIVER=cookie
CACHE_STORE=database
```

Keep `src/testbench/hypervel/config/cache.php` minimal. The existing file is already the correct set of Testbench-specific differences:

- `default` falls back to `array` in raw mode, while package-test mode reads `CACHE_STORE=database`
- the skeleton Redis cache store uses the default Redis connection because the skeleton does not define a separate `cache` Redis connection
- the cache prefix is deterministic for tests

The framework base config supplies the `database` cache store through the normal config merge.

Update `src/testbench/hypervel/config/session.php` to keep only intentional differences:

```php
return [
    'driver' => env('SESSION_DRIVER', 'array'),
    'lottery' => [0, 2],
    'cookie' => 'hypervel_session',
];
```

Do not duplicate the rest of the framework session config. Omitting `store` inherits the base `env('SESSION_STORE')` value. The zero lottery and pinned cookie name are intentional test skeleton differences.

Run changed command test files immediately:

```shell
./vendor/bin/phpunit tests/Testbench/Foundation/Console/TestCommandTest.php
./vendor/bin/phpunit tests/Testing/Console/TestCommandTest.php
./vendor/bin/phpunit tests/Testbench/BootstrapperTest.php
```

Run changed package manifest tests immediately:

```shell
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Foundation/PackageManifestTest.php
```

Run changed Testbench route-file tests immediately:

```shell
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Concerns/DefineCacheRoutesTest.php
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Concerns/DefineCacheRoutesBeforeSetUpTest.php
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Integrations/CacheRouteTest.php
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Integrations/InlineCacheRouteTest.php
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Integrations/StashRouteTest.php
php src/testbench/bin/testbench package:test --parallel --processes=6 --without-tty tests/Testbench/Integrations/SlimSkeletonApplicationTest.php
php src/testbench/bin/testbench package:test --parallel --processes=6 --without-tty tests/Testbench/CommanderServeTest.php
```

### 7. Update env files

Update `.env`:

```env
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=password
REDIS_DB=1
REDIS_TEST_DB_MIN=1
REDIS_TEST_DB_MAX=14
REDIS_TEST_SECONDARY_DB=15
```

Update `.env.example` in the same Redis section and keep the example variables commented, matching the existing file style:

```env
# REDIS_HOST=127.0.0.1
# REDIS_PORT=6379
# REDIS_PASSWORD=password
# REDIS_DB=1
# REDIS_TEST_DB_MIN=1
# REDIS_TEST_DB_MAX=14
# REDIS_TEST_SECONDARY_DB=15
```

Update `.github/workflows/redis.yml` in both Redis 8 and Valkey 9 jobs. They run `vendor/bin/phpunit tests/Integration/Redis` sequentially with only `REDIS_HOST` / `REDIS_PORT`; after `getSecondaryRedisDb()` becomes explicit, those jobs need:

```yaml
REDIS_TEST_SECONDARY_DB: 1
```

Sequential primary defaults to Redis database `0` in those jobs, so database `1` is a safe secondary database.

### 8. Update documentation

Update `src/boost/docs/testing.md`.

Add a subsection after `Parallel Testing and Databases`:

```md
<a name="parallel-testing-and-redis"></a>
#### Parallel Testing and Redis
```

Document:

- tests using `InteractsWithRedis` use the normal configured Redis database when not running in parallel
- parallel tests using `InteractsWithRedis` use `REDIS_TEST_DB_MIN` and `REDIS_TEST_DB_MAX`
- defaults are `REDIS_TEST_DB_MIN=REDIS_DB` and `REDIS_TEST_DB_MAX=15`
- Hypervel fails when a worker cannot be assigned a Redis database
- ParaTest uses the machine CPU count by default, so the Redis worker range must cover the process count or the user should pass `--processes`
- `REDIS_TEST_SECONDARY_DB` is only needed for tests that call the secondary database helper
- when configured, the secondary database is reserved and skipped by worker allocation

Use the same language style as the surrounding docs. Do not mention internal trait names unless needed for user clarity.

Update the callback examples in the existing parallel testing docs so the token parameter type is `string`, matching `ParallelTesting::token(): string|false` and the already-committed `ParallelRunner` token normalization.

Update `src/boost/docs/testbench.md` with a short package-test note:

```md
Package tests use the same parallel database, cache, and Redis isolation behavior as application tests.
```

Keep the wording consistent with the rest of the page.

Update `src/testbench/README.md`. It is currently a stub, so add:

```md
Ported from: https://github.com/orchestral/testbench-core
```

Also add a `Differences From Orchestra Testbench` section explaining that Hypervel does not forward the parent Testbench CLI application's full runtime env to `package:test` subprocesses. Package/workbench env files are loaded from the child runtime clone; shell/CI env, PHPUnit XML values, and Testbench YAML `env:` values still reach package-test child processes through their normal channels.

### 9. Update AGENTS.md

Change the `composer test:parallel` guidance to reflect the new command path:

```md
**Always use `composer test:parallel`** to run the full components suite. This runs raw ParaTest with Hypervel's parallel testing flag supplied by `phpunit.xml.dist`.
```

Also document `composer test:testbench` as the scoped package-mode Testbench contract run. Do not mention Redis preflight or the deleted wrapper as current behavior. Do not say direct `vendor/bin/paratest` bypasses setup; after this change direct ParaTest and `composer test:parallel` use the same runtime setup.

Also add a short note that the default ParaTest process count comes from CPU count, and Redis integration runs need `REDIS_TEST_DB_MIN` / `REDIS_TEST_DB_MAX` to cover the chosen worker count.

## Testing Plan

Run focused tests after each changed test file:

```shell
./vendor/bin/phpunit tests/Foundation/Testing/Concerns/InteractsWithRedisParallelTest.php
./vendor/bin/phpunit tests/Integration/Redis/RedisProxyIntegrationTest.php
./vendor/bin/phpunit tests/Integration/Redis/RedisConnectionIntegrationTest.php
./vendor/bin/phpunit tests/Testbench/Foundation/Console/TestCommandTest.php
./vendor/bin/phpunit tests/Testing/Console/TestCommandTest.php
./vendor/bin/phpunit tests/Testbench/BootstrapperTest.php
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Foundation/PackageManifestTest.php
```

Run the focused Testbench route-file and auth checks:

```shell
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Concerns/DefineCacheRoutesTest.php
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Concerns/DefineCacheRoutesBeforeSetUpTest.php
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Integrations/CacheRouteTest.php
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Integrations/InlineCacheRouteTest.php
php src/testbench/bin/testbench package:test --without-tty tests/Testbench/Integrations/StashRouteTest.php
php src/testbench/bin/testbench package:test --parallel --processes=6 --without-tty tests/Testbench/Integrations/SlimSkeletonApplicationTest.php
php src/testbench/bin/testbench package:test --parallel --processes=6 --without-tty tests/Testbench/CommanderServeTest.php
```

Run the Redis workflow-equivalent sequential command with the same env shape as CI:

```shell
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 REDIS_DB=0 REDIS_TEST_SECONDARY_DB=1 ./vendor/bin/phpunit tests/Integration/Redis
```

Run a targeted raw ParaTest smoke test:

```shell
composer test:parallel -- --filter=InteractsWithRedisParallelTest
```

Run the focused Horizon subprocess check that proves spawned workers use the same Redis database as the parent test app:

```shell
./vendor/bin/paratest --processes=2 tests/Integration/Horizon/Feature/SupervisorTest.php
```

Run the scoped package-mode contract script:

```shell
composer test:testbench
```

Run a targeted package-command smoke test if package-mode Redis behavior needs a smaller reproduction:

```shell
php src/testbench/bin/testbench package:test --parallel --filter=InteractsWithRedisParallelTest
```

Run the mode-keyed Testbench contract files directly with PHPUnit and through `package:test`:

```shell
./vendor/bin/phpunit \
  tests/Testbench/Foundation/EnvTest.php \
  tests/Testbench/DefaultConfigurationTest.php \
  tests/Testbench/TestCaseTest.php \
  tests/Testbench/BootstrapperTest.php \
  tests/Testbench/Foundation/ApplicationTest.php \
  tests/Testbench/Attributes/UsesFrameworkConfigurationTest.php \
  tests/Testbench/Integrations/EnvironmentVariablesTest.php \
  tests/Testbench/Integrations/LoadUsingFrameworkConfigurationTest.php \
  tests/Testbench/Foundation/Console/InstallCommandTest.php

php src/testbench/bin/testbench package:test --without-tty \
  tests/Testbench/Foundation/EnvTest.php \
  tests/Testbench/DefaultConfigurationTest.php \
  tests/Testbench/TestCaseTest.php \
  tests/Testbench/BootstrapperTest.php \
  tests/Testbench/Foundation/ApplicationTest.php \
  tests/Testbench/Attributes/UsesFrameworkConfigurationTest.php \
  tests/Testbench/Integrations/EnvironmentVariablesTest.php \
  tests/Testbench/Integrations/LoadUsingFrameworkConfigurationTest.php \
  tests/Testbench/Foundation/Console/InstallCommandTest.php
```

Run live Redis package-command checks that exercise inherited-env cleanup through Testbench:

```shell
php src/testbench/bin/testbench package:test --without-tty --filter=testRedisLockCanBeAcquiredAndReleasedWithoutSerializationAndCompression
php src/testbench/bin/testbench package:test --parallel --without-tty --filter=PhpRedisCacheLockTest
```

Run the Testbench CLI list command to verify `TESTBENCH_CORE` behavior:

```shell
php src/testbench/bin/testbench list
```

Run the full project check:

```shell
composer fix
```

`composer fix` runs cs-fixer, PHPStan, raw `composer test:parallel`, and scoped `composer test:testbench`.

After `composer fix` passes, run one direct command to prove raw ParaTest has the same setup as the Composer script:

```shell
./vendor/bin/paratest --filter=InteractsWithRedisParallelTest
```

## Self-Review Checklist

- `bin/paratest` is deleted.
- `composer.json` uses raw `paratest` for `test:parallel`.
- `composer.json` has a scoped `test:testbench` script for `tests/Testbench`.
- `composer.json` runs `test:testbench` from normal quality gates.
- `phpunit.xml.dist` sets `HYPERVEL_PARALLEL_TESTING` as a server variable.
- The app skeleton `phpunit.xml` sets `HYPERVEL_PARALLEL_TESTING` as a server variable.
- `src/testbench/bin/testbench` defines `TESTBENCH_CORE`.
- `src/testbench/testbench.yaml` ignores the `hypervel/components` root package so Testbench's own contract suite does not auto-discover the components root provider aggregate.
- Package manifest coverage proves specific package ignores filter root package metadata at read time while the cached file still contains the built root entry.
- `AGENTS.md` no longer says the suite runs through the custom wrapper.
- `AGENTS.md` no longer says direct ParaTest bypasses setup.
- `AGENTS.md` documents `test:testbench` as the package-mode Testbench contract run.
- `AGENTS.md` documents that Redis test range must cover the selected ParaTest process count.
- `TestCommandBase::clearEnv()` deletes env-file keys from `$_SERVER`, `$_ENV`, and the putenv adapter.
- `TestCommandBase::clearEnv()` has a concise comment explaining why direct adapter deletion is needed.
- `Hypervel\Testbench\Foundation\EnvironmentFile` owns Testbench env-file source resolution.
- `CopyTestbenchFiles` delegates env-file resolution to `EnvironmentFile` and no longer duplicates candidate-order logic.
- `Bootstrapper::createRuntimeCopy()` copies the resolved package/workbench env file or skeleton fallback into the runtime clone only in package-tester mode.
- Raw PHPUnit Testbench boot does not copy package/workbench `.env` or skeleton `.env.example` through Bootstrapper.
- `package:test` injects command-owned explicit env variables and declared Testbench YAML `env:` values.
- `package:test` does not inject arbitrary parent CLI runtime env variables.
- `defineCacheRoutes()` writes unique route files for each test instance.
- `defineCacheRoutes()` tracks owned route files and deletes only those files during teardown.
- `SyncTestbenchCachedRoutes` still loads `routes/testbench-*.php` files for remote child support, but stale sibling route files are not created by Testbench itself.
- The Testbench README contains the upstream reference and the deliberate env-forwarding difference from Orchestra Testbench.
- `InteractsWithRedis` has no Redis preflight or Redis capacity probing.
- `InteractsWithRedis` and the Horizon worker test bootstrap share Redis worker DB allocation through `RedisTestDatabases`.
- Redis is not connected from command classes before the suite starts.
- Non-parallel `InteractsWithRedis` tests use `REDIS_DB`.
- Parallel `InteractsWithRedis` tests use `REDIS_TEST_DB_MIN` / `REDIS_TEST_DB_MAX`.
- `REDIS_TEST_DB_MIN` defaults to `REDIS_DB`, then `0`.
- `REDIS_TEST_DB_MAX` defaults to `15`.
- Worker overflow fails clearly.
- `getSecondaryRedisDb()` fails clearly without `REDIS_TEST_SECONDARY_DB`.
- `getSecondaryRedisDb()` fails clearly if it equals the current primary test database.
- Configured secondary database is skipped by worker allocation.
- Secondary database tests clean up their own keys and never rely on a suite-level secondary `flushdb()`.
- `.env` and `.env.example` contain the components Redis test range.
- `.github/workflows/redis.yml` sets `REDIS_TEST_SECONDARY_DB` for both Redis and Valkey jobs.
- Boost docs match the existing style and document the user-facing behavior.
- No command-level package `.env` parser was added.
- Testbench YAML `env:` is described as an explicit package-test command channel, not as child Testbench bootstrap behavior.
- Active source/docs contain no stale references to Redis preflight or `bin/paratest`.
