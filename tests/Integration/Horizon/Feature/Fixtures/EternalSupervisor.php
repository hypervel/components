<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Fixtures;

use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Horizon\SupervisorProcess;
use Symfony\Component\Process\Process;

class EternalSupervisor extends SupervisorProcess
{
    public bool $killed = false;

    public function __construct()
    {
        parent::__construct(
            new SupervisorOptions('eternal', 'redis'),
            new Process(['true']),
        );
    }

    public function terminate(): void
    {
    }

    public function isRunning(): bool
    {
        return true;
    }

    public function kill(): void
    {
        $this->killed = true;
    }
}
