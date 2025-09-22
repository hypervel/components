<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Queue\Contracts\Factory as QueueFactory;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Tests\Horizon\IntegrationTest;
use Hypervel\Horizon\WaitTimeCalculator;
use Hypervel\Queue\Contracts\Queue;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class WaitTimeCalculatorTest extends IntegrationTest
{
    public function testTimeToClearIsCalculatedPerQueue()
    {
        $calculator = $this->with_scenario([
            'test-supervisor' => (object) [
                'processes' => [
                    'redis:test-queue' => 1,
                ],
            ],
            'test-supervisor-2' => (object) [
                'processes' => [
                    'redis:test-queue' => 1,
                ],
            ],
        ], [
            'test-queue' => [
                'size' => 10,
                'runtime' => 1000,
            ],
        ]);

        $this->assertEquals(
            ['redis:test-queue' => 5],
            $calculator->calculate()
        );
    }

    public function testMultipleQueuesAreSupported()
    {
        $calculator = $this->with_scenario([
            'test-supervisor' => (object) [
                'processes' => [
                    'redis:test-queue' => 2,
                ],
            ],
            'test-supervisor-2' => (object) [
                'processes' => [
                    'redis:test-queue-2' => 1,
                ],
            ],
        ], [
            'test-queue' => [
                'size' => 10,
                'runtime' => 1000,
            ],
            'test-queue-2' => [
                'size' => 20,
                'runtime' => 2000,
            ],
        ]);

        $this->assertEquals(
            ['redis:test-queue' => 5, 'redis:test-queue-2' => 40],
            $calculator->calculate()
        );

        // Test easily retrieving the longest wait...
        $this->assertEquals(
            ['redis:test-queue-2' => 40],
            collect($calculator->calculate())->take(1)->all()
        );
    }

    public function testSingleQueueCanBeRetrievedForMultipleQueues()
    {
        $calculator = $this->with_scenario([
            'test-supervisor' => (object) [
                'processes' => [
                    'redis:test-queue' => 2,
                ],
            ],
            'test-supervisor-2' => (object) [
                'processes' => [
                    'redis:test-queue-2' => 1,
                ],
            ],
        ], [
            'test-queue' => [
                'size' => 10,
                'runtime' => 1000,
            ],
            'test-queue-2' => [
                'size' => 20,
                'runtime' => 2000,
            ],
        ]);

        $this->assertEquals(
            ['redis:test-queue-2' => 40],
            $calculator->calculate('redis:test-queue-2')
        );

        $this->assertSame(
            40.0,
            $calculator->calculateFor('redis:test-queue-2')
        );
    }

    public function testTimeToClearCanBeZero()
    {
        $calculator = $this->with_scenario([
            'test-supervisor' => (object) [
                'processes' => [
                    'redis:test-queue' => 1,
                ],
            ],
        ], [
            'test-queue' => [
                'size' => 0,
                'runtime' => 1000,
            ],
        ]);

        $this->assertEquals(
            ['redis:test-queue' => 0],
            $calculator->calculate()
        );
    }

    public function testTotalProcessesCanBeZero()
    {
        $calculator = $this->with_scenario([
            'test-supervisor' => (object) [
                'processes' => [
                    'redis:test-queue' => 0,
                ],
            ],
        ], [
            'test-queue' => [
                'size' => 10,
                'runtime' => 1000,
            ],
        ]);

        $this->assertEquals(
            ['redis:test-queue' => 10],
            $calculator->calculate()
        );
    }

    protected function with_scenario(array $supervisorSettings, array $queues)
    {
        $queue = Mockery::mock(Queue::class);;
        $queueFactory = Mockery::mock(QueueFactory::class);
        $supervisors = Mockery::mock(SupervisorRepository::class);
        $metrics = Mockery::mock(MetricsRepository::class);

        $supervisors->shouldReceive('all')->andReturn($supervisorSettings);
        $queueFactory->shouldReceive('connection')->andReturn($queue);

        foreach ($queues as $name => $queueSettings) {
            $queue->shouldReceive('readyNow')->with($name)->andReturn($queueSettings['size']);
            $metrics->shouldReceive('runtimeForQueue')->with($name)->andReturn($queueSettings['runtime']);
        }

        return new WaitTimeCalculator($queueFactory, $supervisors, $metrics);
    }
}
