<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\RateLimiter\Redis;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\KeyResolver;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\SlidingWindow;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\RateLimiter\Fixtures\RateLimiterStoreContract;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis as PhpRedis;

use function Hypervel\Coroutine\parallel;

class RedisStoreTest extends TestCase
{
    use InteractsWithRedis;
    use RateLimiterStoreContract;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('rate-limiter.default', 'redis');
        $config->set('rate-limiter.prefix', 'integration');
        $config->set('database.redis.default.options.prefix', 'rate-limiter-test:');
    }

    public function testFixedWindowIsAtomicAndRetainsItsOriginalTtl(): void
    {
        $limiter = $this->limiter();
        $policy = Limit::perSecond(3, 10)->by('fixed');

        $this->assertSame(2, $limiter->consume($policy)->remaining());
        $physicalKey = $this->physicalKey($policy);
        $redis = $this->redisClient();
        $before = $redis->pttl($physicalKey);

        $this->assertSame(1, $limiter->consume($policy)->remaining());
        $after = $redis->pttl($physicalKey);

        $this->assertGreaterThan(9000, $before);
        $this->assertGreaterThan($before - 500, $after);

        $denied = $limiter->consume($policy->cost(2));
        $this->assertTrue($denied->denied());
        $this->assertSame(1, $denied->remaining());
        $this->assertSame('2', $redis->get($physicalKey));
    }

    public function testInspectingMissingStateDoesNotCreateAKey(): void
    {
        $policy = Limit::perMinute(10)->by('inspect');
        $result = $this->limiter()->inspect($policy);

        $this->assertTrue($result->allowed());
        $this->assertSame(10, $result->remaining());
        $this->assertSame(0, $result->resetAfter());
        $this->assertSame(0, $this->redisClient()->exists($this->physicalKey($policy)));
    }

    public function testSlidingWindowUsesOneHashAndExtendsTtlOnlyOnAcceptedRotation(): void
    {
        $limiter = $this->limiter();
        $policy = SlidingWindow::perSecond(10, 2)->cost(4)->by('sliding');

        $this->assertSame(6, $limiter->consume($policy)->remaining());
        $physicalKey = $this->physicalKey($policy);
        $redis = $this->redisClient();
        $initialTtl = $redis->pttl($physicalKey);

        $this->assertSame([
            'current' => '4',
            'previous' => '0',
        ], $redis->hGetAll($physicalKey));

        $this->assertSame(5, $limiter->consume($policy->cost(1))->remaining());
        $sameWindowTtl = $redis->pttl($physicalKey);
        $stateBeforeDenial = $redis->hGetAll($physicalKey);

        $this->assertLessThanOrEqual($initialTtl, $sameWindowTtl);
        $this->assertGreaterThan($initialTtl - 500, $sameWindowTtl);
        $this->assertTrue($limiter->consume($policy->cost(6))->denied());
        $this->assertSame($stateBeforeDenial, $redis->hGetAll($physicalKey));
        $this->assertLessThanOrEqual($sameWindowTtl, $redis->pttl($physicalKey));

        $this->waitForRateLimiterStoreContract(
            static fn (): bool => $limiter->inspect($policy)->resetAfter() <= 2,
            2,
        );

        $this->assertTrue($limiter->consume($policy->cost(1))->allowed());
        $this->assertSame([
            'current' => '1',
            'previous' => '5',
        ], $redis->hGetAll($physicalKey));
        $this->assertGreaterThan(3000, $redis->pttl($physicalKey));
    }

    public function testRotatedSlidingWindowDenialKeepsStoredStateUnchanged(): void
    {
        $policy = SlidingWindow::perSecond(10, 2)
            ->cost(7)
            ->by('sliding-rotated-denial');
        $physicalKey = $this->physicalKey($policy);
        $redis = $this->redisClient();
        $redis->hMSet($physicalKey, [
            'current' => '8',
            'previous' => '2',
        ]);
        $redis->pExpire($physicalKey, 1900);

        $result = $this->limiter()->consume($policy);

        $this->assertTrue($result->denied());
        $this->assertSame(1, $result->retryAfter());
        $this->assertSame(2, $result->resetAfter());
        $this->assertSame([
            'current' => '8',
            'previous' => '2',
        ], $redis->hGetAll($physicalKey));

        $ttl = $redis->pttl($physicalKey);

        $this->assertGreaterThan(1000, $ttl);
        $this->assertLessThanOrEqual(1900, $ttl);
    }

    public function testInspectingMissingSlidingWindowStateDoesNotCreateAKey(): void
    {
        $policy = SlidingWindow::perMinute(10)->by('inspect-sliding');
        $result = $this->limiter()->inspect($policy);

        $this->assertTrue($result->allowed());
        $this->assertSame(10, $result->remaining());
        $this->assertSame(0, $result->resetAfter());
        $this->assertSame(0, $this->redisClient()->exists($this->physicalKey($policy)));
    }

    public function testLeakyBucketUsesRedisTimeAndRecoversCapacity(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');
        $policy = LeakyBucket::perSecond(2)->by('leaky');
        $limiter = $this->limiter();

        $this->assertSame(1, $limiter->consume($policy)->remaining());
        $this->assertSame(0, $limiter->consume($policy)->remaining());
        $this->assertTrue($limiter->consume($policy)->denied());

        $storedTat = $this->redisClient()->get($this->physicalKey($policy));

        $this->assertIsString($storedTat);
        $this->assertGreaterThan(1_700_000_000_000_000, (int) $storedTat);
    }

    public function testExponentialBackoffUsesOneRedisStateEntry(): void
    {
        $backoff = Backoff::exponential(
            after: 2,
            initialDelay: 1,
            maxDelay: 4,
            resetAfter: 10,
        )->by('backoff');
        $limiter = $this->limiter();

        $this->assertTrue($limiter->recordFailure($backoff)->allowed());

        $blocked = $limiter->recordFailure($backoff);
        $this->assertTrue($blocked->denied());
        $this->assertSame(2, $blocked->failures());
        $this->assertSame(1, $blocked->retryAfter());
        $this->assertTrue($limiter->inspect($backoff)->denied());
        $this->assertTrue($limiter->clear($backoff));
        $this->assertTrue($limiter->inspect($backoff)->allowed());
    }

    public function testLeadingZeroAndMissingExpiryStateFailClosed(): void
    {
        $policy = Limit::perMinute(10)->by('corrupt');
        $physicalKey = $this->physicalKey($policy);
        $redis = $this->redisClient();

        $redis->set($physicalKey, '010', ['px' => 60_000]);

        try {
            $this->limiter()->consume($policy);
            $this->fail('Expected corrupt leading-zero state to fail.');
        } catch (LuaScriptException) {
            $this->addToAssertionCount(1);
        }

        $redis->set($physicalKey, '1');

        $this->expectException(LuaScriptException::class);

        $this->limiter()->consume($policy);
    }

    public function testPresentBackoffStateWithZeroFailuresFailsClosed(): void
    {
        $backoff = Backoff::exponential()->by('corrupt-backoff');
        $physicalKey = $this->physicalKey($backoff);
        $redis = $this->redisClient();

        $redis->hMSet($physicalKey, [
            'failures' => '0',
            'available_at' => '0',
        ]);
        $redis->pExpire($physicalKey, 60_000);

        $this->expectException(LuaScriptException::class);

        $this->limiter()->inspect($backoff);
    }

    #[DataProvider('corruptSlidingWindowStateProvider')]
    public function testCorruptSlidingWindowStateFailsClosed(
        string $suffix,
        array $state,
        bool $expires,
    ): void {
        $policy = SlidingWindow::perMinute(10)->by("corrupt-sliding-{$suffix}");
        $physicalKey = $this->physicalKey($policy);
        $redis = $this->redisClient();

        $redis->hMSet($physicalKey, $state);

        if ($expires) {
            $redis->pExpire($physicalKey, 60_000);
        }

        $this->expectException(LuaScriptException::class);

        $this->limiter()->inspect($policy);
    }

    public static function corruptSlidingWindowStateProvider(): array
    {
        return [
            'partial' => ['partial', ['current' => '1'], true],
            'noncanonical' => ['noncanonical', ['current' => '01', 'previous' => '0'], true],
            'zero current' => ['zero-current', ['current' => '0', 'previous' => '0'], true],
            'current above capacity' => ['current-capacity', ['current' => '11', 'previous' => '0'], true],
            'previous above capacity' => ['previous-capacity', ['current' => '1', 'previous' => '11'], true],
            'missing expiry' => ['missing-expiry', ['current' => '1', 'previous' => '0'], false],
        ];
    }

    public function testSlidingWindowKeepsRawRollbackDurationsWithoutRewritingTtl(): void
    {
        $policy = SlidingWindow::perSecond(6)->by('sliding-rollback');
        $physicalKey = $this->physicalKey($policy);
        $redis = $this->redisClient();
        $redis->hMSet($physicalKey, [
            'current' => '2',
            'previous' => '4',
        ]);
        $redis->pExpire($physicalKey, 3500);

        $result = $this->limiter()->consume($policy);

        $this->assertTrue($result->denied());
        $this->assertSame(0, $result->remaining());
        $this->assertSame(2, $result->retryAfter());
        $this->assertSame(4, $result->resetAfter());
        $this->assertSame([
            'current' => '2',
            'previous' => '4',
        ], $redis->hGetAll($physicalKey));

        $ttl = $redis->pttl($physicalKey);

        $this->assertGreaterThan(3000, $ttl);
        $this->assertLessThanOrEqual(3500, $ttl);
    }

    public function testSlidingWindowClampsRollbackWeightForAdmissionDecision(): void
    {
        $policy = SlidingWindow::perSecond(6)->by('sliding-clamp');
        $physicalKey = $this->physicalKey($policy);
        $redis = $this->redisClient();
        $redis->hMSet($physicalKey, [
            'current' => '1',
            'previous' => '4',
        ]);
        $redis->pExpire($physicalKey, 3000);

        $result = $this->limiter()->consume($policy);

        $this->assertTrue($result->allowed());
        $this->assertSame(0, $result->remaining());
        $this->assertSame(0, $result->retryAfter());
        $this->assertSame(3, $result->resetAfter());
        $this->assertSame([
            'current' => '2',
            'previous' => '4',
        ], $redis->hGetAll($physicalKey));

        $ttl = $redis->pttl($physicalKey);

        $this->assertGreaterThan(2250, $ttl);
        $this->assertLessThanOrEqual(3000, $ttl);
    }

    public function testMaximumExactIntegerSurvivesSetAndIncrementArguments(): void
    {
        $policy = Limit::perSecond(AdmissionPolicy::MAX_INTEGER)->by('maximum');
        $limiter = $this->limiter();

        $first = $limiter->consume($policy->cost(AdmissionPolicy::MAX_INTEGER - 1));
        $second = $limiter->consume($policy);

        $this->assertSame(1, $first->remaining());
        $this->assertSame(0, $second->remaining());
        $this->assertSame(
            (string) AdmissionPolicy::MAX_INTEGER,
            $this->redisClient()->get($this->physicalKey($policy)),
        );
    }

    public function testConfiguredRedisPrefixIsAppliedExactlyOnce(): void
    {
        $policy = Limit::perMinute(1)->by('prefix');
        $this->limiter()->consume($policy);

        $physicalKey = $this->physicalKey($policy);
        $redis = $this->rawRedisClientWithoutPrefix();

        $this->assertSame(1, $redis->exists('rate-limiter-test:' . $physicalKey));
        $this->assertSame(0, $redis->exists('rate-limiter-test:rate-limiter-test:' . $physicalKey));
    }

    public function testConcurrentClientsNeverAdmitBeyondCapacity(): void
    {
        $limiter = $this->limiter();
        $policy = Limit::perMinute(10)->by('concurrent');

        $results = parallel(array_fill(0, 50, static fn () => $limiter->consume($policy)->allowed()));

        $this->assertSame(10, count(array_filter($results)));
        $this->assertSame(0, $limiter->inspect($policy)->remaining());
    }

    public function testConcurrentWeightedClientsNeverAdmitBeyondCapacity(): void
    {
        $limiter = $this->limiter();
        $policies = [
            Limit::perMinute(20)->cost(3)->by('concurrent-weighted-fixed'),
            SlidingWindow::perMinute(20)->cost(3)->by('concurrent-weighted-sliding'),
            LeakyBucket::perMinute(1)->burst(20)->cost(3)->by('concurrent-weighted-leaky'),
        ];

        foreach ($policies as $policy) {
            $results = parallel(array_fill(0, 50, static fn () => $limiter->consume($policy)->allowed()));

            $this->assertSame(6, count(array_filter($results)));
            $this->assertSame(2, $limiter->inspect($policy)->remaining());
        }
    }

    public function testSerializerAndCompressionOptionsDoNotAffectLimiterState(): void
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis extension is not configured to support the lzf compression.');
        }

        $connection = $this->createRedisConnectionWithOptions('rate_limiter_encoded', [
            'prefix' => 'rate-limiter-encoded:',
            'serializer' => PhpRedis::SERIALIZER_PHP,
            'compression' => PhpRedis::COMPRESSION_LZF,
        ]);
        config([
            'rate-limiter.stores.encoded' => [
                'driver' => 'redis',
                'connection' => $connection,
            ],
        ]);

        $limiter = $this->app->make(RateLimiter::class)->store('encoded');
        $fixed = Limit::perMinute(2)->by('encoded-fixed');
        $sliding = SlidingWindow::perMinute(2)->by('encoded-sliding');
        $leaky = LeakyBucket::perMinute(1)->burst(1)->by('encoded-leaky');
        $backoff = Backoff::exponential(
            after: 1,
            initialDelay: 1,
            maxDelay: 2,
            resetAfter: 5,
        )->by('encoded-backoff');

        $this->assertSame(1, $limiter->consume($fixed)->remaining());
        $this->assertSame(1, $limiter->consume($sliding)->remaining());
        $this->assertTrue($limiter->consume($leaky)->allowed());
        $this->assertTrue($limiter->consume($leaky)->denied());
        $this->assertSame(1, $limiter->recordFailure($backoff)->failures());

        $redis = $this->rawRedisClientWithoutPrefix($connection);

        try {
            $this->assertSame('1', $redis->get('rate-limiter-encoded:' . $this->physicalKey($fixed)));
            $this->assertSame([
                'current' => '1',
                'previous' => '0',
            ], $redis->hGetAll('rate-limiter-encoded:' . $this->physicalKey($sliding)));
            $this->assertMatchesRegularExpression(
                '/^[1-9][0-9]*$/D',
                (string) $redis->get('rate-limiter-encoded:' . $this->physicalKey($leaky)),
            );
            $this->assertSame(
                '1',
                $redis->hGet('rate-limiter-encoded:' . $this->physicalKey($backoff), 'failures'),
            );
        } finally {
            $redis->close();
        }
    }

    private function limiter(): Limiter
    {
        return $this->app->make(RateLimiter::class)->store('redis');
    }

    protected function rateLimiterStoreContract(): Limiter
    {
        return $this->limiter();
    }

    private function physicalKey(AdmissionPolicy|Backoff $policy): string
    {
        return (new KeyResolver('integration', static fn (): ?string => null))->resolve($policy);
    }
}
