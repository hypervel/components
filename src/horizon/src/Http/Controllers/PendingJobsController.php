<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Http\Request;

class PendingJobsController
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        public JobRepository $jobs
    ) {
    }

    /**
     * Get all of the pending jobs.
     */
    public function index(Request $request): array
    {
        $startingAt = $request->query('starting_at') ?: -1;

        $jobs = $this->jobs->getPending((int) $startingAt)->map(function ($job) {
            $job->payload = json_decode($job->payload);

            return $job;
        })->values();

        return [
            'jobs' => $jobs,
            'total' => $this->jobs->countPending(),
        ];
    }

    /**
     * Decode the given job.
     */
    protected function decode(object $job): object
    {
        $job->payload = json_decode($job->payload);

        return $job;
    }
}
