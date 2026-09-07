<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit;

use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\LimitResult;
use Hypervel\Saloon\RateLimit\Exceptions\RateLimitReachedException;
use Hypervel\Saloon\RateLimit\Queue\ReleaseOnRateLimit;
use Hypervel\Tests\TestCase;
use RuntimeException;

class ReleaseOnRateLimitTest extends TestCase
{
    public function testSuccessfulJobsPassThroughAndReturnTheirResult(): void
    {
        $job = new RateLimitedJobStub;

        $result = (new ReleaseOnRateLimit)->handle(
            $job,
            fn (RateLimitedJobStub $handledJob): string => $handledJob === $job ? 'completed' : 'wrong job',
        );

        $this->assertSame('completed', $result);
        $this->assertNull($job->releasedAfter);
    }

    public function testDeniedRequestsReleaseTheJobForTheDecisionDelay(): void
    {
        $job = new RateLimitedJobStub;
        $exception = new RateLimitReachedException(
            Limit::perMinute(1)->by('provider'),
            new LimitResult(false, 1, 0, 2_500_000, 2_500_000),
        );

        $result = (new ReleaseOnRateLimit)->handle(
            $job,
            static fn () => throw $exception,
        );

        $this->assertNull($result);
        $this->assertSame(3, $job->releasedAfter);
    }

    public function testOtherExceptionsPropagate(): void
    {
        $exception = new RuntimeException('Job failed.');

        $this->expectExceptionObject($exception);

        (new ReleaseOnRateLimit)->handle(
            new RateLimitedJobStub,
            static fn () => throw $exception,
        );
    }
}

class RateLimitedJobStub
{
    public ?int $releasedAfter = null;

    public function release(int $delay = 0): void
    {
        $this->releasedAfter = $delay;
    }
}
