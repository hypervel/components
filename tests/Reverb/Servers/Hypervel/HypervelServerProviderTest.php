<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\HypervelServerProvider;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Tests\Reverb\ReverbTestCase;
use InvalidArgumentException;
use Mockery as m;
use ReflectionProperty;

class HypervelServerProviderTest extends ReverbTestCase
{
    public function testBindsSwooleTableSharedStateByDefault(): void
    {
        // Default config: scaling.enabled = false
        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(SwooleTableSharedState::class, $sharedState);
    }

    public function testBindsRedisSharedStateWhenScalingEnabled(): void
    {
        $this->app['config']->set('reverb.servers.reverb.scaling.enabled', true);

        // Re-register the provider with new config
        $provider = new HypervelServerProvider(
            $this->app,
            $this->app['config']->get('reverb.servers.reverb', [])
        );
        $provider->register();

        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(RedisSharedState::class, $sharedState);
    }

    public function testCreatesSwooleTableWithConfiguredRows(): void
    {
        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(SwooleTableSharedState::class, $sharedState);
        $this->assertGreaterThan(0, $sharedState->table()->getSize());
    }

    public function testScalingSharedStateDefaultsToReverbRedisConnection(): void
    {
        $this->app['config']->set('reverb.servers.reverb.scaling.enabled', true);

        $provider = new HypervelServerProvider(
            $this->app,
            $this->app['config']->get('reverb.servers.reverb', [])
        );
        $provider->register();

        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(RedisSharedState::class, $sharedState);
        $this->assertSame('reverb', $this->sharedStateRedisConnection($sharedState)->getName());
    }

    public function testScalingSharedStateUsesConfiguredRedisConnection(): void
    {
        $this->app['config']->set('reverb.servers.reverb.scaling.enabled', true);
        $this->app['config']->set('reverb.servers.reverb.scaling.connection', 'queue');

        $provider = new HypervelServerProvider(
            $this->app,
            $this->app['config']->get('reverb.servers.reverb', [])
        );
        $provider->register();

        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(RedisSharedState::class, $sharedState);
        $this->assertSame('queue', $this->sharedStateRedisConnection($sharedState)->getName());
    }

    public function testSharedStateIsEagerlyCreated(): void
    {
        // SharedState should already exist as an instance binding (not lazy)
        // because it must be created before fork for shared memory.
        $this->assertTrue($this->app->bound(SharedState::class));

        $first = $this->app->make(SharedState::class);
        $second = $this->app->make(SharedState::class);

        $this->assertSame($first, $second);
    }

    public function testRedisClusterScalingIsRejectedWithoutCreatingAPool(): void
    {
        $this->app->make('config')->set('database.redis.reverb.cluster', [
            'enable' => true,
            'seeds' => ['127.0.0.1:6379'],
        ]);
        $this->app->instance(PoolFactory::class, $poolFactory = m::mock(PoolFactory::class));
        $poolFactory->shouldNotReceive('getPool');
        $provider = new HypervelServerProvider(
            $this->app,
            [
                'scaling' => [
                    'enabled' => true,
                    'channel' => 'reverb',
                    'connection' => 'reverb',
                ],
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Reverb scaling does not support Redis Cluster. Disable 'reverb.servers.reverb.scaling.enabled' or set 'database.redis.reverb.cluster.enable' to false.",
        );

        $provider->register();
    }

    public function testRedisClusterIsNotValidatedWhenScalingIsDisabled(): void
    {
        $redisConfig = m::mock(RedisConfig::class);
        $redisConfig->shouldNotReceive('connectionConfig');
        $this->app->instance(RedisConfig::class, $redisConfig);
        $provider = new HypervelServerProvider(
            $this->app,
            [
                'scaling' => [
                    'enabled' => false,
                    'channel' => 'reverb',
                    'connection' => 'reverb',
                ],
                'swoole_shared_state' => [
                    'rows' => 16,
                    'lock_rows' => 16,
                ],
            ],
        );

        $provider->register();

        $this->assertInstanceOf(SwooleTableSharedState::class, $this->app->make(SharedState::class));
    }

    protected function sharedStateRedisConnection(RedisSharedState $sharedState): RedisProxy
    {
        $property = new ReflectionProperty($sharedState, 'redis');

        return $property->getValue($sharedState);
    }
}
