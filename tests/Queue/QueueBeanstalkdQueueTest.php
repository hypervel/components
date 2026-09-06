<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Container\Container as Application;
use Hypervel\Contracts\Container\Container;
use Hypervel\Events\Dispatcher;
use Hypervel\Queue\BeanstalkdQueue;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Jobs\BeanstalkdJob;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\ServerStats;
use Pheanstalk\Values\TubeList;
use Pheanstalk\Values\TubeName;
use Pheanstalk\Values\TubeStats;

class QueueBeanstalkdQueueTest extends TestCase
{
    /**
     * @var BeanstalkdQueue
     */
    private $queue;

    /**
     * @var Container
     */
    private $container;

    public function testQueueNamesPreserveZeroAndDefaultEmptyString(): void
    {
        $this->setQueue('default', 60);

        $this->assertSame('default', $this->queue->getQueue(null));
        $this->assertSame('default', $this->queue->getQueue(''));
        $this->assertSame('0', $this->queue->getQueue('0'));
    }

    public function testSizeIncludesPendingDelayedAndReservedJobsWithOneStatsRequest(): void
    {
        $this->setQueue('default', 60);

        $this->queue->getPheanstalk()
            ->shouldReceive('statsTube')
            ->once()
            ->with(m::on(fn (TubeName $tube) => $tube->value === 'stack'))
            ->andReturn(new TubeStats(
                name: new TubeName('stack'),
                currentJobsUrgent: 0,
                currentJobsReady: 3,
                currentJobsReserved: 5,
                currentJobsDelayed: 4,
                currentJobsBuried: 6,
                totalJobs: 18,
                currentUsing: 0,
                currentWaiting: 0,
                currentWatching: 0,
                pause: 0,
                cmdDelete: 0,
                cmdPauseTube: 0,
                pauseTimeLeft: 0,
            ));

        $this->assertSame(12, $this->queue->size('stack'));
    }

    public function testTotalSizesUseOneServerStatsRequestPerCount(): void
    {
        $this->setQueue('default', 60);

        $this->queue->getPheanstalk()
            ->shouldReceive('stats')
            ->times(4)
            ->withNoArgs()
            ->andReturn(new ServerStats(
                currentJobsUrgent: 1,
                currentJobsReady: 3,
                currentJobsReserved: 5,
                currentJobsDelayed: 4,
                currentJobsBuried: 6,
                cmdPut: 0,
                cmdPeek: 0,
                cmdPeekReady: 0,
                cmdPeekDelayed: 0,
                cmdReserveWithTimeout: 0,
                cmdPeekBuried: 0,
                cmdReserve: 0,
                cmdUse: 0,
                cmdWatch: 0,
                cmdIgnore: 0,
                cmdDelete: 0,
                cmdRelease: 0,
                cmdBury: 0,
                cmdKick: 0,
                cmdStats: 0,
                cmdStatsJob: 0,
                cmdStatsTube: 0,
                cmdListTubes: 0,
                cmdListTubeUsed: 0,
                cmdListTubesWatched: 0,
                cmdPauseTube: 0,
                jobTimeouts: 0,
                totalJobs: 18,
                maxJobSize: 65535,
                currentTubes: 2,
                currentConnections: 1,
                currentProducers: 0,
                currentWorkers: 0,
                currentWaiting: 0,
                totalConnections: 1,
                pid: 1,
                version: '1.13',
                rusageUtime: 0.0,
                rusageStime: 0.0,
                binlogOldestIndex: 0,
                binlogCurrentIndex: 0,
                binlogMaxSize: 0,
                binlogRecordsWritten: 0,
                draining: false,
                id: 'test-server',
                hostname: 'localhost',
                os: 'Linux',
                platform: 'x86_64',
                cmdTouch: 0,
                uptime: 0,
                binlogRecordsMigrated: 0,
            ));

        $this->assertSame(12, $this->queue->totalSize());
        $this->assertSame(3, $this->queue->totalPendingSize());
        $this->assertSame(4, $this->queue->totalDelayedSize());
        $this->assertSame(5, $this->queue->totalReservedSize());
    }

    public function testInspectionReturnsEmptyCollections(): void
    {
        $this->setQueue('default', 60);

        $this->assertTrue($this->queue->pendingJobs()->isEmpty());
        $this->assertTrue($this->queue->delayedJobs()->isEmpty());
        $this->assertTrue($this->queue->reservedJobs()->isEmpty());
        $this->assertTrue($this->queue->allPendingJobs()->isEmpty());
        $this->assertTrue($this->queue->allDelayedJobs()->isEmpty());
        $this->assertTrue($this->queue->allReservedJobs()->isEmpty());
    }

    public function testPushProperlyPushesJobOntoBeanstalkd(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);

        $uuid = Str::uuid();

        Str::createUuidsUsing(fn () => $uuid);

        $this->setQueue('default', 60);
        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->shouldReceive('useTube')->once()->with(m::type(TubeName::class));
        $pheanstalk->shouldReceive('useTube')->once()->with(m::type(TubeName::class));
        $pheanstalk->shouldReceive('put')->twice()->with(json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'delay' => null]), 1024, 0, 60);

        $this->queue->push('foo', ['data'], 'stack');
        $this->queue->push('foo', ['data']);

        $this->container->shouldHaveReceived('bound')->with('events')->times(6);
    }

    public function testJobQueuedReceivesTheExactBeanstalkdJobIdentifier(): void
    {
        $this->setQueue('default', 60);

        $jobId = m::mock(JobIdInterface::class);
        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->shouldReceive('useTube')->once()->with(m::type(TubeName::class));
        $pheanstalk->shouldReceive('put')->once()->andReturn($jobId);

        $events = new Dispatcher;
        $queuedEvent = null;
        $events->listen(JobQueued::class, function (JobQueued $event) use (&$queuedEvent): void {
            $queuedEvent = $event;
        });

        $container = new Application;
        $container->instance('events', $events);
        $this->queue->setContainer($container);

        $this->assertSame($jobId, $this->queue->push('foo', ['data']));
        $this->assertInstanceOf(JobQueued::class, $queuedEvent);
        $this->assertSame($jobId, $queuedEvent->id);
    }

    public function testDelayedPushProperlyPushesJobOntoBeanstalkd(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);

        $uuid = Str::uuid();

        Str::createUuidsUsing(fn () => $uuid);

        $this->setQueue('default', 60);
        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->shouldReceive('useTube')->once()->with(m::type(TubeName::class));
        $pheanstalk->shouldReceive('useTube')->once()->with(m::type(TubeName::class));
        $pheanstalk->shouldReceive('put')->twice()->with(json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'delay' => 5]), Pheanstalk::DEFAULT_PRIORITY, 5, Pheanstalk::DEFAULT_TTR);

        $this->queue->later(5, 'foo', ['data'], 'stack');
        $this->queue->later(5, 'foo', ['data']);

        $this->container->shouldHaveReceived('bound')->with('events')->times(6);
    }

    public function testPopProperlyPopsJobOffOfBeanstalkd()
    {
        $this->setQueue('default', 60);
        $tube = new TubeName('default');

        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->shouldReceive('watch')->once()->with(m::type(TubeName::class))
            ->shouldReceive('listTubesWatched')->once()->andReturn(new TubeList($tube));

        $jobId = m::mock(JobIdInterface::class);
        $jobId->shouldReceive('getId')->once();
        $job = new Job($jobId, '');
        $pheanstalk->shouldReceive('reserveWithTimeout')->once()->with(0)->andReturn($job);

        $result = $this->queue->pop();

        $this->assertInstanceOf(BeanstalkdJob::class, $result);
    }

    public function testBlockingPopProperlyPopsJobOffOfBeanstalkd()
    {
        $this->setQueue('default', 60, 60);
        $tube = new TubeName('default');

        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->shouldReceive('watch')->once()->with(m::type(TubeName::class))
            ->shouldReceive('listTubesWatched')->once()->andReturn(new TubeList($tube));

        $jobId = m::mock(JobIdInterface::class);
        $jobId->shouldReceive('getId')->once();
        $job = new Job($jobId, '');
        $pheanstalk->shouldReceive('reserveWithTimeout')->once()->with(60)->andReturn($job);

        $result = $this->queue->pop();

        $this->assertInstanceOf(BeanstalkdJob::class, $result);
    }

    public function testDeleteProperlyRemoveJobsOffBeanstalkd()
    {
        $this->setQueue('default', 60);

        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->shouldReceive('useTube')->once()->with(m::type(TubeName::class))->andReturn($pheanstalk);
        $pheanstalk->shouldReceive('delete')->once()->with(m::type(JobIdInterface::class));

        $this->queue->deleteMessage('default', 1);
    }

    private function setQueue(string $default, int $timeToRun, int $blockFor = 0): void
    {
        $this->queue = new BeanstalkdQueue(
            m::mock(implode(',', [PheanstalkManagerInterface::class, PheanstalkPublisherInterface::class, PheanstalkSubscriberInterface::class])),
            $default,
            $timeToRun,
            $blockFor
        );
        $this->queue->setConnectionName('beanstalkd');
        $this->container = m::spy(Container::class);
        $this->queue->setContainer($this->container);
    }
}
