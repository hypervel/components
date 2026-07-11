<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\SimpleObjectPool;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Jobs\BeanstalkdJob;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use Pheanstalk\Values\JobStats;
use RuntimeException;
use stdClass;

class PooledJobWorkerTest extends TestCase
{
    public function testProcessedListenerCanReadPrimedAttemptsAfterTerminalDelete(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $container->instance('handler', new PooledJobWorkerHandler);

        $pheanstalk = m::mock(implode(',', [
            PheanstalkManagerInterface::class,
            PheanstalkPublisherInterface::class,
            PheanstalkSubscriberInterface::class,
        ]));
        $pheanstalk->shouldReceive('statsJob')->once()->andReturn($this->stats(3));
        $pheanstalk->shouldReceive('delete')->once();

        $rawJob = m::mock(JobIdInterface::class);
        $rawJob->shouldReceive('getData')->andReturn(json_encode([
            'job' => 'handler',
            'data' => [],
        ], JSON_THROW_ON_ERROR));

        $job = new BeanstalkdJob(
            $container,
            $pheanstalk,
            $rawJob,
            'connection',
            'queue',
        );
        $pool = new SimpleObjectPool(
            $container,
            fn () => new stdClass,
            PoolOptions::fromArray([]),
        );
        $job->withPoolLease(new Lease($pool, $pool->get()));

        $attemptsObservedByListener = null;
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->times(3)
            ->andReturnUsing(function (object $event) use (&$attemptsObservedByListener): array {
                if ($event instanceof JobProcessed) {
                    $attemptsObservedByListener = $event->job->attempts();
                }

                return [];
            });

        $worker = new Worker(
            m::mock(Factory::class),
            $events,
            m::mock(ExceptionHandler::class),
            fn () => false,
        );

        // maxTries=0 takes the normal short-circuit that never reads attempts
        // before the job runs; the processed listener is the first post-hook read.
        $worker->process('connection', $job, new WorkerOptions(maxTries: 0));

        $this->assertSame(3, $attemptsObservedByListener);
        $this->assertTrue($job->isDeleted());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testWorkerExceptionReleasesTheBackendBeforeReturningTheLease(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $container->instance('handler', new FailingPooledJobWorkerHandler);
        $pheanstalk = m::mock(implode(',', [
            PheanstalkManagerInterface::class,
            PheanstalkPublisherInterface::class,
            PheanstalkSubscriberInterface::class,
        ]));
        $pheanstalk->shouldReceive('statsJob')->atLeast()->once()->andReturn($this->stats(1));
        $pheanstalk->shouldReceive('release')->once();
        $rawJob = m::mock(JobIdInterface::class);
        $rawJob->shouldReceive('getData')->andReturn(json_encode([
            'job' => 'handler',
            'data' => [],
        ], JSON_THROW_ON_ERROR));
        $job = new BeanstalkdJob($container, $pheanstalk, $rawJob, 'connection', 'queue');
        $pool = new SimpleObjectPool($container, fn () => new stdClass, PoolOptions::fromArray([]));
        $job->withPoolLease(new Lease($pool, $pool->get()));
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->andReturn([]);
        $worker = new Worker(m::mock(Factory::class), $events, m::mock(ExceptionHandler::class), fn () => false);

        try {
            $worker->process('connection', $job, new WorkerOptions(maxTries: 0, backoff: 3));
            $this->fail('Expected the handler exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('handler failed', $exception->getMessage());
        }

        $this->assertTrue($job->isReleased());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testWorkerTerminalFailureDeletesBeforeReturningTheLease(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $container->instance('handler', new FailingPooledJobWorkerHandler);
        $pheanstalk = m::mock(implode(',', [
            PheanstalkManagerInterface::class,
            PheanstalkPublisherInterface::class,
            PheanstalkSubscriberInterface::class,
        ]));
        $pheanstalk->shouldReceive('statsJob')->atLeast()->once()->andReturn($this->stats(1));
        $pheanstalk->shouldReceive('delete')->once();
        $rawJob = m::mock(JobIdInterface::class);
        $rawJob->shouldReceive('getData')->andReturn(json_encode([
            'job' => 'handler',
            'data' => [],
        ], JSON_THROW_ON_ERROR));
        $job = new BeanstalkdJob($container, $pheanstalk, $rawJob, 'connection', 'queue');
        $pool = new SimpleObjectPool($container, fn () => new stdClass, PoolOptions::fromArray([]));
        $job->withPoolLease(new Lease($pool, $pool->get()));
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->andReturn([]);
        $container->instance(Dispatcher::class, $events);
        $worker = new Worker(m::mock(Factory::class), $events, m::mock(ExceptionHandler::class), fn () => false);

        try {
            $worker->process('connection', $job, new WorkerOptions(maxTries: 1));
            $this->fail('Expected the handler exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('handler failed', $exception->getMessage());
        }

        $this->assertTrue($job->hasFailed());
        $this->assertTrue($job->isDeleted());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    /**
     * Create Beanstalkd job statistics with the given reserve count.
     */
    protected function stats(int $reserves): JobStats
    {
        return JobStats::fromBeanstalkArray([
            'id' => '1',
            'tube' => 'default',
            'state' => 'reserved',
            'pri' => 1024,
            'age' => 1,
            'delay' => 0,
            'ttr' => 60,
            'time-left' => 59,
            'file' => 1,
            'reserves' => $reserves,
            'timeouts' => 0,
            'releases' => 0,
            'buries' => 0,
            'kicks' => 0,
        ]);
    }
}

class PooledJobWorkerHandler
{
    public function fire(BeanstalkdJob $job, array $data): void
    {
        $job->delete();
    }
}

class FailingPooledJobWorkerHandler
{
    public function fire(BeanstalkdJob $job, array $data): never
    {
        throw new RuntimeException('handler failed');
    }
}
