<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Closure;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\BackoffResult;
use Hypervel\RateLimiter\Contracts\Store;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\RateLimiter\KeyResolver;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\LimitResult;
use Hypervel\RateLimiter\WorkerArrayStore;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use RuntimeException;

class LimiterTest extends TestCase
{
    public function testUnlimitedPoliciesNeverAccessTheStore(): void
    {
        $store = new LimiterCountingStore;
        $limiter = new Limiter($store, new KeyResolver('app', static fn (): ?string => null));
        $policy = Limit::none();

        $this->assertTrue($limiter->consume($policy)->allowed());
        $this->assertTrue($limiter->inspect($policy)->allowed());
        $this->assertTrue($limiter->clear($policy));
        $this->assertSame('executed', $limiter->attempt($policy, static fn (): string => 'executed'));
        $this->assertSame(0, $store->calls);
    }

    public function testCrossFieldValidationHappensBeforeKeyOrStoreAccess(): void
    {
        $store = new LimiterCountingStore;
        $scopeCalls = 0;
        $limiter = new Limiter($store, new KeyResolver('app', static function () use (&$scopeCalls): ?string {
            ++$scopeCalls;

            return 'scope';
        }));

        foreach ([
            Limit::perMinute(1)->cost(2),
            LeakyBucket::perSecond(1)->cost(2),
        ] as $policy) {
            try {
                $limiter->consume($policy, 'api');
                $this->fail('Expected an invalid rate limit exception.');
            } catch (InvalidRateLimitException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $scopeCalls);
        $this->assertSame(0, $store->calls);
    }

    public function testTimeRangeValidationHappensBeforeKeyOrStoreAccess(): void
    {
        $store = new LimiterCountingStore;
        $scopeCalls = 0;
        $limiter = new Limiter($store, new KeyResolver('app', static function () use (&$scopeCalls): ?string {
            ++$scopeCalls;

            return 'scope';
        }));
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(
            intdiv(AdmissionPolicy::MAX_INTEGER, 1_000_000) - 30,
        ));

        try {
            $limiter->consume(Limit::perMinute(1), 'api');
            $this->fail('Expected an invalid rate limit exception.');
        } catch (InvalidRateLimitException) {
            $this->addToAssertionCount(1);
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertSame(0, $scopeCalls);
        $this->assertSame(0, $store->calls);
    }

    public function testUnsupportedAdmissionPoliciesFailBeforeKeyOrStoreAccess(): void
    {
        $store = new LimiterCountingStore;
        $scopeCalls = 0;
        $limiter = new Limiter($store, new KeyResolver('app', static function () use (&$scopeCalls): ?string {
            ++$scopeCalls;

            return 'scope';
        }));

        try {
            $limiter->consume(new UnsupportedAdmissionPolicy, 'api');
            $this->fail('Expected an invalid rate limit exception.');
        } catch (InvalidRateLimitException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $scopeCalls);
        $this->assertSame(0, $store->calls);
    }

    // REMOVED: Laravel's primitive counter and fallback-key tests are replaced
    // by atomic policy decisions and canonical identity coverage.

    // REMOVED: Laravel's callback-before-hit attempt() coverage is replaced by
    // one atomic consume before the callback.

    public function testAttemptConsumesBeforeInvokingTheCallback(): void
    {
        $limiter = new Limiter(
            new WorkerArrayStore,
            new KeyResolver('app', static fn (): ?string => null),
        );
        $policy = Limit::perMinute(1)->by('attempt');

        $this->assertTrue($limiter->attempt($policy, static fn (): null => null));
        $this->assertFalse($limiter->attempt($policy, static fn (): string => 'not executed'));
    }

    public function testAttemptRetainsTheChargeWhenTheCallbackThrows(): void
    {
        $limiter = new Limiter(
            new WorkerArrayStore,
            new KeyResolver('app', static fn (): ?string => null),
        );
        $policy = Limit::perMinute(1)->by('exception');

        try {
            $limiter->attempt($policy, static fn (): never => throw new RuntimeException('failed'));
            $this->fail('Expected callback exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed', $exception->getMessage());
        }

        $this->assertTrue($limiter->inspect($policy)->denied());
    }
}

class LimiterCountingStore implements Store
{
    public int $calls = 0;

    public function consume(string $key, AdmissionPolicy $policy): LimitResult
    {
        ++$this->calls;

        return new LimitResult(true, 1, 0, 0, 1_000_000);
    }

    public function inspect(string $key, AdmissionPolicy|Backoff $policy): LimitResult|BackoffResult
    {
        ++$this->calls;

        return $policy instanceof Backoff
            ? new BackoffResult(true, 0, 0)
            : new LimitResult(true, 1, 1, 0, 0);
    }

    public function recordFailure(string $key, Backoff $backoff): BackoffResult
    {
        ++$this->calls;

        return new BackoffResult(true, 1, 0);
    }

    public function clear(string $key): bool
    {
        ++$this->calls;

        return true;
    }
}

readonly class UnsupportedAdmissionPolicy extends AdmissionPolicy
{
    protected function newInstance(
        string $key,
        int $cost,
        bool $global,
        ?Closure $afterCallback,
        ?Closure $responseCallback,
    ): static {
        return new static($key, $cost, $global, $afterCallback, $responseCallback);
    }
}
