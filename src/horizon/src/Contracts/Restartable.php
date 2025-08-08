<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

interface Restartable
{
    /**
     * Restart the process.
     *
     * @return void
     */
    public function restart();
}
