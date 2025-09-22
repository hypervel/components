<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fakes;

use Hypervel\Horizon\MasterSupervisor;

class MasterSupervisorWithFakeExit extends MasterSupervisor
{
    public $exited = false;

    /**
     * End the current PHP process.
     */
    protected function exitProcess(int $status = 0): void
    {
        $this->exited = true;
    }
}
