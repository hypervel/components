<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Exception;
use Hyperf\Pool\PoolOption;
use Hyperf\Redis\Event\CommandExecuted;
use Hyperf\Redis\Pool\PoolFactory;
use Hyperf\Redis\Pool\RedisPool;
use Hypervel\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\Redis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use Mockery;
use Psr\EventDispatcher\EventDispatcherInterface;
use Redis as PhpRedis;
use Throwable;

enum RedisTestStringBackedConnection: string
{
    case Default = 'default';
    case Cache = 'cache';
}

enum RedisTestIntBackedConnection: int
{
    case Primary = 1;
    case Replica = 2;
}

enum RedisTestUnitConnection
{
    case default;
    case cache;
}

/**
 * @internal
 * @coversNothing
 */
class RedisTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function tearDown(): void
    {
        parent::tearDown();
        Context::destroy('redis.connection.default');
    }

    public function testSuccessfulCommandReleasesConnection(): void
    {
        $mockRedisConnection = $this->createMockRedisConnection();
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $result = $redis->get('key');

        $this->assertEquals('value', $result);
    }

    public function testSuccessfulCommandWithContextConnectionDoesNotReleaseConnection(): void
    {
        $mockRedisConnection = $this->createMockRedisConnection();
        $mockRedisConnection->shouldReceive('release')->never();

        // Pre-set context connection
        Context::set('redis.connection.default', $mockRedisConnection);

        $redis = $this->createRedis($mockRedisConnection);

        $result = $redis->get('key');

        $this->assertEquals('value', $result);
    }

    public function testSuccessfulMultiCommandStoresConnectionInContext(): void
    {
        $mockRedisConnection = $this->createMockRedisConnection('multi', true);
        // Connection will be released via defer() when coroutine ends
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $result = $redis->multi();

        $this->assertTrue($result);
        $this->assertSame($mockRedisConnection, Context::get('redis.connection.default'));
    }

    public function testSuccessfulSelectCommandStoresConnectionInContextAndSetsDatabase(): void
    {
        $mockRedisConnection = $this->createMockRedisConnection('select', true);
        // Connection will be released via defer() when coroutine ends
        $mockRedisConnection->shouldReceive('release')->once();
        $mockRedisConnection->shouldReceive('setDatabase')->with(2)->once();

        $redis = $this->createRedis($mockRedisConnection);

        $result = $redis->select(2);

        $this->assertTrue($result);
        $this->assertSame($mockRedisConnection, Context::get('redis.connection.default'));
    }

    public function testExceptionPropagatesToCaller(): void
    {
        $expectedException = new Exception('Redis error');

        $mockRedisConnection = $this->createMockRedisConnection('get', null, $expectedException);
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Redis error');

        $redis->get('key');
    }

    public function testExceptionWithContextConnectionDoesNotReleaseConnection(): void
    {
        $expectedException = new Exception('Redis error');

        $mockRedisConnection = $this->createMockRedisConnection('get', null, $expectedException);
        $mockRedisConnection->shouldReceive('release')->never();

        // Pre-set context connection
        Context::set('redis.connection.default', $mockRedisConnection);

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->get('key');
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Redis error', $e->getMessage());
        }
    }

    public function testExceptionWithSameConnectionCommandReleasesConnectionInsteadOfStoring(): void
    {
        $expectedException = new Exception('Multi failed');

        $mockRedisConnection = $this->createMockRedisConnection('multi', null, $expectedException);
        // On error, connection should be released, NOT stored in context
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->multi();
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Multi failed', $e->getMessage());
        }

        // Connection should NOT be stored in context on error
        $this->assertNull(Context::get('redis.connection.default'));
    }

    public function testEventDispatchedOnSuccess(): void
    {
        $mockEventDispatcher = Mockery::mock(EventDispatcherInterface::class);
        $mockEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(function (CommandExecuted $event) {
                return $event->command === 'get'
                    && $event->parameters === ['key']
                    && $event->result === 'value'
                    && $event->throwable === null;
            }));

        $mockRedisConnection = $this->createMockRedisConnection('get', 'value', null, $mockEventDispatcher);
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $redis->get('key');
    }

    public function testEventDispatchedOnErrorWithExceptionInfo(): void
    {
        $expectedException = new Exception('Redis error');

        $mockEventDispatcher = Mockery::mock(EventDispatcherInterface::class);
        $mockEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(function (CommandExecuted $event) use ($expectedException) {
                return $event->command === 'get'
                    && $event->parameters === ['key']
                    && $event->result === null
                    && $event->throwable === $expectedException;
            }));

        $mockRedisConnection = $this->createMockRedisConnection('get', null, $expectedException, $mockEventDispatcher);
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->get('key');
        } catch (Exception) {
            // Expected
        }
    }

    public function testPipelineCommandStoresConnectionInContext(): void
    {
        $mockRedisConnection = $this->createMockRedisConnection('pipeline', true);
        // Connection will be released via defer() when coroutine ends
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $result = $redis->pipeline();

        $this->assertTrue($result);
        $this->assertSame($mockRedisConnection, Context::get('redis.connection.default'));
    }

    public function testRegularCommandDoesNotStoreConnectionInContext(): void
    {
        $mockRedisConnection = $this->createMockRedisConnection();
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $redis->get('key');

        $this->assertNull(Context::get('redis.connection.default'));
    }

    public function testConnectionAcceptsStringBackedEnum(): void
    {
        $mockRedisProxy = Mockery::mock(RedisProxy::class);

        $mockRedisFactory = Mockery::mock(RedisFactory::class);
        $mockRedisFactory->shouldReceive('get')
            ->with('default')
            ->once()
            ->andReturn($mockRedisProxy);

        $mockContainer = Mockery::mock(\Hypervel\Container\Contracts\Container::class);
        $mockContainer->shouldReceive('get')
            ->with(RedisFactory::class)
            ->andReturn($mockRedisFactory);

        \Hypervel\Context\ApplicationContext::setContainer($mockContainer);

        $redis = new Redis(Mockery::mock(PoolFactory::class));

        $result = $redis->connection(RedisTestStringBackedConnection::Default);

        $this->assertSame($mockRedisProxy, $result);
    }

    public function testConnectionAcceptsUnitEnum(): void
    {
        $mockRedisProxy = Mockery::mock(RedisProxy::class);

        $mockRedisFactory = Mockery::mock(RedisFactory::class);
        $mockRedisFactory->shouldReceive('get')
            ->with('default')
            ->once()
            ->andReturn($mockRedisProxy);

        $mockContainer = Mockery::mock(\Hypervel\Container\Contracts\Container::class);
        $mockContainer->shouldReceive('get')
            ->with(RedisFactory::class)
            ->andReturn($mockRedisFactory);

        \Hypervel\Context\ApplicationContext::setContainer($mockContainer);

        $redis = new Redis(Mockery::mock(PoolFactory::class));

        $result = $redis->connection(RedisTestUnitConnection::default);

        $this->assertSame($mockRedisProxy, $result);
    }

    public function testConnectionAcceptsIntBackedEnum(): void
    {
        $mockRedisProxy = Mockery::mock(RedisProxy::class);

        $mockRedisFactory = Mockery::mock(RedisFactory::class);
        // Int value 1 should be cast to string '1'
        $mockRedisFactory->shouldReceive('get')
            ->with('1')
            ->once()
            ->andReturn($mockRedisProxy);

        $mockContainer = Mockery::mock(\Hypervel\Container\Contracts\Container::class);
        $mockContainer->shouldReceive('get')
            ->with(RedisFactory::class)
            ->andReturn($mockRedisFactory);

        \Hypervel\Context\ApplicationContext::setContainer($mockContainer);

        $redis = new Redis(Mockery::mock(PoolFactory::class));

        $result = $redis->connection(RedisTestIntBackedConnection::Primary);

        $this->assertSame($mockRedisProxy, $result);
    }

    /**
     * Create a mock Redis connection with configurable behavior.
     */
    protected function createMockRedisConnection(
        string $command = 'get',
        mixed $returnValue = 'value',
        ?Throwable $exception = null,
        ?EventDispatcherInterface $eventDispatcher = null
    ): RedisConnection&Mockery\MockInterface {
        $mockPhpRedis = Mockery::mock(PhpRedis::class);

        if ($exception !== null) {
            $mockPhpRedis->shouldReceive($command)
                ->andThrow($exception);
        } else {
            $mockPhpRedis->shouldReceive($command)
                ->andReturn($returnValue);
        }

        $mockRedisConnection = Mockery::mock(RedisConnection::class);
        $mockRedisConnection->shouldReceive('shouldTransform')->andReturnSelf();
        $mockRedisConnection->shouldReceive('getConnection')->andReturn($mockRedisConnection);
        $mockRedisConnection->shouldReceive('getEventDispatcher')->andReturn($eventDispatcher);

        // Forward the command call to the mock PHP Redis
        $mockRedisConnection->shouldReceive($command)
            ->andReturnUsing(function (...$args) use ($mockPhpRedis, $command) {
                return $mockPhpRedis->{$command}(...$args);
            });

        return $mockRedisConnection;
    }

    /**
     * Create a Redis instance with the given mock connection.
     */
    protected function createRedis(RedisConnection $mockConnection): Redis
    {
        $mockPool = Mockery::mock(RedisPool::class);
        $mockPool->shouldReceive('get')->andReturn($mockConnection);
        $mockPool->shouldReceive('getOption')->andReturn(Mockery::mock(PoolOption::class));

        $mockPoolFactory = Mockery::mock(PoolFactory::class);
        $mockPoolFactory->shouldReceive('getPool')->with('default')->andReturn($mockPool);

        return new Redis($mockPoolFactory);
    }
}
