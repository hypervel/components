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
use JMac\Testing\Double;

class RedisWorkloadRepositoryTest extends UnitTestCase
{
    public function testSplitQueueWorkloadsKeepNumericQueueNames(): void
    {
        $queue = Double::for(RedisQueue::class)->strict();
        $queue->allows('readyNow')->with('1')->returns(2);
        $queue->allows('readyNow')->with('2')->returns(3);

        $queueFactory = Double::for(QueueFactory::class)->strict();
        $queueFactory->allows('connection')->with('redis')->returns($queue);

        $supervisors = Double::for(SupervisorRepository::class)->strict();
        $supervisors->allows('all')->returns([(object) ['processes' => ['redis:1,2' => 1]]]);

        $metrics = Double::for(MetricsRepository::class)->strict();
        $metrics->allows('runtimeForQueue')->with('1')->returns(1000.0);
        $metrics->allows('runtimeForQueue')->with('2')->returns(2000.0);

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
