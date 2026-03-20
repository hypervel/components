<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Testing\Concerns\RunsInParallel;
use ParaTest\RunnerInterface;

class ParallelRunner implements RunnerInterface
{
    use RunsInParallel;

    /**
     * Run the test suite.
     */
    public function run(): int
    {
        return $this->execute();
    }
}
