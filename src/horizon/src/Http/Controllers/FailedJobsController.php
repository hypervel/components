<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;

class FailedJobsController
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        public JobRepository $jobs,
        public TagRepository $tags
    ) {
    }

    /**
     * Get all of the failed jobs.
     */
    public function index(Request $request): array
    {
        $jobs = ! $request->query('tag')
                ? $this->paginate($request)
                : $this->paginateByTag($request, $request->query('tag'));

        $total = $request->query('tag')
                ? $this->tags->count('failed:' . $request->query('tag'))
                : $this->jobs->countFailed();

        return [
            'jobs' => $jobs,
            'total' => $total,
        ];
    }

    /**
     * Paginate the failed jobs for the request.
     */
    protected function paginate(Request $request)
    {
        $startingAt = $request->query('starting_at') ?: -1;

        return $this->jobs->getFailed((int) $startingAt)->map(function ($job) {
            return $this->decode($job);
        });
    }

    /**
     * Paginate the failed jobs for the request and tag.
     */
    protected function paginateByTag(Request $request, string $tag): Collection
    {
        $jobIds = $this->tags->paginate(
            'failed:' . $tag,
            ($request->query('starting_at') ?: -1) + 1,
            50
        );

        $startingAt = (int) $request->query('starting_at', 0);

        return $this->jobs->getJobs($jobIds, $startingAt)->map(function ($job) {
            return $this->decode($job);
        });
    }

    /**
     * Get a failed job instance.
     */
    public function show(string $id): mixed
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

        $job->exception = mb_convert_encoding($job->exception, 'UTF-8');

        $job->context = json_decode($job->context ?? '');

        $job->retried_by = collect(! empty($job->retried_by) ? json_decode($job->retried_by) : [])
            ->sortByDesc('retried_at')->values();

        return $job;
    }
}
