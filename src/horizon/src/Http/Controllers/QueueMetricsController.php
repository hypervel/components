<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Support\Collection;

class QueueMetricsController
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        public MetricsRepository $metrics
    ) {
    }

    /**
     * Get all of the measured queues.
     */
    public function index(): array
    {
        return $this->metrics->measuredQueues();
    }

    /**
     * Get metrics for a given queue.
     */
    public function show(string $id): Collection
    {
        return collect($this->metrics->snapshotsForQueue($id))->map(function ($record) {
            $record->runtime = round($record->runtime / 1000, 3);
            $record->throughput = (int) $record->throughput;

            return $record;
        });
    }
}
