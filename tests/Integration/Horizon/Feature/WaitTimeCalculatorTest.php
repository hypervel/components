<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Horizon\WaitTimeCalculator;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class WaitTimeCalculatorTest extends IntegrationTestCase
{
    use VerifiesDoubles;

    public function testTimeToClearIsCalculatedPerQueue(): void
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

    public function testMultipleQueuesAreSupported(): void
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

    public function testSingleQueueCanBeRetrievedForMultipleQueues(): void
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

    public function testQueueNameUsesTheLongestWaitOfThePoolsProcessingIt(): void
    {
        $calculator = $this->with_scenario([
            'test-supervisor' => (object) [
                'processes' => [
                    'redis:high,default' => 1,
                ],
            ],
            'test-supervisor-2' => (object) [
                'processes' => [
                    'secondary:default' => 2,
                    'redis:other' => 1,
                ],
            ],
        ], [
            'high' => [
                'size' => 10,
                'runtime' => 1000,
            ],
            'default' => [
                'size' => 20,
                'runtime' => 1000,
            ],
            'other' => [
                'size' => 100,
                'runtime' => 1000,
            ],
        ]);

        $this->assertSame(30.0, $calculator->calculateForQueueName('default'));
        $this->assertSame(30.0, $calculator->calculateForQueueName('high'));
        $this->assertSame(100.0, $calculator->calculateForQueueName('other'));
        $this->assertSame(0.0, $calculator->calculateForQueueName('missing'));
    }

    public function testQueueFilterDistinguishesZeroAndEmptyString(): void
    {
        $calculator = $this->with_scenario([
            'test-supervisor' => (object) [
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

        $this->assertSame([], $calculator->calculate('0'));
        $this->assertSame(['redis:test-queue' => 10.0], $calculator->calculate(''));
    }

    public function testTimeToClearCanBeZero(): void
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

    public function testTotalProcessesCanBeZero(): void
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

    /**
     * Create a calculator for the given supervisors and queues.
     */
    protected function with_scenario(array $supervisorSettings, array $queues): WaitTimeCalculator
    {
        $queue = Double::for(RedisQueue::class)->strict();
        $queueFactory = Double::for(QueueFactory::class)->strict();
        $supervisors = Double::for(SupervisorRepository::class)->strict();
        $metrics = Double::for(MetricsRepository::class)->strict();

        $supervisors->allows('all')->returns($supervisorSettings);
        $queueFactory->allows('connection')->returns($queue);

        foreach ($queues as $name => $queueSettings) {
            $queue->allows('readyNow')->with($name)->returns($queueSettings['size']);
            $metrics->allows('runtimeForQueue')->with($name)->returns($queueSettings['runtime']);
        }

        return new WaitTimeCalculator($queueFactory, $supervisors, $metrics);
    }
}
