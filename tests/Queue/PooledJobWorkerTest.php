<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\ObjectPool\CallbackObjectPool;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobReleasedAfterException;
use Hypervel\Queue\Jobs\BeanstalkdJob;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use Pheanstalk\Values\Job as PheanstalkJob;
use Pheanstalk\Values\JobId;
use Pheanstalk\Values\JobStats;
use RuntimeException;
use stdClass;

class PooledJobWorkerTest extends TestCase
{
    use VerifiesDoubles;

    /** @var list<CallbackObjectPool> */
    private array $pools = [];

    protected function tearDownInCoroutine(): void
    {
        foreach ($this->pools as $pool) {
            $pool->close();
        }
    }

    public function testProcessedListenerCanReadPrimedAttemptsAfterTerminalDelete(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $container->instance('handler', new PooledJobWorkerHandler);

        $pheanstalk = Double::for(
            PheanstalkManagerInterface::class,
            PheanstalkPublisherInterface::class,
            PheanstalkSubscriberInterface::class,
        )->strict();
        $pheanstalk->expects('statsJob')->returns($this->stats(3));
        $pheanstalk->expects('delete');

        $rawJob = new PheanstalkJob(new JobId(1), json_encode([
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
        $pool = $this->pool();
        $job->withPoolLease(new Lease($pool, $pool->borrow()));

        $attemptsObservedByListener = null;
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(JobProcessing::class)->returns(true);
        $events->expects('hasListeners')->with(JobProcessed::class)->returns(true);
        $events->expects('hasListeners')->with(JobAttempted::class)->returns(true);
        $events->expects('dispatch')->times(3)
            ->resolves(function (object $event) use (&$attemptsObservedByListener): array {
                if ($event instanceof JobProcessed) {
                    $attemptsObservedByListener = $event->job->attempts();
                }

                return [];
            });

        $worker = new Worker(
            Double::for(Factory::class)->strict(),
            $events,
            Double::for(ExceptionHandler::class)->strict(),
            fn () => false,
        );

        // maxTries=0 takes the normal short-circuit that never reads attempts
        // before the job runs; the processed listener is the first post-hook read.
        $worker->process('connection', $job, new WorkerOptions(maxTries: 0));

        $this->assertSame(3, $attemptsObservedByListener);
        $this->assertTrue($job->isDeleted());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testWorkerExceptionReleasesTheBackendBeforeReturningTheLease(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $container->instance('handler', new FailingPooledJobWorkerHandler);
        $pheanstalk = Double::for(
            PheanstalkManagerInterface::class,
            PheanstalkPublisherInterface::class,
            PheanstalkSubscriberInterface::class,
        )->strict();
        $pheanstalk->expects('statsJob')->times(minimum: 1)->returns($this->stats(1));
        $pheanstalk->expects('release');
        $rawJob = new PheanstalkJob(new JobId(1), json_encode([
            'job' => 'handler',
            'data' => [],
        ], JSON_THROW_ON_ERROR));
        $job = new BeanstalkdJob($container, $pheanstalk, $rawJob, 'connection', 'queue');
        $pool = $this->pool();
        $job->withPoolLease(new Lease($pool, $pool->borrow()));
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(JobProcessing::class)->returns(true);
        $events->expects('hasListeners')->with(JobExceptionOccurred::class)->returns(true);
        $events->expects('hasListeners')->with(JobReleasedAfterException::class)->returns(true);
        $events->expects('hasListeners')->with(JobAttempted::class)->returns(true);
        $events->expects('dispatch')->times(4)->returns([]);
        $worker = new Worker(Double::for(Factory::class)->strict(), $events, Double::for(ExceptionHandler::class)->strict(), fn () => false);

        try {
            $worker->process('connection', $job, new WorkerOptions(maxTries: 0, backoff: 3));
            $this->fail('Expected the handler exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('handler failed', $exception->getMessage());
        }

        $this->assertTrue($job->isReleased());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testWorkerTerminalFailureDeletesBeforeReturningTheLease(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $container->instance('handler', new FailingPooledJobWorkerHandler);
        $pheanstalk = Double::for(
            PheanstalkManagerInterface::class,
            PheanstalkPublisherInterface::class,
            PheanstalkSubscriberInterface::class,
        )->strict();
        $pheanstalk->expects('statsJob')->times(minimum: 1)->returns($this->stats(1));
        $pheanstalk->expects('delete');
        $rawJob = new PheanstalkJob(new JobId(1), json_encode([
            'job' => 'handler',
            'data' => [],
        ], JSON_THROW_ON_ERROR));
        $job = new BeanstalkdJob($container, $pheanstalk, $rawJob, 'connection', 'queue');
        $pool = $this->pool();
        $job->withPoolLease(new Lease($pool, $pool->borrow()));
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(JobProcessing::class)->returns(true);
        $events->expects('hasListeners')->with(JobFailed::class)->returns(true);
        $events->expects('hasListeners')->with(JobExceptionOccurred::class)->returns(true);
        $events->expects('hasListeners')->with(JobAttempted::class)->returns(true);
        $events->expects('dispatch')->times(4)->returns([]);
        $container->instance(Dispatcher::class, $events);
        $worker = new Worker(Double::for(Factory::class)->strict(), $events, Double::for(ExceptionHandler::class)->strict(), fn () => false);

        try {
            $worker->process('connection', $job, new WorkerOptions(maxTries: 1));
            $this->fail('Expected the handler exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('handler failed', $exception->getMessage());
        }

        $this->assertTrue($job->hasFailed());
        $this->assertTrue($job->isDeleted());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    /**
     * Create a tracked object pool.
     */
    private function pool(): CallbackObjectPool
    {
        $pool = new CallbackObjectPool(fn () => new stdClass, PoolOptions::fromArray([]));
        $this->pools[] = $pool;

        return $pool;
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
