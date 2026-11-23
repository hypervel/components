<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Container\Container;
use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Redis\Limiters\ConcurrencyLimiterBuilder;
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
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;
use Redis;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Tests for ConcurrencyLimiterBuilder.
 *
 * ConcurrencyLimiterBuilder provides a fluent API for configuring and executing
 * a ConcurrencyLimiter via Redis::funnel('key')->limit(10)->then(...).
 */
class ConcurrencyLimiterBuilderTest extends TestCase
{
    use VerifiesDoubles;

    public function testLimitSetsMaxLocks(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->limit(10);

        $this->assertSame($builder, $result);
        $this->assertSame(10, $builder->maxLocks);
    }

    public function testReleaseAfterSetsReleaseAfterInSeconds(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->releaseAfter(120);

        $this->assertSame($builder, $result);
        $this->assertSame(120, $builder->releaseAfter);
    }

    public function testBlockSetsTimeout(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->block(10);

        $this->assertSame($builder, $result);
        $this->assertSame(10, $builder->timeout);
    }

    public function testSleepSetsSleepDuration(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->sleep(500);

        $this->assertSame($builder, $result);
        $this->assertSame(500, $builder->sleep);
    }

    public function testDefaultReleaseAfterIs60Seconds(): void
    {
        $builder = $this->createBuilder();

        $this->assertSame(60, $builder->releaseAfter);
    }

    public function testDefaultTimeoutIsThreeSeconds(): void
    {
        $builder = $this->createBuilder();

        $this->assertSame(3, $builder->timeout);
    }

    public function testDefaultSleepIs250Milliseconds(): void
    {
        $builder = $this->createBuilder();

        $this->assertSame(250, $builder->sleep);
    }

    public function testThenExecutesCallbackWhenLockAcquired(): void
    {
        [$redis, $client] = $this->createRedis();
        // ConcurrencyLimiter::acquire() Lua script returns a slot name
        $this->expectSlotClaim($redis, 'test-key1');

        // ConcurrencyLimiter::release() called after callback
        $client->expects('eval')
            ->with(Argument::any(), Argument::satisfies(
                static fn (array $arguments): bool => count($arguments) === 2 && $arguments[0] === 'test-key1',
            ), 1)
            ->returns(1);

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0);

        $result = $builder->then(function () {
            return 'success';
        });

        $this->assertSame('success', $result);
    }

    public function testThenPropagatesReleaseFailureAfterSuccessfulCallback(): void
    {
        [$redis, $client] = $this->createRedis();
        $releaseException = new RuntimeException('release failed');

        $this->expectSlotClaim($redis, 'test-key1');
        $client->expects('eval')
            ->throws($releaseException);

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0);

        try {
            $builder->then(fn (): string => 'success');

            $this->fail('Expected the release failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($releaseException, $exception);
        }
    }

    #[TestWith(['ordinary'])]
    #[TestWith(['direct cancellation'])]
    #[TestWith(['wrapped cancellation'])]
    public function testThenPreservesCallbackFailureWhenReleaseAlsoFails(string $kind): void
    {
        [$redis, $client] = $this->createRedis();
        $callbackException = new RuntimeException('callback failed');
        $releaseException = match ($kind) {
            'direct cancellation' => new CanceledException,
            'wrapped cancellation' => new RuntimeException('Release cleanup failed.', previous: new CanceledException),
            default => new RuntimeException('release failed'),
        };

        $this->expectSlotClaim($redis, 'test-key1');
        $client->expects('eval')
            ->throws($releaseException);

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0);

        $caught = null;

        try {
            $builder->then(function () use ($callbackException): never {
                throw $callbackException;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($kind === 'ordinary' ? $callbackException : $releaseException, $caught);
    }

    public function testThenCallsFailureCallbackOnTimeout(): void
    {
        [$redis] = $this->createRedis();
        // ConcurrencyLimiter::acquire() always fails
        $this->expectSlotClaim($redis, false);

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0)->sleep(1);

        $failureCalled = false;
        $result = $builder->then(
            function () {
                return 'should-not-reach';
            },
            function (LimiterTimeoutException $e) use (&$failureCalled) {
                $failureCalled = true;
                return 'fallback';
            }
        );

        $this->assertTrue($failureCalled);
        $this->assertSame('fallback', $result);
    }

    public function testThenThrowsExceptionWithoutFailureCallback(): void
    {
        [$redis] = $this->createRedis();
        // ConcurrencyLimiter::acquire() always fails
        $this->expectSlotClaim($redis, false);

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0)->sleep(1);

        $this->expectException(LimiterTimeoutException::class);

        $builder->then(function () {
            return 'should-not-reach';
        });
    }

    public function testAcquireReturnsOwnedLease(): void
    {
        [$redis] = $this->createRedis();
        $this->expectSlotClaim($redis, 'test-key1');

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0);

        $lease = $builder->acquire();

        $this->assertNotEmpty($lease->owner());
    }

    public function testThenDoesNotRouteCallbackTimeoutExceptionToFailureCallback(): void
    {
        [$redis, $client] = $this->createRedis();
        $this->expectSlotClaim($redis, 'test-key1');
        $client->expects('eval')
            ->returns(1);

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0);

        $failureCalled = false;
        $callbackException = new LimiterTimeoutException;

        try {
            $builder->then(
                function () use ($callbackException): never {
                    throw $callbackException;
                },
                function () use (&$failureCalled) {
                    $failureCalled = true;
                }
            );

            $this->fail('Expected LimiterTimeoutException was not thrown.');
        } catch (LimiterTimeoutException $exception) {
            $this->assertSame($callbackException, $exception);
        }

        $this->assertFalse($failureCalled);
    }

    public function testFluentChaining(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->limit(10)->releaseAfter(120)->block(5)->sleep(500);

        $this->assertSame($builder, $result);
        $this->assertSame(10, $builder->maxLocks);
        $this->assertSame(120, $builder->releaseAfter);
        $this->assertSame(5, $builder->timeout);
        $this->assertSame(500, $builder->sleep);
    }

    /**
     * Create a builder with a mock Redis connection.
     */
    private function createBuilder(): ConcurrencyLimiterBuilder
    {
        return new ConcurrencyLimiterBuilder(Double::for(RedisProxy::class)->strict(), 'test-key');
    }

    /**
     * Create a proxy that borrows a connection wrapping the native client.
     *
     * @return array{DoubleInterface&RedisProxy, DoubleInterface&Redis}
     */
    private function createRedis(): array
    {
        $client = Double::for(Redis::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $connection = Double::for((new PhpRedisConnectionStub)->setActiveConnection($client))->passthru();
        $connection->allows('release');
        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns($connection);
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('limiter')->returns($pool);
        $redis = Double::for(new RedisProxy(
            $poolManager,
            'limiter',
            new RedisSentinelFactory,
        ))->passthru();
        $redis->allows('isCluster')->returns(false);

        return [$redis, $client];
    }

    /**
     * Expect a slot-claim script evaluation on one held Redis connection.
     */
    private function expectSlotClaim(RedisProxy&DoubleInterface $redis, false|string $result): void
    {
        $connection = Double::for(RedisConnection::class)->strict();

        $redis->expects('withConnection')
            ->resolves(fn (callable $callback): mixed => $callback($connection));

        $connection->expects('evalWithShaCache')
            ->returns($result);
    }
}
