<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchRepository;
use Hypervel\Container\Container;
use Mockery as m;
use PHPUnit\Framework\TestCase;

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

        $batch = m::mock(Batch::class);
        $repository = m::mock(BatchRepository::class);
        $repository->shouldReceive('find')->once()->with('test-batch-id')->andReturn($batch);

        $container = new Container();
        $container->instance(BatchRepository::class, $repository);
        Container::setInstance($container);

        $this->assertSame($batch, $class->batch());
    }
}
