<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use BadMethodCallException;
use Exception;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Pool\PoolOption;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\Exceptions\InvalidRedisConnectionException;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Redis\Subscriber\CommandBuilder;
use Hypervel\Redis\Subscriber\Message;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Redis as PhpRedis;
use RedisCluster;
use RedisClusterException;
use RedisException;
use RedisSentinel;
use ReflectionClass;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel as SwooleChannel;
use Throwable;

use function Hypervel\Coroutine\go;

/**
 * Tests for RedisProxy — the pool-aware connection proxy.
 *
 * We mock RedisConnection entirely and verify the proxy properly
 * manages connections, context storage, and command proxying.
 */
class RedisProxyTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        CoroutineContext::forget(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default');
        CoroutineContext::forget(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'cache');
    }

    public function testCommandIsProxiedToConnection(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->once()->with('foo')->andReturn('bar');
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->get('foo');

        $this->assertSame('bar', $result);
    }

    public function testMacroRegistrationMethodsDoNotCheckoutRedis(): void
    {
        $factory = m::mock(PoolFactory::class);
        $factory->expects('getPool')->never();
        $redis = new RedisProxy($factory, 'default', $this->sentinelFactory());

        $redis->macro('greeting', fn (string $name) => "Hello {$name}");
        $redis->mixin(new class {
            protected function farewell(): callable
            {
                return fn (string $name) => "Goodbye {$name}";
            }
        });

        $this->assertTrue($redis->hasMacro('greeting'));
        $this->assertTrue($redis->hasMacro('farewell'));

        $redis->flushMacros();

        $this->assertFalse($redis->hasMacro('greeting'));
        $this->assertFalse($redis->hasMacro('farewell'));
    }

    public function testMixedCaseSubscriptionsUseDedicatedProxyRoute(): void
    {
        $factory = m::mock(PoolFactory::class);
        $factory->expects('getPool')->never();
        $redis = new class($factory, 'default', $this->sentinelFactory()) extends RedisProxy {
            public array $subscriptions = [];

            protected function handleSubscribe(string $name, array $arguments): void
            {
                $this->subscriptions[] = [$name, $arguments];
            }
        };
        $callback = static function (): void {
        };

        $redis->__call('SUBSCRIBE', [['channel'], $callback]);
        $redis->__call('PSUBSCRIBE', [['channel:*'], $callback]);

        $this->assertSame(
            [
                ['subscribe', [['channel'], $callback]],
                ['psubscribe', [['channel:*'], $callback]],
            ],
            $redis->subscriptions,
        );
    }

    public function testSubscriberLoopDeliversMessagesUntilTheChannelCloses(): void
    {
        $message = new Message('updates', 'payload');
        $channel = m::mock(Channel::class);
        $channel->expects('pop')->twice()->andReturn($message, false);
        $channel->expects('isCanceled')->andReturnFalse();
        $subscriber = $this->mockSubscriber($channel);
        $redis = $this->createRedisWithSubscriber($subscriber);
        $received = [];

        $redis->subscribe('updates', function (string $payload, string $channel) use (&$received): void {
            $received[] = [$payload, $channel];
        });

        $this->assertSame([['payload', 'updates']], $received);
    }

    public function testSubscriberLoopConvertsNonThrowingMessageWaitCancellation(): void
    {
        $messageChannel = new Channel(1);
        $ready = new SwooleChannel(1);
        $subscriber = m::mock(Subscriber::class);
        $subscriber->closed = false;
        $subscriber->expects('subscribe')->with('updates');
        $subscriber->expects('channel')->andReturnUsing(function () use ($messageChannel, $ready): Channel {
            $ready->push(true);

            return $messageChannel;
        });
        $subscriber->expects('close');
        $redis = $this->createRedisWithSubscriber($subscriber);
        $captured = null;
        $coroutineId = Coroutine::create(function () use ($redis, &$captured): void {
            try {
                $redis->subscribe('updates', static function (): void {
                });
            } catch (Throwable $exception) {
                $captured = $exception;
            }
        });

        try {
            $this->assertTrue($ready->pop());
            $this->assertTrue(EngineCoroutine::cancelById($coroutineId));
            $this->assertInstanceOf(CanceledException::class, $captured);
            $this->assertSame('The Redis subscriber message wait was canceled.', $captured->getMessage());
        } finally {
            $messageChannel->close();
        }
    }

    public function testSubscriberLoopPreservesCallbackCancellationOverOrdinaryCloseFailure(): void
    {
        $cancellation = new CanceledException('callback canceled');
        $channel = m::mock(Channel::class);
        $channel->expects('pop')->andReturn(new Message('updates', 'payload'));
        $subscriber = $this->mockSubscriber($channel, new RuntimeException('close failed'));
        $redis = $this->createRedisWithSubscriber($subscriber);

        try {
            $redis->subscribe('updates', static function () use ($cancellation): void {
                throw $cancellation;
            });
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testSubscriberLoopLetsCloseCancellationSupersedeOrdinaryCallbackFailure(): void
    {
        $cancellation = new CanceledException('close canceled');
        $channel = m::mock(Channel::class);
        $channel->expects('pop')->andReturn(new Message('updates', 'payload'));
        $subscriber = $this->mockSubscriber($channel, $cancellation);
        $redis = $this->createRedisWithSubscriber($subscriber);

        try {
            $redis->subscribe('updates', static function (): void {
                throw new RuntimeException('callback failed');
            });
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testSubscriberLoopPreservesCallbackCancellationOverCloseCancellation(): void
    {
        $callbackCancellation = new CanceledException('callback canceled');
        $closeCancellation = new CanceledException('close canceled');
        $channel = m::mock(Channel::class);
        $channel->expects('pop')->andReturn(new Message('updates', 'payload'));
        $subscriber = $this->mockSubscriber($channel, $closeCancellation);
        $redis = $this->createRedisWithSubscriber($subscriber);

        try {
            $redis->subscribe('updates', static function () use ($callbackCancellation): void {
                throw $callbackCancellation;
            });
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($callbackCancellation, $exception);
        }
    }

    public function testSubscriberLoopRetainsOrdinaryCloseFailurePrecedence(): void
    {
        $closeFailure = new RuntimeException('close failed');
        $channel = m::mock(Channel::class);
        $channel->expects('pop')->andReturn(new Message('updates', 'payload'));
        $subscriber = $this->mockSubscriber($channel, $closeFailure);
        $redis = $this->createRedisWithSubscriber($subscriber);

        try {
            $redis->subscribe('updates', static function (): void {
                throw new RuntimeException('callback failed');
            });
            $this->fail('Expected the close failure to escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame($closeFailure, $exception);
        }
    }

    public function testConnectionBoundMethodsCannotBeCalledThroughProxy(): void
    {
        $redis = new RedisProxy(
            m::mock(PoolFactory::class),
            'default',
            $this->sentinelFactory(),
        );
        $methods = (new ReflectionClass(RedisProxy::class))
            ->getReflectionConstant('CONNECTION_BOUND_METHODS')
            ?->getValue();
        $this->assertIsArray($methods);

        foreach ($methods as $method) {
            $method = strtoupper($method);

            try {
                $redis->{$method}();
                $this->fail(sprintf('Method [%s] was not blocked.', $method));
            } catch (BadMethodCallException $exception) {
                $this->assertStringContainsString(
                    sprintf('Redis connection method [%s] must be called on a held Redis connection.', $method),
                    $exception->getMessage()
                );
            }
        }
    }

    public function testMixedCaseMultiStoresConnectionInContext(): void
    {
        $multiInstance = m::mock(PhpRedis::class);

        $connection = $this->mockConnection();
        $connection->shouldReceive('multi')->once()->andReturn($multiInstance);
        // Connection is released via defer() at end of coroutine
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->__call('MULTI', []);

        $this->assertSame($multiInstance, $result);
        // Connection should be stored in context
        $this->assertTrue(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testMixedCasePipelineStoresConnectionInContext(): void
    {
        $pipelineInstance = m::mock(PhpRedis::class);

        $connection = $this->mockConnection();
        $connection->shouldReceive('pipeline')->once()->andReturn($pipelineInstance);
        // Connection is released via defer() at end of coroutine
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->__call('PIPELINE', []);

        $this->assertSame($pipelineInstance, $result);
        $this->assertTrue(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testMixedCaseSelectStoresConnectionInContext(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('select')->once()->with(1)->andReturn(true);
        // Connection is released via defer() at end of coroutine
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->__call('SELECT', [1]);

        $this->assertTrue($result);
        $this->assertTrue(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testConnectionIsStoredInContextForSelectZeroDatabase(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('select')->once()->with(0)->andReturn(true);
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->select(0);

        $this->assertTrue($result);
        $this->assertTrue(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testMixedCaseWatchStoresConnectionInContext(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('watch')->once()->with('key')->andReturn(true);
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $this->assertTrue($redis->__call('WATCH', ['key']));
        $this->assertSame(
            $connection,
            CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default')
        );
    }

    public function testNativeDiscardDoesNotInvokePoolDiscard(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('discardTransaction')->once()->andReturn(true);
        $connection->shouldReceive('discard')->never();
        $connection->shouldReceive('release')->never();
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $connection);

        $redis = $this->createRedis($connection);

        $this->assertTrue($redis->__call('DISCARD', []));
        $this->assertSame(
            $connection,
            CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default')
        );
    }

    public function testRepeatedCallbackTransactionsRegisterOneTerminalRelease(): void
    {
        $firstTransaction = m::mock(PhpRedis::class);
        $firstTransaction->expects('exec')->andReturn([]);
        $secondTransaction = m::mock(PhpRedis::class);
        $secondTransaction->expects('exec')->andReturn([]);

        $connection = $this->mockConnection();
        $connection->expects('multi')->twice()->andReturn($firstTransaction, $secondTransaction);
        $connection->expects('release')->twice();

        $redis = $this->createCountingRedis($connection);
        $completed = new Channel(1);
        go(static function () use ($redis, $completed): void {
            Coroutine::defer(static function () use ($completed): void {
                $completed->push(true);
            });

            $redis->transaction(static function (): void {
            });
            $redis->transaction(static function (): void {
            });
        });

        $this->assertTrue($completed->pop(1.0));
        $this->assertSame(1, $redis->contextAbsentReleaseCalls);
    }

    public function testExistingTerminalReleaseOwnsALaterRawPin(): void
    {
        $transaction = m::mock(PhpRedis::class);
        $transaction->expects('exec')->andReturn([]);
        $rawTransaction = m::mock(PhpRedis::class);

        $callbackConnection = $this->mockConnection();
        $callbackConnection->expects('multi')->andReturn($transaction);
        $callbackConnection->expects('release');
        $rawConnection = $this->mockConnection();
        $rawConnection->expects('multi')->andReturn($rawTransaction);
        $rawConnection->expects('release');

        $redis = $this->createCountingRedis($callbackConnection, $rawConnection);
        $completed = new Channel(1);
        go(static function () use ($redis, $completed): void {
            Coroutine::defer(static function () use ($completed): void {
                $completed->push(true);
            });

            $redis->transaction(static function (): void {
            });
            $redis->multi();
        });

        $this->assertTrue($completed->pop(1.0));
        $this->assertSame(0, $redis->contextAbsentReleaseCalls);
    }

    public function testCopiedSiblingContextsBorrowDistinctPinnedConnectionsAndOwnTheirReleases(): void
    {
        $parentTransaction = m::mock(PhpRedis::class);
        $firstChildTransaction = m::mock(PhpRedis::class);
        $secondChildTransaction = m::mock(PhpRedis::class);

        $parentConnection = $this->mockConnection();
        $parentConnection->expects('multi')->andReturn($parentTransaction);
        $parentConnection->expects('release');
        $firstChildConnection = $this->mockConnection();
        $firstChildConnection->expects('multi')->andReturn($firstChildTransaction);
        $firstChildConnection->expects('release');
        $secondChildConnection = $this->mockConnection();
        $secondChildConnection->expects('multi')->andReturn($secondChildTransaction);
        $secondChildConnection->expects('release');

        $redis = $this->createCountingRedis(
            $parentConnection,
            $firstChildConnection,
            $secondChildConnection,
        );
        $redis->multi();

        $childrenReady = new Channel(2);
        $releaseChildren = new Channel(2);
        $childCoroutineIds = [
            go(static function () use ($redis, $childrenReady, $releaseChildren): void {
                $redis->multi();
                $childrenReady->push(CoroutineContext::get(
                    RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default',
                ));
                $releaseChildren->pop();
            }, copyContext: true),
            go(static function () use ($redis, $childrenReady, $releaseChildren): void {
                $redis->multi();
                $childrenReady->push(CoroutineContext::get(
                    RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default',
                ));
                $releaseChildren->pop();
            }, copyContext: true),
        ];

        try {
            $firstBorrowedConnection = $childrenReady->pop(1.0);
            $secondBorrowedConnection = $childrenReady->pop(1.0);

            $this->assertSame($parentConnection, CoroutineContext::get(
                RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default',
            ));
            $this->assertNotSame($parentConnection, $firstBorrowedConnection);
            $this->assertNotSame($parentConnection, $secondBorrowedConnection);
            $this->assertNotSame($firstBorrowedConnection, $secondBorrowedConnection);
        } finally {
            $releaseChildren->push(true);
            $releaseChildren->push(true);
            Coroutine::join($childCoroutineIds, 1.0);
        }

        foreach ($childCoroutineIds as $childCoroutineId) {
            $this->assertFalse(Coroutine::exists($childCoroutineId));
        }
    }

    public function testDetachedCopiedChildBorrowsAfterItsParentReleases(): void
    {
        $parentTransaction = m::mock(PhpRedis::class);
        $childTransaction = m::mock(PhpRedis::class);
        $parentReleased = new Channel(1);
        $childReleased = new Channel(1);

        $parentConnection = $this->mockConnection();
        $parentConnection->expects('multi')->andReturn($parentTransaction);
        $parentConnection->expects('release')->andReturnUsing(static function () use ($parentReleased): void {
            $parentReleased->push(true);
        });
        $childConnection = $this->mockConnection();
        $childConnection->expects('multi')->andReturn($childTransaction);
        $childConnection->expects('release')->andReturnUsing(static function () use ($childReleased): void {
            $childReleased->push(true);
        });

        $redis = $this->createCountingRedis($parentConnection, $childConnection);
        $allowChild = new Channel(1);
        $childBorrowed = new Channel(1);
        $childCoroutineId = new Channel(1);

        $parentCoroutineId = go(static function () use ($redis, $allowChild, $childBorrowed, $childCoroutineId): void {
            $redis->multi();
            $childCoroutineId->push(go(static function () use ($redis, $allowChild, $childBorrowed): void {
                $allowChild->pop();
                $redis->multi();
                $childBorrowed->push(CoroutineContext::get(
                    RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default',
                ));
            }, copyContext: true));
        });

        $detachedChildCoroutineId = null;

        try {
            $detachedChildCoroutineId = $childCoroutineId->pop(1.0);
            $this->assertIsInt($detachedChildCoroutineId);
            $this->assertTrue($parentReleased->pop(1.0));

            $allowChild->push(true);

            $this->assertSame($childConnection, $childBorrowed->pop(1.0));
            $this->assertTrue($childReleased->pop(1.0));
        } finally {
            $allowChild->push(true, 0.01);
            Coroutine::join(array_values(array_filter([
                $parentCoroutineId,
                $detachedChildCoroutineId,
            ], is_int(...))), 1.0);
        }

        $this->assertIsInt($detachedChildCoroutineId);
        $this->assertFalse(Coroutine::exists($detachedChildCoroutineId));
    }

    public function testSelectPinnedConnectionDoesNotLeakAcrossCoroutines(): void
    {
        $setConnection = $this->mockConnection();
        $setConnection->shouldReceive('set')->once()->with('xxxx', 'yyyy')->andReturn('db:0 name:set argument:xxxx,yyyy');
        $setConnection->shouldReceive('release')->once();

        $selectedConnection = $this->mockConnection();
        $selectedConnection->shouldReceive('select')->once()->with(2)->andReturn(true);
        $selectedConnection->shouldReceive('get')->once()->with('xxxx')->andReturn('db:2 name:get argument:xxxx');
        $selectedConnection->shouldReceive('release')->once();

        $otherCoroutineConnection = $this->mockConnection();
        $otherCoroutineConnection->shouldReceive('get')->once()->with('xxxx')->andReturn('db:0 name:get argument:xxxx');
        $otherCoroutineConnection->shouldReceive('release')->once();

        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('get')->times(3)->andReturn(
            $setConnection,
            $selectedConnection,
            $otherCoroutineConnection,
        );
        $pool->shouldReceive('getOption')->andReturn(new PoolOption);

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('default')->andReturn($pool);

        $redis = new RedisProxy($poolFactory, 'default', $this->sentinelFactory());

        $this->assertSame('db:0 name:set argument:xxxx,yyyy', $redis->set('xxxx', 'yyyy'));
        $this->assertTrue($redis->select(2));
        $this->assertSame('db:2 name:get argument:xxxx', $redis->get('xxxx'));

        $channel = new Channel(1);
        go(static function () use ($redis, $channel) {
            $channel->push($redis->get('xxxx'));
        });

        $this->assertSame('db:0 name:get argument:xxxx', $channel->pop());
    }

    public function testPinnedConnectionInOneCoroutineIsNotReusedInAnotherCoroutine(): void
    {
        $pipeline = m::mock(PhpRedis::class);

        $pinnedConnection = $this->mockConnection();
        $pinnedConnection->shouldReceive('multi')->once()->andReturn($pipeline);
        $pinnedConnection->shouldReceive('set')->once()->with('id', '123')->andReturnSelf();
        $pinnedConnection->shouldReceive('exec')->once()->andReturn([]);
        $pinnedConnection->shouldReceive('release')->once();

        $otherCoroutineConnection = $this->mockConnection();
        $otherCoroutineConnection->shouldReceive('get')->once()->with('id')->andReturn('from-other-connection');
        $otherCoroutineConnection->shouldReceive('release')->once();

        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('get')->times(2)->andReturn($pinnedConnection, $otherCoroutineConnection);
        $pool->shouldReceive('getOption')->andReturn(new PoolOption);

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('default')->andReturn($pool);

        $redis = new RedisProxy($poolFactory, 'default', $this->sentinelFactory());

        $redis->multi();
        $redis->set('id', '123');

        $channel = new Channel(1);
        go(static function () use ($redis, $channel) {
            $channel->push($redis->get('id'));
        });

        $this->assertSame('from-other-connection', $channel->pop());

        $this->assertSame([], $redis->exec());
    }

    public function testExistingContextConnectionIsReused(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')->twice()->andReturn('value1', 'value2');
        // Connection is NOT released during the test (it already existed in context),
        // but allow release() call for test cleanup
        $connection->shouldReceive('release')->zeroOrMoreTimes();

        // Pre-set connection in context
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $connection);

        $redis = $this->createRedis($connection);

        // Both calls should use the same connection from context
        $result1 = $redis->get('key1');
        $result2 = $redis->get('key2');

        $this->assertSame('value1', $result1);
        $this->assertSame('value2', $result2);
    }

    public function testExceptionIsPropagated(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('get')
            ->once()
            ->andThrow(new RuntimeException('Redis error'));
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis error');

        $redis->get('key');
    }

    public function testReleasesConnectionWhenUnderlyingGetConnectionFails(): void
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('shouldTransform')->andReturnSelf();
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->shouldReceive('getConnection')
            ->once()
            ->andThrow(new RuntimeException('Get connection failed.'));
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Get connection failed.');

        $redis->set('xxxx', 'yyyy');
    }

    public function testExceptionWithContextConnectionDoesNotReleaseConnection(): void
    {
        $expectedException = new Exception('Redis error');

        $mockRedisConnection = $this->createMockRedisConnection('get', null, $expectedException);
        $mockRedisConnection->shouldReceive('release')->never();

        // Pre-set context connection
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $mockRedisConnection);

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->get('key');
            $this->fail('Expected exception was not thrown');
        } catch (Exception $exception) {
            $this->assertSame('Redis error', $exception->getMessage());
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
        } catch (Exception $exception) {
            $this->assertSame('Multi failed', $exception->getMessage());
        }

        // Connection should NOT be stored in context on error
        $this->assertNull(CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testEventDispatchedOnSuccess(): void
    {
        $mockEventDispatcher = m::mock(Dispatcher::class);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandFailed::class)
            ->andReturn(false);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandExecuted::class)
            ->andReturn(true);
        $mockEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (CommandExecuted $event) {
                return $event->command === 'get'
                    && $event->parameters === ['key']
                    && $event->connectionName === 'default'
                    && $event->time >= 0.0;
            }));

        $mockRedisConnection = $this->createMockRedisConnection('get', 'value', null, $mockEventDispatcher);
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $redis->get('key');
    }

    public function testSuccessEventTemporarilyPublishesTheOwnedConnection(): void
    {
        $dispatcher = m::mock(Dispatcher::class);
        $connection = $this->createMockRedisConnection('get', 'value', eventDispatcher: $dispatcher);
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->andReturnTrue();
        $dispatcher->expects('dispatch')
            ->with(m::type(CommandExecuted::class))
            ->andReturnUsing(function () use ($connection): void {
                $this->assertSame(
                    $connection,
                    CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'),
                );
            });
        $connection->expects('release');

        $this->assertSame('value', $this->createRedis($connection)->get('key'));
        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testSuccessEventPreservesAPreExistingContextConnection(): void
    {
        $dispatcher = m::mock(Dispatcher::class);
        $connection = $this->createMockRedisConnection('get', 'value', eventDispatcher: $dispatcher);
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->andReturnTrue();
        $dispatcher->expects('dispatch')
            ->with(m::type(CommandExecuted::class))
            ->andReturnUsing(function () use ($connection): void {
                $this->assertSame(
                    $connection,
                    CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'),
                );
            });
        $connection->shouldNotReceive('release');
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $connection);

        $this->assertSame('value', $this->createRedis($connection)->get('key'));
        $this->assertSame(
            $connection,
            CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'),
        );
    }

    public function testSuccessEventCanRunANestedCommandOnTheOwnedConnection(): void
    {
        $dispatcher = m::mock(Dispatcher::class);
        $connection = $this->createMockRedisConnection('get', 'outer', eventDispatcher: $dispatcher);
        $connection->expects('set')->with('nested', 'value')->andReturnTrue();
        $connection->expects('release');
        $dispatcher->expects('hasListeners')
            ->twice()
            ->with(CommandExecuted::class)
            ->andReturn(true, false);
        $redis = $this->createRedis($connection);
        $dispatcher->expects('dispatch')
            ->with(m::type(CommandExecuted::class))
            ->andReturnUsing(function () use ($redis): void {
                $this->assertTrue($redis->set('nested', 'value'));
            });

        $this->assertSame('outer', $redis->get('outer'));
        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testEventDispatchedOnErrorWithExceptionInfo(): void
    {
        $expectedException = new Exception('Redis error');

        $mockEventDispatcher = m::mock(Dispatcher::class);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandFailed::class)
            ->andReturn(true);
        $mockEventDispatcher->shouldReceive('hasListeners')
            ->with(CommandExecuted::class)
            ->andReturn(false);
        $mockEventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::on(function (CommandFailed $event) use ($expectedException) {
                return $event->command === 'get'
                    && $event->parameters === ['key']
                    && $event->exception === $expectedException
                    && $event->connectionName === 'default'
                    && $event->time !== null
                    && $event->time >= 0.0;
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

    public function testCommandCancellationSkipsFailureEventAndStillReleasesTheConnection(): void
    {
        $cancellation = new CanceledException('command canceled');
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldNotReceive('hasListeners');
        $connection = $this->createMockRedisConnection(
            'get',
            exception: $cancellation,
            eventDispatcher: $dispatcher,
        );
        $connection->expects('release');

        try {
            $this->createRedis($connection)->get('key');
            $this->fail('Expected the cancellation to escape.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testCommandNormalizesWrappedPhpRedisCancellation(): void
    {
        $nativeFailure = new RedisException('command canceled');
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldNotReceive('hasListeners');
        $connection = $this->createMockRedisConnection(
            'get',
            exception: $nativeFailure,
            eventDispatcher: $dispatcher,
        );
        $connection->expects('release');
        $redis = $this->createRedis($connection);

        $exception = $this->captureCancellationAtBoundary(function () use ($redis): void {
            $redis->get('key');
        });

        $this->assertInstanceOf(CanceledException::class, $exception);
        $this->assertSame($nativeFailure, $exception->getPrevious());
    }

    public function testFailureEventTemporarilyPublishesTheOwnedConnection(): void
    {
        $commandException = new RuntimeException('Command failed.');
        $dispatcher = m::mock(Dispatcher::class);
        $connection = $this->createMockRedisConnection('get', exception: $commandException, eventDispatcher: $dispatcher);
        $dispatcher->expects('hasListeners')->with(CommandFailed::class)->andReturnTrue();
        $dispatcher->expects('dispatch')
            ->with(m::type(CommandFailed::class))
            ->andReturnUsing(function () use ($connection): void {
                $this->assertSame(
                    $connection,
                    CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'),
                );
            });
        $connection->expects('release');

        try {
            $this->createRedis($connection)->get('key');
            $this->fail('Expected the command failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($commandException, $throwable);
        }

        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testFailureEventCanRunANestedCommandOnTheOwnedConnection(): void
    {
        $commandException = new RuntimeException('Command failed.');
        $dispatcher = m::mock(Dispatcher::class);
        $connection = $this->createMockRedisConnection('get', exception: $commandException, eventDispatcher: $dispatcher);
        $connection->expects('set')->with('nested', 'recovered')->andReturnTrue();
        $connection->expects('release');
        $dispatcher->expects('hasListeners')->with(CommandFailed::class)->andReturnTrue();
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->andReturnFalse();
        $redis = $this->createRedis($connection);
        $dispatcher->expects('dispatch')
            ->with(m::type(CommandFailed::class))
            ->andReturnUsing(function () use ($redis): void {
                $this->assertTrue($redis->set('nested', 'recovered'));
            });

        try {
            $redis->get('outer');
            $this->fail('Expected the outer command failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($commandException, $throwable);
        }

        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testMultiListenerQueuesNestedCommandsOnTheOwnedTransaction(): void
    {
        $transaction = m::mock(PhpRedis::class);
        $dispatcher = m::mock(Dispatcher::class);
        $connection = $this->createMockRedisConnection('multi', $transaction, eventDispatcher: $dispatcher);
        $connection->expects('set')->with('nested', 'queued')->andReturn($transaction);
        $connection->expects('release');
        $dispatcher->expects('hasListeners')
            ->twice()
            ->with(CommandExecuted::class)
            ->andReturn(true, false);
        $redis = $this->createRedis($connection);
        $dispatcher->expects('dispatch')
            ->with(m::type(CommandExecuted::class))
            ->andReturnUsing(function () use ($redis, $transaction): void {
                $this->assertSame($transaction, $redis->set('nested', 'queued'));
            });

        $this->assertSame($transaction, $redis->multi());
        $this->assertSame(
            $connection,
            CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'),
        );
    }

    public function testThrowingSuccessListenerStillReleasesOrdinaryConnection(): void
    {
        $eventException = new RuntimeException('Success listener failed.');
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->andReturnTrue();
        $dispatcher->expects('dispatch')->andThrow($eventException);
        $connection = $this->createMockRedisConnection('get', 'value', null, $dispatcher);
        $connection->expects('release');

        try {
            $this->createRedis($connection)->get('key');
            $this->fail('Expected the event listener failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($eventException, $throwable);
        }

        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testThrowingSuccessListenerDoesNotSkipSameConnectionHandoff(): void
    {
        $eventException = new RuntimeException('Success listener failed.');
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->andReturnTrue();
        $dispatcher->expects('dispatch')->andThrow($eventException);
        $transaction = m::mock(PhpRedis::class);
        $connection = $this->createMockRedisConnection('multi', $transaction, null, $dispatcher);
        $connection->expects('release');
        $redis = $this->createRedis($connection);

        try {
            $redis->multi();
            $this->fail('Expected the event listener failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($eventException, $throwable);
        }

        $this->assertSame(
            $connection,
            CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default')
        );
    }

    public function testThrowingFailureListenerStillReleasesAndReplacesCommandFailure(): void
    {
        $commandException = new RuntimeException('Command failed.');
        $eventException = new RuntimeException('Failure listener failed.');
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(CommandFailed::class)->andReturnTrue();
        $dispatcher->expects('dispatch')->andThrow($eventException);
        $connection = $this->createMockRedisConnection('get', null, $commandException, $dispatcher);
        $connection->expects('release');

        try {
            $this->createRedis($connection)->get('key');
            $this->fail('Expected the event listener failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($eventException, $throwable);
        }

        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testCommandFailureRemainsPrimaryOverCleanupFailure(): void
    {
        $commandException = new RuntimeException('Command failed.');
        $connection = $this->createMockRedisConnection('get', null, $commandException);
        $connection->expects('release')->andThrow(new RuntimeException('Cleanup failed.'));

        try {
            $this->createRedis($connection)->get('key');
            $this->fail('Expected the command failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($commandException, $throwable);
        }
    }

    public function testCleanupCancellationSupersedesOrdinaryCommandFailure(): void
    {
        $cancellation = new CanceledException('cleanup canceled');
        $connection = $this->createMockRedisConnection(
            'get',
            exception: new RuntimeException('Command failed.'),
        );
        $connection->expects('release')->andThrow($cancellation);

        try {
            $this->createRedis($connection)->get('key');
            $this->fail('Expected the cleanup cancellation to escape.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testEventCancellationRemainsPrimaryOverOrdinaryCleanupFailure(): void
    {
        $cancellation = new CanceledException('event canceled');
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->andReturnTrue();
        $dispatcher->expects('dispatch')->andThrow($cancellation);
        $connection = $this->createMockRedisConnection(
            'get',
            'value',
            eventDispatcher: $dispatcher,
        );
        $connection->expects('release')->andThrow(new RuntimeException('Cleanup failed.'));

        try {
            $this->createRedis($connection)->get('key');
            $this->fail('Expected the event cancellation to escape.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testCleanupFailurePropagatesAfterSuccessfulCommand(): void
    {
        $cleanupException = new RuntimeException('Cleanup failed.');
        $connection = $this->createMockRedisConnection();
        $connection->expects('release')->andThrow($cleanupException);

        try {
            $this->createRedis($connection)->get('key');
            $this->fail('Expected the cleanup failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($cleanupException, $throwable);
        }
    }

    public function testCallbackTransactionClearsWatchStateAfterSuccessfulExec(): void
    {
        $transaction = m::mock(PhpRedis::class);
        $transaction->expects('exec')->andReturn([]);
        $connection = $this->mockConnection();
        $connection->expects('multi')->andReturn($transaction);
        $connection->expects('clearWatchState');
        $connection->shouldNotReceive('release');
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $connection);

        $this->assertSame(
            [],
            $this->createRedis($connection)->transaction(static function (): void {
            })
        );
    }

    public function testCallbackTransactionClearsWatchStateAfterOptimisticLockConflict(): void
    {
        $transaction = m::mock(PhpRedis::class);
        $transaction->expects('exec')->andReturnFalse();
        $connection = $this->mockConnection();
        $connection->expects('multi')->andReturn($transaction);
        $connection->expects('clearWatchState');
        $connection->shouldNotReceive('release');
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $connection);

        $this->assertFalse(
            $this->createRedis($connection)->transaction(static function (): void {
            })
        );
    }

    public function testCallbackTransactionInvalidatesWithoutClearingWatchStateWhenExecThrows(): void
    {
        $transaction = m::mock(PhpRedis::class);
        $transaction->expects('exec')->andThrow(new RuntimeException('Exec failed.'));
        $connection = $this->mockConnection();
        $connection->expects('multi')->andReturn($transaction);
        $connection->expects('invalidate');
        $connection->shouldNotReceive('clearWatchState');
        $connection->shouldNotReceive('release');
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exec failed.');

        $this->createRedis($connection)->transaction(static function (): void {
        });
    }

    public function testCallbackTransactionPreservesOperationCancellationOverCleanupFailure(): void
    {
        $cancellation = new CanceledException('exec canceled');
        $transaction = m::mock(PhpRedis::class);
        $transaction->expects('exec')->andThrow($cancellation);
        $connection = $this->mockConnection();
        $connection->expects('multi')->andReturn($transaction);
        $connection->expects('invalidate');
        $connection->expects('release')->andThrow(new RuntimeException('Cleanup failed.'));

        try {
            $this->createRedis($connection)->transaction(static function (): void {
            });
            $this->fail('Expected the operation cancellation to escape.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testCallbackTransactionNormalizesWrappedPhpRedisCancellation(): void
    {
        $nativeFailure = new RedisException('exec canceled');
        $transaction = m::mock(PhpRedis::class);
        $transaction->expects('exec')->andThrow($nativeFailure);
        $connection = $this->mockConnection();
        $connection->expects('multi')->andReturn($transaction);
        $connection->expects('invalidate');
        $connection->expects('release');
        $redis = $this->createRedis($connection);

        $exception = $this->captureCancellationAtBoundary(function () use ($redis): void {
            $redis->transaction(static function (): void {
            });
        });

        $this->assertInstanceOf(CanceledException::class, $exception);
        $this->assertSame($nativeFailure, $exception->getPrevious());
    }

    public function testCallbackTransactionCleanupCancellationSupersedesOrdinaryOperationFailure(): void
    {
        $cancellation = new CanceledException('cleanup canceled');
        $transaction = m::mock(PhpRedis::class);
        $transaction->expects('exec')->andThrow(new RuntimeException('Exec failed.'));
        $connection = $this->mockConnection();
        $connection->expects('multi')->andReturn($transaction);
        $connection->expects('invalidate');
        $connection->expects('release')->andThrow($cancellation);

        try {
            $this->createRedis($connection)->transaction(static function (): void {
            });
            $this->fail('Expected the cleanup cancellation to escape.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testCallbackTransactionOrdinaryCleanupFailureRetainsFinallyPrecedence(): void
    {
        $cleanupFailure = new RuntimeException('Cleanup failed.');
        $transaction = m::mock(PhpRedis::class);
        $transaction->expects('exec')->andThrow(new RuntimeException('Exec failed.'));
        $connection = $this->mockConnection();
        $connection->expects('multi')->andReturn($transaction);
        $connection->expects('invalidate');
        $connection->expects('release')->andThrow($cleanupFailure);

        try {
            $this->createRedis($connection)->transaction(static function (): void {
            });
            $this->fail('Expected the cleanup failure to escape.');
        } catch (Throwable $exception) {
            $this->assertSame($cleanupFailure, $exception);
        }
    }

    public function testCallbackPipelineDoesNotClearWatchState(): void
    {
        $pipeline = m::mock(PhpRedis::class);
        $pipeline->expects('exec')->andReturn([]);
        $connection = $this->mockConnection();
        $connection->expects('pipeline')->andReturn($pipeline);
        $connection->shouldNotReceive('clearWatchState');
        $connection->shouldNotReceive('release');
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $connection);

        $this->assertSame(
            [],
            $this->createRedis($connection)->pipeline(static function (): void {
            })
        );
    }

    public function testRegularCommandDoesNotStoreConnectionInContext(): void
    {
        $mockRedisConnection = $this->mockConnection();
        $mockRedisConnection->expects('get')
            ->with('key')
            ->andReturnUsing(function (): string {
                $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));

                return 'value';
            });
        $mockRedisConnection->shouldReceive('release')->once();

        $redis = $this->createRedis($mockRedisConnection);

        $redis->get('key');

        $this->assertNull(CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testWithConnectionExecutesCallbackAndReleasesConnection(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->withConnection(function (RedisConnection $redisConnection) use ($connection) {
            $this->assertSame($connection, $redisConnection);

            return 'callback-result';
        });

        $this->assertSame('callback-result', $result);
    }

    public function testWithConnectionReusesExistingContextConnection(): void
    {
        $connection = $this->mockConnection();
        // Should NOT release since connection was already in context
        $connection->shouldReceive('release')->never();

        // Pre-set connection in context (simulating an active multi/pipeline)
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $connection);

        $redis = $this->createRedis($connection);

        $result = $redis->withConnection(function (RedisConnection $redisConnection) use ($connection) {
            $this->assertSame($connection, $redisConnection);

            return 'reused-connection';
        });

        $this->assertSame('reused-connection', $result);
        // Connection should still be in context
        $this->assertTrue(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testWithConnectionReleasesOnException(): void
    {
        $connection = $this->mockConnection();
        // Should release even on exception
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $redis->withConnection(function (RedisConnection $redisConnection) {
            throw new RuntimeException('Callback failed');
        });
    }

    public function testWithConnectionPreservesCallbackCancellationOverCleanupFailure(): void
    {
        $cancellation = new CanceledException('Callback canceled.');
        $connection = $this->mockConnection();
        $connection->expects('release')->andThrow(new RuntimeException('Cleanup failed.'));

        try {
            $this->createRedis($connection)->withConnection(
                static function () use ($cancellation): never {
                    throw $cancellation;
                },
            );
            $this->fail('Expected the callback cancellation to escape.');
        } catch (Throwable $throwable) {
            $this->assertSame($cancellation, $throwable);
        }
    }

    public function testWithConnectionCleanupCancellationSupersedesOrdinaryCallbackFailure(): void
    {
        $cancellation = new CanceledException('Cleanup canceled.');
        $connection = $this->mockConnection();
        $connection->expects('release')->andThrow($cancellation);

        try {
            $this->createRedis($connection)->withConnection(
                static function (): never {
                    throw new RuntimeException('Callback failed.');
                },
            );
            $this->fail('Expected the cleanup cancellation to escape.');
        } catch (Throwable $throwable) {
            $this->assertSame($cancellation, $throwable);
        }
    }

    public function testWithConnectionOrdinaryCleanupFailureRetainsFinallyPrecedence(): void
    {
        $cleanupFailure = new RuntimeException('Cleanup failed.');
        $connection = $this->mockConnection();
        $connection->expects('release')->andThrow($cleanupFailure);

        try {
            $this->createRedis($connection)->withConnection(
                static function (): never {
                    throw new RuntimeException('Callback failed.');
                },
            );
            $this->fail('Expected the cleanup failure to escape.');
        } catch (Throwable $throwable) {
            $this->assertSame($cleanupFailure, $throwable);
        }
    }

    public function testWithConnectionDoesNotReleaseContextConnectionOnException(): void
    {
        $connection = $this->mockConnection();
        // Should NOT release since connection was in context
        $connection->shouldReceive('release')->never();

        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $connection);

        $redis = $this->createRedis($connection);

        try {
            $redis->withConnection(function (RedisConnection $redisConnection) {
                throw new RuntimeException('Callback failed');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Callback failed', $exception->getMessage());
        }

        // Connection should still be in context
        $this->assertTrue(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testWithConnectionDefaultsToTransformTrue(): void
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->shouldReceive('shouldTransform')
            ->once()
            ->with(true)
            ->andReturnSelf();
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $redis->withConnection(function (RedisConnection $redisConnection) {
            return 'result';
        });
    }

    public function testWithConnectionRespectsTransformFalse(): void
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->shouldReceive('shouldTransform')
            ->once()
            ->with(false)
            ->andReturnSelf();
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $redis->withConnection(function (RedisConnection $redisConnection) {
            return 'result';
        }, transform: false);
    }

    public function testWithConnectionRespectsTransformTrueExplicit(): void
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->shouldReceive('shouldTransform')
            ->once()
            ->with(true)
            ->andReturnSelf();
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $redis->withConnection(function (RedisConnection $redisConnection) {
            return 'result';
        }, transform: true);
    }

    public function testWithConnectionAllowsMultipleOperationsOnSameConnection(): void
    {
        $mockPhpRedis = m::mock(PhpRedis::class);
        $mockPhpRedis->shouldReceive('evalSha')
            ->once()
            ->with('sha123', ['key'], 1)
            ->andReturn(false);
        $mockPhpRedis->shouldReceive('getLastError')
            ->once()
            ->andReturn('NOSCRIPT No matching script');

        $connection = $this->mockConnection();
        $connection->shouldReceive('client')->andReturn($mockPhpRedis);
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->withConnection(function (RedisConnection $connection) {
            $client = $connection->client();
            $evalResult = $client->evalSha('sha123', ['key'], 1);

            if ($evalResult === false) {
                return $client->getLastError();
            }

            return $evalResult;
        });

        $this->assertSame('NOSCRIPT No matching script', $result);
    }

    public function testWithoutSerializationOrCompressionPinsConnectionAndDelegates(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('withoutSerializationOrCompression')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->withoutSerializationOrCompression(function () use ($connection) {
            // Connection must be pinned in Context during callback
            $this->assertSame($connection, CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));

            return 'result';
        });

        $this->assertSame('result', $result);
        // Connection should be unpinned after completion
        $this->assertNull(CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testWithPinnedConnectionPreservesCallbackCancellationAndUnpinsBeforeCleanup(): void
    {
        $cancellation = new CanceledException('Callback canceled.');
        $connection = $this->mockConnection();
        $connection->expects('release')->andReturnUsing(function (): void {
            $this->assertFalse(CoroutineContext::has(
                RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default',
            ));

            throw new RuntimeException('Cleanup failed.');
        });

        try {
            $this->createRedis($connection)->withPinnedConnection(
                static function () use ($cancellation): never {
                    throw $cancellation;
                },
            );
            $this->fail('Expected the callback cancellation to escape.');
        } catch (Throwable $throwable) {
            $this->assertSame($cancellation, $throwable);
        }

        $this->assertFalse(CoroutineContext::has(
            RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default',
        ));
    }

    public function testWithoutSerializationOrCompressionReusesExistingContextConnection(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('withoutSerializationOrCompression')
            ->once()
            ->andReturnUsing(fn (callable $callback) => $callback());
        // Should NOT release since connection was already in context
        $connection->shouldReceive('release')->never();

        // Pre-set connection in context (simulating an active multi/pipeline)
        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $connection);

        $redis = $this->createRedis($connection);

        $result = $redis->withoutSerializationOrCompression(function () use ($connection) {
            // Connection should still be the same one that was pre-set
            $this->assertSame($connection, CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));

            return 'reused';
        });

        $this->assertSame('reused', $result);
        // Connection should still be in context
        $this->assertTrue(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testWithoutSerializationOrCompressionCleansUpOnException(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('withoutSerializationOrCompression')
            ->once()
            ->andReturnUsing(function (callable $callback) {
                return $callback();
            });
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        try {
            $redis->withoutSerializationOrCompression(function () use ($connection) {
                // Connection must be pinned even when callback throws
                $this->assertSame($connection, CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));

                throw new RuntimeException('Callback failed');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Callback failed', $exception->getMessage());
        }

        // Connection should be unpinned and released
        $this->assertNull(CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testWithoutSerializationOrCompressionDoesNotReleaseContextConnectionOnException(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('withoutSerializationOrCompression')
            ->once()
            ->andReturnUsing(function (callable $callback) {
                return $callback();
            });
        // Should NOT release since connection was already in context
        $connection->shouldReceive('release')->never();

        CoroutineContext::set(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default', $connection);

        $redis = $this->createRedis($connection);

        try {
            $redis->withoutSerializationOrCompression(function () use ($connection) {
                // Connection should still be pinned during callback
                $this->assertSame($connection, CoroutineContext::get(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));

                throw new RuntimeException('Callback failed');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Callback failed', $exception->getMessage());
        }

        // Connection should still be in context
        $this->assertTrue(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    public function testSubscriberUsesTheCompleteStandaloneConfiguration(): void
    {
        $server = new RespServer;
        $server->start(static function ($client): void {
            fread($client, 1);
        });
        $pool = m::mock(RedisPool::class);
        $pool->expects('getConfig')->andReturn($this->standaloneConfig([
            'host' => $server->endpoint(),
            'port' => 6379,
            'timeout' => 2.5,
            'options' => ['prefix' => 'app:'],
        ]));
        $pool->shouldNotReceive('get');
        $factory = m::mock(PoolFactory::class);
        $factory->expects('getPool')->with('default')->andReturn($pool);
        $subscriber = (new RedisProxy(
            $factory,
            'default',
            $this->sentinelFactory(),
        ))->subscriber();

        try {
            $this->assertSame($server->endpoint(), $subscriber->host);
            $this->assertSame(2.5, $subscriber->timeout);
            $this->assertSame('app:', $subscriber->prefix);
        } finally {
            $subscriber->close();
            $server->wait();
        }
    }

    public function testSubscriberResolvesSentinelMasterFreshWithConnectionCredentials(): void
    {
        $command = CommandBuilder::build(['auth', '0', '0']);
        $servers = [new RespServer, new RespServer];

        foreach ($servers as $server) {
            $server->start(function ($client) use ($command): void {
                RespServer::readExact($client, strlen($command));
                fwrite($client, "+OK\r\n");
                fread($client, 1);
            });
        }

        [$firstHost, $firstPort] = $servers[0]->hostAndPort();
        [$secondHost, $secondPort] = $servers[1]->hostAndPort();
        $config = $this->sentinelConfig([
            'username' => '0',
            'password' => '0',
            'timeout' => 1.0,
            'options' => ['prefix' => 'sentinel:'],
        ]);
        $pool = m::mock(RedisPool::class);
        $pool->expects('getConfig')->twice()->andReturn($config);
        $pool->shouldNotReceive('get');
        $factory = m::mock(PoolFactory::class);
        $factory->expects('getPool')->twice()->with('default')->andReturn($pool);
        $sentinelFactory = m::mock(RedisSentinelFactory::class);
        $sentinelFactory->expects('resolveMaster')
            ->twice()
            ->with($config)
            ->andReturn([$firstHost, $firstPort], [$secondHost, $secondPort]);
        $proxy = new RedisProxy($factory, 'default', $sentinelFactory);
        $first = $proxy->subscriber();
        $second = $proxy->subscriber();

        try {
            $this->assertSame($firstPort, $first->port);
            $this->assertSame($secondPort, $second->port);
            $this->assertSame('sentinel:', $first->prefix);
        } finally {
            $first->close();
            $second->close();

            foreach ($servers as $server) {
                $server->wait();
            }
        }
    }

    public function testClusterSubscriberUsesConnectionTransportAndReleasesDiscoveryConnectionBeforeEndpointFallback(): void
    {
        $released = false;
        $server = new RespServer;
        $server->start(static function ($client) use (&$released): void {
            if (! $released) {
                throw new RuntimeException('Cluster discovery connection was not released before subscriber dial.');
            }

            fread($client, 1);
        });
        [$host, $port] = $server->hostAndPort();
        $config = $this->clusterConfig([
            'scheme' => 'tcp',
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tcp://127.0.0.1:1'],
            ],
            'context' => [],
            'timeout' => 0.1,
            'options' => ['prefix' => 'cluster:'],
        ]);
        $connection = m::mock(PhpRedisClusterConnection::class);
        $connection->expects('getConnection')->andReturnSelf();
        $connection->expects('masters')->andReturn([
            ['127.0.0.1', 1],
            [$host, $port],
        ]);
        $connection->expects('release')->andReturnUsing(static function () use (&$released): void {
            $released = true;
        });
        $pool = m::mock(RedisPool::class);
        $pool->expects('getConfig')->andReturn($config);
        $pool->expects('get')->andReturn($connection);
        $factory = m::mock(PoolFactory::class);
        $factory->expects('getPool')->with('default')->andReturn($pool);
        $subscriber = (new RedisProxy(
            $factory,
            'default',
            $this->sentinelFactory(),
        ))->subscriber();

        try {
            $this->assertTrue($released);
            $this->assertSame($port, $subscriber->port);
            $this->assertSame('cluster:', $subscriber->prefix);
            $this->assertSame('tcp', $subscriber->scheme);
            $this->assertSame([], $subscriber->context);
        } finally {
            $subscriber->close();
            $server->wait();
        }
    }

    public function testClusterSubscriberUsesTlsConnectionTransportForMaster(): void
    {
        $released = false;
        $clientOptions = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];
        $server = new RespServer('tls://127.0.0.1:0', [
            'ssl' => [
                'local_cert' => __DIR__ . '/Fixtures/Tls/server.crt',
                'local_pk' => __DIR__ . '/Fixtures/Tls/server.key',
                'allow_self_signed' => true,
            ],
        ]);
        $server->start(static function ($client) use (&$released): void {
            if (! $released) {
                throw new RuntimeException(
                    'Cluster discovery connection was not released before subscriber dial.'
                );
            }

            fread($client, 1);
        });
        [$host, $port] = $server->hostAndPort();
        $config = $this->clusterConfig([
            'scheme' => 'tls',
            'context' => $clientOptions,
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tls://127.0.0.1:1'],
            ],
            'timeout' => 0.1,
        ]);
        $connection = m::mock(PhpRedisClusterConnection::class);
        $connection->expects('getConnection')->andReturnSelf();
        $connection->expects('masters')->andReturn([[$host, $port]]);
        $connection->expects('release')->andReturnUsing(static function () use (&$released): void {
            $released = true;
        });
        $pool = m::mock(RedisPool::class);
        $pool->expects('getConfig')->andReturn($config);
        $pool->expects('get')->andReturn($connection);
        $factory = m::mock(PoolFactory::class);
        $factory->expects('getPool')->with('default')->andReturn($pool);
        $subscriber = (new RedisProxy(
            $factory,
            'default',
            $this->sentinelFactory(),
        ))->subscriber();

        try {
            $this->assertTrue($released);
            $this->assertSame('tls', $subscriber->scheme);
            $this->assertSame($clientOptions, $subscriber->context);
        } finally {
            $subscriber->close();
            $server->wait();
        }
    }

    public function testClusterSubscriberAggregatesEndpointFailures(): void
    {
        $config = $this->clusterConfig([
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tcp://127.0.0.1:1'],
            ],
            'timeout' => 0.01,
        ]);
        $connection = m::mock(PhpRedisClusterConnection::class);
        $connection->expects('getConnection')->andReturnSelf();
        $connection->expects('masters')->andReturn([
            ['127.0.0.1', 1],
            ['127.0.0.1', 2],
        ]);
        $connection->expects('release');
        $pool = m::mock(RedisPool::class);
        $pool->expects('getConfig')->andReturn($config);
        $pool->expects('get')->andReturn($connection);
        $factory = m::mock(PoolFactory::class);
        $factory->expects('getPool')->with('default')->andReturn($pool);

        try {
            (new RedisProxy(
                $factory,
                'default',
                $this->sentinelFactory(),
            ))->subscriber();
            $this->fail('Expected every Cluster subscriber endpoint to fail.');
        } catch (InvalidRedisConnectionException $exception) {
            $this->assertStringContainsString('[127.0.0.1:1]', $exception->getMessage());
            $this->assertStringContainsString('[127.0.0.1:2]', $exception->getMessage());
        }
    }

    public function testClusterDiscoveryFailureRemainsPrimaryOverReleaseFailure(): void
    {
        $discoveryException = new RuntimeException('Master discovery failed.');
        $connection = m::mock(PhpRedisClusterConnection::class);
        $connection->expects('getConnection')->andReturnSelf();
        $connection->expects('masters')->andThrow($discoveryException);
        $connection->expects('release')->andThrow(new RuntimeException('Release failed.'));
        $pool = m::mock(RedisPool::class);
        $pool->expects('getConfig')->andReturn([
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tcp://127.0.0.1:6379'],
            ],
        ]);
        $pool->expects('get')->andReturn($connection);
        $factory = m::mock(PoolFactory::class);
        $factory->expects('getPool')->with('default')->andReturn($pool);

        try {
            (new RedisProxy(
                $factory,
                'default',
                $this->sentinelFactory(),
            ))->subscriber();
            $this->fail('Expected Cluster discovery to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($discoveryException, $exception);
        }
    }

    public function testClusterDiscoveryNormalizesWrappedCancellationAndStillReleases(): void
    {
        $nativeFailure = new RedisClusterException('Master discovery canceled.');
        $connection = m::mock(PhpRedisClusterConnection::class);
        $connection->expects('getConnection')->andReturnSelf();
        $connection->expects('masters')->andThrow($nativeFailure);
        $connection->expects('release')->andThrow(new RuntimeException('Release failed.'));
        $pool = m::mock(RedisPool::class);
        $pool->expects('getConfig')->andReturn([
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tcp://127.0.0.1:6379'],
            ],
        ]);
        $pool->expects('get')->andReturn($connection);
        $factory = m::mock(PoolFactory::class);
        $factory->expects('getPool')->with('default')->andReturn($pool);
        $redis = new RedisProxy($factory, 'default', $this->sentinelFactory());

        $exception = $this->captureCancellationAtBoundary(function () use ($redis): void {
            $redis->subscriber();
        });

        $this->assertInstanceOf(CanceledException::class, $exception);
        $this->assertSame($nativeFailure, $exception->getPrevious());
    }

    public function testClusterDiscoveryReleaseCancellationSupersedesOrdinaryDiscoveryFailure(): void
    {
        $cancellation = new CanceledException('Release canceled.');
        $connection = m::mock(PhpRedisClusterConnection::class);
        $connection->expects('getConnection')->andReturnSelf();
        $connection->expects('masters')->andThrow(new RuntimeException('Master discovery failed.'));
        $connection->expects('release')->andThrow($cancellation);
        $pool = m::mock(RedisPool::class);
        $pool->expects('getConfig')->andReturn([
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tcp://127.0.0.1:6379'],
            ],
        ]);
        $pool->expects('get')->andReturn($connection);
        $factory = m::mock(PoolFactory::class);
        $factory->expects('getPool')->with('default')->andReturn($pool);

        try {
            (new RedisProxy(
                $factory,
                'default',
                $this->sentinelFactory(),
            ))->subscriber();
            $this->fail('Expected the release cancellation to escape.');
        } catch (Throwable $throwable) {
            $this->assertSame($cancellation, $throwable);
        }
    }

    public function testRedisClusterConstructorSignature(): void
    {
        $reflection = new ReflectionClass(RedisCluster::class);
        $method = $reflection->getMethod('__construct');
        $names = [
            ['name', 'string'],
            ['seeds', 'array'],
            ['timeout', ['int', 'float']],
            ['read_timeout', ['int', 'float']],
            ['persistent', 'bool'],
            ['auth', 'mixed'],
            ['context', 'array'],
        ];

        foreach ($method->getParameters() as $parameter) {
            [$name, $type] = array_shift($names);
            $this->assertSame($name, $parameter->getName());

            if ($parameter->getName() === 'seeds') {
                $this->assertSame('array', $parameter->getType()?->getName());
                continue;
            }

            if (is_array($type)) {
                foreach ($parameter->getType()?->getTypes() ?? [] as $namedType) {
                    $this->assertTrue(in_array($namedType->getName(), $type, true));
                }

                continue;
            }

            $this->assertSame($type, $parameter->getType()?->getName());
        }
    }

    public function testRedisSentinelConstructorSignature(): void
    {
        $reflection = new ReflectionClass(RedisSentinel::class);
        $method = $reflection->getMethod('__construct');
        $this->assertCount(1, $method->getParameters());
        $this->assertSame('options', $method->getParameters()[0]->getName());
    }

    public function testShuffleNodesMaintainsNodeCount(): void
    {
        $nodes = ['127.0.0.1:6379', '127.0.0.1:6378', '127.0.0.1:6377'];

        shuffle($nodes);

        $this->assertIsArray($nodes);
        $this->assertSame(3, count($nodes));
    }

    public function testFlushByPatternDelegatesToConnection(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('flushByPattern')
            ->once()
            ->with('cache:*')
            ->andReturn(42);
        $connection->shouldReceive('release')->once();

        $redis = $this->createRedis($connection);

        $result = $redis->flushByPattern('cache:*');

        $this->assertSame(42, $result);
    }

    public function testIsClusterReturnsFalseForStandardConfig(): void
    {
        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('getConfig')->andReturn([
            'host' => '127.0.0.1',
            'port' => 6379,
        ]);
        $pool->shouldReceive('get')->never();

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('default')->andReturn($pool);

        $redis = new RedisProxy($poolFactory, 'default', $this->sentinelFactory());

        $this->assertFalse($redis->isCluster());
    }

    public function testIsClusterReturnsTrueForClusterConfig(): void
    {
        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('getConfig')->andReturn([
            'cluster' => ['enabled' => true, 'seeds' => ['tcp://127.0.0.1:6379']],
        ]);
        $pool->shouldReceive('get')->never();

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('cache')->andReturn($pool);

        $proxy = new RedisProxy($poolFactory, 'cache', $this->sentinelFactory());

        $this->assertTrue($proxy->isCluster());
    }

    public function testProxyUsesSpecifiedPoolName(): void
    {
        $cacheConnection = $this->mockConnection();
        $cacheConnection->shouldReceive('get')->once()->with('key')->andReturn('cached');
        $cacheConnection->shouldReceive('release')->once();

        $cachePool = m::mock(RedisPool::class);
        $cachePool->shouldReceive('get')->andReturn($cacheConnection);

        $poolFactory = m::mock(PoolFactory::class);
        // Expect 'cache' pool to be requested, not 'default'
        $poolFactory->shouldReceive('getPool')->with('cache')->andReturn($cachePool);

        $proxy = new RedisProxy($poolFactory, 'cache', $this->sentinelFactory());

        $result = $proxy->get('key');

        $this->assertSame('cached', $result);
    }

    public function testProxyContextKeyUsesPoolName(): void
    {
        $connection = $this->mockConnection();
        $connection->shouldReceive('pipeline')->once()->andReturn(m::mock(PhpRedis::class));
        // Connection is released via defer() at end of coroutine
        $connection->shouldReceive('release')->once();

        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('get')->andReturn($connection);

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('cache')->andReturn($pool);

        $proxy = new RedisProxy($poolFactory, 'cache', $this->sentinelFactory());

        $proxy->pipeline();

        // Context key should use the pool name
        $this->assertTrue(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'cache'));
        $this->assertFalse(CoroutineContext::has(RedisProxy::CONNECTION_CONTEXT_PREFIX . 'default'));
    }

    /**
     * Create a mock RedisConnection with standard expectations.
     */
    private function mockConnection(): m\MockInterface|RedisConnection
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getConnection')->andReturn($connection);
        $connection->shouldReceive('getEventDispatcher')->andReturnNull();
        $connection->shouldReceive('shouldTransform')->andReturnSelf();

        return $connection;
    }

    /**
     * Create a RedisProxy instance with the given mock connection.
     */
    private function createRedis(m\MockInterface|RedisConnection $connection): RedisProxy
    {
        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('get')->andReturn($connection);
        $pool->shouldReceive('getOption')->andReturn(new PoolOption);

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('default')->andReturn($pool);

        return new RedisProxy($poolFactory, 'default', $this->sentinelFactory());
    }

    /**
     * Create a Redis proxy that returns the given subscriber.
     */
    private function createRedisWithSubscriber(Subscriber $subscriber): RedisProxy
    {
        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldNotReceive('getPool');

        return new class($poolFactory, 'default', $this->sentinelFactory(), $subscriber) extends RedisProxy {
            public function __construct(
                PoolFactory $poolFactory,
                string $name,
                RedisSentinelFactory $sentinelFactory,
                private Subscriber $subscriber,
            ) {
                parent::__construct($poolFactory, $name, $sentinelFactory);
            }

            public function subscriber(): Subscriber
            {
                return $this->subscriber;
            }
        };
    }

    /**
     * Create a subscriber with one message channel and configured close behavior.
     */
    private function mockSubscriber(
        Channel $channel,
        ?Throwable $closeFailure = null,
    ): Subscriber&m\MockInterface {
        $subscriber = m::mock(Subscriber::class);
        $subscriber->closed = false;
        $subscriber->expects('subscribe')->with('updates');
        $subscriber->expects('channel')->andReturn($channel);
        $close = $subscriber->expects('close');

        if ($closeFailure !== null) {
            $close->andThrow($closeFailure);
        }

        return $subscriber;
    }

    /**
     * Capture cancellation raised while the current coroutine is canceled.
     */
    private function captureCancellationAtBoundary(callable $callback): Throwable
    {
        $blocker = new SwooleChannel(1);
        $captured = null;
        $coroutineId = Coroutine::create(function () use ($blocker, $callback, &$captured): void {
            try {
                $blocker->pop();
            } catch (CanceledException) {
                try {
                    $callback();
                } catch (Throwable $exception) {
                    $captured = $exception;
                }
            }
        });

        $this->assertTrue(EngineCoroutine::cancelById($coroutineId, throwException: true));
        $this->assertInstanceOf(Throwable::class, $captured);

        return $captured;
    }

    /**
     * Create a release-counting Redis proxy with the given mock connections.
     */
    private function createCountingRedis(
        m\MockInterface|RedisConnection ...$connections
    ): RedisProxyReleaseCountingStub {
        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('get')->andReturn(...$connections);
        $pool->shouldReceive('getOption')->andReturn(new PoolOption);

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('default')->andReturn($pool);

        return new RedisProxyReleaseCountingStub(
            $poolFactory,
            'default',
            $this->sentinelFactory(),
        );
    }

    /**
     * Create a mock Redis connection with configurable behavior.
     */
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

        // Forward the command call to the mock PHP Redis
        $mockRedisConnection->shouldReceive($command)
            ->andReturnUsing(function (...$arguments) use ($mockPhpRedis, $command) {
                return $mockPhpRedis->{$command}(...$arguments);
            });

        return $mockRedisConnection;
    }

    /**
     * Create a complete standalone Redis connection record.
     */
    private function standaloneConfig(array $overrides = []): array
    {
        return array_replace($this->baseConnectionConfig(), [
            'url' => null,
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'name' => null,
        ], $overrides);
    }

    /**
     * Create a complete Sentinel Redis connection record.
     */
    private function sentinelConfig(array $overrides = []): array
    {
        return array_replace($this->baseConnectionConfig(), [
            'database' => 0,
            'name' => null,
            'sentinel' => [
                'enabled' => true,
                'master_name' => 'primary',
                'nodes' => ['tcp://127.0.0.1:26379'],
                'username' => null,
                'password' => null,
                'timeout' => 1.0,
                'read_timeout' => 1.0,
                'context' => [],
            ],
        ], $overrides);
    }

    /**
     * Create a complete Cluster Redis connection record.
     */
    private function clusterConfig(array $overrides = []): array
    {
        return array_replace($this->baseConnectionConfig(), [
            'scheme' => 'tcp',
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tcp://127.0.0.1:7000'],
            ],
        ], $overrides);
    }

    /**
     * Create the members shared by every Redis connection topology.
     */
    private function baseConnectionConfig(): array
    {
        return [
            'scheme' => null,
            'username' => null,
            'password' => null,
            'timeout' => 1.0,
            'read_timeout' => 0.0,
            'context' => [],
            'options' => [],
            'prefix' => null,
            'events' => false,
            'max_retries' => 3,
            'backoff_algorithm' => 'decorrelated_jitter',
            'backoff_base' => 100,
            'backoff_cap' => 1000,
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1.0,
                'heartbeat_timeout' => 1.0,
                'max_idle_time' => 60.0,
                'max_lifetime' => -1.0,
            ],
        ];
    }

    /**
     * Get a mocked Sentinel factory.
     */
    private function sentinelFactory(): RedisSentinelFactory
    {
        return m::mock(RedisSentinelFactory::class);
    }
}

class RedisProxyReleaseCountingStub extends RedisProxy
{
    public int $contextAbsentReleaseCalls = 0;

    public function releaseContextConnection(): void
    {
        $hasContextConnection = CoroutineContext::has(
            self::CONNECTION_CONTEXT_PREFIX . $this->getName()
        );

        parent::releaseContextConnection();

        if (! $hasContextConnection) {
            ++$this->contextAbsentReleaseCalls;
        }
    }
}
