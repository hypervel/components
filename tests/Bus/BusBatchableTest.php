<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchRepository;
use Hypervel\Container\Container;
use Hypervel\Support\Testing\Fakes\BatchFake;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class BusBatchableTest extends TestCase
{
    public function testBatchMayBeRetrieved()
    {
        $class = new class {
            use Batchable;
        };

        $this->assertSame($class, $class->withBatchId('test-batch-id'));
        $this->assertSame('test-batch-id', $class->batchId);

        Container::setInstance($container = new Container);

        $repository = m::mock(BatchRepository::class);
        $batch = m::mock(Batch::class);
        $repository->shouldReceive('find')->once()->with('test-batch-id')->andReturn($batch);
        $container->instance(BatchRepository::class, $repository);

        $this->assertSame($batch, $class->batch());

        Container::setInstance(null);
    }

    public function testWithFakeBatchSetsAndReturnsFake()
    {
        $job = new class {
            use Batchable;
        };

        [$self, $batch] = $job->withFakeBatch('test-batch-id', 'test-batch-name', 3, 3, 0, [], []);

        $this->assertSame($job, $self);
        $this->assertInstanceOf(BatchFake::class, $batch);
        $this->assertSame($batch, $job->batch());
        $this->assertSame('test-batch-id', $job->batch()->id);
        $this->assertSame('test-batch-name', $job->batch()->name);
        $this->assertSame(3, $job->batch()->totalJobs);
    }

    public function testBatchingReflectsCancelledState()
    {
        $job = new class {
            use Batchable;
        };

        $job->withFakeBatch('test-batch-id', 'test-batch-name');

        // Initially not cancelled
        $this->assertTrue($job->batching());

        // Cancel the batch and ensure batching() returns false
        $job->batch()->cancel();
        $this->assertFalse($job->batching());
    }
}
