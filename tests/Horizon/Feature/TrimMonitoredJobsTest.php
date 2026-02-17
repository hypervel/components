<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Horizon\Listeners\TrimMonitoredJobs;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class TrimMonitoredJobsTest extends IntegrationTestCase
{
    public function testTrimmerHasACooldownPeriod()
    {
        $trim = new TrimMonitoredJobs();

        $repository = m::mock(JobRepository::class);
        $repository->shouldReceive('trimMonitoredJobs')->twice();
        $this->app->instance(JobRepository::class, $repository);

        // Should not be called first time since date is initialized...
        $trim->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(1600));

        // Should only be called twice...
        $trim->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));
        $trim->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));
        $trim->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));

        CarbonImmutable::setTestNow();
    }
}
