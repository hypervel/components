<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Jobs\MonitorTag;
use Hypervel\Horizon\Jobs\StopMonitoringTag;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;

class MonitoringController
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
     * Get all of the monitored tags and their job counts.
     */
    public function index(): Collection
    {
        return collect($this->tags->monitoring())->map(function ($tag) {
            return [
                'tag' => $tag,
                'count' => $this->tags->count($tag) + $this->tags->count('failed:' . $tag),
            ];
        })->sortBy('tag')->values();
    }

    /**
     * Paginate the jobs for a given tag.
     */
    public function paginate(Request $request): array
    {
        $tag = $request->query('tag');

        $jobIds = $this->tags->paginate(
            $tag,
            $startingAt = (int) $request->query('starting_at', 0),
            (int) $request->query('limit', 25)
        );

        return [
            'jobs' => $this->getJobs($jobIds, $startingAt),
            'total' => $this->tags->count($tag),
        ];
    }

    /**
     * Get the jobs for the given IDs.
     */
    protected function getJobs(array $jobIds, int $startingAt = 0): Collection
    {
        return $this->jobs->getJobs($jobIds, $startingAt)->map(function ($job) {
            $job->payload = json_decode($job->payload);

            return $job;
        })->values();
    }

    /**
     * Start monitoring the given tag.
     */
    public function store(Request $request): void
    {
        dispatch(new MonitorTag($request->input('tag')));
    }

    /**
     * Stop monitoring the given tag.
     */
    public function destroy(string $tag): void
    {
        dispatch(new StopMonitoringTag($tag));
    }
}
