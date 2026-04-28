<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Queue\Console\PruneBatchesCommand;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class PruneBatchesCommandTest extends TestCase
{
    public function testAllowPruningAllUnfinishedBatches()
    {
        $repo = m::mock(DatabaseBatchRepository::class);
        $repo->shouldReceive('prune')->once();
        $repo->shouldReceive('pruneUnfinished')->once();

        $this->app->instance(BatchRepository::class, $repo);

        $command = new PruneBatchesCommand;
        $command->setHypervel($this->app);

        $command->run(new ArrayInput(['--unfinished' => 0]), new NullOutput);
    }

    public function testAllowPruningAllCancelledBatches()
    {
        $repo = m::mock(DatabaseBatchRepository::class);
        $repo->shouldReceive('prune')->once();
        $repo->shouldReceive('pruneCancelled')->once();

        $this->app->instance(BatchRepository::class, $repo);

        $command = new PruneBatchesCommand;
        $command->setHypervel($this->app);

        $command->run(new ArrayInput(['--cancelled' => 0]), new NullOutput);

        $repo->shouldHaveReceived('pruneCancelled')->once();
    }
}
