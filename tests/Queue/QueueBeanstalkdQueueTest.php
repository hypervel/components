<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hyperf\Stringable\Str;
use Hypervel\Queue\BeanstalkdQueue;
use Hypervel\Queue\Jobs\BeanstalkdJob;
use Mockery as m;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\TubeList;
use Pheanstalk\Values\TubeName;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\UuidFactoryInterface;

/**
 * @internal
 * @coversNothing
 */
class QueueBeanstalkdQueueTest extends TestCase
{
    /**
     * @var BeanstalkdQueue
     */
    private $queue;

    /**
     * @var ContainerInterface
     */
    private $container;

    protected function tearDown(): void
    {
        m::close();

        Uuid::setFactory(new UuidFactory());
    }

    public function testPushProperlyPushesJobOntoBeanstalkd()
    {
        $uuid = Str::uuid();

        $uuidFactory = m::mock(UuidFactoryInterface::class);
        $uuidFactory->shouldReceive('uuid4')->andReturn($uuid);
        Uuid::setFactory($uuidFactory);

        $this->setQueue('default', 60);
        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->shouldReceive('useTube')->once()->with(m::type(TubeName::class));
        $pheanstalk->shouldReceive('useTube')->once()->with(m::type(TubeName::class));
        $pheanstalk->shouldReceive('put')->twice()->with(json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data']]), 1024, 0, 60);

        $this->queue->push('foo', ['data'], 'stack');
        $this->queue->push('foo', ['data']);

        $this->container->shouldHaveReceived('has')->with(EventDispatcherInterface::class)->times(4);
    }

    public function testDelayedPushProperlyPushesJobOntoBeanstalkd()
    {
        $uuid = Str::uuid();

        $uuidFactory = m::mock(UuidFactoryInterface::class);
        $uuidFactory->shouldReceive('uuid4')->andReturn($uuid);
        Uuid::setFactory($uuidFactory);

        $this->setQueue('default', 60);
        $pheanstalk = $this->queue->getPheanstalk();
        $pheanstalk->shouldReceive('useTube')->once()->with(m::type(TubeName::class));
        $pheanstalk->shouldReceive('useTube')->once()->with(m::type(TubeName::class));
        $pheanstalk->shouldReceive('put')->twice()->with(json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data']]), Pheanstalk::DEFAULT_PRIORITY, 5, Pheanstalk::DEFAULT_TTR);

        $this->queue->later(5, 'foo', ['data'], 'stack');
        $this->queue->later(5, 'foo', ['data']);

        $this->container->shouldHaveReceived('has')->with(EventDispatcherInterface::class)->times(4);
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
        $this->container = m::spy(ContainerInterface::class);
        $this->queue->setContainer($this->container);
    }
}
