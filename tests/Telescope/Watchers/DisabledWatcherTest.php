<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Contracts\Cache\Repository;
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
    #[WithConfig('telescope.watchers', [
        CacheWatcher::class => [
            'enabled' => false,
            'hidden' => [],
        ],
    ])]
    public function testDisabledCacheWatcherRecordsNoCacheEntries(): void
    {
        $repository = $this->app->make(Repository::class);

        $repository->put('disabled-key', 'laravel', 1);
        $repository->get('disabled-key');

        $this->assertCount(0, $this->loadTelescopeEntries());
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
