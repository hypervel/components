<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Queue\Contracts\Factory as QueueFactory;
use Hypervel\Horizon\AutoScaler;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Horizon\SystemProcessCounter;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class AutoScalerTest extends IntegrationTestCase
{
    public function testScalerAttemptsToGetCloserToProperBalanceOnEachIteration()
    {
        [$scaler, $supervisor] = $this->with_scaling_scenario(20, [
            'first' => ['current' => 10, 'size' => 20, 'runtime' => 10],
            'second' => ['current' => 10, 'size' => 10, 'runtime' => 10],
        ]);

        $scaler->scale($supervisor);

        $this->assertSame(11, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(9, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);

        $this->assertSame(12, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(8, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);

        $this->assertSame(13, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(7, $supervisor->processPools['second']->totalProcessCount());

        // Assert scaler stays at target values...
        $scaler->scale($supervisor);

        $this->assertSame(13, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(7, $supervisor->processPools['second']->totalProcessCount());
    }

    public function testBalanceStaysEvenWhenQueueIsEmpty()
    {
        [$scaler, $supervisor] = $this->with_scaling_scenario(10, [
            'first' => ['current' => 5, 'size' => 0, 'runtime' => 0],
            'second' => ['current' => 5, 'size' => 0, 'runtime' => 0],
        ]);

        $scaler->scale($supervisor);

        $this->assertSame(4, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(4, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);

        $this->assertSame(3, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(3, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);

        $this->assertSame(2, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(2, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);

        $this->assertSame(1, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(1, $supervisor->processPools['second']->totalProcessCount());
    }

    public function testBalancerAssignsMoreProcessesOnBusyQueue()
    {
        [$scaler, $supervisor] = $this->with_scaling_scenario(10, [
            'first' => ['current' => 1, 'size' => 50, 'runtime' => 50],
            'second' => ['current' => 1, 'size' => 0, 'runtime' => 0],
        ]);

        $scaler->scale($supervisor);
        $scaler->scale($supervisor);

        $this->assertSame(3, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(1, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);
        $scaler->scale($supervisor);

        $this->assertSame(5, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(1, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);
        $scaler->scale($supervisor);

        $this->assertSame(7, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(1, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);
        $scaler->scale($supervisor);
        $scaler->scale($supervisor);

        $this->assertSame(9, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(1, $supervisor->processPools['second']->totalProcessCount());
    }

    public function testBalancingASingleQueueAssignsItTheMinWorkersWithEmptyQueue()
    {
        [$scaler, $supervisor] = $this->with_scaling_scenario(5, [
            'first' => ['current' => 2, 'size' => 0, 'runtime' => 0],
        ]);

        $scaler->scale($supervisor);
        $this->assertSame(1, $supervisor->processPools['first']->totalProcessCount());
    }

    public function testScalerWillNotScalePastMaxProcessThresholdUnderHighLoad()
    {
        [$scaler, $supervisor] = $this->with_scaling_scenario(20, [
            'first' => ['current' => 10, 'size' => 100, 'runtime' => 50],
            'second' => ['current' => 10, 'size' => 100, 'runtime' => 50],
        ]);

        $scaler->scale($supervisor);

        $this->assertSame(10, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(10, $supervisor->processPools['second']->totalProcessCount());
    }

    public function testScalerWillNotScaleBelowMinimumWorkerThreshold()
    {
        $external = Mockery::mock(SystemProcessCounter::class);
        $external->shouldReceive('get')->with('name')->andReturn(5);
        $this->app->instance(SystemProcessCounter::class, $external);

        [$scaler, $supervisor] = $this->with_scaling_scenario(5, [
            'first' => ['current' => 3, 'size' => 1000, 'runtime' => 50],
            'second' => ['current' => 2, 'size' => 1, 'runtime' => 1],
        ], ['minProcesses' => 2]);

        $scaler->scale($supervisor);

        $this->assertSame(3, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(2, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);

        $this->assertSame(3, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(2, $supervisor->processPools['second']->totalProcessCount());
    }

    /**
     * @param mixed $maxProcesses
     * @return array{0: AutoScaler, 1: Supervisor}
     */
    protected function with_scaling_scenario($maxProcesses, array $pools, array $extraOptions = [])
    {
        // Mock dependencies...
        $queueFactory = Mockery::mock(QueueFactory::class);
        $metrics = Mockery::mock(MetricsRepository::class);

        // Create scaler...
        $scaler = new AutoScaler($queueFactory, $metrics);

        // Create Supervisor...
        $options = new SupervisorOptions('name', 'redis', 'default');
        $options->maxProcesses = $maxProcesses;
        $options->balance = 'auto';
        foreach ($extraOptions as $key => $value) {
            $options->{$key} = $value;
        }
        $supervisor = new Supervisor($options);

        // Create process pools...
        $supervisor->processPools = collect($pools)->mapWithKeys(function ($pool, $name) {
            return [$name => new Fakes\FakePool($name, $pool['current'])];
        });


        // Set stats per pool...
        $queue = Mockery::mock(RedisQueue::class);
        collect($pools)->each(function ($pool, $name) use ($queue, $metrics) {
            $queue->shouldReceive('readyNow')->with($name)->andReturn($pool['size']);
            $metrics->shouldReceive('runtimeForQueue')->with($name)->andReturn($pool['runtime']);
        });
        $queueFactory->shouldReceive('connection')->with('redis')->andReturn($queue);

        return [$scaler, $supervisor];
    }

    public function testScalerConsidersMaxShiftAndAttemptsToGetCloserToProperBalanceOnEachIteration()
    {
        [$scaler, $supervisor] = $this->with_scaling_scenario(150, [
            'first' => ['current' => 75, 'size' => 600, 'runtime' => 75],
            'second' => ['current' => 75, 'size' => 300, 'runtime' => 75],
        ]);

        $supervisor->options->balanceMaxShift = 10;

        $scaler->scale($supervisor);

        $this->assertEquals(85, $supervisor->processPools['first']->totalProcessCount());
        $this->assertEquals(65, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);

        $this->assertEquals(95, $supervisor->processPools['first']->totalProcessCount());
        $this->assertEquals(55, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);

        $this->assertEquals(100, $supervisor->processPools['first']->totalProcessCount());
        $this->assertEquals(50, $supervisor->processPools['second']->totalProcessCount());

        // Assert scaler stays at target values...
        $scaler->scale($supervisor);

        $this->assertEquals(100, $supervisor->processPools['first']->totalProcessCount());
        $this->assertEquals(50, $supervisor->processPools['second']->totalProcessCount());

        // Assert scaler still stays at target values...
        $scaler->scale($supervisor);

        $this->assertEquals(100, $supervisor->processPools['first']->totalProcessCount());
        $this->assertEquals(50, $supervisor->processPools['second']->totalProcessCount());
    }

    public function testScalerDoesNotPermitGoingToZeroProcessesDespiteExceedingMaxProcesses()
    {
        $external = Mockery::mock(SystemProcessCounter::class);
        $external->shouldReceive('get')->with('name')->andReturn(5);
        $this->app->instance(SystemProcessCounter::class, $external);

        [$scaler, $supervisor] = $this->with_scaling_scenario(15, [
            'first' => ['current' => 16, 'size' => 1, 'runtime' => 1],
            'second' => ['current' => 1, 'size' => 1, 'runtime' => 1],
        ], ['minProcesses' => 1]);

        $scaler->scale($supervisor);

        $this->assertSame(15, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(1, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);

        $this->assertSame(14, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(1, $supervisor->processPools['second']->totalProcessCount());
    }

    public function testScalerAssignsMoreProcessesToQueueWithMoreJobsWhenUsingSizeStrategy()
    {
        [$scaler, $supervisor] = $this->with_scaling_scenario(100, [
            'first' => ['current' => 50, 'size' => 1000, 'runtime' => 10],
            'second' => ['current' => 50, 'size' => 500, 'runtime' => 1000],
        ], ['autoScalingStrategy' => 'size']);

        $scaler->scale($supervisor);

        $this->assertSame(51, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(49, $supervisor->processPools['second']->totalProcessCount());

        $scaler->scale($supervisor);

        $this->assertSame(52, $supervisor->processPools['first']->totalProcessCount());
        $this->assertSame(48, $supervisor->processPools['second']->totalProcessCount());
    }

    public function testScalerWorksWithASingleProcessPool()
    {
        [$scaler, $supervisor] = $this->with_scaling_scenario(10, [
            'default' => ['current' => 10, 'size' => 1, 'runtime' => 0],
        ], ['balance' => false]);

        $scaler->scale($supervisor);

        $this->assertSame(9, $supervisor->processPools['default']->totalProcessCount());

        [$scaler, $supervisor] = $this->with_scaling_scenario(10, [
            'default' => ['current' => 10, 'size' => 5, 'runtime' => 1000],
        ], ['balance' => false]);

        $scaler->scale($supervisor);

        $this->assertSame(9, $supervisor->processPools['default']->totalProcessCount());

        [$scaler, $supervisor] = $this->with_scaling_scenario(10, [
            'default' => ['current' => 5, 'size' => 11, 'runtime' => 1000],
        ], ['balance' => false]);

        $scaler->scale($supervisor);

        $this->assertSame(6, $supervisor->processPools['default']->totalProcessCount());
    }
}
