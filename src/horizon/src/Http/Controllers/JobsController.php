<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Horizon\Contracts\JobRepository;

class JobsController
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        public JobRepository $jobs
    ) {
    }

    /**
     * Get the details of a recent job by ID.
     */
    public function show(string $id): array
    {
        return (array) $this->jobs->getJobs([$id])->map(function ($job) {
            return $this->decode($job);
        })->first();
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
