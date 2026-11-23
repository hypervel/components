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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class TrimMonitoredJobsTest extends IntegrationTestCase
{
    use VerifiesDoubles;

    public function testTrimmerHasACooldownPeriod(): void
    {
        config()->set('horizon.trim', []);

        $trim = new TrimMonitoredJobs;

        $repository = Double::for(JobRepository::class)->strict();
        $repository->expects('trimMonitoredJobs')->times(2);
        $this->app->instance(JobRepository::class, $repository);

        // Should not be called first time since date is initialized...
        $trim->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));
        $this->assertSame(intdiv(RedisJobRepository::DEFAULT_MONITORED_JOB_RETENTION, 12), $trim->frequency);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(1600));

        // Should only be called twice...
        $trim->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));
        $trim->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));
        $trim->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));

        CarbonImmutable::setTestNow();
    }
}
