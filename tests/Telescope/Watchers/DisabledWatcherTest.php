<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Redis\RedisConfig;
use Hypervel\Telescope\Aspects\GuzzleHttpClientAspect;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Storage\DatabaseEntriesRepository;
use Hypervel\Telescope\Watchers\CacheWatcher;
use Hypervel\Telescope\Watchers\ClientRequestWatcher;
use Hypervel\Telescope\Watchers\RedisWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;

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
    public function testDisabledCacheWatcherDoesNotEnableCacheEvents(): void
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
        'database' => 0,
        'events' => false,
    ])]
    public function testDisabledRedisWatcherDoesNotEnableRedisEvents(): void
    {
        $this->assertFalse(
            $this->app->make(RedisConfig::class)
                ->connectionConfig('foo')['events'],
            'Redis connection should not have events enabled when RedisWatcher is disabled.'
        );
    }

    #[WithConfig('telescope.enabled', false)]
    #[WithConfig('telescope.watchers', [
        CacheWatcher::class => true,
        ClientRequestWatcher::class => true,
        RedisWatcher::class => true,
    ])]
    #[WithConfig('database.redis.foo', [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
        'events' => false,
    ])]
    public function testGloballyDisabledTelescopeRegistersStorageWithoutInstrumentation(): void
    {
        $this->assertInstanceOf(
            DatabaseEntriesRepository::class,
            $this->app->make(EntriesRepository::class),
        );
        $this->assertFalse(config('cache.stores.array.events'));
        $this->assertFalse(
            $this->app->make(RedisConfig::class)
                ->connectionConfig('foo')['events'],
        );
        $this->assertSame([], AspectCollector::getRule(GuzzleHttpClientAspect::class));
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => true,
    ])]
    public function testEnabledClientRequestWatcherRegistersGuzzleInstrumentation(): void
    {
        $this->assertNotEmpty(AspectCollector::getRule(GuzzleHttpClientAspect::class));
    }

    #[WithConfig('telescope.watchers', [
        ClientRequestWatcher::class => false,
    ])]
    public function testDisabledClientRequestWatcherDoesNotRegisterGuzzleInstrumentation(): void
    {
        $this->assertSame([], AspectCollector::getRule(GuzzleHttpClientAspect::class));
    }
}
