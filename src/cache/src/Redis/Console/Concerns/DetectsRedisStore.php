<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Concerns;

/**
 * Provides store detection functionality for commands.
 */
trait DetectsRedisStore
{
    /**
     * Detect the first cache store using the redis driver.
     */
    protected function detectRedisStore(): ?string
    {
        $config = $this->app->get('config');
        $stores = $config->get('cache.stores', []);

        foreach ($stores as $name => $storeConfig) {
            if (($storeConfig['driver'] ?? null) === 'redis') {
                return $name;
            }
        }

        return null;
    }
}
