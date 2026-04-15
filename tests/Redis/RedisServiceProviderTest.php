<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Redis\Connection as ConnectionContract;
use Hypervel\Contracts\Redis\Factory as FactoryContract;
use Hypervel\Redis\RedisManager;
use Hypervel\Redis\RedisProxy;
use Hypervel\Testbench\TestCase;

/**
 * Tests for RedisServiceProvider container bindings and alias resolution.
 */
class RedisServiceProviderTest extends TestCase
{
    public function testRedisBindingResolvesToRedisManagerInstance()
    {
        $redis = $this->app->make('redis');

        $this->assertInstanceOf(RedisManager::class, $redis);
    }

    public function testFactoryContractResolvesToRedisManagerInstance()
    {
        $redis = $this->app->make(FactoryContract::class);

        $this->assertInstanceOf(RedisManager::class, $redis);
    }

    public function testRedisManagerClassResolvesToSameInstanceAsRedisBinding()
    {
        $byKey = $this->app->make('redis');
        $byClass = $this->app->make(RedisManager::class);

        $this->assertSame($byKey, $byClass);
    }

    public function testFactoryContractResolvesToSameInstanceAsRedisBinding()
    {
        $byKey = $this->app->make('redis');
        $byContract = $this->app->make(FactoryContract::class);

        $this->assertSame($byKey, $byContract);
    }

    public function testRedisIsSingleton()
    {
        $first = $this->app->make('redis');
        $second = $this->app->make('redis');

        $this->assertSame($first, $second);
    }

    public function testRedisManagerImplementsFactoryContract()
    {
        $redis = $this->app->make('redis');

        $this->assertInstanceOf(FactoryContract::class, $redis);
    }

    public function testRedisManagerImplementsConnectionContract()
    {
        $redis = $this->app->make('redis');

        $this->assertInstanceOf(ConnectionContract::class, $redis);
    }

    public function testRedisProxyImplementsConnectionContract()
    {
        $this->assertTrue(is_subclass_of(RedisProxy::class, ConnectionContract::class));
        $this->assertFalse(is_subclass_of(RedisProxy::class, RedisManager::class));
    }

    public function testRedisConnectionAliasesAreRegistered()
    {
        // Verify the alias table maps the contract to 'redis.connection'
        // Note: RedisProxy is NOT aliased — it's constructed internally by
        // the manager with per-pool parameters. Aliasing it would cause a
        // circular dependency.
        $this->assertTrue($this->app->isAlias(ConnectionContract::class));
        $this->assertFalse($this->app->isAlias(RedisProxy::class));
    }
}
