<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Support\CarbonImmutable;

class WorkerCachedMaintenanceMode implements MaintenanceModeContract
{
    /**
     * The cached maintenance mode snapshot.
     *
     * Loaded on first access, then periodically refreshed from the underlying
     * driver. Reset to null on worker restart (SIGUSR1) or explicit flush.
     *
     * @var null|array{active: bool, data: array}
     */
    protected static ?array $snapshot = null;

    /**
     * The time when the cached snapshot was last refreshed.
     *
     * Stored as a Unix timestamp float rather than a Carbon instance: this is
     * read on every request through the global middleware stack, and Carbon's
     * interval arithmetic costs microseconds per comparison where a float
     * subtraction costs nanoseconds.
     */
    protected static ?float $refreshedAt = null;

    /**
     * Create a new worker-cached maintenance mode instance.
     */
    public function __construct(
        protected MaintenanceModeContract $driver,
        protected int $refreshInterval = 5
    ) {
    }

    /**
     * Take the application down for maintenance.
     */
    public function activate(array $payload): void
    {
        $this->driver->activate($payload);

        static::flushCache();
    }

    /**
     * Take the application out of maintenance.
     */
    public function deactivate(): void
    {
        $this->driver->deactivate();

        static::flushCache();
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
     *
     * Boot or tests only. Request-time use clears worker-wide state while
     * concurrent coroutines may still be using the previous snapshot.
     */
    public static function flushCache(): void
    {
        static::$snapshot = null;
        static::$refreshedAt = null;
    }

    /**
     * Load the maintenance mode snapshot from the underlying driver.
     *
     * The active state and payload are read separately from the driver, then
     * retained together so subsequent calls within the refresh interval use
     * the same per-worker snapshot.
     *
     * @return array{active: bool, data: array}
     */
    protected function loadSnapshot(): array
    {
        if ($this->shouldRefreshSnapshot()) {
            $active = $this->driver->active();

            static::$snapshot = [
                'active' => $active,
                'data' => $active ? $this->driver->data() : [],
            ];

            // Set after successful reads so failed refreshes retry on the next request.
            static::$refreshedAt = static::now();
        }

        return static::$snapshot;
    }

    /**
     * Determine if the cached snapshot should be refreshed.
     */
    protected function shouldRefreshSnapshot(): bool
    {
        if (static::$snapshot === null || static::$refreshedAt === null) {
            return true;
        }

        return $this->refreshInterval > 0
            && static::now() - static::$refreshedAt >= $this->refreshInterval;
    }

    /**
     * Get the current time as a Unix timestamp in seconds.
     *
     * This runs on every request through the global middleware stack, so the
     * common path uses microtime() rather than Carbon interval arithmetic —
     * building two Carbon instances and an interval to compare them costs
     * microseconds where a float subtraction costs nanoseconds. Carbon is only
     * consulted when a test now is set, so time travel still drives refreshes.
     */
    protected static function now(): float
    {
        return CarbonImmutable::hasTestNow()
            ? (float) CarbonImmutable::now()->getTimestamp()
            : microtime(true);
    }
}
