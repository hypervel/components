<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Exception;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\Pool\PoolOption;
use Hyperf\Redis\Event\CommandExecuted;
use Hyperf\Redis\Pool\PoolFactory;
use Hyperf\Redis\Pool\RedisPool;
use Hyperf\Redis\Redis as HyperfRedis;
use Hypervel\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\Redis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\Redis\Stubs\NativeRedisStub;
use Hypervel\Tests\Redis\Stubs\RedisClientConnectionStub;
use Hypervel\Tests\TestCase;
use Mockery;
use Psr\EventDispatcher\EventDispatcherInterface;
use Redis as PhpRedis;
use RuntimeException;
use Throwable;

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
        Context::destroy('redis.connection.default.eager_release');
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

    public function testCallbackPipelineExecutesAndReleasesConnectionImmediately(): void
    {
        $pipeline = Mockery::mock(PhpRedis::class);
        $pipeline->shouldReceive('set')->with('pipeline-key', 'value')->once()->andReturnSelf();
        $pipeline->shouldReceive('exec')->once()->andReturn(['QUEUED']);

        $mockRedisConnection = $this->createMockRedisConnection('pipeline', $pipeline);
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $result = $redis->pipeline(function (PhpRedis $pipe) {
            $pipe->set('pipeline-key', 'value');
        });

        $this->assertSame(['QUEUED'], $result);
        $this->assertFalse(Context::has('redis.connection.default'));
        $this->assertFalse(Context::has('redis.connection.default.eager_release'));
    }

    public function testCallbackTransactionExecutesAndReleasesConnectionImmediately(): void
    {
        $transaction = Mockery::mock(PhpRedis::class);
        $transaction->shouldReceive('set')->with('transaction-key', 'value')->once()->andReturnSelf();
        $transaction->shouldReceive('exec')->once()->andReturn([true]);

        $mockRedisConnection = $this->createMockRedisConnection('multi', $transaction);
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $result = $redis->transaction(function (PhpRedis $pipe) {
            $pipe->set('transaction-key', 'value');
        });

        $this->assertSame([true], $result);
        $this->assertFalse(Context::has('redis.connection.default'));
        $this->assertFalse(Context::has('redis.connection.default.eager_release'));
    }

    public function testCallbackPipelineExceptionCleansUpContextAndReleasesConnection(): void
    {
        $pipeline = Mockery::mock(PhpRedis::class);
        $pipeline->shouldNotReceive('exec');

        $mockRedisConnection = $this->createMockRedisConnection('pipeline', $pipeline);
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->pipeline(function () {
                throw new RuntimeException('Callback failed');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Callback failed', $exception->getMessage());
        }

        $this->assertFalse(Context::has('redis.connection.default'));
        $this->assertFalse(Context::has('redis.connection.default.eager_release'));
    }

    public function testCallbackPipelineExecExceptionCleansUpContextAndReleasesConnection(): void
    {
        $pipeline = Mockery::mock(PhpRedis::class);
        $pipeline->shouldReceive('exec')->once()->andThrow(new RuntimeException('Exec failed'));

        $mockRedisConnection = $this->createMockRedisConnection('pipeline', $pipeline);
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->pipeline(static function () {
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Exec failed', $exception->getMessage());
        }

        $this->assertFalse(Context::has('redis.connection.default'));
        $this->assertFalse(Context::has('redis.connection.default.eager_release'));
    }

    public function testCallbackPipelinePreservesExistingContextConnection(): void
    {
        $pipeline = Mockery::mock(PhpRedis::class);
        $pipeline->shouldReceive('exec')->once()->andReturn([]);

        $mockRedisConnection = $this->createMockRedisConnection('pipeline', $pipeline);
        $mockRedisConnection->shouldReceive('release')->never();
        Context::set('redis.connection.default', $mockRedisConnection);

        $redis = $this->createRedis($mockRedisConnection);

        $result = $redis->pipeline(static function () {
        });

        $this->assertSame([], $result);
        $this->assertSame($mockRedisConnection, Context::get('redis.connection.default'));
        $this->assertFalse(Context::has('redis.connection.default.eager_release'));
    }

    public function testTransformationIsDisabledBetweenManualTransactionCalls(): void
    {
        $transformEnabled = false;
        $mockRedisConnection = Mockery::mock(RedisConnection::class);
        $mockRedisConnection->shouldReceive('shouldTransform')
            ->andReturnUsing(function (bool $enabled = true) use (&$transformEnabled, $mockRedisConnection) {
                $transformEnabled = $enabled;

                return $mockRedisConnection;
            });
        $mockRedisConnection->shouldReceive('getConnection')->times(3)->andReturnSelf();
        $mockRedisConnection->shouldReceive('getEventDispatcher')->times(3)->andReturnNull();
        $mockRedisConnection->shouldReceive('multi')->once()->andReturnTrue();
        $mockRedisConnection->shouldReceive('set')->with('key', 'value', 'EX', 10)->once()->andReturnTrue();
        $mockRedisConnection->shouldReceive('exec')->once()->andReturn([true]);
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $redis->multi();
        $this->assertFalse($transformEnabled);

        $redis->set('key', 'value', 'EX', 10);
        $this->assertFalse($transformEnabled);

        $this->assertSame([true], $redis->exec());
        $this->assertFalse($transformEnabled);
        $this->assertSame($mockRedisConnection, Context::get('redis.connection.default'));
    }

    public function testTransformationIsDisabledBeforeCommandEventDispatch(): void
    {
        $transformEnabled = false;
        $mockEventDispatcher = Mockery::mock(EventDispatcherInterface::class);
        $mockEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(function (CommandExecuted $event) use (&$transformEnabled) {
                return $event->command === 'get' && $transformEnabled === false;
            }));

        $mockRedisConnection = Mockery::mock(RedisConnection::class);
        $mockRedisConnection->shouldReceive('shouldTransform')
            ->andReturnUsing(function (bool $enabled = true) use (&$transformEnabled, $mockRedisConnection) {
                $transformEnabled = $enabled;

                return $mockRedisConnection;
            });
        $mockRedisConnection->shouldReceive('getConnection')->once()->andReturnSelf();
        $mockRedisConnection->shouldReceive('getEventDispatcher')->once()->andReturn($mockEventDispatcher);
        $mockRedisConnection->shouldReceive('get')->with('key')->once()->andReturn('value');
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $this->assertSame('value', $redis->get('key'));
        $this->assertFalse($transformEnabled);
    }

    public function testTransformationIsDisabledAfterCommandException(): void
    {
        $transformEnabled = false;
        $mockRedisConnection = Mockery::mock(RedisConnection::class);
        $mockRedisConnection->shouldReceive('shouldTransform')
            ->andReturnUsing(function (bool $enabled = true) use (&$transformEnabled, $mockRedisConnection) {
                $transformEnabled = $enabled;

                return $mockRedisConnection;
            });
        $mockRedisConnection->shouldReceive('getConnection')->once()->andReturnSelf();
        $mockRedisConnection->shouldReceive('getEventDispatcher')->once()->andReturnNull();
        $mockRedisConnection->shouldReceive('get')->once()->andThrow(new RuntimeException('Command failed'));
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->get('key');
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Command failed', $exception->getMessage());
        }

        $this->assertFalse($transformEnabled);
    }

    public function testNativeHyperfCommandsUseNativeArgumentsAfterHypervelPipeline(): void
    {
        $nativeRedis = new NativeRedisStub();
        $nativeRedis->execResult = [true];

        $pool = Mockery::mock(RedisPool::class);
        $pool->shouldReceive('getOption')->times(3)->andReturn(new PoolOption(events: []));
        $pool->shouldReceive('release')->times(3);

        $connection = new RedisClientConnectionStub(
            new Container(new DefinitionSource([])),
            $pool,
            []
        );
        $connection->setActiveConnection($nativeRedis);
        $pool->shouldReceive('get')->times(3)->andReturn($connection);

        $factory = Mockery::mock(PoolFactory::class);
        $factory->shouldReceive('getPool')->with('default')->times(3)->andReturn($pool);

        $hypervel = new Redis($factory);
        $hyperf = new HyperfRedis($factory);

        $this->assertSame([true], $hypervel->pipeline(function (PhpRedis $pipe) {
            $pipe->set('pipeline-key', 'value');
        }));
        $this->assertFalse($connection->getShouldTransform());
        $this->assertFalse(Context::has('redis.connection.default'));

        $this->assertTrue($hyperf->set('lock-key', 'owner', ['EX' => 10, 'NX']));
        $this->assertSame('expected', $hyperf->eval('return ARGV[1]', ['expected'], 0));
        $this->assertSame([
            ['pipeline'],
            ['set', 'pipeline-key', 'value', null],
            ['exec'],
            ['set', 'lock-key', 'owner', ['EX' => 10, 'NX']],
            ['eval', 'return ARGV[1]', ['expected'], 0],
        ], $nativeRedis->calls);
    }

    public function testNativeHyperfCommandInEventListenerSeesTransformationDisabled(): void
    {
        $nativeRedis = new NativeRedisStub();
        $nativeRedis->getResult = 'value';

        $pool = Mockery::mock(RedisPool::class);
        $pool->shouldReceive('getOption')->once()->andReturn(new PoolOption(events: []));
        $pool->shouldReceive('release')->once();

        $factory = Mockery::mock(PoolFactory::class);
        $hyperf = new HyperfRedis($factory);

        $dispatcher = Mockery::mock(EventDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatch')
            ->twice()
            ->andReturnUsing(function (CommandExecuted $event) use ($hyperf) {
                if ($event->command === 'get') {
                    $this->assertTrue($hyperf->set('lock-key', 'owner', ['EX' => 10, 'NX']));
                }

                return $event;
            });

        $connection = new RedisClientConnectionStub(
            new Container(new DefinitionSource([])),
            $pool,
            []
        );
        $connection
            ->setActiveConnection($nativeRedis)
            ->setEventDispatcher($dispatcher);
        Context::set('redis.connection.default', $connection);

        $hypervel = new Redis($factory);

        $this->assertSame('value', $hypervel->get('key'));
        $this->assertFalse($connection->getShouldTransform());
        $this->assertSame([
            ['get', 'key'],
            ['set', 'lock-key', 'owner', ['EX' => 10, 'NX']],
        ], $nativeRedis->calls);

        Context::destroy('redis.connection.default');
        $connection->release();
    }

    public function testRegularCommandDoesNotStoreConnectionInContext(): void
    {
        $mockRedisConnection = $this->createMockRedisConnection();
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $redis->get('key');

        $this->assertNull(Context::get('redis.connection.default'));
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
