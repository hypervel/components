<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Symfony\Component\Process\Process;

class BackgroundProcess extends Process
{
    /**
     * Destruct the object.
     */
    public function __destruct()
    {
    }
}
