<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Redis\Connection as ConnectionContract;
use Hypervel\Contracts\Redis\Factory as FactoryContract;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Core\Events\TaskTerminated;
use Hypervel\Events\Dispatcher;
use Hypervel\Redis\RedisManager;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisServiceProvider;
use Hypervel\Testbench\TestCase;
use Redis;
use Swoole\Constant;

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

    public function testNativeRedisClassIsNotBoundToRedisManager()
    {
        $this->assertFalse($this->app->bound(Redis::class));
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

    public function testNonCoroutineTaskLifecycleListenersAreRegistered(): void
    {
        $events = $this->bootProviderWithTaskCoroutines(false);

        $this->assertTrue($events->hasListeners(TaskTerminated::class));
        $this->assertTrue($events->hasListeners(BeforeServerFork::class));
        $this->assertTrue($events->hasListeners(BeforeWorkerStart::class));
    }

    public function testCoroutineTasksDoNotRegisterTerminalTaskCleanup(): void
    {
        $events = $this->bootProviderWithTaskCoroutines(true);

        $this->assertFalse($events->hasListeners(TaskTerminated::class));
        $this->assertTrue($events->hasListeners(BeforeServerFork::class));
        $this->assertTrue($events->hasListeners(BeforeWorkerStart::class));
    }

    /**
     * Boot a fresh provider against an isolated event dispatcher.
     */
    private function bootProviderWithTaskCoroutines(bool $enabled): Dispatcher
    {
        $this->app->make('config')->set(
            'server.settings.' . Constant::OPTION_TASK_ENABLE_COROUTINE,
            $enabled,
        );

        $events = new Dispatcher($this->app);
        $this->app->instance('events', $events);

        (new RedisServiceProvider($this->app))->boot();

        return $events;
    }
}
