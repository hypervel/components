<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Horizon\Repositories\RedisWorkloadRepository;
use Hypervel\Horizon\WaitTimeCalculator;
use Hypervel\Support\Collection;
use Hypervel\Tests\Horizon\UnitTestCase;
use Mockery as m;

class RedisWorkloadRepositoryTest extends UnitTestCase
{
    public function testSplitQueueWorkloadsKeepNumericQueueNames(): void
    {
        $queue = m::mock(RedisQueue::class);
        $queue->shouldReceive('readyNow')->with('1')->andReturn(2);
        $queue->shouldReceive('readyNow')->with('2')->andReturn(3);

        $queueFactory = m::mock(QueueFactory::class);
        $queueFactory->shouldReceive('connection')->with('redis')->andReturn($queue);

        $supervisors = m::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([(object) ['processes' => ['redis:1,2' => 1]]]);

        $metrics = m::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->with('1')->andReturn(1000.0);
        $metrics->shouldReceive('runtimeForQueue')->with('2')->andReturn(2000.0);

        $repository = new RedisWorkloadRepository(
            $queueFactory,
            new WaitTimeCalculator($queueFactory, $supervisors, $metrics),
            $supervisors,
        );

        [$workload] = $repository->get();

        $this->assertSame('1,2', $workload['name']);
        $this->assertSame(5, $workload['length']);
        $this->assertSame(8.0, $workload['wait']);
        $this->assertSame(1, $workload['processes']);
        $this->assertInstanceOf(Collection::class, $workload['split_queues']);
        $this->assertSame([
            1 => ['name' => '1', 'length' => 2, 'wait' => 2.0],
            2 => ['name' => '2', 'length' => 3, 'wait' => 8.0],
        ], $workload['split_queues']->all());
    }
}
