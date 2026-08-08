<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\KeyResolver;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\SlidingWindow;
use Hypervel\RateLimiter\WorkerArrayStore;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\RateLimiter\Fixtures\RateLimiterStoreContract;
use Hypervel\Tests\TestCase;
use UnexpectedValueException;

class WorkerArrayStoreTest extends TestCase
{
    use RateLimiterStoreContract;

    public function testFixedWindowConsumptionInspectionAndExpiration(): void
    {
        $now = CarbonImmutable::parse('2026-08-04 12:00:00.000000');
        CarbonImmutable::setTestNow($now);
        $limiter = $this->limiter();
        $policy = Limit::perSecond(5, 10)->cost(2)->by('fixed');

        $first = $limiter->consume($policy);
        $this->assertTrue($first->allowed());
        $this->assertSame(3, $first->remaining());
        $this->assertSame(10, $first->resetAfter());

        $deniedPolicy = $policy->cost(4);
        $denied = $limiter->consume($deniedPolicy);
        $this->assertTrue($denied->denied());
        $this->assertSame(3, $denied->remaining());
        $this->assertSame(10, $denied->retryAfter());

        $inspection = $limiter->inspect($policy);
        $this->assertTrue($inspection->allowed());
        $this->assertSame(3, $inspection->remaining());

        CarbonImmutable::setTestNow($now->addSeconds(10));

        $expired = $limiter->inspect($policy);
        $this->assertTrue($expired->allowed());
        $this->assertSame(5, $expired->remaining());
        $this->assertSame(0, $expired->resetAfter());
    }

    public function testLeakyBucketRecoversContinuouslyWithoutMutatingDenials(): void
    {
        $now = CarbonImmutable::parse('2026-08-04 12:00:00.000000');
        CarbonImmutable::setTestNow($now);
        $limiter = $this->limiter();
        $policy = LeakyBucket::perSecond(2)->by('leaky');

        $this->assertSame(1, $limiter->consume($policy)->remaining());
        $this->assertSame(0, $limiter->consume($policy)->remaining());

        $denied = $limiter->consume($policy);
        $this->assertTrue($denied->denied());
        $this->assertSame(0, $denied->remaining());
        $this->assertSame(1, $denied->retryAfter());

        CarbonImmutable::setTestNow($now->addMicroseconds(500_000));

        $inspection = $limiter->inspect($policy);
        $this->assertTrue($inspection->allowed());
        $this->assertSame(1, $inspection->remaining());

        $accepted = $limiter->consume($policy);
        $this->assertTrue($accepted->allowed());
        $this->assertSame(0, $accepted->remaining());
    }

    public function testSlidingWindowUsesGenericStateAndExtendsExpiryOnRotation(): void
    {
        $now = CarbonImmutable::parse('2026-08-04 12:00:00.000000');
        CarbonImmutable::setTestNow($now);
        $store = new CorruptibleWorkerArrayStore;
        $policy = SlidingWindow::perSecond(10, 2)->cost(4);
        $expiresAt = (int) $now->getPreciseTimestamp(6) + 4_000_000;

        $this->assertTrue($store->consume('sliding', $policy)->allowed());
        $this->assertSame([4, 0, $expiresAt], $store->stateFor('sliding'));

        CarbonImmutable::setTestNow($now->addSeconds(2));

        $this->assertTrue($store->consume('sliding', $policy->cost(3))->allowed());
        $this->assertSame([3, 4, $expiresAt + 2_000_000], $store->stateFor('sliding'));

        CarbonImmutable::setTestNow($now->addSeconds(6));

        $this->assertSame(10, $store->inspect('sliding', $policy)->remaining());
        $this->assertSame([3, 4, $expiresAt + 2_000_000], $store->stateFor('sliding'));
    }

    public function testExponentialBackoffUsesThresholdDoublingCapAndInactivityReset(): void
    {
        $now = CarbonImmutable::parse('2026-08-04 12:00:00.000000');
        CarbonImmutable::setTestNow($now);
        $limiter = $this->limiter();
        $backoff = Backoff::exponential(
            after: 3,
            initialDelay: 2,
            maxDelay: 8,
            resetAfter: 20,
        )->by('backoff');

        $this->assertTrue($limiter->recordFailure($backoff)->allowed());
        $this->assertTrue($limiter->recordFailure($backoff)->allowed());

        $third = $limiter->recordFailure($backoff);
        $this->assertTrue($third->denied());
        $this->assertSame(3, $third->failures());
        $this->assertSame(2, $third->retryAfter());
        $this->assertTrue($limiter->inspect($backoff)->denied());

        CarbonImmutable::setTestNow($now->addSeconds(2));
        $this->assertTrue($limiter->inspect($backoff)->allowed());
        $this->assertSame(4, $limiter->recordFailure($backoff)->retryAfter());

        CarbonImmutable::setTestNow($now->addSeconds(6));
        $this->assertSame(8, $limiter->recordFailure($backoff)->retryAfter());

        CarbonImmutable::setTestNow($now->addSeconds(14));
        $this->assertSame(8, $limiter->recordFailure($backoff)->retryAfter());

        CarbonImmutable::setTestNow($now->addSeconds(35));
        $reset = $limiter->inspect($backoff);
        $this->assertTrue($reset->allowed());
        $this->assertSame(0, $reset->failures());
    }

    public function testClearIsParameterSensitive(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 12:00:00');
        $limiter = $this->limiter();
        $policy = Limit::perMinute(1)->by('clear');

        $limiter->consume($policy);

        $this->assertFalse($limiter->clear(Limit::perMinute(2)->by('clear')));
        $this->assertTrue($limiter->inspect($policy)->denied());
        $this->assertTrue($limiter->clear($policy));
        $this->assertTrue($limiter->inspect($policy)->allowed());
    }

    public function testClockOriginDoesNotChangeWhenTestTimeStartsAfterStateCreation(): void
    {
        $limiter = $this->limiter();
        $policy = Limit::perSecond(1, 1)->by('clock');
        $before = CarbonImmutable::now();

        $limiter->consume($policy);
        CarbonImmutable::setTestNow($before->addSeconds(2));

        $this->assertTrue($limiter->inspect($policy)->allowed());
        $this->assertSame(0, $limiter->inspect($policy)->resetAfter());
    }

    public function testCorruptNumericStateFailsExplicitly(): void
    {
        CarbonImmutable::setTestNow('2026-08-04 12:00:00');
        $store = new CorruptibleWorkerArrayStore;
        $store->putState('corrupt', 11, 2_000_000_000_000_000, 2_000_000_000_000_000);

        $this->expectException(UnexpectedValueException::class);

        $store->inspect('corrupt', Limit::perMinute(10));
    }

    private function limiter(): Limiter
    {
        return new Limiter(
            new WorkerArrayStore,
            new KeyResolver('test', static fn (): ?string => null),
        );
    }

    protected function rateLimiterStoreContract(): Limiter
    {
        return $this->limiter();
    }

    protected function advanceRateLimiterStoreContractClock(int $seconds): bool
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($seconds));

        return true;
    }
}

class CorruptibleWorkerArrayStore extends WorkerArrayStore
{
    public function putState(string $key, int $value, int $secondaryValue, int $expiresAt): void
    {
        $this->states[$key] = [
            'value' => $value,
            'secondary_value' => $secondaryValue,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{int, int, int}
     */
    public function stateFor(string $key): array
    {
        return $this->state($key);
    }
}
