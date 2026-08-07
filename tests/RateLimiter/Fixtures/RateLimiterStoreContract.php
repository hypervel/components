<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter\Fixtures;

use Closure;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\SlidingWindow;

trait RateLimiterStoreContract
{
    public function testStoreContractFixedWindowDecisionsAreAtomicAndComplete(): void
    {
        $limiter = $this->rateLimiterStoreContract();
        $key = $this->rateLimiterStoreContractKey('fixed');
        $policy = Limit::perSecond(5, 5)->cost(2)->by($key);

        $missing = $limiter->inspect($policy);
        $this->assertTrue($missing->allowed());
        $this->assertSame(5, $missing->limit());
        $this->assertSame(5, $missing->remaining());
        $this->assertSame(0, $missing->retryAfter());
        $this->assertSame(0, $missing->resetAfter());

        $accepted = $limiter->consume($policy);
        $this->assertTrue($accepted->allowed());
        $this->assertSame(3, $accepted->remaining());
        $this->assertGreaterThan(0, $accepted->resetAfter());
        $this->assertLessThanOrEqual(5, $accepted->resetAfter());

        $denied = $limiter->consume($policy->cost(4));
        $this->assertTrue($denied->denied());
        $this->assertSame(3, $denied->remaining());
        $this->assertGreaterThan(0, $denied->retryAfter());

        $inspection = $limiter->inspect($policy);
        $this->assertTrue($inspection->allowed());
        $this->assertSame(3, $inspection->remaining());

        $exact = $limiter->consume($policy->cost(3));
        $this->assertTrue($exact->allowed());
        $this->assertSame(0, $exact->remaining());

        $secondDenial = $limiter->consume($policy->cost(1));
        $this->assertTrue($secondDenial->denied());
        $this->assertSame(0, $secondDenial->remaining());
        $this->assertLessThanOrEqual($denied->retryAfter(), $secondDenial->retryAfter());

        $this->assertFalse($limiter->clear(Limit::perSecond(6, 5)->by($key)));
        $this->assertTrue($limiter->clear($policy));
        $this->assertSame(5, $limiter->inspect($policy)->remaining());
    }

    public function testStoreContractFixedWindowExpiresWithoutBeingExtendedByDenials(): void
    {
        $limiter = $this->rateLimiterStoreContract();
        $policy = Limit::perSecond(1)->by($this->rateLimiterStoreContractKey('fixed-expiry'));

        $this->assertTrue($limiter->consume($policy)->allowed());
        $this->assertTrue($limiter->consume($policy)->denied());

        $this->waitForRateLimiterStoreContract(
            static fn (): bool => $limiter->inspect($policy)->resetAfter() === 0,
            1,
        );

        $expired = $limiter->inspect($policy);
        $this->assertTrue($expired->allowed());
        $this->assertSame(1, $expired->remaining());
    }

    public function testStoreContractSlidingWindowDecisionsAreAtomicAndComplete(): void
    {
        $limiter = $this->rateLimiterStoreContract();
        $key = $this->rateLimiterStoreContractKey('sliding');
        $policy = SlidingWindow::perSecond(10, 2)->cost(4)->by($key);

        $missing = $limiter->inspect($policy);
        $this->assertTrue($missing->allowed());
        $this->assertSame(10, $missing->limit());
        $this->assertSame(10, $missing->remaining());
        $this->assertSame(0, $missing->retryAfter());
        $this->assertSame(0, $missing->resetAfter());

        $accepted = $limiter->consume($policy);
        $this->assertTrue($accepted->allowed());
        $this->assertSame(6, $accepted->remaining());
        $this->assertGreaterThan(2, $accepted->resetAfter());
        $this->assertLessThanOrEqual(4, $accepted->resetAfter());

        $this->assertSame(5, $limiter->consume($policy->cost(1))->remaining());

        $denied = $limiter->consume($policy->cost(6));
        $this->assertTrue($denied->denied());
        $this->assertSame(5, $denied->remaining());
        $this->assertGreaterThan(0, $denied->retryAfter());

        $inspection = $limiter->inspect($policy);
        $this->assertTrue($inspection->allowed());
        $this->assertSame(5, $inspection->remaining());

        $this->assertSame(11, $limiter->inspect(SlidingWindow::perSecond(11, 2)->by($key))->remaining());
        $this->assertFalse($limiter->clear(SlidingWindow::perSecond(11, 2)->by($key)));
        $this->assertTrue($limiter->clear($policy));
        $this->assertSame(10, $limiter->inspect($policy)->remaining());
    }

    public function testStoreContractSlidingWindowRecoversAcrossABoundaryAndExpires(): void
    {
        $limiter = $this->rateLimiterStoreContract();
        $policy = SlidingWindow::perSecond(10, 2)
            ->cost(6)
            ->by($this->rateLimiterStoreContractKey('sliding-recovery'));

        $this->assertTrue($limiter->consume($policy)->allowed());
        $this->assertTrue($limiter->consume($policy->cost(5))->denied());

        $this->waitForRateLimiterStoreContract(
            static fn (): bool => $limiter->inspect($policy->cost(5))->allowed(),
            3,
        );

        $recovered = $limiter->consume($policy->cost(5));
        $this->assertTrue($recovered->allowed());
        $this->assertGreaterThan(0, $recovered->resetAfter());

        $expiring = SlidingWindow::perSecond(1)
            ->by($this->rateLimiterStoreContractKey('sliding-expiry'));
        $this->assertTrue($limiter->consume($expiring)->allowed());

        $this->waitForRateLimiterStoreContract(
            static fn (): bool => $limiter->inspect($expiring)->resetAfter() === 0,
            2,
        );

        $expired = $limiter->inspect($expiring);
        $this->assertTrue($expired->allowed());
        $this->assertSame(1, $expired->remaining());
    }

    public function testStoreContractLeakyBucketRecoversWithoutMutatingDenials(): void
    {
        $limiter = $this->rateLimiterStoreContract();
        $policy = LeakyBucket::perSecond(2)
            ->burst(3)
            ->by($this->rateLimiterStoreContractKey('leaky'));

        $accepted = $limiter->consume($policy->cost(2));
        $this->assertTrue($accepted->allowed());
        $this->assertSame(1, $accepted->remaining());

        $denied = $limiter->consume($policy->cost(2));
        $this->assertTrue($denied->denied());
        $this->assertSame(1, $denied->remaining());
        $this->assertGreaterThan(0, $denied->retryAfter());

        $inspection = $limiter->inspect($policy);
        $this->assertTrue($inspection->allowed());
        $this->assertSame(1, $inspection->remaining());

        $this->assertTrue($limiter->consume($policy)->allowed());
        $this->assertSame(0, $limiter->inspect($policy)->remaining());

        $this->waitForRateLimiterStoreContract(
            static fn (): bool => $limiter->inspect($policy)->remaining() === 3,
            2,
        );

        $full = $limiter->inspect($policy);
        $this->assertTrue($full->allowed());
        $this->assertSame(3, $full->remaining());
        $this->assertSame(0, $full->resetAfter());
    }

    public function testStoreContractBackoffThresholdDoublingCapClearAndInactivityReset(): void
    {
        $limiter = $this->rateLimiterStoreContract();
        $backoff = Backoff::exponential(
            after: 2,
            initialDelay: 1,
            maxDelay: 2,
            resetAfter: 2,
        )->by($this->rateLimiterStoreContractKey('backoff'));

        $missing = $limiter->inspect($backoff);
        $this->assertTrue($missing->allowed());
        $this->assertSame(0, $missing->failures());

        $first = $limiter->recordFailure($backoff);
        $this->assertTrue($first->allowed());
        $this->assertSame(1, $first->failures());

        $threshold = $limiter->recordFailure($backoff);
        $this->assertTrue($threshold->denied());
        $this->assertSame(2, $threshold->failures());
        $this->assertSame(1, $threshold->retryAfter());

        $doubled = $limiter->recordFailure($backoff);
        $this->assertSame(3, $doubled->failures());
        $this->assertSame(2, $doubled->retryAfter());

        $capped = $limiter->recordFailure($backoff);
        $this->assertSame(4, $capped->failures());
        $this->assertSame(2, $capped->retryAfter());

        $this->assertTrue($limiter->clear($backoff));
        $this->assertSame(0, $limiter->inspect($backoff)->failures());

        $inactivity = $backoff->by($this->rateLimiterStoreContractKey('backoff-inactivity'));
        $this->assertSame(1, $limiter->recordFailure($inactivity)->failures());

        $this->waitForRateLimiterStoreContract(
            static fn (): bool => $limiter->inspect($inactivity)->failures() === 0,
            2,
        );

        $this->assertTrue($limiter->inspect($inactivity)->allowed());
    }

    abstract protected function rateLimiterStoreContract(): Limiter;

    /**
     * Advance a deterministic store clock and return whether time was advanced.
     */
    protected function advanceRateLimiterStoreContractClock(int $seconds): bool
    {
        return false;
    }

    /**
     * Wait until a backend-time condition becomes true.
     */
    protected function waitForRateLimiterStoreContract(Closure $condition, int $seconds): void
    {
        if ($this->advanceRateLimiterStoreContractClock($seconds)) {
            $this->assertTrue($condition());

            return;
        }

        $deadline = microtime(true) + $seconds + 2;

        do {
            if ($condition()) {
                $this->addToAssertionCount(1);

                return;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail('The rate limiter state did not expire before the test deadline.');
    }

    /**
     * Create a unique logical key for a contract case.
     */
    protected function rateLimiterStoreContractKey(string $suffix): string
    {
        return static::class . ':' . $suffix;
    }
}
