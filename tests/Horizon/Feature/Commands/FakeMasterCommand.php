<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Commands;

use Hypervel\Horizon\MasterSupervisor;

class FakeMasterCommand
{
    public $processCount = 0;

    public $master;

    public $options;

    public function process(MasterSupervisor $master, array $options)
    {
        ++$this->processCount;
        $this->master = $master;
        $this->options = $options;
    }
}
