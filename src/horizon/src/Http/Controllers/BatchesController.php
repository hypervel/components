<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Bus\BatchRepository;
use Hypervel\Database\QueryException;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Jobs\RetryFailedJob;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\DB;

class BatchesController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param BatchRepository $batches the job repository implementation
     */
    public function __construct(
        public BatchRepository $batches
    ) {
        parent::__construct();
    }

    /**
     * Get all of the batches.
     */
    public function index(Request $request): array
    {
        try {
            $batches = $request->query('query')
                ? $this->searchBatches($request)
                : $this->batches->get(50, $request->query('before_id'));
        } catch (QueryException $e) {
            $batches = [];
        }

        return [
            'batches' => $batches,
        ];
    }

    /**
     * Search the batches by name or ID.
     */
    private function searchBatches(Request $request): array
    {
        $pattern = '%' . addcslashes($request->query('query'), '\%_') . '%';

        return DB::connection(config('queue.batching.database'))
            ->table(config('queue.batching.table', 'job_batches'))
            ->where(function ($q) use ($pattern) {
                $q->whereRaw("lower(name) like lower(?) escape '\\'", [$pattern])
                    ->orWhereRaw("lower(id) like lower(?) escape '\\'", [$pattern]);
            })
            ->orderByDesc('id')
            ->limit(50)
            ->when($request->query('before_id'), fn ($q, $beforeId) => $q->where('id', '<', $beforeId))
            ->pluck('id')
            ->map(fn ($id) => $this->batches->find($id))
            ->filter()
            ->values()
            ->all();
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
