<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\ProcessUtils;
use Hypervel\Testbench\Foundation\Process\ProcessDecorator;
use Hypervel\Testbench\Foundation\Process\RemoteCommand;
use InvalidArgumentException;

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
        value($callback, $app->get($name), $app);
    }
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
    after_resolving($app, Migrator::class, static function (Migrator $migrator) use ($paths): void {
        foreach (Arr::wrap($paths) as $path) {
            $migrator->path($path);
        }
    });
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
    // Migrations live at testbench/migrations/, parallel to testbench/workbench/
    // This mirrors Laravel's testbench-core/laravel/migrations/ structure
    $basePath = dirname(__DIR__) . '/migrations';

    $path = realpath(
        is_null($type)
            ? $basePath
            : join_paths($basePath, $type)
    );

    if ($path === false) {
        throw new InvalidArgumentException(
            sprintf('Unable to resolve migration path for type [%s]', $type ?? 'laravel')
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
 * Get the path to the package folder.
 *
 * @param array<int, null|string>|string ...$path
 */
function package_path(array|string $path = ''): string
{
    $workingPath = defined('TESTBENCH_WORKING_PATH')
        ? TESTBENCH_WORKING_PATH
        : getcwd();

    $path = join_paths(null, ...Arr::wrap(func_num_args() > 1 ? func_get_args() : $path));

    return join_paths(rtrim($workingPath, DIRECTORY_SEPARATOR), ltrim($path, DIRECTORY_SEPARATOR));
}

/**
 * Get defined environment variables to pass to subprocess.
 *
 * @return array<string, mixed>
 */
function defined_environment_variables(): array
{
    return (new Collection(array_merge($_SERVER, $_ENV)))
        ->keys()
        ->mapWithKeys(static fn (string $key) => [$key => $_ENV[$key] ?? $_SERVER[$key] ?? null])
        ->when(
            ! defined('TESTBENCH_WORKING_PATH'),
            static fn (Collection $env) => $env->put('TESTBENCH_WORKING_PATH', package_path())
        )->all();
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
