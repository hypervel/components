<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Redis\Limiters\ConcurrencyLimiter;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class ConcurrencyLimiterTest extends TestCase
{
    public function testBlockExecutesCallbackOnSuccessfulAcquisition(): void
    {
        $redis = $this->mockRedis();

        // acquire() returns a slot name to indicate success
        $this->expectSlotClaim($redis, 'test-lock1');

        // release() calls eval with the release script
        $redis->expects('eval')
            ->withArgs(function (string $script, int $numKeys, string $key, string $id): bool {
                $this->assertSame(1, $numKeys);
                $this->assertSame('test-lock1', $key);
                $this->assertNotEmpty($id);

                return true;
            })
            ->andReturn(1);

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $result = $limiter->block(5, function (): string {
            return 'callback-result';
        });

        $this->assertSame('callback-result', $result);
    }

    public function testBlockReturnsTrueWithoutCallback(): void
    {
        $redis = $this->mockRedis();

        // acquire() succeeds
        $this->expectSlotClaim($redis, 'test-lock1');

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $result = $limiter->block(5);

        $this->assertTrue($result);
    }

    public function testBlockReleasesLockWhenCallbackThrows(): void
    {
        $redis = $this->mockRedis();

        // acquire() succeeds
        $this->expectSlotClaim($redis, 'test-lock1');

        // release() should still be called
        $redis->expects('eval')
            ->withArgs(function (string $script, int $numKeys, string $key, string $id): bool {
                $this->assertSame(1, $numKeys);
                $this->assertSame('test-lock1', $key);

                return true;
            })
            ->andReturn(1);

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test error');

        $limiter->block(5, function (): never {
            throw new RuntimeException('test error');
        });
    }

    public function testBlockPropagatesReleaseFailureAfterSuccessfulCallback(): void
    {
        $redis = $this->mockRedis();
        $releaseException = new RuntimeException('release failed');

        $this->expectSlotClaim($redis, 'test-lock1');
        $redis->expects('eval')
            ->andThrow($releaseException);

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        try {
            $limiter->block(5, fn (): string => 'callback-result');

            $this->fail('Expected the release failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($releaseException, $exception);
        }
    }

    public function testBlockPreservesCallbackFailureWhenReleaseAlsoFails(): void
    {
        $redis = $this->mockRedis();
        $callbackException = new RuntimeException('callback failed');

        $this->expectSlotClaim($redis, 'test-lock1');
        $redis->expects('eval')
            ->andThrow(new RuntimeException('release failed'));

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        try {
            $limiter->block(5, function () use ($callbackException): never {
                throw $callbackException;
            });

            $this->fail('Expected the callback failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackException, $exception);
        }
    }

    public function testBlockThrowsTimeoutExceptionWhenCannotAcquire(): void
    {
        $redis = $this->mockRedis();
        $connection = m::mock(RedisConnection::class);

        // acquire() always fails (returns falsy)
        $redis->shouldReceive('withConnection')
            ->andReturnUsing(fn (callable $callback): mixed => $callback($connection));
        $connection->shouldReceive('evalWithShaCache')
            ->andReturn(false);

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $this->expectException(LimiterTimeoutException::class);

        // Timeout of 0 means it should fail immediately on first retry
        $limiter->block(0, null, 1); // 1ms sleep between retries
    }

    public function testBlockWithZeroLimitDoesNotCallEvalAndTimesOut(): void
    {
        $redis = $this->mockRedis();

        // limit(0) means no slots — acquire must short-circuit before evaluating Lua,
        // otherwise Lua hits redis.call('mget') with no args and errors.
        $redis->shouldNotReceive('withConnection');

        $this->expectException(LimiterTimeoutException::class);

        (new ConcurrencyLimiter($redis, 'zero', 0, 5))->block(0);
    }

    public function testBlockWithNegativeLimitDoesNotCallEvalAndTimesOut(): void
    {
        $redis = $this->mockRedis();

        $redis->shouldNotReceive('withConnection');

        $this->expectException(LimiterTimeoutException::class);

        (new ConcurrencyLimiter($redis, 'neg', -1, 5))->block(0);
    }

    public function testAcquireReturnsOwnedLease(): void
    {
        $redis = $this->mockRedis();

        $this->expectSlotClaim($redis, 'test-lock1');

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $lease = $limiter->acquire(5);

        $this->assertNotEmpty($lease->owner());
    }

    public function testLeaseCanReleaseSlot(): void
    {
        $redis = $this->mockRedis();

        $this->expectSlotClaim($redis, 'test-lock1');
        $redis->expects('eval')
            ->withArgs(function (string $script, int $numKeys, string $key, string $id): bool {
                $this->assertSame(1, $numKeys);
                $this->assertSame('test-lock1', $key);
                $this->assertNotEmpty($id);

                return true;
            })
            ->andReturn(1);

        $lease = (new ConcurrencyLimiter($redis, 'test-lock', 3, 60))->acquire(5);

        $this->assertTrue($lease->release());
    }

    public function testLeaseCanRefreshSlot(): void
    {
        $redis = $this->mockRedis();

        $this->expectSlotClaim($redis, 'test-lock1');
        $redis->expects('eval')
            ->withArgs(function (string $script, int $numKeys, string $key, string $id, int $seconds): bool {
                $this->assertSame(1, $numKeys);
                $this->assertSame('test-lock1', $key);
                $this->assertNotEmpty($id);
                $this->assertSame(60, $seconds);

                return true;
            })
            ->andReturn(1);

        $lease = (new ConcurrencyLimiter($redis, 'test-lock', 3, 60))->acquire(5);

        $this->assertTrue($lease->refresh());
    }

    public function testLeaseReturnsRemainingLifetime(): void
    {
        $redis = $this->mockRedis();

        $this->expectSlotClaim($redis, 'test-lock1');
        $redis->expects('ttl')
            ->with('test-lock1')
            ->andReturn(5);

        $lease = (new ConcurrencyLimiter($redis, 'test-lock', 3, 60))->acquire(5);

        $this->assertSame(5.0, $lease->getRemainingLifetime());
    }

    public function testAcquireUsesHashTagsOnPhpRedisClusterConnection(): void
    {
        $redis = $this->mockRedis();
        $redis->expects('isCluster')->andReturnTrue();

        $this->expectSlotClaim(
            $redis,
            '{test-lock}1',
            function (string $script, array $keys, array $arguments): bool {
                $this->assertStringContainsString('mget', $script);
                $this->assertSame(['{test-lock}1', '{test-lock}2', '{test-lock}3'], $keys);
                $this->assertSame('{test-lock}', $arguments[0]);

                return true;
            },
        );

        $this->expectRelease($redis, '{test-lock}1');

        $result = (new ConcurrencyLimiter($redis, 'test-lock', 3, 60))->block(0, static fn (): string => 'executed');

        $this->assertSame('executed', $result);
    }

    public function testAcquireUsesPlainKeysOnNonClusterConnection(): void
    {
        $redis = $this->mockRedis();
        $redis->expects('isCluster')->andReturnFalse();

        $this->expectSlotClaim(
            $redis,
            'test-lock1',
            function (string $script, array $keys, array $arguments): bool {
                $this->assertStringContainsString('mget', $script);
                $this->assertSame(['test-lock1', 'test-lock2', 'test-lock3'], $keys);
                $this->assertSame('test-lock', $arguments[0]);
                $this->assertSame(60, $arguments[1]);
                $this->assertNotEmpty($arguments[2]);

                return true;
            },
        );
        $this->expectRelease($redis, 'test-lock1');

        $result = (new ConcurrencyLimiter($redis, 'test-lock', 3, 60))->block(0, static fn (): string => 'done');

        $this->assertSame('done', $result);
    }

    // REMOVED: Predis connection tests; Hypervel supports phpredis only.

    public function testReleaseKeyMatchesAcquireKeyOnCluster(): void
    {
        $redis = $this->mockRedis();
        $redis->expects('isCluster')->andReturnTrue();

        // Acquire returns the slot key.
        $this->expectSlotClaim($redis, '{mykey}2');

        // Release should be called with the exact same key.
        $this->expectRelease($redis, '{mykey}2');

        (new ConcurrencyLimiter($redis, 'mykey', 3, 60))->block(0, static function (): void {
            // Callback runs between acquire and release.
        });
    }

    #[DataProvider('existingHashTags')]
    public function testAcquireDoesNotDoubleWrapPreExistingHashTags(string $name): void
    {
        $redis = $this->mockRedis();
        $redis->expects('isCluster')->andReturnTrue();

        // The name already has a hash tag and must not be double-wrapped.
        $this->expectSlotClaim($redis, $name . '1', function (string $script, array $keys, array $arguments) use ($name): bool {
            $this->assertStringContainsString('mget', $script);
            $this->assertSame([$name . '1', $name . '2'], $keys);
            $this->assertSame($name, $arguments[0]);

            return true;
        });
        $this->expectRelease($redis, $name . '1');

        $result = (new ConcurrencyLimiter($redis, $name, 2, 60))->block(0, static fn (): string => 'ok');

        $this->assertSame('ok', $result);
    }

    /**
     * Provide names with existing Redis hash tags.
     */
    public static function existingHashTags(): array
    {
        return [['{mylock}'], ['{test-lock}:funnel']];
    }

    public function testAcquireWrapsUnmatchedBraceOnCluster(): void
    {
        $redis = $this->mockRedis();
        $redis->expects('isCluster')->andReturnTrue();

        // An opening brace without a closing brace is not a valid hash tag.
        $this->expectSlotClaim($redis, '{my{lock}1', function (string $script, array $keys, array $arguments): bool {
            $this->assertStringContainsString('mget', $script);
            $this->assertSame(['{my{lock}1', '{my{lock}2'], $keys);
            $this->assertSame('{my{lock}', $arguments[0]);

            return true;
        });
        $this->expectRelease($redis, '{my{lock}1');

        $result = (new ConcurrencyLimiter($redis, 'my{lock', 2, 60))->block(0, static fn (): string => 'ok');

        $this->assertSame('ok', $result);
    }

    public function testAcquireWrapsEmptyBracesOnCluster(): void
    {
        $redis = $this->mockRedis();
        $redis->expects('isCluster')->andReturnTrue();

        // Empty braces are not a valid hash tag.
        $this->expectSlotClaim($redis, '{my{}lock}1', function (string $script, array $keys, array $arguments): bool {
            $this->assertStringContainsString('mget', $script);
            $this->assertSame(['{my{}lock}1', '{my{}lock}2'], $keys);
            $this->assertSame('{my{}lock}', $arguments[0]);

            return true;
        });
        $this->expectRelease($redis, '{my{}lock}1');

        $result = (new ConcurrencyLimiter($redis, 'my{}lock', 2, 60))->block(0, static fn (): string => 'ok');

        $this->assertSame('ok', $result);
    }

    /**
     * Create a mock RedisProxy.
     */
    private function mockRedis(): m\MockInterface|RedisProxy
    {
        $redis = m::mock(RedisProxy::class);
        $redis->shouldReceive('isCluster')->byDefault()->andReturnFalse();

        return $redis;
    }

    /**
     * Expect a slot-claim script evaluation on one held Redis connection.
     */
    private function expectSlotClaim(
        m\MockInterface|RedisProxy $redis,
        false|string $result,
        ?callable $assertion = null,
    ): void {
        $connection = m::mock(RedisConnection::class);

        $redis->expects('withConnection')
            ->andReturnUsing(fn (callable $callback): mixed => $callback($connection));

        $expectation = $connection->expects('evalWithShaCache');

        if ($assertion !== null) {
            $expectation->withArgs($assertion);
        }

        $expectation->andReturn($result);
    }

    /**
     * Expect a release script evaluation for the acquired slot.
     */
    private function expectRelease(m\MockInterface|RedisProxy $redis, string $key): void
    {
        $redis->expects('eval')
            ->with(m::on(static fn (string $script): bool => str_contains($script, 'del')), 1, $key, m::type('string'))
            ->andReturn(1);
    }
}
