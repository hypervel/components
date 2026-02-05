<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Concerns;

use Hyperf\Contract\ConfigInterface;

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
        $config = $this->app->get(ConfigInterface::class);
        $stores = $config->get('cache.stores', []);

        foreach ($stores as $name => $storeConfig) {
            if (($storeConfig['driver'] ?? null) === 'redis') {
                return $name;
            }
        }

        return null;
    }
}
