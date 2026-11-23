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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class TrimFailedJobsTest extends IntegrationTestCase
{
    use VerifiesDoubles;

    public function testOmittedRetentionUsesTheDefaultCooldown(): void
    {
        config()->set('horizon.trim', []);

        $trimmer = new TrimFailedJobs;

        $repository = Double::for(JobRepository::class)->strict();
        $repository->expects('trimFailedJobs')->times(2);
        $this->app->instance(JobRepository::class, $repository);

        $trimmer->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));

        $this->assertSame(intdiv(RedisJobRepository::DEFAULT_FAILED_JOB_RETENTION, 12), $trimmer->frequency);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(1600));

        $trimmer->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));
        $trimmer->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));
        $trimmer->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));

        CarbonImmutable::setTestNow();
    }
}
