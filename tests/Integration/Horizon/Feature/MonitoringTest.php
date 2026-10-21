<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Jobs\MonitorTag;
use Hypervel\Horizon\Jobs\StopMonitoringTag;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Jobs\BasicJob;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;

class MonitoringTest extends IntegrationTestCase
{
    public function testCanRetrieveAllMonitoredTags(): void
    {
        $repository = resolve(TagRepository::class);

        dispatch(new MonitorTag('first'));
        $this->assertEquals(['first'], $repository->monitoring());

        dispatch(new MonitorTag('second'));
        $monitored = $repository->monitoring();
        $this->assertContains('first', $monitored);
        $this->assertContains('second', $monitored);
        $this->assertCount(2, $monitored);
    }

    public function testCanDetermineIfASetOfTagsAreBeingMonitored(): void
    {
        $repository = resolve(TagRepository::class);
        dispatch(new MonitorTag('first'));
        $this->assertEquals(['first'], $repository->monitored(['first', 'second']));
    }

    public function testCanStopMonitoringTags(): void
    {
        $repository = resolve(TagRepository::class);
        dispatch(new MonitorTag('first'));
        dispatch(new StopMonitoringTag('first'));
        $this->assertEquals([], $repository->monitored(['first', 'second']));
    }

    public function testTagsThatAreRemovedFromMonitoringAreRemovedFromStorage(): void
    {
        dispatch(new MonitorTag('first'));
        dispatch(new StopMonitoringTag('first'));
        $this->assertNull(Redis::connection('horizon')->get('first'));
    }

    public function testCompletedJobsAreStoredInDatabaseWhenOneOfTheirTagsIsBeingMonitored(): void
    {
        dispatch(new MonitorTag('first'));
        $id = Queue::push(new BasicJob);
        $this->work();
        $this->assertSame(1, $this->monitoredJobs('first'));
        $this->assertGreaterThan(0, Redis::connection('horizon')->ttl($id));
    }

    public function testCompletedJobsAreRemovedFromDatabaseWhenTheirTagIsNoLongerMonitored(): void
    {
        dispatch(new MonitorTag('first'));
        Queue::push(new BasicJob);
        $this->work();
        dispatch(new StopMonitoringTag('first'));
        $this->assertSame(0, $this->monitoredJobs('first'));
    }

    public function testAllCompletedJobsAreRemovedFromDatabaseWhenTheirTagIsNoLongerMonitored(): void
    {
        dispatch(new MonitorTag('first'));

        for ($i = 0; $i < 80; ++$i) {
            Queue::push(new BasicJob);
        }

        $this->work();

        dispatch(new StopMonitoringTag('first'));
        $this->assertSame(0, $this->monitoredJobs('first'));
    }
}
