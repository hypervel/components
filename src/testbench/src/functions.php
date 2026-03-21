<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Closure;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Foundation\Application;
use Hypervel\Routing\Router;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\ProcessUtils;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Contracts\TestCase as TestCaseContract;
use Hypervel\Testbench\Exceptions\ApplicationNotAvailableException;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\Foundation\Process\ProcessDecorator;
use Hypervel\Testbench\Foundation\Process\RemoteCommand;
use Hypervel\Testing\PendingCommand;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use PHPUnit\Runner\ShutdownHandler;
use PHPUnit\Runner\Version;
use RuntimeException;

use function Hypervel\Support\php_binary as support_php_binary;

/**
 * Register after resolving callback.
 *
 * Calls the callback when the given abstract is resolved, or immediately if already resolved.
 */
function after_resolving(ApplicationContract $app, string $name, ?Closure $callback = null): void
{
    $app->afterResolving($name, $callback);

    if ($app->resolved($name)) {
        value($callback, $app->make($name), $app);
    }
}

/**
 * Create Hypervel application instance.
 *
 * @param null|callable(ApplicationContract):void $resolvingCallback
 * @param array<string, mixed> $options
 */
function container(
    ?string $basePath = null,
    ?callable $resolvingCallback = null,
    array $options = [],
    ?Config $config = null
): Foundation\Application {
    if ($config instanceof Config) {
        return Foundation\Application::makeFromConfig($config, $resolvingCallback, $options);
    }

    return Foundation\Application::make($basePath, $resolvingCallback, $options);
}

/**
 * Run artisan command.
 */
function artisan(TestCaseContract|ApplicationContract $context, string $command, array $parameters = []): int
{
    if ($context instanceof ApplicationContract) {
        return $context->make(ConsoleKernel::class)->call($command, $parameters);
    }

    $pendingCommand = $context->artisan($command, $parameters);

    return $pendingCommand instanceof PendingCommand
        ? $pendingCommand->run()
        : $pendingCommand;
}

/**
 * Exit cleanly from a test process.
 *
 * Resets PHPUnit's shutdown handler message to prevent
 * "PHPUnit did not exit cleanly" warnings on process exit.
 */
function bail(?object $testCase, int $status = 0): never
{
    if ($testCase instanceof PHPUnitTestCase && phpunit_version_compare('12.3.5', '>=')) {
        ShutdownHandler::resetMessage();
    }

    exit($status);
}

/**
 * Exit cleanly from a test process.
 */
function terminate(?object $testCase, int $status = 0): never
{
    bail($testCase, $status);
}

/**
 * Refresh the router's name and action lookup tables.
 *
 * Route names set via fluent ->name() after RouteCollection::add() are not
 * indexed until refreshNameLookups() runs. This function triggers that refresh.
 */
function refresh_router_lookups(Router $router): void
{
    $router->getRoutes()->refreshNameLookups();
}

/**
 * Load migration paths.
 *
 * Registers the given paths with the migrator so they're included when running migrations.
 *
 * @param array<int, string>|string $paths
 */
function load_migration_paths(ApplicationContract $app, array|string $paths): void
{
    after_resolving($app, 'migrator', static function (Migrator $migrator) use ($paths): void {
        foreach (Arr::wrap($paths) as $path) {
            $migrator->path($path);
        }
    });
}

/**
 * Get the path to the default skeleton application.
 *
 * Returns the path to the runtime copy of the workbench app used for testing.
 * This is set by Bootstrapper::bootstrap() via the BASE_PATH constant.
 *
 * @param array<int, null|string>|string $path
 */
function default_skeleton_path(array|string $path = ''): string|false
{
    if (! defined('BASE_PATH')) {
        Bootstrapper::bootstrap();
    }

    if (! defined('BASE_PATH')) {
        return false;
    }

    $result = join_paths(BASE_PATH, ...Arr::wrap(func_num_args() > 1 ? func_get_args() : $path));

    return realpath($result);
}

/**
 * Determine if application is bootstrapped using Testbench's default skeleton.
 */
function uses_default_skeleton(?string $basePath = null): bool
{
    $basePath ??= default_skeleton_path() ?: null;

    if ($basePath === null) {
        return false;
    }

    return realpath(join_paths($basePath, 'bootstrap', '.testbench-default-skeleton')) !== false;
}

/**
 * Get the migration path by type.
 *
 * Returns the path to framework test migrations in the testbench package.
 * These are separate from the workbench app's migrations (which use database_path()).
 *
 * @throws InvalidArgumentException
 */
function default_migration_path(?string $type = null): string
{
    $basePath = dirname(__DIR__) . '/hypervel/migrations';

    $path = realpath(
        is_null($type)
            ? $basePath
            : join_paths($basePath, $type)
    );

    if ($path === false) {
        throw new InvalidArgumentException(
            sprintf('Unable to resolve migration path for type [%s]', $type ?? 'hypervel')
        );
    }

    return $path;
}

/**
 * Join the given paths together.
 */
function join_paths(?string $basePath, string ...$paths): string
{
    foreach ($paths as $index => $path) {
        if (empty($path)) {
            unset($paths[$index]);
        } else {
            $paths[$index] = DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }
    }

    return $basePath . implode('', $paths);
}

/**
 * Determine if the path is a symlink for both Unix and Windows environments.
 */
function is_symlink(string $path): bool
{
    if (windows_os() && is_dir($path) && readlink($path) !== $path) {
        return true;
    }

    return is_link($path);
}

/**
 * Get the path to the testbench package folder.
 *
 * @param array<int, null|string>|string ...$path
 */
function testbench_path(array|string $path = ''): string
{
    $argumentCount = func_num_args();

    $workingPath = dirname(__DIR__);

    if ($argumentCount === 1 && is_string($path) && str_starts_with($path, './')) {
        return transform_relative_path($path, $workingPath) ?? $workingPath;
    }

    if ($argumentCount === 1 && $path === DIRECTORY_SEPARATOR) {
        return rtrim($workingPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    $path = join_paths(null, ...Arr::wrap($argumentCount > 1 ? func_get_args() : $path));

    return str_starts_with($path, './')
        ? transform_relative_path($path, $workingPath) ?? $workingPath
        : join_paths(rtrim($workingPath, DIRECTORY_SEPARATOR), ltrim($path, DIRECTORY_SEPARATOR));
}

/**
 * Get the path to the package root folder.
 *
 * @param array<int, null|string>|string ...$path
 */
function package_path(array|string $path = ''): string
{
    $argumentCount = func_num_args();

    $workingPath = once(static function (): string {
        $resolvedPath = realpath(match (true) {
            defined('TESTBENCH_WORKING_PATH') => TESTBENCH_WORKING_PATH,
            Env::has('TESTBENCH_WORKING_PATH') => (string) Env::get('TESTBENCH_WORKING_PATH'),
            default => InstalledVersions::getRootPackage()['install_path'],
        });

        return $resolvedPath !== false ? $resolvedPath : getcwd();
    });

    if ($argumentCount === 1 && is_string($path) && str_starts_with($path, './')) {
        return transform_relative_path($path, $workingPath) ?? $workingPath;
    }

    if ($argumentCount === 1 && $path === DIRECTORY_SEPARATOR) {
        return rtrim($workingPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    $path = join_paths(null, ...Arr::wrap($argumentCount > 1 ? func_get_args() : $path));

    return str_starts_with($path, './')
        ? transform_relative_path($path, $workingPath) ?? $workingPath
        : join_paths(rtrim($workingPath, DIRECTORY_SEPARATOR), ltrim($path, DIRECTORY_SEPARATOR));
}

/**
 * Get defined environment variables to pass to subprocess.
 *
 * Filters out non-scalar values (arrays, objects) since environment
 * variables must be strings. This prevents "Array to string conversion"
 * errors when tests pollute $_SERVER with array values.
 *
 * @return array<string, null|bool|float|int|string>
 */
function defined_environment_variables(): array
{
    return (new Collection(array_merge($_SERVER, $_ENV)))
        ->keys()
        ->mapWithKeys(static fn (string $key) => [$key => $_ENV[$key] ?? $_SERVER[$key] ?? null])
        ->filter(static fn ($value) => $value === null || is_scalar($value))
        ->when(
            ! Env::has('TESTBENCH_WORKING_PATH'),
            static fn (Collection $env) => $env->put('TESTBENCH_WORKING_PATH', package_path())
        )->all();
}

/**
 * Get default environment variables.
 *
 * @param iterable<string, mixed> $variables
 * @return array<int, string>
 */
function parse_environment_variables(iterable $variables): array
{
    return (new Collection($variables))
        ->transform(static function (mixed $value, string $key): string {
            if (is_bool($value) || in_array($value, ['true', 'false'], true)) {
                $value = in_array($value, [true, 'true'], true) ? '(true)' : '(false)';
            } elseif ($value === null || $value === 'null') {
                $value = '(null)';
            } else {
                $value = $key === 'APP_DEBUG'
                    ? sprintf('(%s)', trim((string) $value, '()'))
                    : "'{$value}'";
            }

            return "{$key}={$value}";
        })
        ->values()
        ->all();
}

/**
 * Determine if the Hypervel application's vendor directory already matches the working vendor path.
 */
function hypervel_vendor_exists(ApplicationContract $app, ?string $workingPath = null): bool
{
    $filesystem = new \Hypervel\Filesystem\Filesystem();

    $appVendorPath = $app->basePath('vendor');
    $workingPath ??= package_path('vendor');

    return $filesystem->isFile(join_paths($appVendorPath, 'autoload.php'))
        && $filesystem->hash(join_paths($appVendorPath, 'autoload.php')) === $filesystem->hash(join_paths($workingPath, 'autoload.php'));
}

/**
 * Transform realpath to alias path.
 */
function transform_realpath_to_relative(string $path, ?string $workingPath = null, string $prefix = ''): string
{
    $separator = DIRECTORY_SEPARATOR;

    if ($workingPath !== null) {
        return str_replace(rtrim($workingPath, $separator) . $separator, $prefix . $separator, $path);
    }

    $hypervelPath = default_skeleton_path();
    $workbenchPath = workbench_path();
    $packagePath = package_path();

    return match (true) {
        $hypervelPath !== false && str_starts_with($path, $hypervelPath) => str_replace($hypervelPath . $separator, '@hypervel' . $separator, $path),
        str_starts_with($path, $workbenchPath) => str_replace($workbenchPath . $separator, '@workbench' . $separator, $path),
        str_starts_with($path, $packagePath) => str_replace($packagePath . $separator, '.' . $separator, $path),
        $prefix !== '' => implode($separator, [$prefix, ltrim($path, $separator)]),
        default => $path,
    };
}

/**
 * Transform relative path to an absolute path using the given working path.
 */
function transform_relative_path(?string $path, string $workingPath): ?string
{
    if ($path === null || $path === '') {
        return $path;
    }

    if ($path === '@testbench') {
        return default_skeleton_path() ?: $path;
    }

    if (str_starts_with($path, './') || str_starts_with($path, '../')) {
        return realpath(join_paths($workingPath, $path)) ?: join_paths($workingPath, $path);
    }

    return $path;
}

/**
 * Get the workbench configuration.
 *
 * @return array<string, mixed>
 */
function workbench(): array
{
    /** @var ConfigContract $config */
    $config = app()->bound(ConfigContract::class)
        ? app()->make(ConfigContract::class)
        : new Config();

    return $config->getWorkbenchAttributes();
}

/**
 * Get the path to the workbench folder.
 *
 * @param array<int, null|string>|string ...$path
 */
function workbench_path(array|string $path = ''): string
{
    $argumentCount = func_num_args();

    $packageWorkbenchPath = package_path('workbench');
    $workingPath = is_dir($packageWorkbenchPath)
        || is_file(package_path('testbench.yaml'))
        || is_file(package_path('testbench.yaml.example'))
        || is_file(package_path('testbench.yaml.dist'))
            ? $packageWorkbenchPath
            : testbench_path('workbench');

    if ($argumentCount === 1 && is_string($path) && str_starts_with($path, './')) {
        return transform_relative_path($path, $workingPath) ?? $workingPath;
    }

    if ($argumentCount === 1 && $path === DIRECTORY_SEPARATOR) {
        return rtrim($workingPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    $path = join_paths(null, ...Arr::wrap($argumentCount > 1 ? func_get_args() : $path));

    return str_starts_with($path, './')
        ? transform_relative_path($path, $workingPath) ?? $workingPath
        : join_paths(rtrim($workingPath, DIRECTORY_SEPARATOR), ltrim($path, DIRECTORY_SEPARATOR));
}

/**
 * Get the package-relative path to the testbench folder.
 *
 * @param array<int, null|string>|string ...$path
 */
function testbench_relative_path(array|string $path = ''): string
{
    $resolvedPath = testbench_path(...Arr::wrap(func_num_args() > 1 ? func_get_args() : $path));
    $packageRoot = rtrim(package_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return ltrim(str_replace($packageRoot, '', $resolvedPath), DIRECTORY_SEPARATOR);
}

/**
 * Get the package-relative path to the workbench folder.
 *
 * @param array<int, null|string>|string ...$path
 */
function workbench_relative_path(array|string $path = ''): string
{
    return testbench_relative_path('workbench', ...Arr::wrap(func_num_args() > 1 ? func_get_args() : $path));
}

/**
 * Compare the installed version of a package.
 */
function package_version_compare(string $package, string $version, ?string $operator = null): int|bool
{
    $prettyVersion = InstalledVersions::getPrettyVersion($package);

    if ($prettyVersion === null) {
        throw new RuntimeException(sprintf('Unable to compare "%s" version', $package));
    }

    $versionParser = new VersionParser();
    $normalizedPackageVersion = $versionParser->normalize($prettyVersion);
    $normalizedVersion = $versionParser->normalize($version);

    if ($operator === null) {
        return version_compare($normalizedPackageVersion, $normalizedVersion);
    }

    return version_compare($normalizedPackageVersion, $normalizedVersion, $operator);
}

/**
 * Compare the installed Hypervel framework version.
 */
function hypervel_version_compare(string $version, ?string $operator = null): int|bool
{
    $versionParser = new VersionParser();
    $normalizedApplicationVersion = $versionParser->normalize(Application::VERSION);
    $normalizedVersion = $versionParser->normalize($version);

    if ($operator === null) {
        return version_compare($normalizedApplicationVersion, $normalizedVersion);
    }

    return version_compare($normalizedApplicationVersion, $normalizedVersion, $operator);
}

/**
 * Compare the installed PHPUnit version.
 */
function phpunit_version_compare(string $version, ?string $operator = null): int|bool
{
    $currentVersion = Version::id();

    $normalizedCurrentVersion = match (true) {
        str_starts_with($currentVersion, '13.0-') => '13.0.0',
        default => $currentVersion,
    };

    if ($operator === null) {
        return version_compare($normalizedCurrentVersion, $version);
    }

    return version_compare($normalizedCurrentVersion, $version, $operator);
}

/**
 * Ensure the provided application is available or throw an exception.
 */
function hypervel_or_fail(mixed $app, ?string $caller = null): Application
{
    if ($app instanceof Application) {
        return $app;
    }

    if ($caller === null) {
        $debug = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? null;

        if (is_array($debug) && isset($debug['class'])) {
            $caller = sprintf('%s::%s', $debug['class'], $debug['function']);
        } elseif (is_array($debug)) {
            $caller = $debug['function'];
        }
    }

    throw ApplicationNotAvailableException::make($caller);
}

/**
 * Determine if running via the Testbench CLI.
 */
function is_testbench_cli(?bool $dusk = null): bool
{
    $usingTestbench = \defined('TESTBENCH_CORE');
    $usingTestbenchDusk = \defined('TESTBENCH_DUSK');

    return match ($dusk) {
        false => $usingTestbench === true && $usingTestbenchDusk === false,
        true => $usingTestbench === true && $usingTestbenchDusk === true,
        default => $usingTestbench === true,
    };
}

/**
 * Determine the PHP binary.
 */
function php_binary(bool $escape = false): string
{
    $phpBinary = support_php_binary();

    return $escape ? ProcessUtils::escapeArgument($phpBinary) : $phpBinary;
}

/**
 * Run remote action using Testbench CLI.
 *
 * Spawns a subprocess to run a console command, useful for testing scenarios
 * that require process isolation (e.g., queue workers with job timeouts).
 *
 * @param array<int, string>|Closure|string $command The command to run
 * @param array<string, mixed>|string $env Environment variables or APP_ENV value
 * @param null|bool $tty Whether to enable TTY mode
 */
function remote(Closure|array|string $command, array|string $env = [], ?bool $tty = null): ProcessDecorator
{
    $remote = new RemoteCommand(package_path(), $env, $tty);

    // Look for testbench binary in order of preference:
    // 1. vendor/bin/testbench (installed as dependency)
    // 2. src/testbench/bin/testbench (monorepo structure)
    // 3. Fall back to 'testbench' in PATH
    $vendorBinary = package_path('vendor', 'bin', 'testbench');
    $srcBinary = package_path('src', 'testbench', 'bin', 'testbench');

    $commander = match (true) {
        is_file($vendorBinary) => $vendorBinary,
        is_file($srcBinary) => $srcBinary,
        default => 'testbench',
    };

    return $remote->handle($commander, $command);
}
