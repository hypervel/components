<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

interface Restartable
{
    /**
     * Restart the process.
     */
    public function restart(): void;
}
