# Testbench Package-Mode Dogfood And Serve Options

## Goal

Finish the follow-up work exposed by the Testbench package-mode and parallel testing changes:

- Make root package provider discovery deterministic in package-test workers.
- Add real `serve --host` and `serve --port` options at the Hypervel server command layer.
- Add root Composer binary metadata for packages replaced by `hypervel/components`.
- Add a small real package fixture that dogfoods `vendor/bin/testbench package:test --parallel` from outside the components root.
- Remove the Testbench todo item once the deterministic discovery contract is covered by tests.

The end state should read as if Hypervel was designed this way from the start. Do not keep stale todo entries, stale docs, dead branches, or duplicated test-only behavior.

## Research

### Package Manifest Discovery

`Hypervel\Testbench\Foundation\PackageManifest` currently includes root Composer metadata only when the current process is the Testbench CLI:

```php
use function Hypervel\Testbench\is_testbench_cli;
use function Hypervel\Testbench\package_path;

protected function providersFromTestbench(): ?array
{
    if (is_testbench_cli() && is_file($composerFile = package_path('composer.json'))) {
        return $this->files->json($composerFile);
    }

    return null;
}
```

`is_testbench_cli()` is tied to the `TESTBENCH_CORE` constant. That is correct for remote Testbench CLI children, but it is not enough for PHPUnit / ParaTest workers launched through `package:test`.

In Hypervel, Testbench copies the skeleton to a per-worker runtime directory. A PHPUnit worker running package tests has `TESTBENCH_PACKAGE_TESTER`, but it does not define `TESTBENCH_CORE`. If the first manifest build happens inside that worker, the runtime clone can build a manifest without the package root metadata. Later remote CLI children can rebuild with root metadata because those children are the Testbench CLI. That makes discovery depend on process order.

The package-tester signal is already part of Testbench's own runtime contract:

- `Bootstrapper::createRuntimeCopy()` uses `Env::has('TESTBENCH_PACKAGE_TESTER')` to copy the package environment file into the runtime skeleton.
- `CreatesApplication` and `Foundation\Application` already use the same signal to distinguish package-test runtime behavior.
- `package_path()` resolves through `TESTBENCH_WORKING_PATH`, so root Composer metadata can be read from the package under test rather than the runtime clone.

`tests/Testbench/Foundation/PackageManifestTest.php` already covers root metadata merging and `dont-discover` filtering, but its anonymous test harness overrides `providersFromTestbench()`. New coverage must hit the real gate.

### Serve Command

`Hypervel\Server\Commands\ServerStartCommand` extends Symfony Command directly. It does this because the Swoole server owns the event loop:

```php
/**
 * Extends Symfony Command directly — NOT Hypervel\Console\Command — because the
 * Swoole server must own the event loop. Hypervel\Console\Command brings coroutine
 * wrapping and signal traits that start the event loop before Server::start().
 */
#[AsCommand(name: 'serve', description: 'Start Hypervel servers.')]
class ServerStartCommand extends SymfonyCommand
{
    public function __construct(protected Container $container)
    {
        parent::__construct('serve');
        $this->setDescription('Start Hypervel servers.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->startServer();
    }

    protected function startServer(): int
    {
        $serverConfig = $this->container->make('config')->array('server', []);
        // ...
        $serverFactory->configure($serverConfig);
        $serverFactory->start();
    }
}
```

Testbench's `serve` command extends the base server command:

```php
class ServeCommand extends ServerStartCommand
{
    // package-specific bootstrapping, then startServer()
}
```

So `--host` and `--port` belong in `ServerStartCommand`, not in Testbench. Testbench should inherit the same options as applications.

The default server config stores servers under `server.servers`, with the HTTP server marked by `Hypervel\Server\ServerInterface::SERVER_HTTP`:

```php
[
    'name' => 'http',
    'type' => ServerInterface::SERVER_HTTP,
    'host' => env('HTTP_SERVER_HOST', '0.0.0.0'),
    'port' => (int) env('HTTP_SERVER_PORT', 9501),
]
```

Reverb already mutates `server.servers` before server start so the Swoole server sees all listeners:

```php
// Register-time config mutation: it must run before ServerStartCommand reads
// `server.servers` and before workers or coroutines exist.
$config->set('server.servers', $servers);
```

`serve --host` and `serve --port` should follow the same boot-time-only pattern: mutate config before `ServerFactory::configure()` and before workers start. This does not create runtime config mutation inside a worker.

`tests/Testbench/CommanderServeTest.php` currently starts the package server by exporting `HTTP_SERVER_HOST` and `HTTP_SERVER_PORT`:

```php
$process = remote('serve --no-ansi', [
    'APP_DEBUG' => 'true',
    'APP_ENV' => 'workbench',
    'HTTP_SERVER_HOST' => '127.0.0.1',
    'HTTP_SERVER_PORT' => (string) $serverPort,
]);
```

That test should use the command options after they exist.

### Composer Binary Metadata

The root `composer.json` replaces both packages that publish binaries:

```json
"replace": {
    "hypervel/facade-documenter": "self.version",
    "hypervel/testbench": "self.version"
}
```

Their package Composer files declare binaries:

```json
// src/facade-documenter/composer.json
"bin": [
    "facade.php"
]

// src/testbench/composer.json
"bin": [
    "bin/testbench"
]
```

The root package does not currently declare those binaries. A real package that resolves `hypervel/testbench` through the root `hypervel/components` path repository will not receive `vendor/bin/testbench` from the root package unless root metadata exposes it.

Root Composer metadata should include both binaries:

```json
"bin": [
    "src/facade-documenter/facade.php",
    "src/testbench/bin/testbench"
]
```

### Dogfood Package

The components test suite now has:

- Raw framework tests through `composer test` / `composer test:parallel`.
- A scoped Testbench package-mode suite through `composer test:testbench`.

Those prove the framework's own tests, but they do not prove the real third-party package shape:

- A package outside the components root.
- Its own `composer.json`.
- A path repository that resolves Hypervel packages from the components checkout.
- `vendor/bin/testbench` installed by Composer.
- Package provider discovery from the package root.
- Workbench provider/config loading.
- Remote Testbench CLI children staying pointed at the package under test.
- Parallel package testing from the package's own working directory.

The fixture must not live under `tests/` because `phpunit.xml.dist` discovers `./tests` recursively, and `composer test:testbench` passes `tests/Testbench` as a CLI path. A fixture package under that tree would be discovered by the components suite. It also must not live under `src/testbench/` because `src/testbench` is the subtree-split package source.

Use a top-level fixture directory:

```text
dogfood/testbench-package/
```

### Clone Dirty Check Diagnostic

A clone dirty-check diagnostic was discussed as a possible hygiene tool. It would snapshot each Testbench runtime clone between tests and fail on unrestored writes.

Decision: keep this as a separate explicit owner decision. It is useful as a future opt-in diagnostic, but it is not needed to finish the signed-off package-mode contract. If it is added later, it should be opt-in, live inside Testbench test infrastructure, and have a very small allowlist. If the allowlist grows, the diagnostic is carrying too much policy.

## Implementation Plan

### 1. Make Package Manifest Discovery Deterministic

Update `src/testbench/src/Foundation/PackageManifest.php`.

Add `Env` usage and include package-tester mode in `providersFromTestbench()`:

```php
use Hypervel\Testbench\Foundation\Env;

protected function providersFromTestbench(): ?array
{
    if ((is_testbench_cli() || Env::has('TESTBENCH_PACKAGE_TESTER'))
        && is_file($composerFile = package_path('composer.json'))) {
        return $this->files->json($composerFile);
    }

    return null;
}
```

Add a concise source comment because this intentionally differs from Orchestra's persistent-skeleton model:

```php
// PHPUnit workers are not Testbench CLI processes, but package:test workers
// still need the package root metadata when they build a fresh clone manifest.
```

Do not change `getManifest()` filtering. `dont-discover` must remain read-time filtering so the manifest can contain the root package while each Testbench instance decides which packages to expose.

Keep `tests/Testbench/Foundation/PackageManifestTest.php`'s anonymous manifest harness tests intact. Its existing specific-ignore coverage already proves read-time filtering for root metadata by writing the root package into the cached manifest while `hasPackage()`, `providers()`, and `aliases()` hide it. Tighten that test only if the implementation changes make the assertion unclear.

Add a subprocess-backed test file for the real env-gate behavior:

```text
tests/Testbench/Foundation/PackageManifestPackageTesterTest.php
```

Do not test this gate in-process. `package_path()` checks the `TESTBENCH_WORKING_PATH` constant before it checks the env repository, and that constant is process-wide. Under `composer test:testbench`, `Hypervel\Testbench\Features\ParallelRunner` defines `TESTBENCH_WORKING_PATH` before tests execute. Under the raw full suite, any earlier Testbench bootstrap in the same worker can define it. `Once::flushState()` cannot fix a defined constant.

Test the gate through a fresh PHP subprocess for each scenario. The existing fixture already contains the needed package root shape:

```text
tests/Testbench/Foundation/Fixtures/PackageManifest/
├── composer.json
└── vendor/composer/installed.json
```

Add a small fixture script:

```text
tests/Testbench/Foundation/Fixtures/PackageManifest/build-manifest.php
```

The script should load the components autoloader, optionally define `TESTBENCH_CORE`, construct the real `Hypervel\Testbench\Foundation\PackageManifest` against the fixture base path, and build to a manifest path passed in argv:

```php
<?php

declare(strict_types=1);

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\PackageManifest;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$basePath = __DIR__;
$manifestPath = $argv[1] ?? null;

if (($argv[2] ?? null) === '--testbench-core' && ! defined('TESTBENCH_CORE')) {
    define('TESTBENCH_CORE', true);
}

if (! is_string($manifestPath) || $manifestPath === '') {
    throw new InvalidArgumentException('A manifest path is required.');
}

(new PackageManifest(new Filesystem, $basePath, $manifestPath))->build();
```

The new test file should:

- Extend `Hypervel\Tests\TestCase`.
- Create a manifest file under `ParallelTesting::tempDir('PackageManifestPackageTesterTest')`.
- Run `build-manifest.php` with Symfony Process.
- Pass `TESTBENCH_WORKING_PATH` as an env var pointing at the fixture path.
- Pass `TESTBENCH_PACKAGE_TESTER=(true)` only for package-tester scenarios.
- Pass `TESTBENCH_PACKAGE_TESTER => false` for the negative and Testbench CLI scenarios. Symfony Process inherits unmentioned parent environment variables, and this test runs under `composer test:testbench` where package-test workers already carry `TESTBENCH_PACKAGE_TESTER=(true)`.
- Run each scenario in a fresh subprocess so `package_path()`, `once()`, and process constants behave exactly as they do in a real child process.
- Write the package manifest to `ParallelTesting::tempDir('PackageManifestPackageTesterTest') . '/packages.php'`, then delete the temp directory in `tearDown()`.

Test cases:

```php
#[Test]
public function itAddsRootMetadataWhenRunningInsidePackageTester(): void
{
    // TESTBENCH_PACKAGE_TESTER=true and TESTBENCH_WORKING_PATH points at
    // tests/Testbench/Foundation/Fixtures/PackageManifest.
    // Build using the real PackageManifest method.
    // Assert testbench/example exists in the written manifest.
}

#[Test]
public function itDoesNotAddRootMetadataOutsideCliAndPackageTesterMode(): void
{
    // TESTBENCH_WORKING_PATH is set, but there is no TESTBENCH_PACKAGE_TESTER
    // and the script does not define TESTBENCH_CORE. Pass
    // TESTBENCH_PACKAGE_TESTER => false to strip any inherited package-test env.
    // Build using the real PackageManifest method.
    // Assert testbench/example does not exist in the written manifest.
}

#[Test]
public function itAddsRootMetadataWhenRunningInsideTheTestbenchCli(): void
{
    // TESTBENCH_WORKING_PATH points at the fixture and the script defines
    // TESTBENCH_CORE. Pass TESTBENCH_PACKAGE_TESTER => false so this proves
    // the CLI gate rather than the package-tester gate.
    // Build using the real PackageManifest method.
    // Assert testbench/example exists in the written manifest.
}
```

In `tests/Testbench/Foundation/PackageManifestTest.php`, retain read-time filtering coverage through the existing anonymous manifest harness:

```php
#[Test]
public function itStillFiltersRootMetadataAtReadTime(): void
{
    // Root metadata is written, but ignorePackageDiscoveriesFrom() hides it
    // from providers(), aliases(), and hasPackage().
}
```

Use PHPUnit attributes, matching the Testbench package tests.

Delete the Testbench package manifest item from `docs/todo.md` after coverage lands.

### 2. Add Server Host And Port Options

Update `src/server/src/Commands/ServerStartCommand.php`.

Add Symfony options:

```php
use Hypervel\Server\ServerInterface;
use Symfony\Component\Console\Input\InputOption;

protected function configure(): void
{
    $this
        ->addOption('host', null, InputOption::VALUE_REQUIRED, 'The host address to serve the application on')
        ->addOption('port', null, InputOption::VALUE_REQUIRED, 'The port to serve the application on');
}
```

Pass the input into `startServer()` because the command extends Symfony directly:

```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    return $this->startServer($input);
}

protected function startServer(InputInterface $input): int
{
    // ...
}
```

Update Testbench's `ServeCommand` override to call `startServer($input)`.

Before `ServerFactory::configure()`, apply CLI overrides to the first configured HTTP server:

```php
$config = $this->container->make('config');
$serverConfig = $config->array('server', []);

$host = $input->getOption('host');
$port = $input->getOption('port');

if ($host !== null || $port !== null) {
    if ($port !== null && filter_var($port, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('The serve port must be an integer.');
    }

    $servers = $serverConfig['servers'] ?? [];
    $httpServerIndex = null;

    foreach ($servers as $index => $server) {
        if (($server['type'] ?? null) === ServerInterface::SERVER_HTTP) {
            $httpServerIndex = $index;
            break;
        }
    }

    if ($httpServerIndex === null) {
        throw new InvalidArgumentException('Cannot override server host or port because no HTTP server is configured.');
    }

    if ($host !== null) {
        $servers[$httpServerIndex]['host'] = (string) $host;
    }

    if ($port !== null) {
        $servers[$httpServerIndex]['port'] = (int) $port;
    }

    $serverConfig['servers'] = $servers;

    // Command options are applied before workers start so ServerFactory and
    // later config readers agree on the bound HTTP address.
    $config->set('server.servers', $servers);
}
```

Use the repository's typed config getter for the initial server config, and mutate config only before server start. Do not mutate config from worker/runtime code.

Update `tests/Server/ServerStartCommandTest.php`:

- Keep existing coverage for fail-fast console mode and Symfony boundary.
- Update the start test to include a realistic `servers` entry with `type`, `host`, and `port`.
- Add `set('server.servers', ...)` expectations only in option tests.
- Add tests for:
  - No options: existing config is passed to `ServerFactory::configure()` unchanged.
  - `--host` and `--port`: first HTTP server is overridden and written back to config.
  - Non-integer `--port` fails with a clear exception instead of silently casting to `0`.
  - Non-HTTP listeners stay unchanged.
  - Options with no HTTP server throw the clear exception.

Update `tests/Testbench/CommanderServeTest.php`:

```php
$process = remote("serve --host=127.0.0.1 --port={$serverPort} --no-ansi", [
    'APP_DEBUG' => 'true',
    'APP_ENV' => 'workbench',
]);
```

Do not add host/port options to `watch`. `watch` already has the `watcher.command` config channel for controlling the command it spawns.

### 3. Add Root Binary Metadata

Update root `composer.json`:

```json
"bin": [
    "src/facade-documenter/facade.php",
    "src/testbench/bin/testbench"
],
```

Place the `bin` key in normal Composer metadata order near `autoload` / `autoload-dev`, consistent with the file's existing structure.

Do not add a separate facade-documenter test. Composer's binary behavior for Testbench is proved by the dogfood fixture. The facade-documenter entry is the same metadata shape and does not need a fake package just for that binary.

### 4. Add A Real Dogfood Package

Create this directory:

```text
dogfood/testbench-package/
```

Add a package `composer.json`:

```json
{
    "name": "hypervel/dogfood-testbench-package",
    "type": "library",
    "description": "Dogfood package for Hypervel Testbench package-mode tests.",
    "license": "MIT",
    "autoload": {
        "psr-4": {
            "Hypervel\\Dogfood\\TestbenchPackage\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Hypervel\\Dogfood\\TestbenchPackage\\Tests\\": "tests/",
            "Workbench\\App\\": "workbench/app/"
        }
    },
    "repositories": [
        {
            "type": "path",
            "url": "../..",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "php": "^8.4",
        "hypervel/console": "0.4.x-dev",
        "hypervel/contracts": "0.4.x-dev",
        "hypervel/support": "0.4.x-dev"
    },
    "require-dev": {
        "brianium/paratest": "^7.19",
        "fakerphp/faker": "^1.24",
        "hypervel/components": "0.4.x-dev",
        "hypervel/testbench": "0.4.x-dev",
        "mockery/mockery": "1.6.x-dev",
        "phpunit/phpunit": "^13.0.3",
        "symfony/yaml": "^8.0.12"
    },
    "extra": {
        "hypervel": {
            "providers": [
                "Hypervel\\Dogfood\\TestbenchPackage\\DogfoodServiceProvider"
            ]
        }
    },
    "scripts": {
        "test": "testbench package:test --parallel"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

Use a single path repository pointing at the components root. The dogfood fixture requires `hypervel/components` in dev so Composer selects the path package; once selected, the root package's `replace` map satisfies split component dependencies and the new root `bin` metadata gives the fixture `vendor/bin/testbench`.

Declare PHPUnit, ParaTest, Faker, Mockery, and Symfony YAML directly in the fixture's `require-dev`. `hypervel/testbench` is satisfied by the selected root `hypervel/components` package through `replace`, so Testbench's split-package dependency list does not enter dependency resolution. A real package author also needs test runner dependencies in this topology, and the fixture should prove that shape honestly.

Move the committed Testbench Workbench namespaces to dev autoload in both the components root `composer.json` and `src/testbench/composer.json`.

In the components root package:

```json
"autoload-dev": {
    "psr-4": {
        "Workbench\\App\\": "src/testbench/workbench/app/",
        "Workbench\\Database\\Factories\\": "src/testbench/workbench/database/factories/",
        "Workbench\\Database\\Seeders\\": "src/testbench/workbench/database/seeders/"
    }
}
```

In the split Testbench package:

```json
"autoload-dev": {
    "psr-4": {
        "Workbench\\App\\": "workbench/app/",
        "Workbench\\Database\\Factories\\": "workbench/database/factories/",
        "Workbench\\Database\\Seeders\\": "workbench/database/seeders/"
    }
}
```

The Workbench shipped with Testbench is for framework and package development. Consumers own their `Workbench\*` namespaces, and `InstallCommand` already writes consumer Workbench mappings into the package's autoload-dev section. Keeping the framework Workbench in production autoload would leak Hypervel's committed Workbench classes into every package install and collide with real package Workbench providers.

Do not commit `dogfood/testbench-package/composer.lock`, `dogfood/testbench-package/vendor/`, or the fixture PHPUnit cache directory.

Add `.gitignore` entries:

```gitignore
/dogfood/testbench-package/.phpunit.cache/
/dogfood/testbench-package/composer.lock
/dogfood/testbench-package/vendor/
```

Update `.php-cs-fixer.php` so code style runs never traverse third-party fixture dependencies:

```php
->exclude('dogfood/testbench-package/vendor')
```

Add `phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    bootstrap="vendor/autoload.php"
    cacheDirectory=".phpunit.cache"
    colors="true"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/13.0/phpunit.xsd"
>
    <testsuites>
        <testsuite name="Dogfood Testbench Package">
            <directory>tests</directory>
        </testsuite>
    </testsuites>

    <extensions>
        <bootstrap class="Hypervel\Testing\PHPUnit\AfterEachTestExtension" />
    </extensions>
</phpunit>
```

Add package source:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\TestbenchPackage;

use Hypervel\Support\ServiceProvider;

class DogfoodServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->make('config')->set('dogfood.package_provider_loaded', true);
        $this->commands([DogfoodProbeCommand::class]);
    }
}
```

Add a command used by remote CLI tests:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\TestbenchPackage;

use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'dogfood:probe')]
class DogfoodProbeCommand extends Command
{
    protected string $description = 'Probe the dogfood package runtime.';

    /**
     * Execute the console command.
     */
    public function handle(ConfigRepository $config): int
    {
        $this->line($config->boolean('dogfood.package_provider_loaded', false) ? 'package-provider' : 'missing-package-provider');
        $this->line($config->boolean('dogfood.workbench_provider_loaded', false) ? 'workbench-provider' : 'missing-workbench-provider');

        return self::SUCCESS;
    }
}
```

Add `testbench.yaml`:

```yaml
providers:
  - Workbench\App\Providers\WorkbenchServiceProvider

workbench:
  install: true
  discovers:
    config: true
```

Add a workbench provider:

```php
<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Hypervel\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->make('config')->set('dogfood.workbench_provider_loaded', true);
    }
}
```

Add a workbench config file to prove config discovery:

```text
dogfood/testbench-package/workbench/config/dogfood.php
```

```php
<?php

declare(strict_types=1);

return [
    'workbench_config_loaded' => true,
];
```

Add tests under the dogfood package's own `tests/` directory:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\TestbenchPackage\Tests;

use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\remote;

class PackageRuntimeTest extends TestCase
{
    use WithWorkbench;

    #[Test]
    public function itDiscoversTheRootPackageProvider(): void
    {
        $this->assertTrue($this->app->make('config')->boolean('dogfood.package_provider_loaded'));
    }

    #[Test]
    public function itLoadsWorkbenchProviderAndConfig(): void
    {
        $config = $this->app->make('config');

        $this->assertTrue($config->boolean('dogfood.workbench_provider_loaded'));
        $this->assertTrue($config->boolean('dogfood.workbench_config_loaded'));
    }

    #[Test]
    public function itRunsRemoteCommandsInsideThePackageRuntime(): void
    {
        $process = remote('dogfood:probe --no-ansi')->mustRun();

        $this->assertStringContainsString('package-provider', $process->getOutput());
        $this->assertStringContainsString('workbench-provider', $process->getOutput());
    }

    #[Test]
    public function itRunsInsideParallelPackageMode(): void
    {
        $this->assertNotSame('', (string) ($_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? ''));
        $this->assertSame('(true)', (string) ($_SERVER['TESTBENCH_PACKAGE_TESTER'] ?? $_ENV['TESTBENCH_PACKAGE_TESTER'] ?? ''));
    }
}
```

Add a root Composer script:

```json
"test:dogfood": [
    "Composer\\Config::disableProcessTimeout",
    "composer --working-dir=dogfood/testbench-package install --prefer-dist -n -o",
    "composer --working-dir=dogfood/testbench-package test"
]
```

Wire it into root scripts:

```json
"check": [
    "@test",
    "@test:testbench",
    "@test:dogfood",
    "@analyse",
    "@lint"
],
"fix": [
    "@lint:fix",
    "@analyse",
    "@test:parallel",
    "@test:testbench",
    "@test:dogfood"
]
```

Add a CI step after the scoped Testbench package-mode suite:

```yaml
- name: Run Testbench dogfood package suite
  run: composer test:dogfood
```

This makes CI prove both the components-owned Testbench suite and real package usage.

Do not add the dogfood fixture to `phpstan.neon.dist`. Root phpstan currently analyses source paths, not tests. The dogfood package is test infrastructure, and its own coverage comes from running its package test suite through `composer test:dogfood`.

### 5. Update Documentation

Update `src/testbench/README.md` under "Differences From Orchestra Testbench" with the package-tester manifest gate difference:

```md
Hypervel includes root package discovery metadata when a `package:test` worker builds the Testbench package manifest. Orchestra's persistent skeleton can be seeded by the parent Testbench CLI process, while Hypervel's per-worker runtime skeletons may build their manifests directly inside PHPUnit / ParaTest workers.
```

Update `src/boost/docs/testbench.md` in the same style as the existing doc:

- In "Serving the Workbench Application", mention `--host` and `--port`:

```shell
vendor/bin/testbench serve --host=127.0.0.1 --port=9502
```

- Keep the wording short and practical:

```md
You may pass `--host` and `--port` to temporarily override the configured HTTP server address for the current process.
```

- In "Running Package Tests", keep the existing statement that package tests use the same parallel database, cache, and Redis isolation behavior as application tests. No new split-run explanation is needed for package authors.

Update server docs:

- `src/boost/docs/installation.md`: after `php artisan serve`, mention `php artisan serve --host=127.0.0.1 --port=9502` as a temporary override, while `config/server.php` remains the main configuration source.
- `src/boost/docs/deployment.md`: mention the flags in the "Running the Hypervel Server" section without implying they are preferred over production config.
- `src/boost/docs/lifecycle.md`: mention that `serve` reads `config/server.php` and command flags are applied before Swoole workers start.

Remove the package-test discovery todo from `docs/todo.md` after the implementation and tests prove the contract.

## Testing Plan

Run tests incrementally after each logical test file or group:

1. Package manifest:

```shell
./vendor/bin/phpunit --no-progress tests/Testbench/Foundation/PackageManifestTest.php
./vendor/bin/phpunit --no-progress tests/Testbench/Foundation/PackageManifestPackageTesterTest.php
```

2. Server command:

```shell
./vendor/bin/phpunit --no-progress tests/Server/ServerStartCommandTest.php
```

3. Commander serve:

```shell
./vendor/bin/phpunit --no-progress tests/Testbench/CommanderServeTest.php
```

4. Dogfood package:

```shell
composer test:dogfood
```

5. Scoped Testbench package-mode suite:

```shell
composer test:testbench
```

6. Full repository checks:

```shell
composer fix
```

`composer fix` runs code style, phpstan, raw ParaTest, the scoped Testbench package-mode suite, and the dogfood package suite after this plan is implemented.

## Self-Review Checklist

Before requesting code review after implementation:

- Confirm `PackageManifest::providersFromTestbench()` has exactly two valid gates: Testbench CLI and package-test worker.
- Confirm package-test workers and remote CLI children produce the same manifest content for root metadata.
- Confirm read-time `dont-discover` still hides ignored packages without changing the built manifest.
- Confirm `src/testbench/README.md` records the intentional Orchestra difference next to the other Testbench differences.
- Confirm no Testbench behavior changes for non-package-test raw Testbench usage.
- Confirm `serve --host` and `serve --port` mutate only the HTTP server entry, not WebSocket or base server entries.
- Confirm config mutation happens before `ServerFactory::configure()` and before Swoole workers start.
- Confirm `CommanderServeTest` no longer relies on `HTTP_SERVER_HOST` / `HTTP_SERVER_PORT`.
- Confirm `.php-cs-fixer.php` excludes `dogfood/testbench-package/vendor`.
- Confirm root Composer bin metadata creates `vendor/bin/testbench` for the dogfood fixture.
- Confirm the dogfood fixture declares its own PHPUnit, ParaTest, and Symfony YAML dev dependencies.
- Confirm the dogfood fixture registers `Hypervel\Testing\PHPUnit\AfterEachTestExtension` in `phpunit.xml.dist`.
- Confirm the dogfood fixture is not discovered by the components root PHPUnit suite.
- Confirm the dogfood package proves root provider discovery, workbench provider/config loading, remote command behavior, and parallel package mode.
- Confirm `docs/todo.md` has no stale Testbench package-discovery item.
- Confirm docs use the same tone and structure as nearby sections.
- Confirm `composer fix` is green.
