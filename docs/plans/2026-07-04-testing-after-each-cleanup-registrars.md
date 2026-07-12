# Testing After Each Cleanup Registrars Plan

## Scope

Make Hypervel's after-each test cleanup extensible for apps and packages while keeping framework cleanup centralized, deterministic, and independent of application boot.

## Goal

Build a first-class test cleanup API for Hypervel's long-lived worker model:

- Keep the framework-owned after-each cleanup list centralized in `hypervel/testing`.
- Let apps register their own worker-lifetime/static cleanup in one obvious generated class.
- Let packages ship their own cleanup registrars so app developers do not need to know package internals.
- Make cleanup registration work from the first test in every PHPUnit or Paratest worker, including workers that run only app-less unit tests.
- Avoid service provider based registration, separate local PHPUnit subscribers, duplicated Composer manifest parsing, stale docs, and any code that suggests cleanup is optional for worker-lifetime state.
- Fix the app skeleton so generated apps run Hypervel's framework cleanup after every test.

Churn and backwards compatibility do not constrain this plan. The resulting codebase should read as if the testing cleanup API was designed this way from the start.

## Source Material Reviewed

Project instructions:

- `contrib/hypervel/components/AGENTS.md`, read in full on 2026-07-04 before writing this plan.

Hypervel source and docs:

- `src/testing/src/PHPUnit/AfterEachTestExtension.php`
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`
- `src/testing/composer.json`
- `src/foundation/src/PackageManifest.php`
- `src/testbench/src/Foundation/PackageManifest.php`
- `src/boost/docs/testing.md`
- `src/boost/docs/packages.md`
- `phpunit.xml.dist`
- `tests/Testing/TestingStaticStateTest.php`
- `tests/Foundation/FoundationPackageManifestTest.php`
- `tests/Foundation/Fixtures/composer.json`
- `tests/Foundation/Fixtures/vendor/composer/installed.json`

Application skeleton:

- `../hypervel/phpunit.xml`
- `../hypervel/composer.json`
- `../hypervel/tests/TestCase.php`
- `../hypervel/tests/Feature/ExampleTest.php`
- `../hypervel/tests/Unit/ExampleTest.php`

Codesonic consensus messages:

- `.codesonic/agents/messages/2026-07-04-235259-codex-to-claude-second-opinion-testing-after-each-cleanup-extension-point-for-apps-and-packages.md`
- `.codesonic/agents/messages/2026-07-05-000034-claude-to-codex-second-opinion-testing-after-each-cleanup-extension-point-for-apps-and-packages.md`
- `.codesonic/agents/messages/2026-07-05-001905-claude-to-codex-second-opinion-full-cleanup-api-with-package-owned-automatic-registration.md`
- `.codesonic/agents/messages/2026-07-05-003017-claude-to-codex-second-opinion-full-cleanup-api-with-package-owned-automatic-registration.md`
- `.codesonic/agents/messages/2026-07-05-003236-codex-to-claude-second-opinion-full-cleanup-api-with-package-owned-automatic-registration.md`
- `.codesonic/agents/messages/2026-07-05-003409-claude-to-codex-second-opinion-full-cleanup-api-with-package-owned-automatic-registration.md`

## Current State

### Framework Cleanup

`src/testing/src/PHPUnit/AfterEachTestExtension.php` currently only registers the subscriber:

```php
class AfterEachTestExtension implements Extension
{
    /**
     * Bootstrap the extension.
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new AfterEachTestSubscriber);
    }
}
```

`src/testing/src/PHPUnit/AfterEachTestSubscriber.php` has a single large `notify()` method. It runs:

- `Mockery::close()`;
- Carbon, container, coroutine context, model, routing, view, support, testing, and other framework static cleanup;
- optional first-party package cleanup methods at the bottom through `callIfExists()`.

The optional first-party cleanup methods are intentionally grouped at the bottom:

```php
$this->flushFortifyState();
$this->flushHorizonState();
$this->flushInertiaState();
$this->flushNestedSetState();
$this->flushPasskeysState();
$this->flushPermissionState();
$this->flushReverbState();
$this->flushSanctumState();
$this->flushScoutState();
$this->flushSentryState();
$this->flushTelescopeState();
$this->flushTestbenchState();
$this->flushWayfinderState();
```

This works for framework-owned classes and first-party optional packages that the framework knows about. It does not give third-party packages or apps a clean, automatic place to register their own static cleanup.

### Package Manifest

`src/foundation/src/PackageManifest.php` currently mixes two responsibilities inside `build()`:

1. Scan `vendor/composer/installed.json`, read each package's `extra.hypervel` metadata, merge `dont-discover`, and append package `version`.
2. Write the resulting manifest to disk.

The scan details currently live only inside `build()`:

```php
$packages = [];

if ($this->files->exists($path = $this->vendorPath . '/composer/installed.json')) {
    $installed = json_decode($this->files->get($path), true);

    $packages = $installed['packages'] ?? $installed;
}

$ignore = $this->packagesToIgnore();

$manifest = (new Collection($packages))->mapWithKeys(function (array $package) {
    return [$this->format($package['name']) => [
        ...($package['extra']['hypervel'] ?? []),
        'version' => $package['version'] ?? null,
    ]];
})->each(function (array $configuration) use (&$ignore) {
    $ignore = array_merge($ignore, $configuration['dont-discover'] ?? []);
})->reject(function (array $configuration, string $package) use ($ignore) {
    return in_array($package, $ignore, true);
})->filter()->all();
```

The testing package needs the scan result at PHPUnit extension bootstrap, but it must not call `build()` because `build()` writes to `bootstrap/cache`. Package test repos and partial installs may not have that directory or may not want writes during extension bootstrap.

### Application Skeleton

`contrib/hypervel/hypervel/phpunit.xml` currently has:

```xml
bootstrap="vendor/autoload.php"
```

It has no `<extensions>` block and does not register `Hypervel\Testing\PHPUnit\AfterEachTestExtension`. Apps generated from the skeleton therefore run zero framework static-state cleanup between tests. Framework statics such as the container instance, coroutine context, Carbon test time, model caches, macros, and facade resolved instances can leak across an app test suite.

The components repo and the private package repos already register `AfterEachTestExtension` in `phpunit.xml.dist`. The missing artifact is the app skeleton.

### Documentation

`src/boost/docs/testing.md` currently tells users to manually flush `Collection` macros in `tearDown()`:

```php
protected function tearDown(): void
{
    Collection::flushMacros();

    parent::tearDown();
}
```

That advice is stale for framework classes. `Hypervel\Support\Collection::flushState()` already clears macros and proxies, and `AfterEachTestSubscriber` already calls `\Hypervel\Support\Collection::flushState()`. The docs should instead explain:

- framework cleanup is automatic when the testing extension is registered;
- app-owned or package-owned macroable/static state should be registered through the new cleanup API;
- app developers should not duplicate cleanup for framework classes already handled by Hypervel.

## Decisions

### Use A Registry For After-Each Cleanup Callbacks

Add `Hypervel\Testing\PHPUnit\AfterEachTestCleanup`.

The registry owns callbacks that run after every test. Registrations are process-local and persist for the PHPUnit worker lifetime. It is a test bootstrap API, not application runtime API.

Proposed shape:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use Closure;
use Throwable;

class AfterEachTestCleanup
{
    /**
     * The registered after-each cleanup callbacks.
     *
     * @var array<string, Closure(): void>
     */
    protected static array $callbacks = [];

    /**
     * Register a callback to flush test state after every test.
     *
     * Boot or tests only. The callback persists in static state for the
     * PHPUnit worker lifetime and runs after every subsequent test.
     */
    public static function flushUsing(string $name, callable $callback): void
    {
        static::$callbacks[$name] = Closure::fromCallable($callback);
    }

    /**
     * Run registered callbacks.
     *
     * @throws Throwable
     */
    public static function runCallbacks(): void
    {
        /** @var Throwable|null $exception */
        $exception = null;

        foreach (static::$callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Forget all registered callbacks.
     *
     * Boot or tests only. This removes process-local cleanup registrations
     * for the current PHPUnit worker, including callbacks discovered from
     * app and package metadata.
     */
    public static function forgetCallbacks(): void
    {
        static::$callbacks = [];
    }
}
```

Important details:

- The name is required. This makes registration idempotent by construction and avoids append-only growth if a registrar runs more than once.
- Package-owned names should be Composer package names such as `vendor/package`.
- App-owned names may use `app`.
- Last registration for a name wins.
- Unique root app callbacks register after package callbacks, so they run after package callbacks and before framework cleanup.
- `runCallbacks()` continues through all callbacks if one throws, then rethrows the first exception. This prevents one broken package cleanup callback from suppressing another package's cleanup.
- Do not add `AfterEachTestCleanup` to the framework subscriber's flush list. Calling `forgetCallbacks()` after every test would erase suite-level registrations and silently disable app/package cleanup after the first test. Add a short class-level comment explaining that this class intentionally does not expose `flushState()` and should not be included in `AfterEachTestSubscriber`.

### Run Custom Cleanup Before Framework Cleanup

Refactor `AfterEachTestSubscriber` so `notify()` delegates to a method that can be tested directly:

```php
/**
 * Clean up static state after a test finishes.
 */
public function notify(Finished $event): void
{
    $this->flushStateAfterTest();
}

/**
 * Flush all test state after a test finishes.
 */
public function flushStateAfterTest(): void
{
    try {
        AfterEachTestCleanup::runCallbacks();
    } finally {
        $this->flushFrameworkState();
    }
}

/**
 * Flush framework-owned static state.
 */
protected function flushFrameworkState(): void
{
    // Existing notify() body moves here.
}
```

Custom callbacks run before framework cleanup because app/package cleanup may need framework services, facades, the container, context, or other framework state while cleaning itself up. The framework cleanup then gets the last pass.

If a custom callback throws and framework cleanup also throws inside the `finally`, PHP will surface the framework cleanup exception. That double-fault behavior is acceptable because framework cleanup must still be attempted, and a framework cleanup failure is itself a suite-level failure that needs fixing.

Keep the optional first-party package cleanup methods together at the bottom of `flushFrameworkState()`, preserving the current layout.

### Discover Package And App Registrars At PHPUnit Extension Bootstrap

Do not register package cleanup from service providers.

Provider-based registration fails in normal Paratest scenarios:

- `Hypervel\Tests\TestCase` unit tests do not boot an application.
- A Paratest worker can receive only app-less unit test files.
- In that worker, package providers never run.
- Any provider-registered cleanup would be missing until an app-booting test happens to run in the same worker.

Package cleanup must be discovered and registered by `AfterEachTestExtension::bootstrap()` because the PHPUnit extension runs at worker bootstrap before the first test, independent of application boot.

Add a small discoverer class in the testing package, for example:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use Composer\InstalledVersions;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Support\Env;
use RuntimeException;

class TestStateRegistrars
{
    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * Create a test-state registrar discoverer.
     */
    public function __construct(
        protected string $basePath,
        protected string $vendorPath,
        ?Filesystem $files = null
    ) {
        $this->files = $files ?? new Filesystem;
    }

    /**
     * Create a discoverer for the current Composer root install.
     */
    public static function forRootInstall(): static
    {
        $basePath = static::installedRootPath();

        return new static(
            $basePath,
            Env::get('COMPOSER_VENDOR_DIR') ?: $basePath . '/vendor'
        );
    }

    /**
     * Register discovered test-state registrars.
     */
    public function register(): void
    {
        foreach ($this->registrars() as $source => $classes) {
            foreach ((array) $classes as $class) {
                $this->registerClass($source, $class);
            }
        }
    }

    /**
     * Get root test-state registrar classes.
     *
     * @return array<int, class-string>
     */
    protected function rootRegistrars(): array
    {
        $path = $this->basePath . '/composer.json';

        if (! $this->files->isFile($path)) {
            return [];
        }

        $composer = json_decode(
            $this->files->get($path),
            true
        );

        if (! is_array($composer)) {
            return [];
        }

        return (array) ($composer['extra']['hypervel']['test-state'] ?? []);
    }

    /**
     * Get registrar classes keyed by their source package.
     *
     * @return array<string, array<int, class-string>>
     */
    protected function registrars(): array
    {
        $registrars = [];
        $baseIgnore = PackageManifest::packagesToIgnoreFromComposer($this->files, $this->basePath);

        foreach (PackageManifest::discoverInstalledPackages($this->files, $this->vendorPath, $baseIgnore) as $package => $configuration) {
            if (isset($configuration['test-state'])) {
                $registrars[$package] = (array) $configuration['test-state'];
            }
        }

        $rootRegistrars = $this->rootRegistrars();

        if ($rootRegistrars !== []) {
            $registrars['root'] = $rootRegistrars;
        }

        return $registrars;
    }

    /**
     * Register one registrar class.
     */
    protected function registerClass(string $source, string $class): void
    {
        if (! class_exists($class)) {
            throw new RuntimeException(
                "Test-state registrar [{$class}] declared by [{$source}] does not exist. Check that it is in normal autoload, not dependency autoload-dev."
            );
        }

        if (! method_exists($class, 'register')) {
            throw new RuntimeException(
                "Test-state registrar [{$class}] declared by [{$source}] must define a register method."
            );
        }

        $class::register();
    }
}
```

The implementation should include the needed root path helper. The vendor path must be computed the same way as `Hypervel\Foundation\PackageManifest`, honoring `COMPOSER_VENDOR_DIR` before falling back to `$basePath . '/vendor'`; do not derive a separate vendor path by reflecting Composer internals.

```php
/**
 * Get the Composer root package path.
 */
protected static function installedRootPath(): string
{
    $rootPackage = InstalledVersions::getRootPackage();
    $basePath = $rootPackage['install_path'] ?? getcwd();

    if (! is_string($basePath) || $basePath === '') {
        $currentPath = getcwd();
        $basePath = $currentPath === false ? '.' : $currentPath;
    }

    $realPath = realpath($basePath);

    return $realPath === false ? $basePath : $realPath;
}
```

The implementation must satisfy static analysis. If a value has already been proven locally but phpstan cannot infer it, use clear local structure or a local `@var` assertion rather than defensive runtime clutter.

Register the discoverer from the extension:

```php
public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
{
    TestStateRegistrars::forRootInstall()->register();

    $facade->registerSubscriber(new AfterEachTestSubscriber);
}
```

Discovery order is stable:

1. Package registrars in the order returned by Composer's `installed.json` after `dont-discover` filtering.
2. Root app/package registrars from root `composer.json` last.

Callbacks registered by root app registrars therefore run after package callbacks when they use unique names. Duplicate callback names are last-wins; use package-scoped names to avoid accidental replacement.

### Use `extra.hypervel.test-state`

Packages declare registrar classes in their normal package `composer.json` metadata:

```json
{
    "autoload": {
        "psr-4": {
            "Vendor\\Package\\": "src/"
        }
    },
    "extra": {
        "hypervel": {
            "providers": [
                "Vendor\\Package\\PackageServiceProvider"
            ],
            "test-state": [
                "Vendor\\Package\\Testing\\TestState"
            ]
        }
    }
}
```

Package registrars must live in the package's normal source autoload, not `autoload-dev`, because consuming apps do not load dependency dev autoloaders.

A package registrar should aggregate that package's cleanup:

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Testing;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Vendor\Package\Support\InvoiceNumbers;
use Vendor\Package\Support\ReceiptMacros;
use Vendor\Package\Support\TaxRates;

class TestState
{
    /**
     * Register package test-state cleanup.
     */
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('vendor/package', fn () => static::flushState());
    }

    /**
     * Flush package static state.
     */
    public static function flushState(): void
    {
        InvoiceNumbers::flushState();
        TaxRates::flushState();
        ReceiptMacros::flushState();
    }
}
```

For monopackages with many internal sub-packages, use one registrar class and one Composer metadata entry. The registrar may internally group optional cleanup:

```php
public static function flushState(): void
{
    static::flushCoreState();
    static::flushAuthState();
    static::flushBillingState();
}

protected static function flushBillingState(): void
{
    static::callIfExists(BillingManager::class, 'flushState');
}
```

Optional checks belong inside the owning registrar. The discovery layer only validates the declared registrar class itself.

Root apps and root package repos declare their own registrars in root `composer.json`:

```json
"extra": {
    "hypervel": {
        "dont-discover": [],
        "test-state": [
            "Tests\\Support\\TestState"
        ]
    }
}
```

The root class may live under root `autoload-dev` because the root dev autoload is loaded during tests:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;

class TestState
{
    /**
     * Register application test-state cleanup.
     */
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('app', fn () => static::flushState());
    }

    /**
     * Flush application static state.
     */
    public static function flushState(): void
    {
        //
    }
}
```

### Declare Direct Testing Package Dependencies

Update `src/testing/composer.json` to require `hypervel/filesystem` directly.

The testing package already imports filesystem classes indirectly through existing cleanup calls, and the registrar discoverer will use `Hypervel\Filesystem\Filesystem` directly. `hypervel/testing` currently receives filesystem transitively through `hypervel/foundation`; the final package metadata should declare its direct imports instead of relying on transitive resolution.

The monorepo root `composer.json` already replaces `hypervel/filesystem` and `hypervel/testing`, so the split package composer file is the important dependency surface for this change.

### Fail Fast Only For Declared Broken Registrars

The discovery layer should distinguish missing metadata files from broken declared metadata:

- Missing root `composer.json` or missing `vendor/composer/installed.json`: degrade to framework cleanup only.
- Malformed root `composer.json` or malformed `vendor/composer/installed.json`: degrade to framework cleanup only without emitting PHP warnings from chained array access.
- A value named in `extra.hypervel.test-state` that is not a class name string: throw at PHPUnit bootstrap with the source package.
- A class named in `extra.hypervel.test-state` that does not exist: throw at PHPUnit bootstrap with the class and source package.
- A class named in `extra.hypervel.test-state` that lacks `register()`: throw at PHPUnit bootstrap with the class and source package.
- A non-static `register()` method may fail naturally when called statically. Reflection is not required unless the implementation chooses to improve the error message without adding clutter.

This keeps partial package repos and CI states resilient while making real package/app metadata bugs obvious.

### Extract No-Write Package Discovery From `PackageManifest`

Add a pure helper to `Hypervel\Foundation\PackageManifest`:

```php
/**
 * Discover installed Hypervel package metadata.
 *
 * @param array<int, string> $baseIgnore
 */
public static function discoverInstalledPackages(Filesystem $files, string $vendorPath, array $baseIgnore): array
{
    $packages = [];

    if ($files->exists($path = $vendorPath . '/composer/installed.json')) {
        $installed = json_decode($files->get($path), true);

        if (is_array($installed)) {
            $packages = $installed['packages'] ?? $installed;
        }
    }

    $ignore = $baseIgnore;

    return (new Collection($packages))->mapWithKeys(function (array $package) use ($vendorPath) {
        return [static::formatPackageName($package['name'], $vendorPath) => [
            ...($package['extra']['hypervel'] ?? []),
            'version' => $package['version'] ?? null,
        ]];
    })->each(function (array $configuration) use (&$ignore) {
        $ignore = array_merge($ignore, $configuration['dont-discover'] ?? []);
    })->reject(function (array $configuration, string $package) use ($ignore) {
        return in_array($package, $ignore, true);
    })->filter()->all();
}
```

Then make `build()` a write-through caller:

```php
/**
 * Build the manifest and write it to disk.
 */
public function build(): void
{
    $manifest = static::discoverInstalledPackages(
        $this->files,
        $this->vendorPath,
        $this->packagesToIgnore()
    );

    $this->write($manifest);

    $this->manifest = $manifest;
    $this->rawManifest = $manifest;
}
```

The helper must keep `build()` output byte-identical for existing fixtures:

- same `vendor/composer/installed.json` handling;
- same `$installed['packages'] ?? $installed`;
- same root `extra.hypervel.dont-discover`;
- same per-package `dont-discover` merge before filtering;
- same package name formatting;
- same `version` key appended to every package configuration;
- same inclusion of version-only packages unless they are ignored.
- same virtual `packagesToIgnore()` dispatch from `build()`.
- malformed JSON handled without PHP warnings.

The static helper must take the initial ignore list as an argument. Do not make it read root `composer.json` directly. `Hypervel\Testbench\Foundation\PackageManifest` overrides `packagesToIgnore()` to return `[]` during build so testbench can manage package ignores at read time. `build()` must keep calling `$this->packagesToIgnore()` and pass that result into the static helper, preserving testbench's override.

Add helper methods only where they remove real duplication:

```php
/**
 * Format the given package name.
 */
protected static function formatPackageName(string $package, string $vendorPath): string
{
    return str_replace($vendorPath . '/', '', $package);
}

/**
 * Get the package names ignored by root composer metadata.
 *
 * @return array<int, string>
 */
public static function packagesToIgnoreFromComposer(Filesystem $files, string $basePath): array
{
    if (! $files->isFile($basePath . '/composer.json')) {
        return [];
    }

    $composer = json_decode($files->get(
        $basePath . '/composer.json'
    ), true);

    if (! is_array($composer)) {
        return [];
    }

    return $composer['extra']['hypervel']['dont-discover'] ?? [];
}
```

Keep the existing `format()` instance method because `Hypervel\Testbench\Foundation\PackageManifest::providersFromRoot()` calls it. Update `format()` to delegate to `static::formatPackageName($package, $this->vendorPath)` if useful, but do not remove it.

Keep the existing `packagesToIgnore()` instance method as the virtual seam that `build()` calls. It may delegate to `static::packagesToIgnoreFromComposer($this->files, $this->basePath)`, but it must remain overridable so testbench's override keeps working.

### Update The Application Skeleton

Update `contrib/hypervel/hypervel/phpunit.xml` to register the after-each cleanup extension:

```xml
<extensions>
    <bootstrap class="Hypervel\Testing\PHPUnit\AfterEachTestExtension" />
</extensions>
```

Do not replace `bootstrap="vendor/autoload.php"` with a custom `tests/bootstrap.php`. Composer root metadata discovery means apps do not need another bootstrap file just to register cleanup.

Update `contrib/hypervel/hypervel/composer.json`:

```json
"extra": {
    "hypervel": {
        "dont-discover": [],
        "test-state": [
            "Tests\\Support\\TestState"
        ]
    }
}
```

Add `contrib/hypervel/hypervel/tests/Support/TestState.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;

class TestState
{
    /**
     * Register application test-state cleanup.
     */
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('app', fn () => static::flushState());
    }

    /**
     * Flush application static state.
     */
    public static function flushState(): void
    {
        //
    }
}
```

This class is intentionally empty in the skeleton. It gives app developers a wired place to add cleanup without exposing PHPUnit extension internals.

The app skeleton currently also does not enable `SlowTestExtension`. That is a separate skeleton policy decision, not part of state cleanup correctness. During implementation, surface that decision to the owner before making any slow-test skeleton change.

### Update Documentation

Update `src/boost/docs/testing.md`.

Add a "Test State Cleanup" section near "Running Tests in Coroutines" and before "Macro State":

~~~md
<a name="test-state-cleanup"></a>
### Test State Cleanup

Hypervel applications keep framework objects, static caches, macros, and manager state in memory for the life of the PHP process. During tests, Hypervel's PHPUnit extension flushes framework-owned state after every test method.

If your application has its own worker-lifetime state, add its cleanup to `tests/Support/TestState.php`. Use this class as one application-level entry point that aggregates the cleanup for any stateful classes your app owns:

```php
namespace Tests\Support;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;

class TestState
{
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('app', fn () => static::flushState());
    }

    public static function flushState(): void
    {
        InvoiceNumbers::flushState();
        TaxRates::flushState();
        ReceiptMacros::flushState();
    }
}
```

Callbacks registered by your app run after package cleanup callbacks and before Hypervel flushes framework state. This means framework services are still available while your callback runs, then Hypervel tears them down immediately after.

Do not call `AfterEachTestCleanup::forgetCallbacks()` from ordinary application tests. That method clears all registered callbacks for the current PHPUnit worker, including callbacks discovered from application and package metadata.
~~~

Document package authors in `src/boost/docs/packages.md` because `extra.hypervel.test-state` is package metadata. Place this as a `### Test State Cleanup` section near package discovery metadata and add it to the page's section list.

~~~md
<a name="test-state-cleanup"></a>
### Test State Cleanup

Packages that keep worker-lifetime state for tests may declare a test-state registrar:

```json
"extra": {
    "hypervel": {
        "test-state": [
            "Vendor\\Package\\Testing\\TestState"
        ]
    }
}
```

The registrar must be autoloadable from the package's normal `autoload` section and must define `register()`. Use the registrar as one package-level entry point that aggregates the cleanup for any stateful classes your package owns:

```php
namespace Vendor\Package\Testing;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;

class TestState
{
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('vendor/package', fn () => static::flushState());
    }

    public static function flushState(): void
    {
        InvoiceNumbers::flushState();
        TaxRates::flushState();
        ReceiptMacros::flushState();
    }
}
```

Use your Composer package name as the callback name. Registrar classes are discovered during PHPUnit extension bootstrap, so package cleanup runs even in workers that only execute unit tests and never boot a Hypervel application.
~~~

Replace the current `Collection::flushMacros()` example in "Macro State" with app-owned macroable guidance:

```md
Hypervel already flushes framework macroable classes such as `Collection`, `ResponseFactory`, `View\Factory`, and testing helpers after every test. Do not add teardown cleanup for framework classes already handled by Hypervel.

If your application or package defines its own macroable class and registers temporary macros inside a test, add that class to your test-state cleanup.
```

Also update the table of contents.

### Update AGENTS.md If Implementation Changes The Pattern

After implementation, update `contrib/hypervel/components/AGENTS.md` only where its current guidance becomes stale.

The current "Static State and Test Cleanup" section says:

- `AfterEachTestSubscriber` handles global cleanup.
- When porting source classes that use static properties, add `flushState()`.
- Check whether the subscriber should call it.

After the new API exists, keep the framework guidance but add package/app guidance:

- framework-owned classes still go in `AfterEachTestSubscriber`;
- first-party optional framework packages may remain in grouped optional methods at the bottom of the subscriber;
- third-party packages and app/private package repos should use `extra.hypervel.test-state` and `TestState` registrars;
- do not add `AfterEachTestCleanup` itself to the subscriber's flush list.

This prevents future contributors from hardcoding private/third-party cleanup into the framework subscriber.

## Implementation Steps

1. Read current `AfterEachTestExtension`, `AfterEachTestSubscriber`, `PackageManifest`, app skeleton `phpunit.xml`, app skeleton `composer.json`, `testing.md`, and `packages.md` before editing.
2. Add `src/testing/src/PHPUnit/AfterEachTestCleanup.php`.
3. Add tests for `AfterEachTestCleanup` under `tests/Testing/PHPUnit/AfterEachTestCleanupTest.php`.
4. Refactor `AfterEachTestSubscriber`:
   - `notify()` delegates to `flushStateAfterTest()`;
   - `flushStateAfterTest()` runs custom callbacks before framework cleanup;
   - existing framework cleanup body moves to `flushFrameworkState()`;
   - optional package methods stay grouped at the bottom.
5. Add tests for subscriber callback ordering and failure behavior under `tests/Testing/PHPUnit/AfterEachTestSubscriberTest.php`.
6. Add `hypervel/filesystem` as a direct dependency in `src/testing/composer.json`.
7. Extract `PackageManifest::discoverInstalledPackages()`.
8. Update `PackageManifest::build()` to call the helper through `$this->packagesToIgnore()` and keep cached output identical.
9. Add/adjust Foundation and Testbench package manifest tests for the pure helper, byte-identical build output, and preserved testbench ignore behavior.
10. Add `src/testing/src/PHPUnit/TestStateRegistrars.php` or equivalent discoverer.
11. Update `AfterEachTestExtension` to run registrar discovery before registering the subscriber.
12. Add tests for registrar discovery:
    - package registrars from `extra.hypervel.test-state`;
    - root registrars from root `composer.json`;
    - package registrars run before root registrars;
    - missing `composer.json` and missing `installed.json` degrade cleanly;
    - declared missing registrar class throws a clear exception;
    - declared registrar without `register()` throws a clear exception;
    - duplicate callback names are idempotent and last-wins.
13. Update app skeleton:
    - add `AfterEachTestExtension` to `phpunit.xml`;
    - add root `extra.hypervel.test-state`;
    - add `tests/Support/TestState.php`.
14. Surface the `SlowTestExtension` skeleton decision to the owner before making any slow-test skeleton change.
15. Update `src/boost/docs/testing.md`.
16. Update `src/boost/docs/packages.md`.
17. Update `AGENTS.md` cleanup guidance and correct the stale `tests/AfterEachTestSubscriber.php` path to `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`.
18. Run focused tests after each changed test file.
19. Run the app skeleton test command from `contrib/hypervel/hypervel` after changing the skeleton.
20. Run `composer fix` from `contrib/hypervel/components`.
21. Do a full self-review of all changes, tracing discovery, registration, callback execution, manifest scanning, skeleton config, and docs.
22. Request code review from Claude and loop until signoff.

## Testing Plan

### AfterEachTestCleanup

Create `tests/Testing/PHPUnit/AfterEachTestCleanupTest.php`.

The test class should call `AfterEachTestCleanup::forgetCallbacks()` in `tearDown()` because the registry intentionally persists for the PHPUnit worker lifetime.

Assertions:

- `flushUsing()` registers callbacks by name.
- `runCallbacks()` executes callbacks in registration order.
- callbacks persist across multiple `runCallbacks()` calls.
- registering the same name replaces the callback.
- unique root/app callback registered after package callback runs after package callback.
- `forgetCallbacks()` clears all callbacks.
- if one callback throws, later callbacks still run and the first exception is rethrown.

Run immediately after creating the file:

```shell
cd contrib/hypervel/components
./vendor/bin/phpunit --no-progress tests/Testing/PHPUnit/AfterEachTestCleanupTest.php
```

### AfterEachTestSubscriber

Create `tests/Testing/PHPUnit/AfterEachTestSubscriberTest.php`.

The test class should call `AfterEachTestCleanup::forgetCallbacks()` in `tearDown()` and reset any framework state it mutates if the assertion fails before the subscriber cleanup runs.

Assertions:

- `flushStateAfterTest()` runs custom callbacks before framework cleanup.
- framework cleanup still runs when a custom callback throws.
- the thrown callback exception still surfaces after cleanup.

Do not call the real `flushFrameworkState()` from this test. It clears the container instance, coroutine context, facade resolved instances, and other state that the test process itself is using. Instead, test ordering and `finally` behavior with an anonymous subclass:

```php
$subscriber = new class extends AfterEachTestSubscriber {
    /**
     * The observed cleanup order.
     *
     * @var array<int, string>
     */
    public array $order = [];

    /**
     * Flush framework-owned static state.
     */
    protected function flushFrameworkState(): void
    {
        $this->order[] = 'framework';
    }
};

AfterEachTestCleanup::flushUsing('probe', function () use ($subscriber): void {
    $subscriber->order[] = 'custom';
});

$subscriber->flushStateAfterTest();

$this->assertSame(['custom', 'framework'], $subscriber->order);
```

Do not construct PHPUnit's `Finished` event; test the direct method. The real `flushFrameworkState()` body is exercised by the global subscriber during the suite.

Run immediately:

```shell
cd contrib/hypervel/components
./vendor/bin/phpunit --no-progress tests/Testing/PHPUnit/AfterEachTestSubscriberTest.php
```

### PackageManifest

Extend `tests/Foundation/FoundationPackageManifestTest.php`.

Assertions:

- `PackageManifest::discoverInstalledPackages(new Filesystem, $vendorPath, $baseIgnore)` returns the same manifest array that `build()` writes for the existing fixture when `$baseIgnore` comes from root composer metadata.
- the helper handles Composer 2 object format using the current fixture.
- add a small bare-array `installed.json` fixture or temporary file if no existing test covers legacy bare-array format.
- root `dont-discover` and package `dont-discover` still filter the same packages.
- version keys remain present.
- version-only packages remain present unless filtered, matching current behavior.
- missing `installed.json` returns `[]` and does not write.
- malformed `installed.json` returns `[]` without PHP warnings.
- malformed root `composer.json` yields an empty base ignore list without PHP warnings.
- `Hypervel\Testbench\Foundation\PackageManifest` still bypasses root `dont-discover` during build through its `packagesToIgnore()` override.
- `Hypervel\Testbench\Foundation\PackageManifest::providersFromRoot()` still works because `format()` remains available.

Run immediately:

```shell
cd contrib/hypervel/components
./vendor/bin/phpunit --no-progress tests/Foundation/FoundationPackageManifestTest.php
```

### TestStateRegistrars

Create `tests/Testing/PHPUnit/TestStateRegistrarsTest.php`.

The test class should call `AfterEachTestCleanup::forgetCallbacks()` in `tearDown()` because registrar tests intentionally mutate the cleanup registry.

Use temporary Composer roots under `ParallelTesting::tempDir()` rather than writing to committed fixtures, unless a small static fixture is clearer and read-only. Construct `TestStateRegistrars` directly with the temp root and temp vendor path; do not rely on `InstalledVersions::getRootPackage()` in unit tests.

The test root should contain:

- root `composer.json`;
- `vendor/composer/installed.json`;
- fixture registrar classes autoloadable from the test suite.

Assertions:

- package registrar classes listed in package `extra.hypervel.test-state` have `register()` called.
- root `extra.hypervel.test-state` registrar is called after package registrars.
- package `dont-discover` suppresses package test-state discovery consistently with provider discovery.
- root `dont-discover` suppresses package test-state discovery but not root test-state.
- missing `composer.json` does not throw.
- missing `vendor/composer/installed.json` does not throw.
- malformed root `composer.json` and malformed `vendor/composer/installed.json` do not emit PHP warnings.
- missing declared registrar class throws a `RuntimeException` naming the class and source package.
- declared registrar class without `register()` throws a `RuntimeException` naming the class and source package.
- `forRootInstall()` honors `COMPOSER_VENDOR_DIR`.

Run immediately:

```shell
cd contrib/hypervel/components
./vendor/bin/phpunit --no-progress tests/Testing/PHPUnit/TestStateRegistrarsTest.php
```

### Application Skeleton

The app skeleton is a sibling repository at `contrib/hypervel/hypervel`, not the committed testbench skeleton under `src/testbench/hypervel`. Do not add a components test that depends on a sibling checkout being present in upstream CI.

Verify the skeleton directly after updating it:

```shell
cd contrib/hypervel/hypervel
composer test
```

Also inspect these files during self-review:

- `phpunit.xml` contains `Hypervel\Testing\PHPUnit\AfterEachTestExtension`;
- root `composer.json` lists `Tests\\Support\\TestState` under `extra.hypervel.test-state`;
- `tests/Support/TestState.php` exists and registers the `app` callback.

### Documentation And Static Analysis

After code and docs are updated:

```shell
cd contrib/hypervel/components
composer fix
```

`composer fix` runs php-cs-fixer, phpstan, and the parallel test suite in the configured order.

## Self-Review Checklist

Before requesting code review:

- Re-read `AfterEachTestCleanup` and confirm it does not have `flushState()` and is not called by the subscriber's framework cleanup list.
- Trace `AfterEachTestExtension::bootstrap()` and confirm registrar discovery happens before the subscriber is registered.
- Trace `TestStateRegistrars` with:
  - normal app root;
  - package repo root;
  - missing root `composer.json`;
  - missing `vendor/composer/installed.json`;
  - bad declared registrar class;
  - class with missing `register()`;
  - duplicate callback names.
- Trace `PackageManifest::build()` and verify the cache array is unchanged for existing fixtures.
- Confirm package discovery and provider/alias discovery still use one owner for installed package scan semantics.
- Confirm root `test-state` is read separately from installed package metadata.
- Confirm `dont-discover` affects package registrars consistently with providers and aliases.
- Confirm root `test-state` still registers even when root `dont-discover` disables all package discovery.
- Confirm custom callbacks run before framework cleanup and framework cleanup still runs when a custom callback throws.
- Confirm docs do not tell users to manually flush framework macro state in `tearDown()`.
- Confirm app skeleton tests use the generated `Tests\Support\TestState` class and no custom `tests/bootstrap.php`.
- Confirm no stale comments, workaround language, old API names such as `test-cleanup`, or dead helpers remain.
