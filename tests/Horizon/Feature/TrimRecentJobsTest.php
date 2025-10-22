<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Horizon\Listeners\TrimRecentJobs;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class TrimRecentJobsTest extends IntegrationTestCase
{
    public function testTrimmerHasACooldownPeriod()
    {
        $trim = new TrimRecentJobs();

        $repository = Mockery::mock(JobRepository::class);
        $repository->shouldReceive('trimRecentJobs')->twice();
        $this->app->instance(JobRepository::class, $repository);

        // Should not be called first time since date is initialized...
        $trim->handle(new MasterSupervisorLooped(Mockery::mock(MasterSupervisor::class)));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(30));

        // Should only be called twice...
        $trim->handle(new MasterSupervisorLooped(Mockery::mock(MasterSupervisor::class)));
        $trim->handle(new MasterSupervisorLooped(Mockery::mock(MasterSupervisor::class)));
        $trim->handle(new MasterSupervisorLooped(Mockery::mock(MasterSupervisor::class)));

        CarbonImmutable::setTestNow();
    }
}
