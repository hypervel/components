<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Pest;

use Closure;

/**
 * @internal
 */
final class Hook
{
    /**
     * The cached hooks.
     *
     * @var array<string, array<string, null|array|Closure>>
     */
    public static array $cachedHooks = [
        '@setUp' => [],
        '@tearDown' => [],
        '@defineEnvironment' => [],
        '@defineRoutes' => [],
        '@defineWebRoutes' => [],
        '@defineDatabaseMigrations' => [],
        '@destroyDatabaseMigrations' => [],
        '@defineDatabaseSeeders' => [],

        '@afterApplicationCreated' => [],
        '@beforeApplicationDestroyed' => [],
        '@usesTestingFeature' => [],
    ];

    /**
     * Define a hook for Pest test file.
     */
    public static function attach(string $type, string $fileOrMethod, Closure|array|null $callback = null): void
    {
        self::$cachedHooks[$type][$fileOrMethod] = $callback;
    }

    /**
     * Unpack the hook.
     */
    public static function unpack(string $type, string $fileOrMethod, Closure|array|null $callback = null): Closure|array|null
    {
        return self::$cachedHooks[$type][$fileOrMethod] ?? $callback;
    }
}
