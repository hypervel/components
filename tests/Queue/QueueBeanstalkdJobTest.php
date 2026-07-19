<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Closure;
use Exception;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\SimpleObjectPool;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Jobs\BeanstalkdJob;
use Hypervel\Queue\TimeoutExceededException;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\JobStats;
use RuntimeException;
use stdClass;

class QueueBeanstalkdJobTest extends TestCase
{
    public function testFireProperlyCallsTheJobHandler(): void
    {
        $job = $this->getJob();
        $job->getPheanstalkJob()->shouldReceive('getData')->once()->andReturn(json_encode(['job' => 'foo', 'data' => ['data']]));
        $job->getContainer()->shouldReceive('make')->once()->with('foo')->andReturn($handler = m::mock(stdClass::class));
        $handler->shouldReceive('fire')->once()->with($job, ['data']);

        $job->fire();
    }

    public function testFailProperlyCallsTheJobHandler(): void
    {
        $job = $this->getJob();
        $job->getPheanstalkJob()->shouldReceive('getData')->andReturn(json_encode(['job' => 'foo', 'uuid' => 'test-uuid', 'data' => ['data']]));
        $job->getContainer()->shouldReceive('make')->once()->with('foo')->andReturn($handler = m::mock(BeanstalkdJobTestFailedTest::class));
        $job->getPheanstalk()->shouldReceive('delete')->once()->with($job->getPheanstalkJob())->andReturnSelf();
        $handler->shouldReceive('failed')->once()->with(['data'], m::type(Exception::class), 'test-uuid', m::type(BeanstalkdJob::class));
        $job->getContainer()->shouldReceive('make')->once()->with(Dispatcher::class)->andReturn($events = m::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(JobFailed::class))->andReturnNull();

        $job->fail(new Exception);
    }

    public function testFailWithNullFailedDriverDoesNotRollBackDatabaseTransaction(): void
    {
        $job = $this->getJob();
        $job->getPheanstalkJob()->shouldReceive('getData')->andReturn(json_encode(['job' => 'foo', 'uuid' => 'test-uuid', 'data' => ['data']]));
        $job->getContainer()->shouldReceive('make')->once()->with('config')->andReturn(new ConfigRepository([
            'queue' => [
                'failed' => [
                    'driver' => null,
                    'database' => 'sqlite',
                ],
            ],
        ]));
        $job->getContainer()->shouldReceive('bound')->never();
        $job->getContainer()->shouldReceive('make')->never()->with('db');
        $job->getContainer()->shouldReceive('make')->once()->with('foo')->andReturn($handler = m::mock(BeanstalkdJobTestFailedTest::class));
        $job->getPheanstalk()->shouldReceive('delete')->once()->with($job->getPheanstalkJob())->andReturnSelf();
        $handler->shouldReceive('failed')->once()->with(['data'], m::type(TimeoutExceededException::class), 'test-uuid', m::type(BeanstalkdJob::class));
        $job->getContainer()->shouldReceive('make')->once()->with(Dispatcher::class)->andReturn($events = m::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(JobFailed::class))->andReturnNull();

        $job->fail(TimeoutExceededException::forJob($job));
    }

    public function testDeleteRemovesTheJobFromBeanstalkd(): void
    {
        $job = $this->getJob();
        $job->getPheanstalk()->shouldReceive('delete')->once()->with($job->getPheanstalkJob());

        $job->delete();
    }

    public function testReleaseProperlyReleasesJobOntoBeanstalkd(): void
    {
        $job = $this->getJob();
        $job->getPheanstalk()->shouldReceive('release')->once()->with($job->getPheanstalkJob(), Pheanstalk::DEFAULT_PRIORITY, 0);

        $job->release();
    }

    public function testBuryProperlyBuryTheJobFromBeanstalkd(): void
    {
        $job = $this->getJob();
        $job->getPheanstalk()->shouldReceive('bury')->once()->with($job->getPheanstalkJob());

        $job->bury();
    }

    public function testDeleteReleasesPoolLeaseAfterBackendCall(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getPheanstalk()->shouldReceive('statsJob')->once()->andReturn($this->stats(1));
        $job->getPheanstalk()->shouldReceive('delete')->once()->with($job->getPheanstalkJob())
            ->andReturnUsing(function () use ($pool): void {
                $this->assertSame(1, $pool->getBorrowedObjectNumber());
            });

        $job->withPoolLease($lease)->delete();

        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testReleaseReleasesPoolLeaseAfterBackendCall(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getPheanstalk()->shouldReceive('statsJob')->once()->andReturn($this->stats(1));
        $job->getPheanstalk()->shouldReceive('release')->once()
            ->with($job->getPheanstalkJob(), Pheanstalk::DEFAULT_PRIORITY, 5)
            ->andReturnUsing(function () use ($pool): void {
                $this->assertSame(1, $pool->getBorrowedObjectNumber());
            });

        $job->withPoolLease($lease)->release(5);

        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testBuryReleasesPoolLeaseAfterBackendCall(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getPheanstalk()->shouldReceive('statsJob')->once()->andReturn($this->stats(1));
        $job->getPheanstalk()->shouldReceive('bury')->once()->with($job->getPheanstalkJob())
            ->andReturnUsing(function () use ($pool): void {
                $this->assertSame(1, $pool->getBorrowedObjectNumber());
            });

        $job->withPoolLease($lease)->bury();

        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testBackendFailureDiscardsPoolLeaseAndPreservesTheException(): void
    {
        $destroyed = 0;
        [$pool, $lease] = $this->lease(function () use (&$destroyed): void {
            ++$destroyed;
        });
        $job = $this->getJob();
        $expected = new Exception('delete failed');
        $job->getPheanstalk()->shouldReceive('statsJob')->once()->andReturn($this->stats(1));
        $job->getPheanstalk()->shouldReceive('delete')->once()->andThrow($expected);

        try {
            $job->withPoolLease($lease)->delete();
            $this->fail('The backend exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($expected, $exception);
        }

        $this->assertSame(1, $destroyed);
        $this->assertSame(0, $pool->getCurrentObjectNumber());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
    }

    public function testAttemptsRemainAvailableButBackendAccessIsRejectedAfterFinalization(): void
    {
        [$pool, $lease] = $this->lease();
        $job = $this->getJob();
        $job->getPheanstalk()->shouldReceive('statsJob')
            ->twice()
            ->andReturn($this->stats(1), $this->stats(2));
        $job->getPheanstalk()->shouldReceive('delete')->once();

        $job->withPoolLease($lease);
        $this->assertSame(2, $job->attempts());
        $job->delete();

        $this->assertSame(2, $job->attempts());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('backend is no longer available');

        $job->getPheanstalk();
    }

    /**
     * Create a checked-out object under a queue-job lease.
     *
     * @return array{SimpleObjectPool, Lease}
     */
    protected function lease(?Closure $destroyCallback = null): array
    {
        $pool = new SimpleObjectPool(
            fn () => new stdClass,
            PoolOptions::fromArray([]),
            $destroyCallback,
        );

        return [$pool, new Lease($pool, $pool->get())];
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

    protected function getJob(): BeanstalkdJob
    {
        return new BeanstalkdJob(
            m::mock(Container::class),
            m::mock(implode(',', [PheanstalkManagerInterface::class, PheanstalkPublisherInterface::class, PheanstalkSubscriberInterface::class])),
            m::mock(JobIdInterface::class),
            'connection-name',
            'default'
        );
    }
}

class BeanstalkdJobTestFailedTest
{
    public function failed(array $data, Exception $exception, string $uuid, BeanstalkdJob $job): void
    {
    }
}
