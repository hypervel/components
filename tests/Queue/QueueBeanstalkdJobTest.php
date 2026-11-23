<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Closure;
use Exception;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Events\Dispatcher as EventsDispatcher;
use Hypervel\ObjectPool\CallbackObjectPool;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Jobs\BeanstalkdJob;
use Hypervel\Queue\TimeoutExceededException;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job as PheanstalkJob;
use Pheanstalk\Values\JobId;
use Pheanstalk\Values\JobStats;
use RuntimeException;
use stdClass;

class QueueBeanstalkdJobTest extends TestCase
{
    use VerifiesDoubles;

    /** @var list<CallbackObjectPool> */
    private array $pools = [];

    /**
     * Close the pools created by the test.
     */
    protected function tearDownInCoroutine(): void
    {
        foreach ($this->pools as $pool) {
            $pool->close();
        }
    }

    public function testFireProperlyCallsTheJobHandler(): void
    {
        $job = $this->getJob(json_encode(['job' => 'foo', 'data' => ['data']]));
        $handler = new BeanstalkdJobTestHandler;
        $job->getContainer()->instance('foo', $handler);

        $job->fire();

        $this->assertSame([[$job, ['data']]], $handler->fired);
    }

    public function testFailProperlyCallsTheJobHandler(): void
    {
        $job = $this->getJob(json_encode(['job' => 'foo', 'uuid' => 'test-uuid', 'data' => ['data']]));
        $handler = new BeanstalkdJobTestFailedTest;
        $job->getContainer()->instance('foo', $handler);
        $job->getPheanstalk()->expects('delete')->with(Argument::same($job->getPheanstalkJob()));
        $events = new EventsDispatcher;
        $failed = [];
        $events->listen(JobFailed::class, function (JobFailed $event) use (&$failed): void {
            $failed[] = $event;
        });
        $job->getContainer()->instance(Dispatcher::class, $events);

        $job->fail($exception = new Exception);

        $this->assertCount(1, $handler->failed);
        $this->assertSame(['data'], $handler->failed[0][0]);
        $this->assertSame($exception, $handler->failed[0][1]);
        $this->assertSame('test-uuid', $handler->failed[0][2]);
        $this->assertSame($job, $handler->failed[0][3]);

        $this->assertCount(1, $failed);
        $this->assertSame($job, $failed[0]->job);
        $this->assertSame($exception, $failed[0]->exception);
    }

    public function testFailWithNullFailedDriverDoesNotRollBackDatabaseTransaction(): void
    {
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $job = $this->getJob(
            json_encode(['job' => 'foo', 'uuid' => 'test-uuid', 'data' => ['data']]),
            $container->instance(),
        );
        $container->expects('make')->with('config')->returns(new ConfigRepository([
            'queue' => [
                'failed' => [
                    'driver' => null,
                    'database' => 'sqlite',
                ],
            ],
        ]));
        $container->expects('bound')->never();
        $container->expects('make')->never()->with('db');
        $container->expects('make')->with('foo')->returns($handler = Double::for(BeanstalkdJobTestFailedTest::class)->strict());
        $job->getPheanstalk()->expects('delete')->with(Argument::same($job->getPheanstalkJob()));
        $handler->expects('failed')->with(['data'], Argument::type(TimeoutExceededException::class), 'test-uuid', Argument::type(BeanstalkdJob::class));
        $container->expects('make')->with(Dispatcher::class)->returns($events = Double::for(Dispatcher::class)->strict());
        $events->expects('hasListeners')->with(JobFailed::class)->returns(true);
        $events->expects('dispatch')->with(Argument::type(JobFailed::class))->returns(null);

        $job->fail(TimeoutExceededException::forJob($job));
    }

    public function testDeleteRemovesTheJobFromBeanstalkd(): void
    {
        $job = $this->getJob();
        $job->getPheanstalk()->expects('delete')->with(Argument::same($job->getPheanstalkJob()));

        $job->delete();
    }

    public function testReleaseProperlyReleasesJobOntoBeanstalkd(): void
    {
        $job = $this->getJob();
        $job->getPheanstalk()->expects('release')->with(Argument::same($job->getPheanstalkJob()), Pheanstalk::DEFAULT_PRIORITY, 0);

        $job->release();
    }

    public function testBuryProperlyBuryTheJobFromBeanstalkd(): void
    {
        $job = $this->getJob();
        $job->getPheanstalk()->expects('bury')->with(Argument::same($job->getPheanstalkJob()));

        $job->bury();
    }

    public function testDeleteReleasesPoolLeaseAfterBackendCall(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getPheanstalk()->expects('statsJob')->returns($this->stats(1));
        $job->getPheanstalk()->expects('delete')->with(Argument::same($job->getPheanstalkJob()))
            ->resolves(function () use ($pool): void {
                $this->assertSame(1, $pool->getBorrowedCount());
            });

        $job->withPoolLease($lease)->delete();

        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testReleaseReleasesPoolLeaseAfterBackendCall(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getPheanstalk()->expects('statsJob')->returns($this->stats(1));
        $job->getPheanstalk()->expects('release')
            ->with(Argument::same($job->getPheanstalkJob()), Pheanstalk::DEFAULT_PRIORITY, 5)
            ->resolves(function () use ($pool): void {
                $this->assertSame(1, $pool->getBorrowedCount());
            });

        $job->withPoolLease($lease)->release(5);

        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testBuryReleasesPoolLeaseAfterBackendCall(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getPheanstalk()->expects('statsJob')->returns($this->stats(1));
        $job->getPheanstalk()->expects('bury')->with(Argument::same($job->getPheanstalkJob()))
            ->resolves(function () use ($pool): void {
                $this->assertSame(1, $pool->getBorrowedCount());
            });

        $job->withPoolLease($lease)->bury();

        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testBackendFailureDiscardsPoolLeaseAndPreservesTheException(): void
    {
        $destroyed = 0;
        [$pool, $lease] = $this->lease(function () use (&$destroyed): void {
            ++$destroyed;
        });
        $job = $this->getJob();
        $expected = new Exception('delete failed');
        $job->getPheanstalk()->expects('statsJob')->returns($this->stats(1));
        $job->getPheanstalk()->expects('delete')->throws($expected);

        try {
            $job->withPoolLease($lease)->delete();
            $this->fail('The backend exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($expected, $exception);
        }

        $this->assertSame(1, $destroyed);
        $this->assertSame(0, $pool->getManagedCount());
        $this->assertSame(0, $pool->getBorrowedCount());
    }

    public function testAttemptsRemainAvailableButBackendAccessIsRejectedAfterFinalization(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getPheanstalk()->expects('statsJob')
            ->times(2)
            ->returns($this->stats(1), $this->stats(2));
        $job->getPheanstalk()->expects('delete');

        $job->withPoolLease($lease);
        $this->assertSame(2, $job->attempts());
        $job->delete();

        $this->assertSame(2, $job->attempts());
        $this->assertSame(0, $pool->getBorrowedCount());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('backend is no longer available');

        $job->getPheanstalk();
    }

    /**
     * Create a checked-out object under a queue-job lease.
     *
     * @return array{CallbackObjectPool, Lease}
     */
    protected function lease(?Closure $destroyCallback = null): array
    {
        $pool = new CallbackObjectPool(
            fn (): stdClass => new stdClass,
            PoolOptions::fromArray([]),
            $destroyCallback,
        );
        $this->pools[] = $pool;

        return [$pool, new Lease($pool, $pool->borrow())];
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

    /**
     * Create a Beanstalkd job with mocked dependencies.
     */
    protected function getJob(string $data = '', ?ContainerContract $container = null): BeanstalkdJob
    {
        return new BeanstalkdJob(
            $container ?? new Container,
            Double::for(PheanstalkManagerInterface::class, PheanstalkPublisherInterface::class, PheanstalkSubscriberInterface::class)->strict(),
            new PheanstalkJob(new JobId(1), $data),
            'connection-name',
            'default'
        );
    }
}

class BeanstalkdJobTestHandler
{
    public array $fired = [];

    /**
     * Record the invoked job handler.
     */
    public function fire(BeanstalkdJob $job, array $data): void
    {
        $this->fired[] = [$job, $data];
    }
}

class BeanstalkdJobTestFailedTest
{
    public array $failed = [];

    /**
     * Record the failed job.
     */
    public function failed(array $data, ?Exception $exception = null, ?string $uuid = null, ?BeanstalkdJob $job = null): void
    {
        $this->failed[] = [$data, $exception, $uuid, $job];
    }
}
