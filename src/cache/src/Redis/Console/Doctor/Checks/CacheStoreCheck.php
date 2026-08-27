<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;

/**
 * Checks and displays cache store configuration.
 *
 * This is an informational check that shows the current store name,
 * driver, and tagging mode. It always passes if the store is a Redis store.
 */
final class CacheStoreCheck implements EnvironmentCheckInterface
{
    /**
     * Create a new cache store check instance.
     */
    public function __construct(
        private readonly string $storeName,
        private readonly string $driver,
        private readonly string $taggingMode,
    ) {
    }

    /**
     * Get the human-readable name of this check.
     */
    public function name(): string
    {
        return 'Cache Store Configuration';
    }

    /**
     * Run the check and return results.
     */
    public function run(): CheckResult
    {
        $result = new CheckResult;

        $isRedisDriver = $this->driver === 'redis';

        $result->assert(
            $isRedisDriver,
            $isRedisDriver
                ? "Cache store '{$this->storeName}' uses redis driver"
                : "Cache store '{$this->storeName}' uses redis driver (current: {$this->driver})"
        );

        if ($isRedisDriver) {
            $result->assert(
                true,
                "Tagging mode: {$this->taggingMode}"
            );
        }

        return $result;
    }

    /**
     * Get details about how to fix a failed check.
     */
    public function getFixInstructions(): ?string
    {
        if ($this->driver !== 'redis') {
            return "Update the driver for '{$this->storeName}' store to 'redis' in config/cache.php";
        }

        return null;
    }
}
