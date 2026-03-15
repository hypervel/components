<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Redis\Connection as ConnectionContract;
use Hypervel\Contracts\Redis\Factory as FactoryContract;
use Hypervel\Redis\Redis;
use Hypervel\Redis\RedisProxy;
use Hypervel\Testbench\TestCase;

/**
 * Tests for RedisServiceProvider container bindings and alias resolution.
 *
 * @internal
 * @coversNothing
 */
class RedisServiceProviderTest extends TestCase
{
    public function testRedisBindingResolvesToRedisInstance()
    {
        $redis = $this->app->make('redis');

        $this->assertInstanceOf(Redis::class, $redis);
    }

    public function testFactoryContractResolvesToRedisInstance()
    {
        $redis = $this->app->make(FactoryContract::class);

        $this->assertInstanceOf(Redis::class, $redis);
    }

    public function testRedisClassResolvesToSameInstanceAsRedisBinding()
    {
        $byKey = $this->app->make('redis');
        $byClass = $this->app->make(Redis::class);

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

    public function testRedisImplementsFactoryContract()
    {
        $redis = $this->app->make('redis');

        $this->assertInstanceOf(FactoryContract::class, $redis);
    }

    public function testRedisImplementsConnectionContract()
    {
        $redis = $this->app->make('redis');

        $this->assertInstanceOf(ConnectionContract::class, $redis);
    }

    public function testRedisProxyExtendsRedisAndInheritsContracts()
    {
        // RedisProxy extends Redis, so it should satisfy both contracts
        $this->assertTrue(is_subclass_of(RedisProxy::class, Redis::class));
        $this->assertTrue(is_subclass_of(RedisProxy::class, FactoryContract::class));
        $this->assertTrue(is_subclass_of(RedisProxy::class, ConnectionContract::class));
    }

    public function testRedisConnectionAliasesAreRegistered()
    {
        // Verify the alias table maps the contract to 'redis.connection'
        // Note: RedisProxy is NOT aliased — it's constructed internally by RedisFactory
        // with per-pool parameters. Aliasing it would cause a circular dependency.
        $this->assertTrue($this->app->isAlias(ConnectionContract::class));
        $this->assertFalse($this->app->isAlias(RedisProxy::class));
    }
}
