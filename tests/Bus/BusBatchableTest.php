<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Context\ApplicationContext;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Contracts\Bus\BatchRepository;
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

        $container = new Container(
            new DefinitionSource([
                BatchRepository::class => fn () => $repository,
            ])
        );
        ApplicationContext::setContainer($container);

        $this->assertSame($batch, $class->batch());
    }
}
