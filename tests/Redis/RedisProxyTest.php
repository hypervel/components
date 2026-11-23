<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use BadMethodCallException;
use Exception;
use Hypervel\ConnectionPool\PoolOptions;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\Exceptions\InvalidRedisConnectionException;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Redis\Subscriber\CommandBuilder;
use Hypervel\Redis\Subscriber\Message;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;
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
 * Verify connection ownership and command forwarding through real wrappers
 * around isolated native clients.
 */
class RedisProxyTest extends TestCase
{
    use VerifiesDoubles;

    public function testCommandIsProxiedToConnection(): void
    {
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('get')->with('foo')->returns('bar');
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $result = $redis->get('foo');

        $this->assertSame('bar', $result);
    }

    public function testMacroRegistrationMethodsDoNotCheckoutRedis(): void
    {
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->never();
        $redis = new RedisProxy($poolManager, 'default', $this->sentinelFactory());

        $redis->macro('greeting', fn (string $name) => "Hello {$name}");
        $redis->mixin(new class {
            /**
             * Provide the farewell macro.
             */
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
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->never();
        $redis = new class($poolManager, 'default', $this->sentinelFactory()) extends RedisProxy {
            public array $subscriptions = [];

            /**
             * Record the subscription route.
             */
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
        $channel = Double::for(Channel::class)->strict();
        $channel->expects('pop')->times(2)->returns($message, false);
        $channel->expects('isCanceled')->returns(false);
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
        $subscriber = Double::for(Subscriber::class)->strict();
        $subscriber->closed = false;
        $subscriber->expects('subscribe')->with('updates');
        $subscriber->expects('channel')->resolves(function () use ($messageChannel, $ready): Channel {
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

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'wrapped cancellation')]
    public function testSubscriberLoopPreservesCallbackCancellationOverOrdinaryCloseFailure(bool $wrapped): void
    {
        $cancellation = new CanceledException('callback canceled');
        if ($wrapped) {
            $cancellation = new RuntimeException('Callback cleanup failed.', previous: $cancellation);
        }
        $channel = Double::for(Channel::class)->strict();
        $channel->expects('pop')->returns(new Message('updates', 'payload'));
        $subscriber = $this->mockSubscriber($channel, new RuntimeException('close failed'));
        $redis = $this->createRedisWithSubscriber($subscriber);

        try {
            $redis->subscribe('updates', static function () use ($cancellation): void {
                throw $cancellation;
            });
            $this->fail('Expected the cancellation to escape.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testSubscriberLoopLetsCloseCancellationSupersedeOrdinaryCallbackFailure(): void
    {
        $cancellation = new CanceledException('close canceled');
        $channel = Double::for(Channel::class)->strict();
        $channel->expects('pop')->returns(new Message('updates', 'payload'));
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
        $channel = Double::for(Channel::class)->strict();
        $channel->expects('pop')->returns(new Message('updates', 'payload'));
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
        $channel = Double::for(Channel::class)->strict();
        $channel->expects('pop')->returns(new Message('updates', 'payload'));
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
            Double::for(PoolManager::class)->strict(),
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
        $multiInstance = Double::for(PhpRedis::class)->strict();

        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('multi')->returns($multiInstance);
        // Connection is released via defer() at end of coroutine
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $result = $redis->__call('MULTI', []);

        $this->assertSame($multiInstance, $result);
        // Connection should be stored in context
        $this->assertTrue(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testMixedCasePipelineStoresConnectionInContext(): void
    {
        $pipelineInstance = Double::for(PhpRedis::class)->strict();

        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('pipeline')->returns($pipelineInstance);
        // Connection is released via defer() at end of coroutine
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $result = $redis->__call('PIPELINE', []);

        $this->assertSame($pipelineInstance, $result);
        $this->assertTrue(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testMixedCaseSelectStoresConnectionInContext(): void
    {
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('select')->with(1)->returns(true);
        // Connection is released via defer() at end of coroutine
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $result = $redis->__call('SELECT', [1]);

        $this->assertTrue($result);
        $this->assertTrue(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testConnectionIsStoredInContextForSelectZeroDatabase(): void
    {
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('select')->with(0)->returns(true);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $result = $redis->select(0);

        $this->assertTrue($result);
        $this->assertTrue(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testMixedCaseWatchStoresConnectionInContext(): void
    {
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('watch')->with('key')->returns(true);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $this->assertTrue($redis->__call('WATCH', ['key']));
        $this->assertSame(
            $connection,
            CoroutineContext::get($this->contextKey($redis))
        );
    }

    public function testNativeDiscardDoesNotInvokePoolDiscard(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('discardTransaction')->returns(true);
        $connection->expects('discard')->never();
        $connection->expects('release')->never();
        $redis = $this->createRedis($connection);
        CoroutineContext::set($this->contextKey($redis), $connection);

        $this->assertTrue($redis->__call('DISCARD', []));
        $this->assertSame(
            $connection,
            CoroutineContext::get($this->contextKey($redis))
        );
    }

    public function testRepeatedCallbackTransactionsRegisterOneTerminalRelease(): void
    {
        $firstTransaction = Double::for(PhpRedis::class)->strict();
        $firstTransaction->expects('exec')->returns([]);
        $secondTransaction = Double::for(PhpRedis::class)->strict();
        $secondTransaction->expects('exec')->returns([]);

        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('multi')->times(2)->returns($firstTransaction, $secondTransaction);
        $connection->expects('release')->times(2);

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
        $transaction = Double::for(PhpRedis::class)->strict();
        $transaction->expects('exec')->returns([]);
        $rawTransaction = Double::for(PhpRedis::class)->strict();

        $callbackConnection = $this->mockConnection();
        $callbackConnection->nativeClientForTest()->expects('multi')->returns($transaction);
        $callbackConnection->expects('release');
        $rawConnection = $this->mockConnection();
        $rawConnection->nativeClientForTest()->expects('multi')->returns($rawTransaction);
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
        $parentTransaction = Double::for(PhpRedis::class)->strict();
        $firstChildTransaction = Double::for(PhpRedis::class)->strict();
        $secondChildTransaction = Double::for(PhpRedis::class)->strict();

        $parentConnection = $this->mockConnection();
        $parentConnection->nativeClientForTest()->expects('multi')->returns($parentTransaction);
        $parentConnection->expects('release');
        $firstChildConnection = $this->mockConnection();
        $firstChildConnection->nativeClientForTest()->expects('multi')->returns($firstChildTransaction);
        $firstChildConnection->expects('release');
        $secondChildConnection = $this->mockConnection();
        $secondChildConnection->nativeClientForTest()->expects('multi')->returns($secondChildTransaction);
        $secondChildConnection->expects('release');

        $redis = $this->createCountingRedis(
            $parentConnection,
            $firstChildConnection,
            $secondChildConnection,
        );
        $redis->multi();
        $contextKey = $this->contextKey($redis);

        $childrenReady = new Channel(2);
        $releaseChildren = new Channel(2);
        $childCoroutineIds = [
            go(static function () use ($redis, $contextKey, $childrenReady, $releaseChildren): void {
                $redis->multi();
                $childrenReady->push(CoroutineContext::get($contextKey));
                $releaseChildren->pop();
            }, copyContext: true),
            go(static function () use ($redis, $contextKey, $childrenReady, $releaseChildren): void {
                $redis->multi();
                $childrenReady->push(CoroutineContext::get($contextKey));
                $releaseChildren->pop();
            }, copyContext: true),
        ];

        try {
            $firstBorrowedConnection = $childrenReady->pop(1.0);
            $secondBorrowedConnection = $childrenReady->pop(1.0);

            $this->assertSame($parentConnection, CoroutineContext::get($contextKey));
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
        $parentTransaction = Double::for(PhpRedis::class)->strict();
        $childTransaction = Double::for(PhpRedis::class)->strict();
        $parentReleased = new Channel(1);
        $childReleased = new Channel(1);

        $parentConnection = $this->mockConnection();
        $parentConnection->nativeClientForTest()->expects('multi')->returns($parentTransaction);
        $parentConnection->expects('release')->resolves(static function () use ($parentReleased): void {
            $parentReleased->push(true);
        });
        $childConnection = $this->mockConnection();
        $childConnection->nativeClientForTest()->expects('multi')->returns($childTransaction);
        $childConnection->expects('release')->resolves(static function () use ($childReleased): void {
            $childReleased->push(true);
        });

        $redis = $this->createCountingRedis($parentConnection, $childConnection);
        $contextKey = $this->contextKey($redis);
        $allowChild = new Channel(1);
        $childBorrowed = new Channel(1);
        $childCoroutineId = new Channel(1);

        $parentCoroutineId = go(static function () use ($redis, $contextKey, $allowChild, $childBorrowed, $childCoroutineId): void {
            $redis->multi();
            $childCoroutineId->push(go(static function () use ($redis, $contextKey, $allowChild, $childBorrowed): void {
                $allowChild->pop();
                $redis->multi();
                $childBorrowed->push(CoroutineContext::get($contextKey));
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
        $setConnection->nativeClientForTest()->expects('set')->with('xxxx', 'yyyy', null)->returns('db:0 name:set argument:xxxx,yyyy');
        $setConnection->expects('release');

        $selectedConnection = $this->mockConnection();
        $selectedConnection->nativeClientForTest()->expects('select')->with(2)->returns(true);
        $selectedConnection->nativeClientForTest()->expects('get')->with('xxxx')->returns('db:2 name:get argument:xxxx');
        $selectedConnection->expects('release');

        $otherCoroutineConnection = $this->mockConnection();
        $otherCoroutineConnection->nativeClientForTest()->expects('get')->with('xxxx')->returns('db:0 name:get argument:xxxx');
        $otherCoroutineConnection->expects('release');

        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('borrow')->times(3)->returns(
            $setConnection,
            $selectedConnection,
            $otherCoroutineConnection,
        );
        $pool->allows('getOptions')->returns(PoolOptions::fromArray([]));

        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('default')->returns($pool);

        $redis = new RedisProxy($poolManager, 'default', $this->sentinelFactory());

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
        $pipeline = Double::for(PhpRedis::class)->strict();

        $pinnedConnection = $this->mockConnection();
        $pinnedConnection->nativeClientForTest()->expects('multi')->returns($pipeline);
        $pinnedConnection->nativeClientForTest()->expects('set')->with('id', '123', null)->returns($pinnedConnection->nativeClientForTest());
        $pinnedConnection->nativeClientForTest()->expects('exec')->returns([]);
        $pinnedConnection->expects('release');

        $otherCoroutineConnection = $this->mockConnection();
        $otherCoroutineConnection->nativeClientForTest()->expects('get')->with('id')->returns('from-other-connection');
        $otherCoroutineConnection->expects('release');

        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('borrow')->times(2)->returns($pinnedConnection, $otherCoroutineConnection);
        $pool->allows('getOptions')->returns(PoolOptions::fromArray([]));

        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('default')->returns($pool);

        $redis = new RedisProxy($poolManager, 'default', $this->sentinelFactory());

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
        $connection->nativeClientForTest()->expects('get')->times(2)->returns('value1', 'value2');
        $connection->expects('release')->never();

        $redis = $this->createRedis($connection);

        // Pre-set connection in context
        CoroutineContext::set($this->contextKey($redis), $connection);

        // Both calls should use the same connection from context
        $result1 = $redis->get('key1');
        $result2 = $redis->get('key2');

        $this->assertSame('value1', $result1);
        $this->assertSame('value2', $result2);
    }

    public function testExceptionIsPropagated(): void
    {
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('get')
            ->throws(new RuntimeException('Redis error'));
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Redis error');

        $redis->get('key');
    }

    public function testReleasesConnectionWhenUnderlyingGetConnectionFails(): void
    {
        $connection = Double::for(PhpRedisConnection::class)->strict();
        $connection->allows('shouldTransform');
        $connection->allows('getEventDispatcher')->returns(null);
        $connection->expects('getConnection')
            ->throws(new RuntimeException('Get connection failed.'));
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Get connection failed.');

        $redis->set('xxxx', 'yyyy');
    }

    public function testExceptionWithContextConnectionDoesNotReleaseConnection(): void
    {
        $expectedException = new Exception('Redis error');

        $mockRedisConnection = $this->createMockRedisConnection('get', null, $expectedException);
        $mockRedisConnection->expects('release')->never();

        $redis = $this->createRedis($mockRedisConnection);

        // Pre-set context connection
        CoroutineContext::set($this->contextKey($redis), $mockRedisConnection);

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
        $mockRedisConnection->expects('release');

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->multi();
            $this->fail('Expected exception was not thrown');
        } catch (Exception $exception) {
            $this->assertSame('Multi failed', $exception->getMessage());
        }

        // Connection should NOT be stored in context on error
        $this->assertNull(CoroutineContext::get($this->contextKey($redis)));
    }

    public function testEventDispatchedOnSuccess(): void
    {
        $mockEventDispatcher = Double::for(Dispatcher::class)->strict();
        $mockEventDispatcher->allows('hasListeners')
            ->with(CommandFailed::class)
            ->returns(false);
        $mockEventDispatcher->allows('hasListeners')
            ->with(CommandExecuted::class)
            ->returns(true);
        $mockEventDispatcher->expects('dispatch')
            ->with(Argument::satisfies(function (CommandExecuted $event) {
                return $event->command === 'get'
                    && $event->parameters === ['key']
                    && $event->connectionName === 'default'
                    && $event->time >= 0.0;
            }));

        $mockRedisConnection = $this->createMockRedisConnection('get', 'value', null, $mockEventDispatcher);
        $mockRedisConnection->expects('release');

        $redis = $this->createRedis($mockRedisConnection);

        $redis->get('key');
    }

    public function testSuccessEventTemporarilyPublishesTheOwnedConnection(): void
    {
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $connection = $this->createMockRedisConnection('get', 'value', eventDispatcher: $dispatcher);
        $redis = $this->createRedis($connection);
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(CommandExecuted::class))
            ->resolves(function () use ($connection, $redis): void {
                $this->assertSame(
                    $connection,
                    CoroutineContext::get($this->contextKey($redis)),
                );
            });
        $connection->expects('release');

        $this->assertSame('value', $redis->get('key'));
        $this->assertFalse(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testSuccessEventPreservesAPreExistingContextConnection(): void
    {
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $connection = $this->createMockRedisConnection('get', 'value', eventDispatcher: $dispatcher);
        $redis = $this->createRedis($connection);
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(CommandExecuted::class))
            ->resolves(function () use ($connection, $redis): void {
                $this->assertSame(
                    $connection,
                    CoroutineContext::get($this->contextKey($redis)),
                );
            });
        $connection->expects('release')->never();
        CoroutineContext::set($this->contextKey($redis), $connection);

        $this->assertSame('value', $redis->get('key'));
        $this->assertSame(
            $connection,
            CoroutineContext::get($this->contextKey($redis)),
        );
    }

    public function testSuccessEventCanRunANestedCommandOnTheOwnedConnection(): void
    {
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $connection = $this->createMockRedisConnection('get', 'outer', eventDispatcher: $dispatcher);
        $connection->nativeClientForTest()->expects('set')->with('nested', 'value', null)->returns(true);
        $connection->expects('release');
        $dispatcher->expects('hasListeners')
            ->times(2)
            ->with(CommandExecuted::class)
            ->returns(true, false);
        $redis = $this->createRedis($connection);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(CommandExecuted::class))
            ->resolves(function () use ($redis): void {
                $this->assertTrue($redis->set('nested', 'value'));
            });

        $this->assertSame('outer', $redis->get('outer'));
        $this->assertFalse(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testEventDispatchedOnErrorWithExceptionInfo(): void
    {
        $expectedException = new Exception('Redis error');

        $mockEventDispatcher = Double::for(Dispatcher::class)->strict();
        $mockEventDispatcher->allows('hasListeners')
            ->with(CommandFailed::class)
            ->returns(true);
        $mockEventDispatcher->allows('hasListeners')
            ->with(CommandExecuted::class)
            ->returns(false);
        $mockEventDispatcher->expects('dispatch')
            ->with(Argument::satisfies(function (CommandFailed $event) use ($expectedException) {
                return $event->command === 'get'
                    && $event->parameters === ['key']
                    && $event->exception === $expectedException
                    && $event->connectionName === 'default'
                    && $event->time !== null
                    && $event->time >= 0.0;
            }));

        $mockRedisConnection = $this->createMockRedisConnection('get', null, $expectedException, $mockEventDispatcher);
        $mockRedisConnection->expects('release');

        $redis = $this->createRedis($mockRedisConnection);

        try {
            $redis->get('key');
        } catch (Exception) {
            // Expected
        }
    }

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'wrapped cancellation')]
    public function testCommandCancellationSkipsFailureEventAndStillReleasesTheConnection(bool $wrapped): void
    {
        $cancellation = new CanceledException('command canceled');
        if ($wrapped) {
            $cancellation = new RuntimeException('Command cleanup failed.', previous: $cancellation);
        }
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('hasListeners')->never();
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
        $nativeFailure = new RedisException('command canceled', previous: new CanceledException);
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('hasListeners')->never();
        $connection = $this->createMockRedisConnection(
            'get',
            exception: $nativeFailure,
            eventDispatcher: $dispatcher,
        );
        $connection->expects('release');
        $exception = null;

        try {
            $this->createRedis($connection)->get('key');
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(CanceledException::class, $exception);
        $this->assertSame('Executing Redis command [get] was canceled.', $exception->getMessage());
        $this->assertSame($nativeFailure, $exception->getPrevious());
    }

    public function testFailureEventTemporarilyPublishesTheOwnedConnection(): void
    {
        $commandException = new RuntimeException('Command failed.');
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $connection = $this->createMockRedisConnection('get', exception: $commandException, eventDispatcher: $dispatcher);
        $redis = $this->createRedis($connection);
        $dispatcher->expects('hasListeners')->with(CommandFailed::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(CommandFailed::class))
            ->resolves(function () use ($connection, $redis): void {
                $this->assertSame(
                    $connection,
                    CoroutineContext::get($this->contextKey($redis)),
                );
            });
        $connection->expects('release');

        try {
            $redis->get('key');
            $this->fail('Expected the command failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($commandException, $throwable);
        }

        $this->assertFalse(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testFailureEventCanRunANestedCommandOnTheOwnedConnection(): void
    {
        $commandException = new RuntimeException('Command failed.');
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $connection = $this->createMockRedisConnection('get', exception: $commandException, eventDispatcher: $dispatcher);
        $connection->nativeClientForTest()->expects('set')->with('nested', 'recovered', null)->returns(true);
        $connection->expects('release');
        $dispatcher->expects('hasListeners')->with(CommandFailed::class)->returns(true);
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->returns(false);
        $redis = $this->createRedis($connection);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(CommandFailed::class))
            ->resolves(function () use ($redis): void {
                $this->assertTrue($redis->set('nested', 'recovered'));
            });

        try {
            $redis->get('outer');
            $this->fail('Expected the outer command failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($commandException, $throwable);
        }

        $this->assertFalse(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testMultiListenerQueuesNestedCommandsOnTheOwnedTransaction(): void
    {
        $transaction = Double::for(PhpRedis::class)->strict();
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $connection = $this->createMockRedisConnection('multi', $transaction, eventDispatcher: $dispatcher);
        $connection->nativeClientForTest()->expects('set')->with('nested', 'queued', null)->returns($transaction);
        $connection->expects('release');
        $dispatcher->expects('hasListeners')
            ->times(2)
            ->with(CommandExecuted::class)
            ->returns(true, false);
        $redis = $this->createRedis($connection);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(CommandExecuted::class))
            ->resolves(function () use ($redis, $transaction): void {
                $this->assertSame($transaction, $redis->set('nested', 'queued'));
            });

        $this->assertSame($transaction, $redis->multi());
        $this->assertSame(
            $connection,
            CoroutineContext::get($this->contextKey($redis)),
        );
    }

    public function testThrowingSuccessListenerStillReleasesOrdinaryConnection(): void
    {
        $eventException = new RuntimeException('Success listener failed.');
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->returns(true);
        $dispatcher->expects('dispatch')->throws($eventException);
        $connection = $this->createMockRedisConnection('get', 'value', null, $dispatcher);
        $connection->expects('release');
        $redis = $this->createRedis($connection);

        try {
            $redis->get('key');
            $this->fail('Expected the event listener failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($eventException, $throwable);
        }

        $this->assertFalse(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testThrowingSuccessListenerDoesNotSkipSameConnectionHandoff(): void
    {
        $eventException = new RuntimeException('Success listener failed.');
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->returns(true);
        $dispatcher->expects('dispatch')->throws($eventException);
        $transaction = Double::for(PhpRedis::class)->strict();
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
            CoroutineContext::get($this->contextKey($redis))
        );
    }

    public function testThrowingFailureListenerStillReleasesAndReplacesCommandFailure(): void
    {
        $commandException = new RuntimeException('Command failed.');
        $eventException = new RuntimeException('Failure listener failed.');
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('hasListeners')->with(CommandFailed::class)->returns(true);
        $dispatcher->expects('dispatch')->throws($eventException);
        $connection = $this->createMockRedisConnection('get', null, $commandException, $dispatcher);
        $connection->expects('release');
        $redis = $this->createRedis($connection);

        try {
            $redis->get('key');
            $this->fail('Expected the event listener failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($eventException, $throwable);
        }

        $this->assertFalse(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testCommandFailureRemainsPrimaryOverCleanupFailure(): void
    {
        $commandException = new RuntimeException('Command failed.');
        $connection = $this->createMockRedisConnection('get', null, $commandException);
        $connection->expects('release')->throws(new RuntimeException('Cleanup failed.'));

        try {
            $this->createRedis($connection)->get('key');
            $this->fail('Expected the command failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($commandException, $throwable);
        }
    }

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'wrapped cancellation')]
    public function testCleanupCancellationSupersedesOrdinaryCommandFailure(bool $wrapped): void
    {
        $cancellation = new CanceledException('cleanup canceled');
        if ($wrapped) {
            $cancellation = new RuntimeException('Release cleanup failed.', previous: $cancellation);
        }
        $connection = $this->createMockRedisConnection(
            'get',
            exception: new RuntimeException('Command failed.'),
        );
        $connection->expects('release')->throws($cancellation);

        try {
            $this->createRedis($connection)->get('key');
            $this->fail('Expected the cleanup cancellation to escape.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'wrapped cancellation')]
    public function testEventCancellationRemainsPrimaryOverOrdinaryCleanupFailure(bool $wrapped): void
    {
        $cancellation = new CanceledException('event canceled');
        if ($wrapped) {
            $cancellation = new RuntimeException('Event cleanup failed.', previous: $cancellation);
        }
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('hasListeners')->with(CommandExecuted::class)->returns(true);
        $dispatcher->expects('dispatch')->throws($cancellation);
        $connection = $this->createMockRedisConnection(
            'get',
            'value',
            eventDispatcher: $dispatcher,
        );
        $connection->expects('release')->throws(new RuntimeException('Cleanup failed.'));

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
        $connection->expects('release')->throws($cleanupException);

        try {
            $this->createRedis($connection)->get('key');
            $this->fail('Expected the cleanup failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($cleanupException, $throwable);
        }
    }

    public function testCallbackTransactionClearsWatchStateAfterSuccessfulExec(): void
    {
        $transaction = Double::for(PhpRedis::class)->strict();
        $transaction->expects('exec')->returns([]);
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('multi')->returns($transaction);
        $connection->expects('clearWatchState');
        $connection->expects('release')->never();
        $redis = $this->createRedis($connection);
        CoroutineContext::set($this->contextKey($redis), $connection);

        $this->assertSame(
            [],
            $redis->transaction(static function (): void {
            })
        );
    }

    public function testCallbackTransactionClearsWatchStateAfterOptimisticLockConflict(): void
    {
        $transaction = Double::for(PhpRedis::class)->strict();
        $transaction->expects('exec')->returns(false);
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('multi')->returns($transaction);
        $connection->expects('clearWatchState');
        $connection->expects('release')->never();
        $redis = $this->createRedis($connection);
        CoroutineContext::set($this->contextKey($redis), $connection);

        $this->assertFalse(
            $redis->transaction(static function (): void {
            })
        );
    }

    public function testCallbackTransactionInvalidatesWithoutClearingWatchStateWhenExecThrows(): void
    {
        $transaction = Double::for(PhpRedis::class)->strict();
        $transaction->expects('exec')->throws(new RuntimeException('Exec failed.'));
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('multi')->returns($transaction);
        $connection->expects('invalidate');
        $connection->expects('clearWatchState')->never();
        $connection->expects('release')->never();
        $redis = $this->createRedis($connection);
        CoroutineContext::set($this->contextKey($redis), $connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Exec failed.');

        $redis->transaction(static function (): void {
        });
    }

    public function testCallbackTransactionPreservesOperationCancellationOverCleanupFailure(): void
    {
        $cancellation = new CanceledException('exec canceled');
        $transaction = Double::for(PhpRedis::class)->strict();
        $transaction->expects('exec')->throws($cancellation);
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('multi')->returns($transaction);
        $connection->expects('invalidate');
        $connection->expects('release')->throws(new RuntimeException('Cleanup failed.'));

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
        $nativeFailure = new RedisException('exec canceled', previous: new CanceledException);
        $transaction = Double::for(PhpRedis::class)->strict();
        $transaction->expects('exec')->throws($nativeFailure);
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('multi')->returns($transaction);
        $connection->expects('invalidate');
        $connection->expects('release');
        $exception = null;

        try {
            $this->createRedis($connection)->transaction(static function (): void {
            });
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(CanceledException::class, $exception);
        $this->assertSame('Executing the Redis transaction was canceled.', $exception->getMessage());
        $this->assertSame($nativeFailure, $exception->getPrevious());
    }

    public function testCallbackTransactionCleanupCancellationSupersedesOrdinaryOperationFailure(): void
    {
        $cancellation = new CanceledException('cleanup canceled');
        $transaction = Double::for(PhpRedis::class)->strict();
        $transaction->expects('exec')->throws(new RuntimeException('Exec failed.'));
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('multi')->returns($transaction);
        $connection->expects('invalidate');
        $connection->expects('release')->throws($cancellation);

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
        $transaction = Double::for(PhpRedis::class)->strict();
        $transaction->expects('exec')->throws(new RuntimeException('Exec failed.'));
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('multi')->returns($transaction);
        $connection->expects('invalidate');
        $connection->expects('release')->throws($cleanupFailure);

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
        $pipeline = Double::for(PhpRedis::class)->strict();
        $pipeline->expects('exec')->returns([]);
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('pipeline')->returns($pipeline);
        $connection->expects('clearWatchState')->never();
        $connection->expects('release')->never();
        $redis = $this->createRedis($connection);
        CoroutineContext::set($this->contextKey($redis), $connection);

        $this->assertSame(
            [],
            $redis->pipeline(static function (): void {
            })
        );
    }

    public function testRegularCommandDoesNotStoreConnectionInContext(): void
    {
        $mockRedisConnection = $this->mockConnection();
        $mockRedisConnection->expects('release');
        $redis = $this->createRedis($mockRedisConnection);
        $mockRedisConnection->nativeClientForTest()->expects('get')
            ->with('key')
            ->resolves(function () use ($redis): string {
                $this->assertFalse(CoroutineContext::has($this->contextKey($redis)));

                return 'value';
            });

        $redis->get('key');

        $this->assertNull(CoroutineContext::get($this->contextKey($redis)));
    }

    public function testWithConnectionExecutesCallbackAndReleasesConnection(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('release');

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
        $connection->expects('release')->never();

        $redis = $this->createRedis($connection);

        // Pre-set connection in context (simulating an active multi/pipeline)
        CoroutineContext::set($this->contextKey($redis), $connection);

        $result = $redis->withConnection(function (RedisConnection $redisConnection) use ($connection) {
            $this->assertSame($connection, $redisConnection);

            return 'reused-connection';
        });

        $this->assertSame('reused-connection', $result);
        // Connection should still be in context
        $this->assertTrue(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testWithConnectionReleasesOnException(): void
    {
        $connection = $this->mockConnection();
        // Should release even on exception
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Callback failed');

        $redis->withConnection(function (RedisConnection $redisConnection) {
            throw new RuntimeException('Callback failed');
        });
    }

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'wrapped cancellation')]
    public function testWithConnectionPreservesCallbackCancellationOverCleanupFailure(bool $wrapped): void
    {
        $cancellation = new CanceledException('Callback canceled.');
        if ($wrapped) {
            $cancellation = new RuntimeException('Callback cleanup failed.', previous: $cancellation);
        }
        $connection = $this->mockConnection();
        $connection->expects('release')->throws(new RuntimeException('Cleanup failed.'));

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
        $connection->expects('release')->throws($cancellation);

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
        $connection->expects('release')->throws($cleanupFailure);

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
        $connection->expects('release')->never();

        $redis = $this->createRedis($connection);

        CoroutineContext::set($this->contextKey($redis), $connection);

        try {
            $redis->withConnection(function (RedisConnection $redisConnection) {
                throw new RuntimeException('Callback failed');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Callback failed', $exception->getMessage());
        }

        // Connection should still be in context
        $this->assertTrue(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testWithConnectionDefaultsToTransformTrue(): void
    {
        $connection = Double::for(PhpRedisConnection::class)->strict();
        $connection->allows('getConnection')->returns($connection);
        $connection->allows('getEventDispatcher')->returns(null);
        $connection->expects('shouldTransform')
            ->with(true);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $redis->withConnection(function (RedisConnection $redisConnection) {
            return 'result';
        });
    }

    public function testWithConnectionRespectsTransformFalse(): void
    {
        $connection = Double::for(PhpRedisConnection::class)->strict();
        $connection->allows('getConnection')->returns($connection);
        $connection->allows('getEventDispatcher')->returns(null);
        $connection->expects('shouldTransform')
            ->with(false);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $redis->withConnection(function (RedisConnection $redisConnection) {
            return 'result';
        }, transform: false);
    }

    public function testWithConnectionRespectsTransformTrueExplicit(): void
    {
        $connection = Double::for(PhpRedisConnection::class)->strict();
        $connection->allows('getConnection')->returns($connection);
        $connection->allows('getEventDispatcher')->returns(null);
        $connection->expects('shouldTransform')
            ->with(true);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $redis->withConnection(function (RedisConnection $redisConnection) {
            return 'result';
        }, transform: true);
    }

    public function testWithConnectionAllowsMultipleOperationsOnSameConnection(): void
    {
        $connection = $this->mockConnection();
        $client = $connection->nativeClientForTest();
        $client->expects('evalSha')
            ->with('sha123', ['key'], 1)
            ->returns(false);
        $client->expects('getLastError')
            ->returns('NOSCRIPT No matching script');

        $connection->expects('release');

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
        $connection->expects('withoutSerializationOrCompression')
            ->resolves(fn (callable $callback) => $callback());
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $result = $redis->withoutSerializationOrCompression(function () use ($connection, $redis) {
            // Connection must be pinned in Context during callback
            $this->assertSame($connection, CoroutineContext::get($this->contextKey($redis)));

            return 'result';
        });

        $this->assertSame('result', $result);
        // Connection should be unpinned after completion
        $this->assertNull(CoroutineContext::get($this->contextKey($redis)));
    }

    public function testWithPinnedConnectionPreservesCallbackCancellationAndUnpinsBeforeCleanup(): void
    {
        $cancellation = new CanceledException('Callback canceled.');
        $connection = $this->mockConnection();
        $redis = $this->createRedis($connection);
        $connection->expects('release')->resolves(function () use ($redis): void {
            $this->assertFalse(CoroutineContext::has($this->contextKey($redis)));

            throw new RuntimeException('Cleanup failed.');
        });

        try {
            $redis->withPinnedConnection(
                static function () use ($cancellation): never {
                    throw $cancellation;
                },
            );
            $this->fail('Expected the callback cancellation to escape.');
        } catch (Throwable $throwable) {
            $this->assertSame($cancellation, $throwable);
        }

        $this->assertFalse(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testWithoutSerializationOrCompressionReusesExistingContextConnection(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('withoutSerializationOrCompression')
            ->resolves(fn (callable $callback) => $callback());
        // Should NOT release since connection was already in context
        $connection->expects('release')->never();

        $redis = $this->createRedis($connection);

        // Pre-set connection in context (simulating an active multi/pipeline)
        CoroutineContext::set($this->contextKey($redis), $connection);

        $result = $redis->withoutSerializationOrCompression(function () use ($connection, $redis) {
            // Connection should still be the same one that was pre-set
            $this->assertSame($connection, CoroutineContext::get($this->contextKey($redis)));

            return 'reused';
        });

        $this->assertSame('reused', $result);
        // Connection should still be in context
        $this->assertTrue(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testWithoutSerializationOrCompressionCleansUpOnException(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('withoutSerializationOrCompression')
            ->resolves(function (callable $callback) {
                return $callback();
            });
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        try {
            $redis->withoutSerializationOrCompression(function () use ($connection, $redis) {
                // Connection must be pinned even when callback throws
                $this->assertSame($connection, CoroutineContext::get($this->contextKey($redis)));

                throw new RuntimeException('Callback failed');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Callback failed', $exception->getMessage());
        }

        // Connection should be unpinned and released
        $this->assertNull(CoroutineContext::get($this->contextKey($redis)));
    }

    public function testWithoutSerializationOrCompressionDoesNotReleaseContextConnectionOnException(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('withoutSerializationOrCompression')
            ->resolves(function (callable $callback) {
                return $callback();
            });
        // Should NOT release since connection was already in context
        $connection->expects('release')->never();

        $redis = $this->createRedis($connection);

        CoroutineContext::set($this->contextKey($redis), $connection);

        try {
            $redis->withoutSerializationOrCompression(function () use ($connection, $redis) {
                // Connection should still be pinned during callback
                $this->assertSame($connection, CoroutineContext::get($this->contextKey($redis)));

                throw new RuntimeException('Callback failed');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Callback failed', $exception->getMessage());
        }

        // Connection should still be in context
        $this->assertTrue(CoroutineContext::has($this->contextKey($redis)));
    }

    public function testSubscriberUsesTheCompleteStandaloneConfiguration(): void
    {
        $server = new RespServer;
        $server->start(static function ($client): void {
            fread($client, 1);
        });
        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('getConfig')->returns($this->standaloneConfig([
            'host' => $server->endpoint(),
            'port' => 6379,
            'timeout' => 2.5,
            'options' => ['prefix' => 'app:'],
        ]));
        $pool->expects('borrow')->never();
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->with('default')->returns($pool);
        $subscriber = (new RedisProxy(
            $poolManager,
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
        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('getConfig')->times(2)->returns($config);
        $pool->expects('borrow')->never();
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->times(2)->with('default')->returns($pool);
        $sentinelFactory = Double::for(RedisSentinelFactory::class)->strict();
        $sentinelFactory->expects('resolveMaster')
            ->times(2)
            ->with($config)
            ->returns([$firstHost, $firstPort], [$secondHost, $secondPort]);
        $proxy = new RedisProxy($poolManager, 'default', $sentinelFactory);
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
        $connection = Double::for(PhpRedisClusterConnection::class)->strict();
        $connection->expects('getConnection');
        $connection->expects('masters')->returns([
            ['127.0.0.1', 1],
            [$host, $port],
        ]);
        $connection->expects('release')->resolves(static function () use (&$released): void {
            $released = true;
        });
        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('getConfig')->returns($config);
        $pool->expects('borrow')->returns($connection);
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->with('default')->returns($pool);
        $subscriber = (new RedisProxy(
            $poolManager,
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
        $connection = Double::for(PhpRedisClusterConnection::class)->strict();
        $connection->expects('getConnection');
        $connection->expects('masters')->returns([[$host, $port]]);
        $connection->expects('release')->resolves(static function () use (&$released): void {
            $released = true;
        });
        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('getConfig')->returns($config);
        $pool->expects('borrow')->returns($connection);
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->with('default')->returns($pool);
        $subscriber = (new RedisProxy(
            $poolManager,
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
        $connection = Double::for(PhpRedisClusterConnection::class)->strict();
        $connection->expects('getConnection');
        $connection->expects('masters')->returns([
            ['127.0.0.1', 1],
            ['127.0.0.1', 2],
        ]);
        $connection->expects('release');
        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('getConfig')->returns($config);
        $pool->expects('borrow')->returns($connection);
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->with('default')->returns($pool);

        try {
            (new RedisProxy(
                $poolManager,
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
        $connection = Double::for(PhpRedisClusterConnection::class)->strict();
        $connection->expects('getConnection');
        $connection->expects('masters')->throws($discoveryException);
        $connection->expects('release')->throws(new RuntimeException('Release failed.'));
        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('getConfig')->returns([
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tcp://127.0.0.1:6379'],
            ],
        ]);
        $pool->expects('borrow')->returns($connection);
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->with('default')->returns($pool);

        try {
            (new RedisProxy(
                $poolManager,
                'default',
                $this->sentinelFactory(),
            ))->subscriber();
            $this->fail('Expected Cluster discovery to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($discoveryException, $exception);
        }
    }

    public function testPinnedClusterDiscoveryFailureKeepsTheCallersConnection(): void
    {
        $failure = new RuntimeException('Master discovery failed.');
        $connection = Double::for(PhpRedisClusterConnection::class)->strict();
        $connection->expects('shouldTransform')->with(true);
        $connection->expects('getConnection')->times(2);
        $connection->expects('masters')->throws($failure);
        $connection->expects('release');
        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('getConfig')->returns($this->clusterConfig());
        $pool->expects('borrow')->returns($connection);
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('default')->returns($pool);
        $redis = new RedisProxy($poolManager, 'default', $this->sentinelFactory());

        $redis->withPinnedConnection(function () use ($redis, $failure, $connection): void {
            try {
                $redis->subscriber();
                $this->fail('Expected Cluster discovery to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }

            $this->assertSame(
                $connection,
                CoroutineContext::get($this->contextKey($redis)),
            );
        });
    }

    public function testClusterDiscoveryCancellationIsPreservedAndStillReleases(): void
    {
        $cancellation = new RedisClusterException('Master discovery canceled.', previous: new CanceledException);
        $connection = Double::for(PhpRedisClusterConnection::class)->strict();
        $connection->expects('getConnection');
        $connection->expects('masters')->throws($cancellation);
        $connection->expects('release')->throws(new RuntimeException('Release failed.'));
        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('getConfig')->returns([
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tcp://127.0.0.1:6379'],
            ],
        ]);
        $pool->expects('borrow')->returns($connection);
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->with('default')->returns($pool);

        try {
            (new RedisProxy($poolManager, 'default', $this->sentinelFactory()))->subscriber();
            $this->fail('Expected the discovery cancellation to escape.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'wrapped cancellation')]
    public function testClusterDiscoveryReleaseCancellationSupersedesOrdinaryDiscoveryFailure(bool $wrapped): void
    {
        $cancellation = new CanceledException('Release canceled.');
        if ($wrapped) {
            $cancellation = new RuntimeException('Release cleanup failed.', previous: $cancellation);
        }
        $connection = Double::for(PhpRedisClusterConnection::class)->strict();
        $connection->expects('getConnection');
        $connection->expects('masters')->throws(new RuntimeException('Master discovery failed.'));
        $connection->expects('release')->throws($cancellation);
        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('getConfig')->returns([
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tcp://127.0.0.1:6379'],
            ],
        ]);
        $pool->expects('borrow')->returns($connection);
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->with('default')->returns($pool);

        try {
            (new RedisProxy(
                $poolManager,
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

            if (is_array($type)) {
                foreach ($parameter->getType()?->getTypes() ?? [] as $namedType) {
                    $this->assertContains($namedType->getName(), $type);
                }

                continue;
            }

            $this->assertSame($type, $parameter->getType()?->getName());
        }

        $this->assertSame([], $names);
    }

    public function testRedisSentinelConstructorSignature(): void
    {
        $reflection = new ReflectionClass(RedisSentinel::class);
        $method = $reflection->getMethod('__construct');
        $this->assertCount(1, $method->getParameters());
        $this->assertSame('options', $method->getParameters()[0]->getName());
    }

    public function testFlushByPatternDelegatesToConnection(): void
    {
        $connection = $this->mockConnection();
        $connection->expects('flushByPattern')
            ->with('cache:*')
            ->returns(42);
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $result = $redis->flushByPattern('cache:*');

        $this->assertSame(42, $result);
    }

    public function testIsClusterReturnsFalseForStandardConfig(): void
    {
        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('getConfig')->returns([
            'host' => '127.0.0.1',
            'port' => 6379,
        ]);
        $pool->expects('borrow')->never();

        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('default')->returns($pool);

        $redis = new RedisProxy($poolManager, 'default', $this->sentinelFactory());

        $this->assertFalse($redis->isCluster());
    }

    public function testIsClusterReturnsTrueForClusterConfig(): void
    {
        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('getConfig')->returns([
            'cluster' => ['enabled' => true, 'seeds' => ['tcp://127.0.0.1:6379']],
        ]);
        $pool->expects('borrow')->never();

        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('cache')->returns($pool);

        $proxy = new RedisProxy($poolManager, 'cache', $this->sentinelFactory());

        $this->assertTrue($proxy->isCluster());
    }

    public function testProxyUsesSpecifiedPoolName(): void
    {
        $cacheConnection = $this->mockConnection();
        $cacheConnection->nativeClientForTest()->expects('get')->with('key')->returns('cached');
        $cacheConnection->expects('release');

        $cachePool = Double::for(RedisPool::class)->strict();
        $cachePool->allows('borrow')->returns($cacheConnection);

        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('cache')->returns($cachePool);

        $proxy = new RedisProxy($poolManager, 'cache', $this->sentinelFactory());

        $result = $proxy->get('key');

        $this->assertSame('cached', $result);
    }

    public function testProxyContextKeyUsesPoolName(): void
    {
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('pipeline')->returns(Double::for(PhpRedis::class)->strict());
        // Connection is released via defer() at end of coroutine
        $connection->expects('release');

        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns($connection);

        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('cache')->returns($pool);

        $proxy = new RedisProxy($poolManager, 'cache', $this->sentinelFactory());
        $defaultProxy = new RedisProxy($poolManager, 'default', $this->sentinelFactory());

        $proxy->pipeline();

        // Context key should use the pool name
        $this->assertTrue(CoroutineContext::has($this->contextKey($proxy)));
        $this->assertFalse(CoroutineContext::has($this->contextKey($defaultProxy)));
    }

    public function testSameNamedConnectionsOnDifferentPoolManagersKeepSeparatePins(): void
    {
        $firstConnection = $this->mockConnection();
        $firstConnection->nativeClientForTest()->expects('multi')->returns(Double::for(PhpRedis::class)->strict());
        $firstConnection->nativeClientForTest()->expects('get')->with('key')->returns('first');
        $firstConnection->expects('release');
        $secondConnection = $this->mockConnection();
        $secondConnection->nativeClientForTest()->expects('multi')->returns(Double::for(PhpRedis::class)->strict());
        $secondConnection->expects('discard');
        $first = $this->createRedis($firstConnection);
        $second = $this->createRedis($secondConnection);

        $first->multi();
        $second->multi();
        $second->discardContextConnection();

        $this->assertSame('first', $first->get('key'));
    }

    public function testProxiesForTheSamePoolManagerAndNameSharePins(): void
    {
        $connection = $this->mockConnection();
        $connection->nativeClientForTest()->expects('multi')->returns(Double::for(PhpRedis::class)->strict());
        $connection->nativeClientForTest()->expects('get')->with('key')->returns('value');
        $connection->expects('release');
        $pool = Double::for(RedisPool::class)->strict();
        $pool->expects('borrow')->returns($connection);
        $pool->allows('getOptions')->returns(PoolOptions::fromArray([]));
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('default')->returns($pool);
        $first = new RedisProxy($poolManager, 'default', $this->sentinelFactory());
        $second = new RedisProxy($poolManager, 'default', $this->sentinelFactory());

        $first->multi();

        $this->assertSame('value', $second->get('key'));
    }

    public function testDeferredCleanupReleasesEachPoolManagersPinnedConnection(): void
    {
        $firstConnection = $this->mockConnection();
        $firstConnection->nativeClientForTest()->expects('multi')->returns(Double::for(PhpRedis::class)->strict());
        $firstConnection->expects('release');
        $secondConnection = $this->mockConnection();
        $secondConnection->nativeClientForTest()->expects('multi')->returns(Double::for(PhpRedis::class)->strict());
        $secondConnection->expects('release');
        $first = $this->createRedis($firstConnection);
        $second = $this->createRedis($secondConnection);

        $coroutineId = go(static function () use ($first, $second): void {
            $first->multi();
            $second->multi();
        });

        Coroutine::join([$coroutineId], 1.0);

        $this->assertFalse(Coroutine::exists($coroutineId));
    }

    /**
     * Create a real connection wrapper with a strict native client.
     */
    private function mockConnection(): PhpRedisConnectionStub&DoubleInterface
    {
        $client = Double::for(PhpRedis::class)->strict();
        $client->allows('getMode')->returns(PhpRedis::ATOMIC);

        return Double::for((new PhpRedisConnectionStub)->setActiveConnection($client))->passthru();
    }

    /**
     * Create a RedisProxy instance with the given mock connection.
     */
    private function createRedis(RedisConnection&DoubleInterface $connection): RedisProxy
    {
        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns($connection);
        $pool->allows('getOptions')->returns(PoolOptions::fromArray([]));

        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('default')->returns($pool);

        return new RedisProxy($poolManager, 'default', $this->sentinelFactory());
    }

    /**
     * Get the coroutine context key of the given proxy's pinned connection.
     */
    private function contextKey(RedisProxy $redis): string
    {
        return (fn (): string => $this->getContextKey())->call($redis);
    }

    /**
     * Create a Redis proxy that returns the given subscriber.
     */
    private function createRedisWithSubscriber(Subscriber $subscriber): RedisProxy
    {
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('pool')->never();

        return new class($poolManager, 'default', $this->sentinelFactory(), $subscriber) extends RedisProxy {
            /**
             * Create a proxy with the configured subscriber.
             */
            public function __construct(
                PoolManager $poolManager,
                string $name,
                RedisSentinelFactory $sentinelFactory,
                private Subscriber $subscriber,
            ) {
                parent::__construct($poolManager, $name, $sentinelFactory);
            }

            /**
             * Return the configured subscriber.
             */
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
    ): Subscriber&DoubleInterface {
        $subscriber = Double::for(Subscriber::class)->strict();
        $subscriber->closed = false;
        $subscriber->expects('subscribe')->with('updates');
        $subscriber->expects('channel')->returns($channel);
        $close = $subscriber->expects('close');

        if ($closeFailure !== null) {
            $close->throws($closeFailure);
        }

        return $subscriber;
    }

    /**
     * Create a release-counting Redis proxy with the given mock connections.
     */
    private function createCountingRedis(
        RedisConnection&DoubleInterface ...$connections
    ): RedisProxyReleaseCountingStub {
        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns(...$connections);
        $pool->allows('getOptions')->returns(PoolOptions::fromArray([]));

        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('default')->returns($pool);

        return new RedisProxyReleaseCountingStub(
            $poolManager,
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
    ): PhpRedisConnectionStub&DoubleInterface {
        $connection = $this->mockConnection();
        $expectation = $connection->nativeClientForTest()->allows($command);

        if ($exception !== null) {
            $expectation->throws($exception);
        } else {
            $expectation->returns($returnValue);
        }

        $connection->allows('getEventDispatcher')->returns($eventDispatcher);
        $connection->allows('getName')->returns('default');

        return $connection;
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
            'tcp_keepalive' => 0,
            'pool' => [
                'min_retained_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat_interval' => null,
                'heartbeat_timeout' => 1.0,
                'max_idle_time' => 60.0,
                'max_lifetime' => null,
            ],
        ];
    }

    /**
     * Get a mocked Sentinel factory.
     */
    private function sentinelFactory(): RedisSentinelFactory
    {
        return Double::for(RedisSentinelFactory::class)->strict();
    }
}

class RedisProxyReleaseCountingStub extends RedisProxy
{
    public int $contextAbsentReleaseCalls = 0;

    /**
     * Record cleanup calls made without a pinned connection.
     */
    public function releaseContextConnection(): void
    {
        $hasContextConnection = CoroutineContext::has($this->getContextKey());

        parent::releaseContextConnection();

        if (! $hasContextConnection) {
            ++$this->contextAbsentReleaseCalls;
        }
    }
}
