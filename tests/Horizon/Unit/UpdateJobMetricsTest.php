<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Events\JobDeleted;
use Hypervel\Horizon\Listeners\UpdateJobMetrics;
use Hypervel\Horizon\Stopwatch;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Tests\Horizon\UnitTestCase;
use JMac\Testing\Double;
use RuntimeException;

class UpdateJobMetricsTest extends UnitTestCase
{
    public function testQueueMetricFailureStillReleasesTheStopwatchEntry(): void
    {
        $exception = new RuntimeException('queue metrics failed');
        $metrics = Double::for(MetricsRepository::class)->strict();
        $metrics->expects('incrementQueue')->with('critical', 1.5)->throws($exception);
        $metrics->expects('incrementJob')->never();

        $watch = Double::for(Stopwatch::class)->strict();
        $watch->expects('check')->with('job-id')->returns(1.5);
        $watch->expects('forget')->with('job-id');

        try {
            (new UpdateJobMetrics($metrics, $watch))->handle($this->event());
            $this->fail('Expected the metrics failure to propagate.');
        } catch (RuntimeException $thrown) {
            $this->assertSame($exception, $thrown);
        }
    }

    public function testJobMetricFailureStillReleasesTheStopwatchEntry(): void
    {
        $exception = new RuntimeException('job metrics failed');
        $metrics = Double::for(MetricsRepository::class)->strict();
        $metrics->expects('incrementQueue')->with('critical', 1.5);
        $metrics->expects('incrementJob')->with('ExampleJob', 1.5)->throws($exception);

        $watch = Double::for(Stopwatch::class)->strict();
        $watch->expects('check')->with('job-id')->returns(1.5);
        $watch->expects('forget')->with('job-id');

        try {
            (new UpdateJobMetrics($metrics, $watch))->handle($this->event());
            $this->fail('Expected the metrics failure to propagate.');
        } catch (RuntimeException $thrown) {
            $this->assertSame($exception, $thrown);
        }
    }

    private function event(): JobDeleted
    {
        $job = Double::for(RedisJob::class)->strict();
        $job->expects('hasFailed')->returns(false);
        $job->expects('getQueue')->returns('critical');

        return new JobDeleted(
            $job,
            json_encode([
                'id' => 'job-id',
                'displayName' => 'ExampleJob',
            ]),
        );
    }
}
