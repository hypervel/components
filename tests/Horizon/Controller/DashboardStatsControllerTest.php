<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Controller;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\WaitTimeCalculator;
use Hypervel\Tests\Horizon\ControllerTest;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class DashboardStatsControllerTest extends ControllerTest
{
    public function testAllStatsAreCorrectlyReturned()
    {
        // Setup supervisor data...
        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([
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
        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('jobsProcessedPerMinute')->andReturn(1);
        $metrics->shouldReceive('queueWithMaximumRuntime')->andReturn('default');
        $metrics->shouldReceive('queueWithMaximumThroughput')->andReturn('default');
        $this->app->instance(MetricsRepository::class, $metrics);

        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('countRecentlyFailed')->andReturn(1);
        $jobs->shouldReceive('countRecent')->andReturn(1);
        $this->app->instance(JobRepository::class, $jobs);

        // Setup wait time data...
        $wait = Mockery::mock(WaitTimeCalculator::class);
        $wait->shouldReceive('calculate')->andReturn([
            'first' => 20,
            'second' => 10,
        ]);
        $this->app->instance(WaitTimeCalculator::class, $wait);

        $this->app['config']->set('horizon.trim.recent_failed', 10080);
        $this->app['config']->set('horizon.trim.recent', 60);

        $response = $this->actingAs(new Fakes\User())
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
                'failedJobs' => 10080,
                'recentJobs' => 60,
            ],
        ]);
    }

    public function testPausedStatusIsReflectedIfAllMasterSupervisorsArePaused()
    {
        $masters = Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->andReturn([
            (object) [
                'status' => 'paused',
            ],
            (object) [
                'status' => 'paused',
            ],
        ]);
        $this->app->instance(MasterSupervisorRepository::class, $masters);

        $response = $this->actingAs(new Fakes\User())
            ->get('/horizon/api/stats');

        $response->assertJson([
            'status' => 'paused',
        ]);
    }

    public function testPausedStatusIsntReflectedIfNotAllMasterSupervisorsArePaused()
    {
        $masters = Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->andReturn([
            (object) [
                'status' => 'running',
            ],
            (object) [
                'status' => 'paused',
            ],
        ]);
        $this->app->instance(MasterSupervisorRepository::class, $masters);

        $response = $this->actingAs(new Fakes\User())
            ->get('/horizon/api/stats');

        $response->assertJson([
            'status' => 'running',
        ]);
    }
}
