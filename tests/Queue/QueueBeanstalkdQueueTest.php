<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Container\Container;
use Hypervel\Events\Dispatcher;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\BeanstalkdQueue;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Jobs\BeanstalkdJob;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\Queue\Fixtures\IntegerQueueName;
use Hypervel\Tests\Queue\Fixtures\UnitQueueName;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use Pheanstalk\Exception\TubeNotFoundException;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\ServerStats;
use Pheanstalk\Values\TubeList;
use Pheanstalk\Values\TubeName;
use Pheanstalk\Values\TubeStats;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

class QueueBeanstalkdQueueTest extends TestCase
{
    use VerifiesDoubles;

    private BeanstalkdQueue $queue;

    private DoubleInterface $container;

    public function testQueueNamesPreserveZeroAndDefaultEmptyString(): void
    {
        $this->setQueue('default', 60);

        $this->assertSame('default', $this->queue->getQueue(null));
        $this->assertSame('default', $this->queue->getQueue(''));
        $this->assertSame('0', $this->queue->getQueue('0'));
        $this->assertSame('0', $this->queue->getQueue(IntegerQueueName::Zero));
        $this->assertSame('Emails', $this->queue->getQueue(UnitQueueName::Emails));
    }

    public function testSizeIncludesPendingDelayedAndReservedJobsWithOneStatsRequest(): void
    {
        $this->setQueue('default', 60);

        $this->queue->getPheanstalk()
            ->expects('statsTube')
            ->with(Argument::satisfies(fn (TubeName $tube): bool => $tube->value === 'stack'))
            ->returns(new TubeStats(
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

    #[DataProvider('tubeCountMethods')]
    public function testAnAbsentTubeHasNoJobs(string $method): void
    {
        $this->setQueue('default', 60);
        $this->queue->getPheanstalk()->expects('statsTube')
            ->throws(new TubeNotFoundException(new TubeName('unused')));

        $this->assertSame(0, $this->queue->{$method}('unused'));
    }

    /**
     * Provide queue size methods backed by tube statistics.
     */
    public static function tubeCountMethods(): array
    {
        return [['size'], ['pendingSize'], ['delayedSize'], ['reservedSize']];
    }

    public function testTubeStatisticsDoNotHideConnectionFailures(): void
    {
        $this->setQueue('default', 60);
        $failure = new RuntimeException('Beanstalkd connection failed.');
        $this->queue->getPheanstalk()->expects('statsTube')->throws($failure);

        $this->expectExceptionObject($failure);

        $this->queue->size();
    }

    public function testTotalSizesUseOneServerStatsRequestPerCount(): void
    {
        $this->setQueue('default', 60);

        $this->queue->getPheanstalk()
            ->expects('stats')
            ->times(4)
            ->with()
            ->returns(new ServerStats(
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

        Str::createUuidsUsing(fn (): Uuid => $uuid);

        $this->setQueue('default', 60);
        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->expects('useTube')->times(2)->with(Argument::type(TubeName::class));
        $pheanstalk->expects('put')->times(2)->with(json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'stringJob' => true, 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'delay' => null]), 1024, 0, 60);

        $this->queue->push('foo', ['data'], 'stack');
        $this->queue->push('foo', ['data']);

        $this->container->received('bound')->with('events')->times(6);
    }

    public function testJobQueuedReceivesTheExactBeanstalkdJobIdentifier(): void
    {
        $this->setQueue('default', 60);

        $jobId = Double::for(JobIdInterface::class)->strict();
        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->expects('useTube')->with(Argument::satisfies(fn (TubeName $tube): bool => $tube->value === '0'));
        $pheanstalk->expects('put')->returns($jobId);

        $events = new Dispatcher;
        $queuedEvent = null;
        $events->listen(JobQueued::class, function (JobQueued $event) use (&$queuedEvent): void {
            $queuedEvent = $event;
        });

        $container = new Container;
        $container->instance('events', $events);
        $this->queue->setContainer($container);

        $this->assertSame($jobId, $this->queue->push('foo', ['data'], IntegerQueueName::Zero));
        $this->assertInstanceOf(JobQueued::class, $queuedEvent);
        $this->assertSame($jobId, $queuedEvent->id);
        $this->assertSame('0', $queuedEvent->queue);
    }

    public function testDelayedPushProperlyPushesJobOntoBeanstalkd(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);

        $uuid = Str::uuid();

        Str::createUuidsUsing(fn (): Uuid => $uuid);

        $this->setQueue('default', 60);
        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->expects('useTube')->times(2)->with(Argument::type(TubeName::class));
        $pheanstalk->expects('put')->times(2)->with(json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'stringJob' => true, 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'delay' => 5]), Pheanstalk::DEFAULT_PRIORITY, 5, Pheanstalk::DEFAULT_TTR);

        $this->queue->later(5, 'foo', ['data'], 'stack');
        $this->queue->later(5, 'foo', ['data']);

        $this->container->received('bound')->with('events')->times(6);
    }

    public function testBulkRespectsDelayAttributeWhenPushingOntoBeanstalkd(): void
    {
        $this->setQueue('default', 60);
        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->expects('useTube')->with(Argument::type(TubeName::class));
        $pheanstalk->expects('put')->with(Argument::type('string'), Pheanstalk::DEFAULT_PRIORITY, 15, Pheanstalk::DEFAULT_TTR);

        $this->queue->bulk([new BeanstalkdJobWithDelayAttribute], ['data']);
    }

    public function testPopProperlyPopsJobOffOfBeanstalkd(): void
    {
        $this->setQueue('default', 60);
        $tube = new TubeName('default');

        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->expects('watch')->with(Argument::type(TubeName::class));
        $pheanstalk->expects('listTubesWatched')->returns(new TubeList($tube));

        $jobId = Double::for(JobIdInterface::class)->strict();
        $jobId->expects('getId');
        $job = new Job($jobId, '');
        $pheanstalk->expects('reserveWithTimeout')->with(0)->returns($job);

        $result = $this->queue->pop();

        $this->assertInstanceOf(BeanstalkdJob::class, $result);
    }

    public function testBlockingPopProperlyPopsJobOffOfBeanstalkd(): void
    {
        $this->setQueue('default', 60, 60);
        $tube = new TubeName('default');

        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->expects('watch')->with(Argument::type(TubeName::class));
        $pheanstalk->expects('listTubesWatched')->returns(new TubeList($tube));

        $jobId = Double::for(JobIdInterface::class)->strict();
        $jobId->expects('getId');
        $job = new Job($jobId, '');
        $pheanstalk->expects('reserveWithTimeout')->with(60)->returns($job);

        $result = $this->queue->pop();

        $this->assertInstanceOf(BeanstalkdJob::class, $result);
    }

    public function testDeleteProperlyRemoveJobsOffBeanstalkd(): void
    {
        $this->setQueue('default', 60);

        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->expects('useTube')->with(Argument::type(TubeName::class));
        $pheanstalk->expects('delete')->with(Argument::type(JobIdInterface::class));

        $this->queue->deleteMessage('default', 1);
    }

    /**
     * Configure the queue and its container.
     */
    private function setQueue(string $default, int $timeToRun, int $blockFor = 0): void
    {
        $this->queue = new BeanstalkdQueue(
            Double::for(PheanstalkManagerInterface::class, PheanstalkPublisherInterface::class, PheanstalkSubscriberInterface::class)->strict(),
            $default,
            $timeToRun,
            $blockFor
        );
        $this->queue->setConnectionName('beanstalkd');
        $this->container = Double::for(Container::class, override: true)->passthru(new Container);
        $this->queue->setContainer($this->container->instance());
    }
}

#[Delay(15)]
class BeanstalkdJobWithDelayAttribute
{
}
