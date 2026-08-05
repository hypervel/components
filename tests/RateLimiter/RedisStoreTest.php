<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RedisStore;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;
use UnexpectedValueException;

class RedisStoreTest extends TestCase
{
    public function testFixedWindowUsesOneKeyAndCanonicalArguments(): void
    {
        $captured = [];
        $store = $this->store([1, 10, 7, 0, 60_000_000], $captured);

        $result = $store->consume('physical-key', Limit::perMinute(10)->cost(3));

        $this->assertTrue($result->allowed());
        $this->assertSame(7, $result->remaining());
        $this->assertSame(['physical-key'], $captured['keys']);
        $this->assertSame(['consume', '3', '10', '60000'], $captured['arguments']);
        $this->assertStringContainsString("redis.call('INCRBY', KEYS[1], ARGV[2])", $captured['script']);
        $this->assertStringNotContainsString('KEEPTTL', $captured['script']);
    }

    public function testLeakyBucketUsesOneKeyAndMicrosecondArguments(): void
    {
        $captured = [];
        $store = $this->store([0, 20, 5, 250_000, 1_000_000], $captured);
        $policy = LeakyBucket::perSecond(10)->burst(20)->cost(3);

        $result = $store->inspect('physical-key', $policy);

        $this->assertTrue($result->denied());
        $this->assertSame(5, $result->remaining());
        $this->assertSame(['physical-key'], $captured['keys']);
        $this->assertSame(['inspect', '3', '10', '1000000', '20'], $captured['arguments']);
        $this->assertStringContainsString("redis.call('TIME')", $captured['script']);
    }

    public function testBackoffReturnsItsFailureCountAndDelay(): void
    {
        $captured = [];
        $store = $this->store([0, 5, 0, 8_000_000, 0], $captured);
        $backoff = Backoff::exponential(
            after: 3,
            initialDelay: 2,
            maxDelay: 8,
            resetAfter: 20,
        );

        $result = $store->recordFailure('physical-key', $backoff);

        $this->assertTrue($result->denied());
        $this->assertSame(5, $result->failures());
        $this->assertSame(8, $result->retryAfter());
        $this->assertSame(['failure', '3', '2000000', '8000000', '20000000'], $captured['arguments']);
    }

    public function testMalformedTuplesFailExplicitly(): void
    {
        foreach ([
            false,
            null,
            [1, 10, 9],
            [2, 10, 9, 0, 1],
            [1, 10, -1, 0, 1],
            ['1', 10, 9, 0, 1],
            [1, 11, 9, 0, 1],
        ] as $response) {
            try {
                $captured = [];
                $this->store($response, $captured)->consume('key', Limit::perMinute(10));
                $this->fail('Expected an invalid Redis result exception.');
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testClearDeletesThePhysicalKeyOnTheConfiguredConnection(): void
    {
        $redis = m::mock(RedisFactory::class);
        $proxy = m::mock(RedisProxy::class);
        $connection = m::mock(RedisConnection::class);

        $redis->shouldReceive('connection')->once()->with('limiter')->andReturn($proxy);
        $proxy->shouldReceive('withConnection')
            ->once()
            ->with(m::type('callable'), false)
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($connection));
        $connection->shouldReceive('del')->once()->with('physical-key')->andReturn(1);

        $this->assertTrue((new RedisStore($redis, 'limiter'))->clear('physical-key'));
    }

    private function store(mixed $response, array &$captured): RedisStore
    {
        $redis = m::mock(RedisFactory::class);
        $proxy = m::mock(RedisProxy::class);
        $connection = m::mock(RedisConnection::class);

        $redis->shouldReceive('connection')->once()->with('limiter')->andReturn($proxy);
        $proxy->shouldReceive('withConnection')
            ->once()
            ->with(m::type('callable'), false)
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($connection));
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturnUsing(static function (string $script, array $keys, array $arguments) use ($response, &$captured): mixed {
                $captured = compact('script', 'keys', 'arguments');

                return $response;
            });

        return new RedisStore($redis, 'limiter');
    }
}
