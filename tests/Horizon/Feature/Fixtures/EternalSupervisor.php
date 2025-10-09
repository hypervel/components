<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Fixtures;

class EternalSupervisor
{
    public $name = 'eternal';

    public function terminate()
    {
    }

    public function isRunning()
    {
        return true;
    }
}
