<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\Events\Dispatcher;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\HypervelServerProvider;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Reverb\Servers\Hypervel\Scaling\SwooleTableSharedState;
use Hypervel\Testbench\Attributes\WithEnv;
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

    #[WithEnv('REVERB_SCALING_ENABLED', 'true')]
    public function testBindsRedisSharedStateWhenScalingEnabled(): void
    {
        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(RedisSharedState::class, $sharedState);
    }

    public function testCreatesSwooleTableWithConfiguredRows(): void
    {
        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(SwooleTableSharedState::class, $sharedState);
        $this->assertSame(64, $sharedState->table()->getSize());
        $this->assertSame(64, $sharedState->lockTable()->getSize());
    }

    #[WithEnv('REVERB_SCALING_ENABLED', 'true')]
    public function testScalingSharedStateDefaultsToReverbRedisConnection(): void
    {
        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(RedisSharedState::class, $sharedState);
        $this->assertSame('reverb', $this->sharedStateRedisConnection($sharedState)->getName());
    }

    #[WithEnv('REVERB_SCALING_ENABLED', 'true')]
    #[WithEnv('REVERB_SCALING_CONNECTION', 'queue')]
    public function testScalingSharedStateUsesConfiguredRedisConnection(): void
    {
        $sharedState = $this->app->make(SharedState::class);

        $this->assertInstanceOf(RedisSharedState::class, $sharedState);
        $this->assertSame('queue', $this->sharedStateRedisConnection($sharedState)->getName());
    }

    public function testSharedStateIsNotCreatedDuringBoot(): void
    {
        $this->assertTrue($this->app->bound(SharedState::class));
        $this->assertFalse($this->app->resolved(SharedState::class));
    }

    public function testSharedStateIsCreatedBeforeTheServerStarts(): void
    {
        $events = $this->bootServerProvider();

        $this->assertFalse($this->app->resolved(SharedState::class));

        $events->dispatch(new BeforeServerStart('reverb'));

        $this->assertTrue($this->app->resolved(SharedState::class));
    }

    #[WithEnv('REVERB_SCALING_ENABLED', 'true')]
    public function testRedisSharedStateIsNotResolvedBeforeTheServerStarts(): void
    {
        $events = $this->bootServerProvider();

        $events->dispatch(new BeforeServerStart('reverb'));

        $this->assertFalse($this->app->resolved(SharedState::class));
    }

    public function testRedisClusterScalingIsRejectedWithoutCreatingAPool(): void
    {
        $this->app->make('config')->set('database.redis.reverb.cluster', [
            'enabled' => true,
            'seeds' => ['127.0.0.1:6379'],
        ]);
        $this->app->instance(PoolManager::class, $poolManager = m::mock(PoolManager::class));
        $poolManager->shouldNotReceive('pool');
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
            "Reverb scaling does not support Redis Cluster. Disable 'reverb.servers.reverb.scaling.enabled' or set 'database.redis.reverb.cluster.enabled' to false.",
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

    /**
     * Boot a server provider against its own event dispatcher.
     *
     * The application dispatcher also carries the cache and rate limiter
     * listeners, which create their own tables on this event. The application
     * wiring is covered by Integration\Reverb\MultiWorkerServerTest, which
     * runs a real forked server.
     */
    protected function bootServerProvider(): Dispatcher
    {
        $this->app->instance('events', $events = new Dispatcher($this->app));

        $provider = new HypervelServerProvider($this->app, config()->array('reverb.servers.reverb'));
        $provider->register();
        $provider->boot();

        return $events;
    }

    protected function sharedStateRedisConnection(RedisSharedState $sharedState): RedisProxy
    {
        $property = new ReflectionProperty($sharedState, 'redis');

        return $property->getValue($sharedState);
    }
}
