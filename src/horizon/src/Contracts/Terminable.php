<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

interface Terminable
{
    /**
     * Terminate the process.
     */
    public function terminate(int $status = 0): void;
}
