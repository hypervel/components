<?php

declare(strict_types=1);

namespace Hypervel\Contracts\ObjectPool;

interface Recycler
{
    /**
     * Start periodic pool maintenance.
     *
     * Boot-only. Starting during a request schedules worker-wide maintenance
     * that affects every subsequently registered pool.
     */
    public function start(): void;

    /**
     * Stop automatic maintenance of objects in managed pools.
     *
     * Boot or tests only. Stopping disables automatic maintenance for every
     * pool in the worker.
     */
    public function stop(): void;
}
