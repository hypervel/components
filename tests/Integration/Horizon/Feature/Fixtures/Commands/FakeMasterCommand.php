<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Commands;

use Hypervel\Horizon\MasterSupervisor;

class FakeMasterCommand
{
    public int $processCount = 0;

    public ?MasterSupervisor $master = null;

    public ?array $options = null;

    /**
     * Record the master supervisor and command options.
     */
    public function process(MasterSupervisor $master, array $options): void
    {
        ++$this->processCount;
        $this->master = $master;
        $this->options = $options;
    }
}
