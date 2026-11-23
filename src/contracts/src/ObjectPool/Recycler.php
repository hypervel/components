<?php

declare(strict_types=1);

namespace Hypervel\Contracts\ObjectPool;

use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\Attributes\BootOrTestsOnly;

interface Recycler
{
    /**
     * Start periodic pool maintenance.
     *
     * Starting during a request schedules worker-wide maintenance that
     * affects every subsequently registered pool.
     */
    #[BootOnly]
    public function start(): void;

    /**
     * Stop automatic maintenance of objects in managed pools.
     *
     * Stopping disables automatic maintenance for every pool in the worker.
     */
    #[BootOrTestsOnly]
    public function stop(): void;
}
