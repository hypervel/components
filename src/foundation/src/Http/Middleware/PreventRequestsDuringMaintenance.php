<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

use Hypervel\Support\Arr;

/**
 * @TODO Port fully from Laravel once the maintenance mode subsystem is ported.
 * This requires: MaintenanceMode contract, MaintenanceModeManager, FileBasedMaintenanceMode,
 * MaintenanceModeBypassCookie, and Application::maintenanceMode(). Currently only the static
 * configuration methods exist so that Configuration\Middleware and Configuration\ApplicationBuilder
 * can reference this class without errors.
 */
class PreventRequestsDuringMaintenance
{
    /**
     * The URIs that should be accessible during maintenance.
     *
     * @var array<int, string>
     */
    protected static array $neverPrevent = [];

    /**
     * Indicate that the given URIs should always be accessible.
     */
    public static function except(array|string $uris): void
    {
        static::$neverPrevent = array_values(array_unique(
            array_merge(static::$neverPrevent, Arr::wrap($uris))
        ));
    }

    /**
     * Flush the state of the middleware.
     */
    public static function flushState(): void
    {
        static::$neverPrevent = [];
    }
}
