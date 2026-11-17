<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Redis as PhpRedis;
use RuntimeException;

class MultiExecTest extends TestCase
{
    use VerifiesDoubles;

    public function testPipelineWithoutCallbackReturnsInstanceForChaining(): void
    {
        $pipelineInstance = Double::for(PhpRedis::class)->strict();

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('pipeline')->returns($pipelineInstance);

        $connection = $this->createMockConnection($phpRedis);
        // Connection is stored in context and released via defer() at end of coroutine
        $connection->expects('release');
        $redis = $this->createRedis($connection);

        $result = $redis->pipeline();

        // Without callback, returns the pipeline instance for chaining
        $this->assertSame($pipelineInstance, $result);
    }

    public function testPipelineWithCallbackExecutesAndReturnsResults(): void
    {
        $execResults = ['OK', 'OK', 'value'];

        $pipelineInstance = Double::for(PhpRedis::class)->strict();
        $pipelineInstance->expects('set')->times(2)->returns($pipelineInstance);
        $pipelineInstance->expects('get')->returns($pipelineInstance);
        $pipelineInstance->expects('exec')->returns($execResults);

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('pipeline')->returns($pipelineInstance);

        $connection = $this->createMockConnection($phpRedis);
        $connection->expects('release');
        $redis = $this->createRedis($connection);

        $result = $redis->pipeline(function ($pipe) {
            $pipe->set('key1', 'value1');
            $pipe->set('key2', 'value2');
            $pipe->get('key1');
        });

        $this->assertSame($execResults, $result);
    }

    public function testPipelineWithCallbackReturnsFalseWhenExecAborts(): void
    {
        $pipelineInstance = Double::for(PhpRedis::class)->strict();
        $pipelineInstance->expects('exec')->returns(false);

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('pipeline')->returns($pipelineInstance);

        $connection = $this->createMockConnection($phpRedis);
        $connection->expects('release');

        $result = $this->createRedis($connection)->pipeline(static function (): void {
        });

        $this->assertFalse($result);
    }

    public function testTransactionWithoutCallbackReturnsInstanceForChaining(): void
    {
        $multiInstance = Double::for(PhpRedis::class)->strict();

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('multi')->returns($multiInstance);

        $connection = $this->createMockConnection($phpRedis);
        // Connection is stored in context and released via defer() at end of coroutine
        $connection->expects('release');
        $redis = $this->createRedis($connection);

        $result = $redis->transaction();

        // Without callback, returns the multi instance for chaining
        $this->assertSame($multiInstance, $result);
    }

    public function testTransactionWithCallbackExecutesAndReturnsResults(): void
    {
        $execResults = ['OK', 5];

        $multiInstance = Double::for(PhpRedis::class)->strict();
        $multiInstance->expects('set')->returns($multiInstance);
        $multiInstance->expects('incr')->returns($multiInstance);
        $multiInstance->expects('exec')->returns($execResults);

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('multi')->returns($multiInstance);

        $connection = $this->createMockConnection($phpRedis);
        $connection->expects('release');
        $redis = $this->createRedis($connection);

        $result = $redis->transaction(function ($tx) {
            $tx->set('key', 'value');
            $tx->incr('counter');
        });

        $this->assertSame($execResults, $result);
    }

    public function testTransactionWithCallbackReturnsFalseWhenExecAborts(): void
    {
        $multiInstance = Double::for(PhpRedis::class)->strict();
        $multiInstance->expects('exec')->returns(false);

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('multi')->returns($multiInstance);

        $connection = $this->createMockConnection($phpRedis);
        $connection->expects('release');

        $result = $this->createRedis($connection)->transaction(static function (): void {
        });

        $this->assertFalse($result);
    }

    public function testPipelineWithCallbackDoesNotReleaseExistingContextConnection(): void
    {
        $pipelineInstance = Double::for(PhpRedis::class)->strict();
        $pipelineInstance->expects('exec')->returns([]);

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('pipeline')->returns($pipelineInstance);

        $connection = $this->createMockConnection($phpRedis);
        $redis = $this->createRedis($connection);
        // Set up existing connection in context BEFORE the pipeline call
        CoroutineContext::set($this->contextKey($redis), $connection);

        $connection->expects('release')->never();

        $redis->pipeline(function ($pipe) {
            // empty callback
        });
    }

    public function testPipelineWithCallbackInvalidatesAndReleasesOnException(): void
    {
        $pipelineInstance = Double::for(PhpRedis::class)->strict();
        // exec throws exception
        $pipelineInstance->expects('exec')->throws(new RuntimeException('Redis error'));

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('pipeline')->returns($pipelineInstance);

        $connection = $this->createMockConnection($phpRedis);
        $connection->expects('invalidate');
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Redis error');

        $redis->pipeline(function ($pipe) {
            // callback runs, but exec will throw
        });
    }

    public function testTransactionWithCallbackDoesNotReleaseExistingContextConnection(): void
    {
        $multiInstance = Double::for(PhpRedis::class)->strict();
        $multiInstance->expects('exec')->returns([]);

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('multi')->returns($multiInstance);

        $connection = $this->createMockConnection($phpRedis);
        $redis = $this->createRedis($connection);
        // Set up existing connection in context BEFORE the transaction call
        CoroutineContext::set($this->contextKey($redis), $connection);

        $connection->expects('release')->never();
        $connection->expects('clearWatchState');

        $redis->transaction(function ($tx) {
            // empty callback
        });
    }

    public function testTransactionWithCallbackInvalidatesAndReleasesOnException(): void
    {
        $multiInstance = Double::for(PhpRedis::class)->strict();
        $multiInstance->expects('exec')->throws(new RuntimeException('Transaction failed'));

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('multi')->returns($multiInstance);

        $connection = $this->createMockConnection($phpRedis);
        $connection->expects('invalidate');
        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Transaction failed');

        $redis->transaction(function ($tx) {
            // callback runs, but exec will throw
        });
    }

    public function testTransactionWithCallbackInvalidatesExistingContextConnectionOnException(): void
    {
        $exception = new RuntimeException('Transaction failed');
        $multiInstance = Double::for(PhpRedis::class)->strict();
        $multiInstance->expects('exec')->throws($exception);

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('multi')->returns($multiInstance);

        $connection = $this->createMockConnection($phpRedis);
        $connection->expects('invalidate');
        $connection->expects('release')->never();
        $redis = $this->createRedis($connection);
        CoroutineContext::set($this->contextKey($redis), $connection);

        try {
            $redis->transaction(static function (): void {
            });
            $this->fail('Expected the transaction failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertSame(
            $connection,
            CoroutineContext::get($this->contextKey($redis)),
        );
    }

    public function testPipelineEnablesTransformationAndReleasesConnectionAfterCallback(): void
    {
        $execResults = ['OK'];

        $pipelineInstance = Double::for(PhpRedis::class)->strict();
        $pipelineInstance->expects('set')->returns($pipelineInstance);
        $pipelineInstance->expects('exec')->returns($execResults);

        $phpRedis = Double::for(PhpRedis::class)->strict();
        $phpRedis->expects('pipeline')->returns($pipelineInstance);

        $connection = $this->createMockConnection($phpRedis);

        $connection->expects('release');

        $redis = $this->createRedis($connection);

        $redis->pipeline(function ($pipe) {
            $pipe->set('key', 'value');
        });

        $connection->received('shouldTransform')->with(true);
        $this->assertNull(CoroutineContext::get($this->contextKey($redis)));
    }

    /**
     * Create a mock RedisConnection.
     */
    private function createMockConnection(PhpRedis&DoubleInterface $phpRedis): RedisConnection&DoubleInterface
    {
        $phpRedis->allows('getMode')->returns(PhpRedis::ATOMIC);

        return Double::for((new PhpRedisConnectionStub)->setActiveConnection($phpRedis))->passthru();
    }

    /**
     * Create a RedisProxy instance with the given mock connection.
     */
    private function createRedis(RedisConnection&DoubleInterface $connection): RedisProxy
    {
        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns($connection);

        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('default')->returns($pool);

        return new RedisProxy(
            $poolManager,
            'default',
            Double::for(RedisSentinelFactory::class)->strict(),
        );
    }

    /**
     * Get the coroutine context key of the given proxy's pinned connection.
     */
    private function contextKey(RedisProxy $redis): string
    {
        return (fn (): string => $this->getContextKey())->call($redis);
    }
}
