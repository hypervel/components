<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Horizon\Listeners\TrimMonitoredJobs;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\Repositories\RedisJobRepository;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use Mockery as m;

class TrimMonitoredJobsTest extends IntegrationTestCase
{
    public function testTrimmerHasACooldownPeriod(): void
    {
        config()->set('horizon.trim', []);

        $trim = new TrimMonitoredJobs;

        $repository = m::mock(JobRepository::class);
        $repository->shouldReceive('trimMonitoredJobs')->twice();
        $this->app->instance(JobRepository::class, $repository);

        // Should not be called first time since date is initialized...
        $trim->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));
        $this->assertSame(intdiv(RedisJobRepository::DEFAULT_MONITORED_JOB_RETENTION, 12), $trim->frequency);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(1600));

        // Should only be called twice...
        $trim->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));
        $trim->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));
        $trim->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));

        CarbonImmutable::setTestNow();
    }
}
