<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\Stopwatch;
use Hypervel\Horizon\WaitTimeCalculator;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Jobs\BasicJob;
use Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Jobs\ConditionallyFailingJob;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use Mockery as m;

class MetricsTest extends IntegrationTestCase
{
    /**
     * Clear metrics before the test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Clear through Horizon's metrics repository so every test starts from
        // the same explicit state, even if Redis pool state survives setUp.
        resolve(MetricsRepository::class)->clear();
    }

    public function testTotalThroughputIsStored(): void
    {
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);

        $this->work();
        $this->work();

        $this->assertSame(2, resolve(MetricsRepository::class)->throughput());
    }

    public function testThroughputIsStoredPerJobClass(): void
    {
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);
        Queue::push(new ConditionallyFailingJob);

        $this->work();
        $this->work();
        $this->work();
        $this->work();

        $this->assertSame(4, resolve(MetricsRepository::class)->throughput());
        $this->assertSame(3, resolve(MetricsRepository::class)->throughputForJob(BasicJob::class));
        $this->assertSame(1, resolve(MetricsRepository::class)->throughputForJob(ConditionallyFailingJob::class));
    }

    public function testThroughputIsStoredPerQueue(): void
    {
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);
        Queue::push(new ConditionallyFailingJob);

        $this->work();
        $this->work();
        $this->work();
        $this->work();

        $this->assertSame(4, resolve(MetricsRepository::class)->throughput());
        $this->assertSame(4, resolve(MetricsRepository::class)->throughputForQueue('default'));
    }

    public function testAverageRuntimeIsStoredPerJobClassInMilliseconds(): void
    {
        $stopwatch = m::mock(Stopwatch::class);
        $stopwatch->shouldReceive('start');
        $stopwatch->shouldReceive('forget');
        $stopwatch->shouldReceive('check')->andReturn(1, 2);
        $this->app->instance(Stopwatch::class, $stopwatch);

        Queue::push(new BasicJob);
        Queue::push(new BasicJob);

        $this->work();
        $this->work();

        $this->assertSame(1.5, resolve(MetricsRepository::class)->runtimeForJob(BasicJob::class));
    }

    public function testAverageRuntimeIsStoredPerQueueInMilliseconds(): void
    {
        $stopwatch = m::mock(Stopwatch::class);
        $stopwatch->shouldReceive('start');
        $stopwatch->shouldReceive('forget');
        $stopwatch->shouldReceive('check')->andReturn(1, 2);
        $this->app->instance(Stopwatch::class, $stopwatch);

        Queue::push(new BasicJob);
        Queue::push(new BasicJob);

        $this->work();
        $this->work();

        $this->assertSame(1.5, resolve(MetricsRepository::class)->runtimeForQueue('default'));
    }

    public function testListOfAllJobsWithMetricInformationIsMaintained(): void
    {
        Queue::push(new BasicJob);
        Queue::push(new ConditionallyFailingJob);

        $this->work();
        $this->work();

        $jobs = resolve(MetricsRepository::class)->measuredJobs();
        $this->assertCount(2, $jobs);
        $this->assertContains(ConditionallyFailingJob::class, $jobs);
        $this->assertContains(BasicJob::class, $jobs);
    }

    public function testSnapshotOfMetricsPerformanceCanBeStored(): void
    {
        $stopwatch = m::mock(Stopwatch::class);
        $stopwatch->shouldReceive('start');
        $stopwatch->shouldReceive('forget');
        $stopwatch->shouldReceive('check')->andReturn(1, 2, 3);
        $this->app->instance(Stopwatch::class, $stopwatch);

        Queue::push(new BasicJob);
        Queue::push(new BasicJob);

        // Run first two jobs...
        $this->work();
        $this->work();

        // Take initial snapshot and set initial timestamp...
        $firstTimestamp = CarbonImmutable::create(2026, 1, 1, 0, 0, 0);
        $secondTimestamp = $firstTimestamp->addSecond();

        CarbonImmutable::setTestNow($firstTimestamp);
        resolve(MetricsRepository::class)->snapshot();

        // The runtime remains the latest estimate while the throughput restarts...
        $this->assertSame(1.5, resolve(MetricsRepository::class)->runtimeForJob(BasicJob::class));
        $this->assertSame(1.5, resolve(MetricsRepository::class)->runtimeForQueue('default'));
        $this->assertSame(0, resolve(MetricsRepository::class)->throughputForJob(BasicJob::class));
        $this->assertSame(0, resolve(MetricsRepository::class)->throughputForQueue('default'));

        // Work another job and take another snapshot...
        Queue::push(new BasicJob);
        $this->work();
        CarbonImmutable::setTestNow($secondTimestamp);
        resolve(MetricsRepository::class)->snapshot();

        // Take two snapshots without completed jobs...
        $thirdTimestamp = $secondTimestamp->addSecond();
        $fourthTimestamp = $thirdTimestamp->addSecond();

        CarbonImmutable::setTestNow($thirdTimestamp);
        resolve(MetricsRepository::class)->snapshot();
        CarbonImmutable::setTestNow($fourthTimestamp);
        resolve(MetricsRepository::class)->snapshot();

        $this->assertSame(3.0, resolve(MetricsRepository::class)->runtimeForJob(BasicJob::class));
        $this->assertSame(3.0, resolve(MetricsRepository::class)->runtimeForQueue('default'));

        $snapshots = resolve(MetricsRepository::class)->snapshotsForJob(BasicJob::class);

        // Test job snapshots...
        $this->assertEquals([
            (object) [
                'throughput' => 2,
                'runtime' => 1.5,
                'time' => $firstTimestamp->getTimestamp(),
            ],
            (object) [
                'throughput' => 1,
                'runtime' => 3,
                'time' => $secondTimestamp->getTimestamp(),
            ],
            (object) [
                'throughput' => false,
                'runtime' => false,
                'time' => $thirdTimestamp->getTimestamp(),
            ],
            (object) [
                'throughput' => false,
                'runtime' => false,
                'time' => $fourthTimestamp->getTimestamp(),
            ],
        ], $snapshots);

        // Test queue snapshots...
        $snapshots = resolve(MetricsRepository::class)->snapshotsForQueue('default');
        $this->assertEquals([
            (object) [
                'throughput' => 2,
                'runtime' => 1.5,
                'wait' => 0,
                'time' => $firstTimestamp->getTimestamp(),
            ],
            (object) [
                'throughput' => 1,
                'runtime' => 3,
                'wait' => 0,
                'time' => $secondTimestamp->getTimestamp(),
            ],
            (object) [
                'throughput' => false,
                'runtime' => false,
                'wait' => 0,
                'time' => $thirdTimestamp->getTimestamp(),
            ],
            (object) [
                'throughput' => false,
                'runtime' => false,
                'wait' => 0,
                'time' => $fourthTimestamp->getTimestamp(),
            ],
        ], $snapshots);
    }

    public function testQueueSnapshotsRecordTheWaitOfThePoolsProcessingTheQueue(): void
    {
        $stopwatch = m::mock(Stopwatch::class);
        $stopwatch->shouldReceive('start');
        $stopwatch->shouldReceive('forget');
        $stopwatch->shouldReceive('check')->andReturn(1000);
        $this->app->instance(Stopwatch::class, $stopwatch);

        $supervisors = m::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([
            (object) ['processes' => ['redis:high,default' => 1]],
        ]);
        $this->app->instance(SupervisorRepository::class, $supervisors);

        Queue::push(new BasicJob);
        $this->work();

        // Leave two jobs waiting through a snapshot without completed jobs...
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);

        $firstTimestamp = CarbonImmutable::create(2026, 1, 1, 0, 0, 0);
        $secondTimestamp = $firstTimestamp->addSecond();

        CarbonImmutable::setTestNow($firstTimestamp);
        resolve(MetricsRepository::class)->snapshot();
        CarbonImmutable::setTestNow($secondTimestamp);
        resolve(MetricsRepository::class)->snapshot();

        $this->assertSame(2.0, resolve(WaitTimeCalculator::class)->calculateFor('redis:high,default'));
        $this->assertSame(
            [2, 2],
            array_column(resolve(MetricsRepository::class)->snapshotsForQueue('default'), 'wait'),
        );

        CarbonImmutable::setTestNow();
    }

    public function testJobsProcessedPerMinuteSinceLastSnapshotIsCalculable(): void
    {
        $stopwatch = m::mock(Stopwatch::class);
        $stopwatch->shouldReceive('start');
        $stopwatch->shouldReceive('forget');
        $stopwatch->shouldReceive('check')->andReturn(1);
        $this->app->instance(Stopwatch::class, $stopwatch);

        Queue::push(new BasicJob);
        Queue::push(new BasicJob);

        // Run first two jobs...
        $this->work();
        $this->work();

        $this->assertSame(
            2.0,
            resolve(MetricsRepository::class)->jobsProcessedPerMinute()
        );

        // Adjust current time...
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        $this->assertSame(
            1.0,
            resolve(MetricsRepository::class)->jobsProcessedPerMinute()
        );

        // take snapshot and ensure count is reset...
        resolve(MetricsRepository::class)->snapshot();

        $this->assertSame(
            0.0,
            resolve(MetricsRepository::class)->jobsProcessedPerMinute()
        );
    }

    public function testQueueWithMaximumRuntimeAndThroughputComparesLatestSnapshot(): void
    {
        $repository = resolve(MetricsRepository::class);
        $connection = $repository->connection();

        // Two measured queues, each with three snapshots (the case where the
        // ZRANGE range matters — fewer than three would mask the bug). The most
        // recent snapshot is the highest-scored member. "fast" has the greater
        // throughput, "slow" the greater runtime, so each card must surface a
        // different queue rather than an arbitrary one.
        $connection->sAdd('measured_queues', 'queue:fast', 'queue:slow');

        foreach ([
            'fast' => [['throughput' => 10, 'runtime' => 5], ['throughput' => 50, 'runtime' => 10], ['throughput' => 102, 'runtime' => 21]],
            'slow' => [['throughput' => 3, 'runtime' => 100], ['throughput' => 7, 'runtime' => 200], ['throughput' => 11, 'runtime' => 338]],
        ] as $queue => $snapshots) {
            foreach ($snapshots as $score => $snapshot) {
                $connection->zAdd('snapshot:queue:' . $queue, $score, json_encode($snapshot));
            }
        }

        $this->assertSame('fast', $repository->queueWithMaximumThroughput());
        $this->assertSame('slow', $repository->queueWithMaximumRuntime());
    }

    // REMOVED: Laravel Horizon's null HMGET snapshot test does not apply; PhpRedis returns false fields for a
    // missing hash, which RedisMetricsRepositoryTest covers.

    public function testOmittedRetentionSettingsKeepTheDefaultNumberOfSnapshots(): void
    {
        config()->set('horizon.metrics.trim_snapshots', []);
        $retention = 24;

        $stopwatch = m::mock(Stopwatch::class);
        $stopwatch->shouldReceive('start');
        $stopwatch->shouldReceive('forget');
        $stopwatch->shouldReceive('check')->andReturn(1);
        $this->app->instance(Stopwatch::class, $stopwatch);

        CarbonImmutable::setTestNow(CarbonImmutable::now());

        // Run the jobs...
        for ($i = 0; $i < 30; ++$i) {
            Queue::push(new BasicJob);
            $this->work();
            resolve(MetricsRepository::class)->snapshot();
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1));
        }

        // Check the job snapshots...
        $snapshots = resolve(MetricsRepository::class)->snapshotsForJob(BasicJob::class);
        $this->assertCount($retention, $snapshots);
        $this->assertSame(
            CarbonImmutable::now()->getTimestamp() - 1,
            $snapshots[$retention - 1]->time,
        );

        // Check the queue snapshots...
        $snapshots = resolve(MetricsRepository::class)->snapshotsForQueue('default');
        $this->assertCount($retention, $snapshots);
        $this->assertSame(
            CarbonImmutable::now()->getTimestamp() - 1,
            $snapshots[$retention - 1]->time,
        );

        CarbonImmutable::setTestNow();
    }

    public function testClearRemovesAllMetricsData(): void
    {
        $stopwatch = m::mock(Stopwatch::class);
        $stopwatch->shouldReceive('start');
        $stopwatch->shouldReceive('forget');
        $stopwatch->shouldReceive('check')->andReturn(1);
        $this->app->instance(Stopwatch::class, $stopwatch);

        Queue::push(new BasicJob);
        Queue::push(new ConditionallyFailingJob);

        $this->work();
        $this->work();

        // Take a snapshot so we have snapshot:* keys too
        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $metrics = resolve(MetricsRepository::class);
        $metrics->snapshot();

        // Verify data exists before clearing
        $this->assertNotEmpty($metrics->measuredJobs());
        $this->assertNotEmpty($metrics->measuredQueues());
        $this->assertNotEmpty($metrics->snapshotsForJob(BasicJob::class));
        $this->assertNotEmpty($metrics->snapshotsForQueue('default'));

        // Clear all metrics
        $metrics->clear();

        // Verify everything is gone
        $this->assertEmpty($metrics->measuredJobs());
        $this->assertEmpty($metrics->measuredQueues());
        $this->assertSame(0, $metrics->throughput());
        $this->assertSame(0.0, $metrics->runtimeForJob(BasicJob::class));
        $this->assertSame(0.0, $metrics->runtimeForQueue('default'));
        $this->assertEmpty($metrics->snapshotsForJob(BasicJob::class));
        $this->assertEmpty($metrics->snapshotsForJob(ConditionallyFailingJob::class));
        $this->assertEmpty($metrics->snapshotsForQueue('default'));

        CarbonImmutable::setTestNow();
    }

    public function testClearIsIdempotent(): void
    {
        $metrics = resolve(MetricsRepository::class);

        // Clear when no data exists should not error
        $metrics->clear();

        $this->assertEmpty($metrics->measuredJobs());
        $this->assertEmpty($metrics->measuredQueues());
        $this->assertSame(0, $metrics->throughput());
    }
}
