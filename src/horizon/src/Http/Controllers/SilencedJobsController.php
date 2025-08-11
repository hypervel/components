<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Http\Request;

class SilencedJobsController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param JobRepository $jobs The job repository implementation.
     */
    public function __construct(
        public JobRepository $jobs
    ) {
        parent::__construct();
    }

    /**
     * Get all of the silenced jobs.
     */
    public function index(Request $request): array
    {
        $jobs = $this->jobs->getSilenced($request->query('starting_at', -1))->map(function ($job) {
            $job->payload = json_decode($job->payload);

            return $job;
        })->values();

        return [
            'jobs' => $jobs,
            'total' => $this->jobs->countSilenced(),
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
