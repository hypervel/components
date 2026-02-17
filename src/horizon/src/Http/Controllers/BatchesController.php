<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Contracts\Bus\BatchRepository;
use Hypervel\Database\QueryException;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Jobs\RetryFailedJob;
use Hypervel\Http\Request;

class BatchesController
{
    /**
     * Create a new controller instance.
     *
     * @param BatchRepository $batches the job repository implementation
     */
    public function __construct(
        public BatchRepository $batches
    ) {
    }

    /**
     * Get all of the batches.
     */
    public function index(Request $request): array
    {
        try {
            $batches = $this->batches->get(50, $request->query('before_id', null));
        } catch (QueryException $e) {
            $batches = [];
        }

        return [
            'batches' => $batches,
        ];
    }

    /**
     * Get the details of a batch by ID.
     */
    public function show(string $id): array
    {
        $batch = $this->batches->find($id);

        if ($batch) {
            $failedJobs = app(JobRepository::class)
                ->getJobs($batch->failedJobIds);
        }

        return [
            'batch' => $batch,
            'failedJobs' => $failedJobs ?? null,
        ];
    }

    /**
     * Retry the given batch.
     */
    public function retry(string $id): void
    {
        $batch = $this->batches->find($id);

        if ($batch) {
            app(JobRepository::class)
                ->getJobs($batch->failedJobIds)
                ->reject(function ($job) {
                    $payload = json_decode($job->payload);

                    return isset($payload->retry_of);
                })->each(function ($job) {
                    dispatch(new RetryFailedJob($job->id));
                });
        }
    }
}
