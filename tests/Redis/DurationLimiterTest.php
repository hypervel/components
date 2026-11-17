<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Container\Container;
use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Sleep;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Redis;

/**
 * Tests for DurationLimiter.
 *
 * DurationLimiter provides a fixed-window rate limiter using Redis Lua scripts.
 */
class DurationLimiterTest extends TestCase
{
    use VerifiesDoubles;

    public function testAcquireSucceedsWhenBelowLimit(): void
    {
        $redis = $this->mockRedis();
        // Lua script returns: [acquired (1=success), decaysAt, count]
        $this->expectEvaluation($redis, [1, time() + 60, 1]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->acquire();

        $this->assertTrue($result);
        $this->assertSame(4, $limiter->remaining);
    }

    public function testAcquireUsesShaCachedEvalSignature(): void
    {
        $redis = $this->mockRedis();
        $this->expectEvaluation(
            $redis,
            [1, time() + 60, 1],
            function (string $script, array $keys, array $arguments): bool {
                $this->assertNotSame('', $script);
                $this->assertSame(['test-key'], $keys);
                $this->assertGreaterThan(0.0, $arguments[0]);
                $this->assertGreaterThan(0, $arguments[1]);
                $this->assertSame(60, $arguments[2]);
                $this->assertSame(5, $arguments[3]);

                return true;
            },
        );

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $this->assertTrue($limiter->acquire());
    }

    public function testAcquireFailsWhenAtLimit(): void
    {
        $redis = $this->mockRedis();
        // Lua script returns: [acquired (0=failed), decaysAt, count]
        $this->expectEvaluation($redis, [0, time() + 30, '6']);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->acquire();

        $this->assertFalse($result);
        $this->assertSame(0, $limiter->remaining);
    }

    public function testTooManyAttemptsReturnsTrueWhenNoRemaining(): void
    {
        $redis = $this->mockRedis();
        $this->expectEvaluation($redis, [time() + 60, '5']);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->tooManyAttempts();

        $this->assertTrue($result);
        $this->assertSame(0, $limiter->remaining);
    }

    public function testTooManyAttemptsReturnsFalseWhenHasRemaining(): void
    {
        $redis = $this->mockRedis();
        $this->expectEvaluation($redis, [time() + 60, '2']);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->tooManyAttempts();

        $this->assertFalse($result);
        $this->assertSame(3, $limiter->remaining);
    }

    public function testTooManyAttemptsUsesShaCachedEvalSignature(): void
    {
        $redis = $this->mockRedis();
        $this->expectEvaluation(
            $redis,
            [time() + 60, '3'],
            function (string $script, array $keys, array $arguments): bool {
                $this->assertNotSame('', $script);
                $this->assertSame(['test-key'], $keys);
                $this->assertGreaterThan(0.0, $arguments[0]);
                $this->assertGreaterThan(0, $arguments[1]);
                $this->assertSame(60, $arguments[2]);
                $this->assertSame(5, $arguments[3]);

                return true;
            },
        );

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $this->assertFalse($limiter->tooManyAttempts());
        $this->assertSame(2, $limiter->remaining);
    }

    public function testClearDeletesKey(): void
    {
        $client = Double::for(Redis::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $client->expects('del')->with('test-key')->returns(1);
        $connection = Double::for((new PhpRedisConnectionStub)->setActiveConnection($client))->passthru();
        $connection->allows('release');
        $pool = Double::for(RedisPool::class)->strict();
        $pool->allows('borrow')->returns($connection);
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->allows('pool')->with('limiter')->returns($pool);
        $redis = new RedisProxy(
            $poolManager,
            'limiter',
            new RedisSentinelFactory,
        );

        (new DurationLimiter($redis, 'test-key', 5, 60))->clear();
    }

    public function testBlockExecutesCallbackOnSuccess(): void
    {
        $redis = $this->mockRedis();
        $this->expectEvaluation($redis, [1, time() + 60, 1]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $callbackExecuted = false;
        $result = $limiter->block(5, function () use (&$callbackExecuted) {
            $callbackExecuted = true;
            return 'callback-result';
        });

        $this->assertTrue($callbackExecuted);
        $this->assertSame('callback-result', $result);
    }

    public function testBlockThrowsExceptionAfterTimeout(): void
    {
        $redis = $this->mockRedis();
        $connection = Double::for(RedisConnection::class)->strict();

        $redis->allows('withConnection')
            ->resolves(fn (callable $callback): mixed => $callback($connection));

        // Always fail to acquire
        $connection->allows('evalWithShaCache')
            ->returns([0, time() + 60, '6']);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $this->expectException(LimiterTimeoutException::class);

        // Timeout of 0 means it should fail immediately on first retry
        $limiter->block(0, null, 1); // 1ms sleep between retries
    }

    public function testBlockCanAcquireAtTheDeadlineWithAShorterFinalSleep(): void
    {
        Sleep::fake(syncWithCarbon: true);
        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $redis = $this->mockRedis();
        $connection = Double::for(RedisConnection::class)->strict();
        $redis->allows('withConnection')
            ->resolves(fn (callable $callback): mixed => $callback($connection));
        $connection->expects('evalWithShaCache')->times(4)
            ->returns([0, 0, '1'], [0, 0, '1'], [0, 0, '1'], [1, 10, '1']);

        $limiter = new DurationLimiter($redis, 'test-key', 1, 10);

        $this->assertTrue($limiter->block(2, sleep: 750));

        Sleep::assertSequence([
            Sleep::for(750)->milliseconds(),
            Sleep::for(750)->milliseconds(),
            Sleep::for(500)->milliseconds(),
        ]);
    }

    // REMOVED: testUsesSpecifiedConnectionName - Connection is now resolved before creating the limiter,
    // so DurationLimiter no longer has a connection name parameter.

    /**
     * Create a mock RedisProxy.
     */
    private function mockRedis(): RedisProxy&DoubleInterface
    {
        return Double::for(RedisProxy::class)->strict();
    }

    /**
     * Expect a script evaluation on one held Redis connection.
     */
    private function expectEvaluation(
        RedisProxy&DoubleInterface $redis,
        mixed $result,
        ?callable $assertion = null,
    ): void {
        $connection = Double::for(RedisConnection::class)->strict();

        $redis->expects('withConnection')
            ->resolves(fn (callable $callback): mixed => $callback($connection));

        $expectation = $connection->expects('evalWithShaCache');

        if ($assertion !== null) {
            $expectation->with(Argument::all($assertion));
        }

        $expectation->returns($result);
    }
}
