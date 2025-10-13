<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

interface Pausable
{
    /**
     * Pause the process.
     */
    public function pause(): void;

    /**
     * Instruct the process to continue working.
     */
    public function continue(): void;
}
