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
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Throwable;

class RedisEventsTest extends TestCase
{
    use VerifiesDoubles;

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

        $events->assertDispatchedOnce(CommandFailed::class);
        $events->assertDispatched(CommandFailed::class, function (CommandFailed $event): bool {
            return $event->connectionName === 'default';
        });
    }

    public function testCommandFailedEventContainsTime(): void
    {
        $exception = new Exception('Test exception');

        $mockEventDispatcher = Double::for(Dispatcher::class)->strict();
        $mockEventDispatcher->allows('hasListeners')
            ->with(CommandFailed::class)
            ->returns(true);
        $mockEventDispatcher->allows('hasListeners')
            ->with(CommandExecuted::class)
            ->returns(false);
        $mockEventDispatcher->expects('dispatch')
            ->with(Argument::satisfies(function (mixed $event): bool {
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
        $mockEventDispatcher = Double::for(Dispatcher::class)->strict();
        $mockEventDispatcher->expects('listen')
            ->with(CommandExecuted::class, Argument::type(Closure::class));

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
        $mockEventDispatcher = Double::for(Dispatcher::class)->strict();
        $mockEventDispatcher->allows('hasListeners')
            ->with(CommandFailed::class)
            ->returns(false);
        $mockEventDispatcher->allows('hasListeners')
            ->with(CommandExecuted::class)
            ->returns(false);
        $mockEventDispatcher->expects('dispatch')->never();

        $connection = $this->createMockRedisConnection('get', 'value', null, $mockEventDispatcher);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $redis->get('key');
    }

    public function testCommandFailedNotDispatchedWhenNoListeners(): void
    {
        $exception = new Exception('Redis error');

        $mockEventDispatcher = Double::for(Dispatcher::class)->strict();
        $mockEventDispatcher->allows('hasListeners')
            ->with(CommandFailed::class)
            ->returns(false);
        $mockEventDispatcher->allows('hasListeners')
            ->with(CommandExecuted::class)
            ->returns(false);
        $mockEventDispatcher->expects('dispatch')->never();

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

        $redis->listen(fn () => $this->fail('Registering a listener must not invoke it.'));

        $this->assertFalse($container->bound('events'));
    }

    public function testListenForFailuresNoOpsWhenEventsUnbound(): void
    {
        $container = Container::getInstance();

        // Ensure events is not bound
        $container->forgetInstance('events');

        $redis = $this->createRedis($this->createMockRedisConnection());

        $redis->listenForFailures(fn () => $this->fail('Registering a listener must not invoke it.'));

        $this->assertFalse($container->bound('events'));
    }

    /**
     * Create a Redis proxy using the given connection.
     */
    private function createRedis(RedisConnection $connection): RedisProxy
    {
        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns($connection);
        $pool->allows('getOptions')->returns(PoolOptions::fromArray([]));

        $poolManager = Double::for(PoolManager::class)->passthru();
        $poolManager->allows('pool')->with('default')->returns($pool);

        return new RedisProxy(
            $poolManager,
            'default',
            Double::for(RedisSentinelFactory::class)->strict(),
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
    ): RedisConnection&DoubleInterface {
        $mockRedisConnection = Double::for(RedisEventsConnection::class)->strict();
        $mockRedisConnection->allows('shouldTransform')->returns($mockRedisConnection);
        $mockRedisConnection->allows('getConnection')->returns($mockRedisConnection);
        $mockRedisConnection->allows('getEventDispatcher')->returns($eventDispatcher);
        $mockRedisConnection->allows('getName')->returns('default');

        if ($exception !== null) {
            $mockRedisConnection->expects($command)->with('key')->throws($exception);
        } else {
            $mockRedisConnection->allows($command)->with('key')->returns($returnValue);
        }

        return $mockRedisConnection;
    }
}

// Declare the dynamic command so Double can enforce its arguments and call count.
abstract class RedisEventsConnection extends PhpRedisConnection
{
    /**
     * Get the value stored at the key.
     */
    abstract public function get(string $key): mixed;
}
