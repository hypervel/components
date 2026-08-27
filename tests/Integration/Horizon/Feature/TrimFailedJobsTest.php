<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Horizon\Listeners\TrimFailedJobs;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\Repositories\RedisJobRepository;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use Mockery as m;

class TrimFailedJobsTest extends IntegrationTestCase
{
    public function testOmittedRetentionUsesTheDefaultCooldown(): void
    {
        config()->set('horizon.trim', []);

        $trimmer = new TrimFailedJobs;

        $repository = m::mock(JobRepository::class);
        $repository->shouldReceive('trimFailedJobs')->twice();
        $this->app->instance(JobRepository::class, $repository);

        $trimmer->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));

        $this->assertSame(intdiv(RedisJobRepository::DEFAULT_FAILED_JOB_RETENTION, 12), $trimmer->frequency);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(1600));

        $trimmer->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));
        $trimmer->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));
        $trimmer->handle(new MasterSupervisorLooped(m::mock(MasterSupervisor::class)));

        CarbonImmutable::setTestNow();
    }
}
