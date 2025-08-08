<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

interface Pausable
{
    /**
     * Pause the process.
     *
     * @return void
     */
    public function pause();

    /**
     * Instruct the process to continue working.
     *
     * @return void
     */
    public function continue();
}
