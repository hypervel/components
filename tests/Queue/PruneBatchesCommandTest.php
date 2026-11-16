<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Queue\Console\PruneBatchesCommand;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class PruneBatchesCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testAllowPruningAllUnfinishedBatches(): void
    {
        $repo = Double::for(DatabaseBatchRepository::class);
        $repo->expects('prune');

        $this->app->instance(BatchRepository::class, $repo);

        $command = new PruneBatchesCommand;
        $command->setHypervel($this->app);

        $command->run(new ArrayInput(['--unfinished' => 0]), new NullOutput);

        $repo->received('pruneUnfinished')->times(1);
    }

    public function testAllowPruningAllCancelledBatches(): void
    {
        $repo = Double::for(DatabaseBatchRepository::class);
        $repo->expects('prune');

        $this->app->instance(BatchRepository::class, $repo);

        $command = new PruneBatchesCommand;
        $command->setHypervel($this->app);

        $command->run(new ArrayInput(['--cancelled' => 0]), new NullOutput);

        $repo->received('pruneCancelled')->times(1);
    }
}
