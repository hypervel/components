<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Controller;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\Repositories\RedisJobRepository;
use Hypervel\Horizon\WaitTimeCalculator;
use Hypervel\Tests\Integration\Horizon\Controller\Fixtures\User;
use Hypervel\Tests\Integration\Horizon\ControllerTestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class DashboardStatsControllerTest extends ControllerTestCase
{
    use VerifiesDoubles;

    public function testAllStatsAreCorrectlyReturned(): void
    {
        // Setup supervisor data...
        $supervisors = Double::for(SupervisorRepository::class)->strict();
        $supervisors->allows('all')->returns([
            (object) [
                'processes' => [
                    'redis:first' => 10,
                    'redis:second' => 10,
                ],
            ],
            (object) [
                'processes' => [
                    'redis:first' => 10,
                ],
            ],
        ]);
        $this->app->instance(SupervisorRepository::class, $supervisors);

        // Setup metrics data...
        $metrics = Double::for(MetricsRepository::class)->strict();
        $metrics->allows('jobsProcessedPerMinute')->returns(1);
        $metrics->allows('queueWithMaximumRuntime')->returns('default');
        $metrics->allows('queueWithMaximumThroughput')->returns('default');
        $this->app->instance(MetricsRepository::class, $metrics);

        $jobs = Double::for(JobRepository::class)->strict();
        $jobs->allows('countRecentlyFailed')->returns(1);
        $jobs->allows('countRecent')->returns(1);
        $this->app->instance(JobRepository::class, $jobs);

        // Setup wait time data...
        $wait = Double::for(WaitTimeCalculator::class)->strict();
        $wait->allows('calculate')->returns([
            'first' => 20,
            'second' => 10,
        ]);
        $this->app->instance(WaitTimeCalculator::class, $wait);

        $config = $this->app->make('config');

        $config->set('horizon.trim', ['failed' => 120]);

        $response = $this->actingAs(new User)
            ->get('/horizon/api/stats');

        $response->assertJson([
            'jobsPerMinute' => 1,
            'wait' => ['first' => 20],
            'processes' => 30,
            'status' => 'inactive',
            'failedJobs' => 1,
            'recentJobs' => 1,
            'queueWithMaxRuntime' => 'default',
            'queueWithMaxThroughput' => 'default',
            'periods' => [
                'failedJobs' => 120,
                'recentJobs' => RedisJobRepository::DEFAULT_RECENT_JOB_RETENTION,
            ],
        ]);
    }

    public function testPausedStatusIsReflectedIfAllMasterSupervisorsArePaused(): void
    {
        $masters = Double::for(MasterSupervisorRepository::class)->strict();
        $masters->allows('all')->returns([
            (object) [
                'status' => 'paused',
            ],
            (object) [
                'status' => 'paused',
            ],
        ]);
        $this->app->instance(MasterSupervisorRepository::class, $masters);

        $response = $this->actingAs(new User)
            ->get('/horizon/api/stats');

        $response->assertJson([
            'status' => 'paused',
        ]);
    }

    public function testPausedStatusIsntReflectedIfNotAllMasterSupervisorsArePaused(): void
    {
        $masters = Double::for(MasterSupervisorRepository::class)->strict();
        $masters->allows('all')->returns([
            (object) [
                'status' => 'running',
            ],
            (object) [
                'status' => 'paused',
            ],
        ]);
        $this->app->instance(MasterSupervisorRepository::class, $masters);

        $response = $this->actingAs(new User)
            ->get('/horizon/api/stats');

        $response->assertJson([
            'status' => 'running',
        ]);
    }
}
