<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Contracts;

use Hypervel\Support\Collection;

interface WorkloadRepository
{
    /**
     * Get the current workload of each queue.
     *
     * @return array<int, array{
     *   "name": string,
     *   "length": int,
     *   "wait": float,
     *   "processes": int,
     *   "split_queues": null|Collection<array-key, array{"name": string, "wait": float, "length": int}>
     * }>
     */
    public function get(): array;
}
