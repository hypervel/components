<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Support\Collection;

class JobMetricsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param MetricsRepository $metrics The metrics repository implementation.
     */
    public function __construct(
        public MetricsRepository $metrics
    ) {
        parent::__construct();
    }

    /**
     * Get all of the measured jobs.
     */
    public function index(): array
    {
        return $this->metrics->measuredJobs();
    }

    /**
     * Get metrics for a given job.
     */
    public function show(string $id): Collection
    {
        return collect($this->metrics->snapshotsForJob($id))->map(function ($record) {
            $record->runtime = round($record->runtime / 1000, 3);
            $record->throughput = (int) $record->throughput;

            return $record;
        });
    }
}
