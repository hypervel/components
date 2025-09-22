<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Commands;

use Hypervel\Horizon\Supervisor;

class FakeCommand
{
    public $processCount = 0;

    public $supervisor;

    public $options;

    public function process(Supervisor $supervisor, array $options)
    {
        ++$this->processCount;
        $this->supervisor = $supervisor;
        $this->options = $options;
    }
}
