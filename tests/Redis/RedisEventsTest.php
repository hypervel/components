<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Closure;
use Exception;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Pool\PoolOption;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\Redis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis as PhpRedis;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class RedisEventsTest extends TestCase
{
    public function testCommandFailedEventIsDispatched(): void
    {
        $exception = new Exception('Test exception');

        $mockEventDispatcher = m::mock(Dispatcher::class);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandFailed::class)
            ->andReturn(true);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandExecuted::class)
            ->andReturn(false);
        $mockEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function ($event) use ($exception) {
                return $event instanceof CommandFailed
                    && $event->command === 'get'
                    && $event->parameters === ['key']
                    && $event->exception === $exception;
            }));

        $connection = $this->createMockRedisConnection('get', null, $exception, $mockEventDispatcher);
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        try {
            $redis->get('key');
        } catch (Exception) {
            // Expected
        }
    }

    public function testCommandExecutedEventIsNotDispatchedWhenCommandFails(): void
    {
        $exception = new Exception('Test exception');

        $mockEventDispatcher = m::mock(Dispatcher::class);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandFailed::class)
            ->andReturn(true);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandExecuted::class)
            ->andReturn(true);
        $mockEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(CommandFailed::class));
        $mockEventDispatcher->shouldNotReceive('dispatch')
            ->with(m::type(CommandExecuted::class));

        $connection = $this->createMockRedisConnection('get', null, $exception, $mockEventDispatcher);
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        try {
            $redis->get('key');
        } catch (Exception) {
            // Expected
        }
    }

    public function testCommandFailedEventContainsConnectionName(): void
    {
        $exception = new Exception('Test exception');

        $mockEventDispatcher = m::mock(Dispatcher::class);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandFailed::class)
            ->andReturn(true);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandExecuted::class)
            ->andReturn(false);
        $mockEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function ($event) {
                return $event instanceof CommandFailed
                    && $event->connectionName === 'default';
            }));

        $connection = $this->createMockRedisConnection('get', null, $exception, $mockEventDispatcher);
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        try {
            $redis->get('key');
        } catch (Exception) {
            // Expected
        }
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
        $mockEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function ($event) {
                return $event instanceof CommandFailed
                    && is_float($event->time)
                    && $event->time >= 0;
            }));

        $connection = $this->createMockRedisConnection('get', null, $exception, $mockEventDispatcher);
        $connection->shouldReceive('release')->once();

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
        $mockEventDispatcher->shouldReceive('listen')
            ->once()
            ->with(CommandExecuted::class, m::type(Closure::class));

        $container = Container::getInstance();
        $container->instance('events', $mockEventDispatcher);

        $redis = $this->createRedis($this->createMockRedisConnection());

        $redis->listen(function () {
            // callback
        });
    }

    public function testListenForFailuresRegistersCallback(): void
    {
        $mockEventDispatcher = m::mock(Dispatcher::class);
        $mockEventDispatcher->shouldReceive('listen')
            ->once()
            ->with(CommandFailed::class, m::type(Closure::class));

        $container = Container::getInstance();
        $container->instance('events', $mockEventDispatcher);

        $redis = $this->createRedis($this->createMockRedisConnection());

        $redis->listenForFailures(function () {
            // callback
        });
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
        $connection->shouldReceive('release')->once();

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
        $connection->shouldReceive('release')->once();

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
        $redis->listen(function () {
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
        $redis->listenForFailures(function () {
            // callback
        });

        $this->assertTrue(true);
    }

    private function createRedis(m\MockInterface|RedisConnection $connection): Redis
    {
        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('get')->andReturn($connection);
        $pool->shouldReceive('getOption')->andReturn(m::mock(PoolOption::class));

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('default')->andReturn($pool);

        return new Redis($poolFactory);
    }

    private function createMockRedisConnection(
        string $command = 'get',
        mixed $returnValue = 'value',
        ?Throwable $exception = null,
        ?Dispatcher $eventDispatcher = null
    ): RedisConnection&m\MockInterface {
        $mockPhpRedis = m::mock(PhpRedis::class);

        if ($exception !== null) {
            $mockPhpRedis->shouldReceive($command)
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
            ->andReturnUsing(function (...$args) use ($mockPhpRedis, $command) {
                return $mockPhpRedis->{$command}(...$args);
            });

        return $mockRedisConnection;
    }
}
