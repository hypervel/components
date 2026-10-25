<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Closure;
use Exception;
use Hypervel\ConnectionPool\PoolOptions;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Events\Dispatcher as EventsDispatcher;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis as PhpRedis;
use Throwable;

class RedisEventsTest extends TestCase
{
    public function testCommandFailedEventIsDispatched(): void
    {
        $exception = new Exception('Test exception');

        $events = new EventFake(new EventsDispatcher);

        $connection = $this->createMockRedisConnection('get', null, $exception, $events);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        try {
            $redis->get('key');
            $this->fail('Expected the Redis command to fail.');
        } catch (Exception $thrown) {
            $this->assertSame($exception, $thrown);
        }

        $events->assertDispatchedOnce(CommandFailed::class);
        $events->assertDispatched(CommandFailed::class, function (CommandFailed $event) use ($exception): bool {
            return $event->command === 'get'
                && $event->parameters === ['key']
                && $event->exception === $exception;
        });
    }

    public function testCommandExecutedEventIsNotDispatchedWhenCommandFails(): void
    {
        $exception = new Exception('Test exception');

        $events = new EventFake(new EventsDispatcher);

        $connection = $this->createMockRedisConnection('get', null, $exception, $events);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        try {
            $redis->get('key');
        } catch (Exception) {
            // Expected
        }

        $events->assertDispatchedOnce(CommandFailed::class);
        $events->assertNotDispatched(CommandExecuted::class);
    }

    public function testCommandFailedEventContainsConnectionName(): void
    {
        $exception = new Exception('Test exception');

        $events = new EventFake(new EventsDispatcher);

        $connection = $this->createMockRedisConnection('get', null, $exception, $events);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        try {
            $redis->get('key');
        } catch (Exception) {
            // Expected
        }

        $events->assertDispatched(CommandFailed::class, function (CommandFailed $event): bool {
            return $event->connectionName === 'default';
        });
    }

    public function testCommandFailedEventContainsTime(): void
    {
        $exception = new Exception('Test exception');

        $mockEventDispatcher = m::mock(Dispatcher::class);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandFailed::class)
            ->andReturn(true);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandExecuted::class)
            ->andReturn(false);
        $mockEventDispatcher->expects('dispatch')
            ->with(m::on(function (mixed $event): bool {
                return $event instanceof CommandFailed
                    && is_float($event->time)
                    && $event->time >= 0;
            }));

        $connection = $this->createMockRedisConnection('get', null, $exception, $mockEventDispatcher);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        try {
            $redis->get('key');
        } catch (Exception) {
            // Expected
        }
    }

    public function testListenRegistersCallback(): void
    {
        $mockEventDispatcher = m::mock(Dispatcher::class);
        $mockEventDispatcher->expects('listen')
            ->with(CommandExecuted::class, m::type(Closure::class));

        $container = Container::getInstance();
        $container->instance('events', $mockEventDispatcher);

        $redis = $this->createRedis($this->createMockRedisConnection());

        $redis->listen(function (): void {
            // callback
        });
    }

    public function testListenForFailuresRegistersCallback(): void
    {
        $events = new EventsDispatcher;

        $container = Container::getInstance();
        $container->instance('events', $events);

        $redis = $this->createRedis($this->createMockRedisConnection());

        $redis->listenForFailures(function (): void {
            // callback
        });

        $this->assertTrue($events->hasListeners(CommandFailed::class));
    }

    public function testCommandExecutedNotDispatchedWhenNoListeners(): void
    {
        $mockEventDispatcher = m::mock(Dispatcher::class);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandFailed::class)
            ->andReturn(false);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandExecuted::class)
            ->andReturn(false);
        $mockEventDispatcher->shouldNotReceive('dispatch');

        $connection = $this->createMockRedisConnection('get', 'value', null, $mockEventDispatcher);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $redis->get('key');
    }

    public function testCommandFailedNotDispatchedWhenNoListeners(): void
    {
        $exception = new Exception('Redis error');

        $mockEventDispatcher = m::mock(Dispatcher::class);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandFailed::class)
            ->andReturn(false);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandExecuted::class)
            ->andReturn(false);
        $mockEventDispatcher->shouldNotReceive('dispatch');

        $connection = $this->createMockRedisConnection('get', null, $exception, $mockEventDispatcher);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        try {
            $redis->get('key');
        } catch (Exception) {
            // Expected
        }
    }

    public function testListenNoOpsWhenEventsUnbound(): void
    {
        $container = Container::getInstance();

        // Ensure events is not bound
        $container->forgetInstance('events');

        $redis = $this->createRedis($this->createMockRedisConnection());

        // Should not throw
        $redis->listen(function (): void {
            // callback
        });

        $this->assertTrue(true);
    }

    public function testListenForFailuresNoOpsWhenEventsUnbound(): void
    {
        $container = Container::getInstance();

        // Ensure events is not bound
        $container->forgetInstance('events');

        $redis = $this->createRedis($this->createMockRedisConnection());

        // Should not throw
        $redis->listenForFailures(function (): void {
            // callback
        });

        $this->assertTrue(true);
    }

    /**
     * Create a Redis proxy using the given connection.
     */
    private function createRedis(m\MockInterface|RedisConnection $connection): RedisProxy
    {
        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('borrow')->andReturn($connection);
        $pool->shouldReceive('getOptions')->andReturn(PoolOptions::fromArray([]));

        $poolManager = m::mock(PoolManager::class);
        $poolManager->shouldReceive('pool')->with('default')->andReturn($pool);

        return new RedisProxy(
            $poolManager,
            'default',
            m::mock(RedisSentinelFactory::class),
        );
    }

    /**
     * Create a mock Redis connection for the given command.
     */
    private function createMockRedisConnection(
        string $command = 'get',
        mixed $returnValue = 'value',
        ?Throwable $exception = null,
        ?Dispatcher $eventDispatcher = null
    ): RedisConnection&m\MockInterface {
        $mockPhpRedis = m::mock(PhpRedis::class);

        if ($exception !== null) {
            $mockPhpRedis->expects($command)
                ->with('key')
                ->andThrow($exception);
        } else {
            $mockPhpRedis->shouldReceive($command)
                ->andReturn($returnValue);
        }

        $mockRedisConnection = m::mock(PhpRedisConnection::class);
        $mockRedisConnection->shouldReceive('shouldTransform')->andReturnSelf();
        $mockRedisConnection->shouldReceive('getConnection')->andReturn($mockRedisConnection);
        $mockRedisConnection->shouldReceive('getEventDispatcher')->andReturn($eventDispatcher);
        $mockRedisConnection->shouldReceive('getName')->andReturn('default');

        $mockRedisConnection->shouldReceive($command)
            ->andReturnUsing(function (mixed ...$arguments) use ($mockPhpRedis, $command): mixed {
                return $mockPhpRedis->{$command}(...$arguments);
            });

        return $mockRedisConnection;
    }
}
