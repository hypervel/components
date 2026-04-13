<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Telescope\Watchers\CacheWatcher;
use Hypervel\Telescope\Watchers\RedisWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
class DisabledWatcherTest extends FeatureTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        // Override the FeatureTestCase default so we can detect whether
        // the watcher's enableCacheEvents() mutated this config.
        $app->make('config')->set('cache.stores.array.events', false);
    }

    #[WithConfig('telescope.watchers', [
        CacheWatcher::class => [
            'enabled' => false,
            'hidden' => [],
        ],
    ])]
    public function testDisabledCacheWatcherDoesNotEnableCacheEvents()
    {
        $config = $this->app->make('config');

        foreach (array_keys($config->get('cache.stores', [])) as $store) {
            $this->assertFalse(
                $config->get("cache.stores.{$store}.events", false),
                "Cache store '{$store}' should not have events enabled when CacheWatcher is disabled."
            );
        }
    }

    #[WithConfig('telescope.watchers', [
        RedisWatcher::class => [
            'enabled' => false,
        ],
    ])]
    #[WithConfig('database.redis.foo', [
        'host' => '127.0.0.1',
        'port' => 6379,
        'db' => 0,
    ])]
    public function testDisabledRedisWatcherDoesNotEnableRedisEvents()
    {
        $config = $this->app->make('config');

        $this->assertFalse(
            $config->get('database.redis.foo.event.enable', false),
            'Redis connection should not have events enabled when RedisWatcher is disabled.'
        );
    }
}
