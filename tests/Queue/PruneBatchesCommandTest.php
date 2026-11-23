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
        $now = $this->freezeSecond();
        $repo = Double::for(DatabaseBatchRepository::class)->strict();
        $repo->expects('prune');
        $repo->expects('pruneUnfinished')->with($now);

        $this->app->instance(BatchRepository::class, $repo);

        $command = new PruneBatchesCommand;
        $command->setHypervel($this->app);

        $command->run(new ArrayInput(['--unfinished' => 0]), new NullOutput);
    }

    public function testAllowPruningAllCancelledBatches(): void
    {
        $now = $this->freezeSecond();
        $repo = Double::for(DatabaseBatchRepository::class)->strict();
        $repo->expects('prune');
        $repo->expects('pruneCancelled')->with($now);

        $this->app->instance(BatchRepository::class, $repo);

        $command = new PruneBatchesCommand;
        $command->setHypervel($this->app);

        $command->run(new ArrayInput(['--cancelled' => 0]), new NullOutput);
    }
}
