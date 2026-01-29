<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Support\Arr;
use InvalidArgumentException;

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
 * @throws InvalidArgumentException
 */
function default_migration_path(?string $type = null): string
{
    $path = realpath(
        is_null($type)
            ? base_path('migrations')
            : base_path(join_paths('migrations', $type))
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
