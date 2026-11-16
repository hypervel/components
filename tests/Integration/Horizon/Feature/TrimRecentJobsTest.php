<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\MasterSupervisorLooped;
use Hypervel\Horizon\Listeners\TrimRecentJobs;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class TrimRecentJobsTest extends IntegrationTestCase
{
    use VerifiesDoubles;

    public function testTrimmerHasACooldownPeriod(): void
    {
        $trim = new TrimRecentJobs;

        $repository = Double::for(JobRepository::class)->strict();
        $repository->expects('trimRecentJobs')->times(2);
        $this->app->instance(JobRepository::class, $repository);

        // Should not be called first time since date is initialized...
        $trim->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(30));

        // Should only be called twice...
        $trim->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));
        $trim->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));
        $trim->handle(new MasterSupervisorLooped(Double::for(MasterSupervisor::class)->strict()));

        CarbonImmutable::setTestNow();
    }
}
