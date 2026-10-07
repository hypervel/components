<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Hypervel\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\LimitResult;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\RateLimit\Exceptions\RateLimitReachedException;
use Hypervel\Saloon\RateLimit\Queue\ReleaseOnRateLimit;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\RateLimit\Fixtures\Jobs\ApiRateLimitedJob;
use RuntimeException;

// ReleaseOnRateLimit is the job middleware in place of upstream's Helpers\ApiRateLimited.
class JobMiddlewareTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Define the environment setup.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('saloon.rate_limiter.store', 'worker-array');
    }

    public function testTheApiRateLimitedJobWillReleaseAJobOntoTheQueueIfALimitIsReached(): void
    {
        $limit = Limit::perMinute(3);
        $connector = new TestConnector([$limit]);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['name' => 'Sam'])]);

        $jobs = [];

        Queue::after(static function (JobProcessed $event) use (&$jobs): void {
            $jobs[] = $event->job;
        });

        ApiRateLimitedJob::dispatchSync($connector);
        ApiRateLimitedJob::dispatchSync($connector);
        ApiRateLimitedJob::dispatchSync($connector);
        ApiRateLimitedJob::dispatchSync($connector);

        $this->assertCount(4, $jobs);
        $this->assertFalse($jobs[0]->isReleased());
        $this->assertFalse($jobs[1]->isReleased());
        $this->assertFalse($jobs[2]->isReleased());
        $this->assertTrue($jobs[3]->isReleased());

        $this->assertTrue(Saloon::inspectRateLimit($connector, $limit)->denied());
        Http::assertSentCount(3);
    }

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
            'saloon:provider',
            Limit::perMinute(1)->by('provider'),
            new LimitResult(false, 1, 0, 2_500_000, 2_500_000),
        );

        $result = (new ReleaseOnRateLimit)->handle($job, static fn (): never => throw $exception);

        $this->assertNull($result);
        $this->assertSame(3, $job->releasedAfter);
    }

    public function testOtherExceptionsPropagate(): void
    {
        $exception = new RuntimeException('Job failed.');

        $this->expectExceptionObject($exception);

        (new ReleaseOnRateLimit)->handle(new RateLimitedJobStub, static fn (): never => throw $exception);
    }
}

class RateLimitedJobStub
{
    public ?int $releasedAfter = null;

    /**
     * Release the job back onto the queue.
     */
    public function release(int $delay = 0): void
    {
        $this->releasedAfter = $delay;
    }
}
