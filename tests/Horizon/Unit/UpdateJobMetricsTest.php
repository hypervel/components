<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Events\JobDeleted;
use Hypervel\Horizon\Listeners\UpdateJobMetrics;
use Hypervel\Horizon\Stopwatch;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;
use RuntimeException;

class UpdateJobMetricsTest extends UnitTestCase
{
    public function testQueueMetricFailureStillReleasesTheStopwatchEntry(): void
    {
        $exception = new RuntimeException('queue metrics failed');
        $metrics = m::mock(MetricsRepository::class);
        $metrics->shouldReceive('incrementQueue')->once()->with('critical', 1.5)->andThrow($exception);
        $metrics->shouldNotReceive('incrementJob');

        $watch = m::mock(Stopwatch::class);
        $watch->shouldReceive('check')->once()->with('job-id')->andReturn(1.5);
        $watch->shouldReceive('forget')->once()->with('job-id');

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
        $metrics = m::mock(MetricsRepository::class);
        $metrics->shouldReceive('incrementQueue')->once()->with('critical', 1.5);
        $metrics->shouldReceive('incrementJob')->once()->with('ExampleJob', 1.5)->andThrow($exception);

        $watch = m::mock(Stopwatch::class);
        $watch->shouldReceive('check')->once()->with('job-id')->andReturn(1.5);
        $watch->shouldReceive('forget')->once()->with('job-id');

        try {
            (new UpdateJobMetrics($metrics, $watch))->handle($this->event());
            $this->fail('Expected the metrics failure to propagate.');
        } catch (RuntimeException $thrown) {
            $this->assertSame($exception, $thrown);
        }
    }

    private function event(): JobDeleted
    {
        $job = m::mock(RedisJob::class);
        $job->shouldReceive('hasFailed')->once()->andReturnFalse();
        $job->shouldReceive('getQueue')->once()->andReturn('critical');

        return new JobDeleted(
            $job,
            json_encode([
                'id' => 'job-id',
                'displayName' => 'ExampleJob',
            ]),
        );
    }
}
