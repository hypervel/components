<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateInterval;
use Exception;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class InteractsWithQueueTest extends TestCase
{
    use VerifiesDoubles;

    public function testCreatesAnExceptionFromString(): void
    {
        $queueJob = Double::for(Job::class);
        $queueJob->expects('fail')->with(Argument::satisfies(function (Exception $exception): bool {
            $this->assertSame('Whoops!', $exception->getMessage());

            return true;
        }));

        $job = new class {
            use InteractsWithQueue;

            public ?Job $job = null;
        };

        $job->job = $queueJob;
        $job->fail('Whoops!');
    }

    public function testReleasesUsingDateInterval(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $queueJob = Double::for(Job::class);
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
}
