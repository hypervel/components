<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;

class WorkerCachedMaintenanceMode implements MaintenanceModeContract
{
    /**
     * The cached maintenance mode snapshot.
     *
     * Loaded once per worker lifetime from the underlying driver,
     * then served from memory for all subsequent requests.
     * Reset to null on worker restart (SIGUSR1) or explicit flush.
     *
     * @var null|array{active: bool, data: array}
     */
    protected static ?array $snapshot = null;

    /**
     * Create a new worker-cached maintenance mode instance.
     */
    public function __construct(
        protected MaintenanceModeContract $driver
    ) {
    }

    /**
     * Take the application down for maintenance.
     */
    public function activate(array $payload): void
    {
        $this->driver->activate($payload);

        static::$snapshot = null;
    }

    /**
     * Take the application out of maintenance.
     */
    public function deactivate(): void
    {
        $this->driver->deactivate();

        static::$snapshot = null;
    }

    /**
     * Determine if the application is currently down for maintenance.
     */
    public function active(): bool
    {
        return $this->loadSnapshot()['active'];
    }

    /**
     * Get the maintenance mode data payload.
     */
    public function data(): array
    {
        return $this->loadSnapshot()['data'];
    }

    /**
     * Flush the cached maintenance mode state.
     */
    public static function flushCache(): void
    {
        static::$snapshot = null;
    }

    /**
     * Load the maintenance mode snapshot from the underlying driver.
     *
     * Both active state and data payload are loaded atomically
     * in a single call, eliminating any race between checking
     * active() and reading data() on the backing store.
     *
     * @return array{active: bool, data: array}
     */
    protected function loadSnapshot(): array
    {
        if (static::$snapshot === null) {
            $active = $this->driver->active();

            static::$snapshot = [
                'active' => $active,
                'data' => $active ? $this->driver->data() : [],
            ];
        }

        return static::$snapshot;
    }
}
