<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateInterval;
use Exception;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;

class InteractsWithQueueTest extends TestCase
{
    use VerifiesDoubles;

    public function testCreatesAnExceptionFromString(): void
    {
        $queueJob = Double::for(Job::class)->strict();
        $queueJob->expects('fail')->with(Argument::capture($exception));

        $job = new class {
            use InteractsWithQueue;

            public ?Job $job = null;
        };

        $job->job = $queueJob;
        $job->fail('Whoops!');

        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertSame('Whoops!', $exception->getMessage());
    }

    public function testReleasesUsingDateInterval(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $queueJob = Double::for(Job::class)->strict();
        $queueJob->expects('release')->with(60);

        $job = new class {
            use InteractsWithQueue;
        };

        $job->job = $queueJob;
        $job->release(new DateInterval('PT1M'));
    }

    public function testAssertsReleaseUsingDateInterval(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $job = new class {
            use InteractsWithQueue;
        };

        $job->withFakeQueueInteractions()
            ->release(new DateInterval('PT1M'));

        $job->assertReleased(new DateInterval('PT1M'));
    }

    public function testAssertsReleaseOfFakeJobReleasedDirectlyWithDateInterval(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $job = new class {
            use InteractsWithQueue;
        };

        $job->withFakeQueueInteractions();
        $job->job->release(new DateInterval('PT1M'));

        $job->assertReleased(60);
    }

    #[TestWith(['10,30,60'])]
    #[TestWith([[10, 30, 60]])]
    public function testReleaseUsingBackoffUsesTheDelayForTheCurrentAttempt(array|string $backoff): void
    {
        $job = $this->jobWithBackoff($backoff, attempts: 2);

        $job->releaseUsingBackoff();

        $this->assertSame(30, $job->job->releaseDelay);
    }

    #[TestWith(['10,30,60'])]
    #[TestWith([[10, 30, 60]])]
    public function testReleaseUsingBackoffUsesTheLastDelayWhenAttemptsExceedTheSchedule(array|string $backoff): void
    {
        $job = $this->jobWithBackoff($backoff, attempts: 5);

        $job->releaseUsingBackoff();

        $this->assertSame(60, $job->job->releaseDelay);
    }

    public function testReleaseUsingBackoffSupportsASingleValue(): void
    {
        $job = $this->jobWithBackoff(15, attempts: 3);

        $job->releaseUsingBackoff();

        $this->assertSame(15, $job->job->releaseDelay);
    }

    public function testReleaseUsingBackoffReleasesImmediatelyWithoutABackoff(): void
    {
        $job = $this->jobWithBackoff(null, attempts: 1);

        $job->releaseUsingBackoff();

        $this->assertTrue($job->job->isReleased());
        $this->assertSame(0, $job->job->releaseDelay);
    }

    /**
     * Create a job whose fake queue job reports the given backoff and attempt count.
     */
    protected function jobWithBackoff(array|int|string|null $backoff, int $attempts): object
    {
        $job = new class {
            use InteractsWithQueue;
        };

        $job->job = new class($backoff, $attempts) extends FakeJob {
            /**
             * Create a fake job with the given backoff and attempt count.
             */
            public function __construct(protected array|int|string|null $backoff, protected int $attemptCount)
            {
                parent::__construct();
            }

            /**
             * Get the backoff for the job.
             */
            public function backoff(): array|int|string|null
            {
                return $this->backoff;
            }

            /**
             * Get the number of times the job has been attempted.
             */
            public function attempts(): int
            {
                return $this->attemptCount;
            }
        };

        return $job;
    }
}
