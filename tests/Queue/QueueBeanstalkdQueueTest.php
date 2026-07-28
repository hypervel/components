<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Container\Container;
use Hypervel\Queue\BeanstalkdQueue;
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

        $this->container->shouldHaveReceived('bound')->with('events')->times(4);
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

        $this->container->shouldHaveReceived('bound')->with('events')->times(4);
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
