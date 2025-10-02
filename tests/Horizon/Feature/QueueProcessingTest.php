<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\JobReserved;
use Hypervel\Horizon\Events\JobsMigrated;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;

/**
 * @internal
 * @coversNothing
 */
class QueueProcessingTest extends IntegrationTestCase
{
    public function testLegacyJobsCanBeProcessedWithoutErrors()
    {
        Queue::push('Hypervel\Tests\Horizon\Feature\Jobs\LegacyJob');
        $this->work();
    }

    public function testCompletedJobsAreNotNormallyStoredInCompletedDatabase()
    {
        Queue::push(new Jobs\BasicJob());
        $this->work();
        $this->assertSame(0, $this->monitoredJobs('first'));
        $this->assertSame(0, $this->monitoredJobs('second'));
    }

    public function testPendingJobsAreStoredInPendingJobDatabase()
    {
        $id = Queue::push(new Jobs\BasicJob());
        $this->assertSame(1, $this->recentJobs());
        $this->assertSame('pending', Redis::connection('horizon')->hget($id, 'status'));
    }

    public function testPendingDelayedJobsAreStoredInPendingJobDatabase()
    {
        $id = Queue::later(1, new Jobs\BasicJob());
        $this->assertSame(1, $this->recentJobs());
        $this->assertSame('pending', Redis::connection('horizon')->hget($id, 'status'));
    }

    public function testPendingJobsAreStoredWithTheirTags()
    {
        $id = Queue::push(new Jobs\BasicJob());
        $payload = json_decode(Redis::connection('horizon')->hget($id, 'payload'), true);
        $this->assertEquals(['first', 'second'], $payload['tags']);
    }

    public function testPendingJobsAreStoredWithTheirType()
    {
        $id = Queue::push(new Jobs\BasicJob());
        $payload = json_decode(Redis::connection('horizon')->hget($id, 'payload'), true);
        $this->assertSame('job', $payload['type']);
    }

    public function testPendingJobsAreNoLongerInPendingDatabaseAfterBeingWorked()
    {
        Queue::push(new Jobs\BasicJob());
        $this->work();

        $recent = resolve(JobRepository::class)->getRecent();
        $this->assertSame('completed', $recent[0]->status);
    }

    public function testPendingJobIsMarkedAsReservedDuringProcessing()
    {
        $id = Queue::push(new Jobs\BasicJob());

        $status = null;
        Event::listen(JobReserved::class, function ($event) use ($id, &$status) {
            $status = Redis::connection('horizon')->hget($id, 'status');
        });

        $this->work();

        $this->assertSame('reserved', $status);
    }

    public function testStaleReservedJobsAreMarkedAsPendingAfterMigrating()
    {
        $id = Queue::later(CarbonImmutable::now()->addSeconds(0), new Jobs\BasicJob());

        Redis::connection('horizon')->hset($id, 'status', 'reserved');

        $status = null;
        Event::listen(JobsMigrated::class, function ($event) use ($id, &$status) {
            $status = Redis::connection('horizon')->hget($id, 'status');
        });

        $this->work();

        $this->assertSame('pending', $status);
    }
}
