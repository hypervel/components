<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Tests\Support\RedisIntegrationTestCase;

/**
 * Base test case for Cache + Redis integration tests.
 *
 * Extends the generic Redis integration test case and adds
 * cache-specific configuration (sets Redis as the cache driver).
 *
 * NOTE: Concrete test classes extending this MUST add @group redis-integration
 * for proper test filtering in CI.
 *
 * @internal
 * @coversNothing
 */
abstract class CacheRedisIntegrationTestCase extends RedisIntegrationTestCase
{
    /**
     * Configure cache to use Redis as the default driver.
     */
    protected function configurePackage(): void
    {
        $config = $this->app->get(ConfigInterface::class);

        $config->set('cache.default', 'redis');
    }
}
