<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\WaitTimeCalculator;

class DashboardStatsController
{
    /**
     * Get the key performance stats for the dashboard.
     */
    public function index(): array
    {
        return [
            'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
            'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
            'pausedMasters' => $this->totalPausedMasters(),
            'periods' => [
                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                'recentJobs' => config('horizon.trim.recent'),
            ],
            'processes' => $this->totalProcessCount(),
            'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
            'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
            'recentJobs' => app(JobRepository::class)->countRecent(),
            'status' => $this->currentStatus(),
            'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1),
        ];
    }

    /**
     * Get the total process count across all supervisors.
     */
    protected function totalProcessCount(): int
    {
        $supervisors = app(SupervisorRepository::class)->all();

        return collect($supervisors)->reduce(function ($carry, $supervisor) {
            return $carry + collect($supervisor->processes)->sum();
        }, 0);
    }

    /**
     * Get the current status of Horizon.
     */
    protected function currentStatus(): string
    {
        if (! $masters = app(MasterSupervisorRepository::class)->all()) {
            return 'inactive';
        }

        return collect($masters)->every(function ($master) {
            return $master->status === 'paused';
        }) ? 'paused' : 'running';
    }

    /**
     * Get the number of master supervisors that are currently paused.
     */
    protected function totalPausedMasters(): int
    {
        if (! $masters = app(MasterSupervisorRepository::class)->all()) {
            return 0;
        }

        return collect($masters)->filter(function ($master) {
            return $master->status === 'paused';
        })->count();
    }
}
