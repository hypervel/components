<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Horizon\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 */
class FailedJobTest extends IntegrationTestCase
{
    public function testFailedJobsArePlacedInTheFailedJobTable()
    {
        $id = Queue::push(new Jobs\FailingJob());
        $this->work();
        $this->assertSame(1, $this->failedJobs());
        $this->assertGreaterThan(0, Redis::connection('horizon')->ttl($id));

        $job = resolve(JobRepository::class)->getJobs([$id])[0];

        $this->assertTrue(isset($job->exception));
        $this->assertTrue(isset($job->failed_at));
        $this->assertSame('failed', $job->status);
        $this->assertIsNumeric($job->failed_at);
        $this->assertSame(Jobs\FailingJob::class, $job->name);
    }

    public function testTagsForFailedJobsAreStoredInRedis()
    {
        $id = Queue::push(new Jobs\FailingJob());
        $this->work();
        $ids = resolve(TagRepository::class)->jobs('failed:first');
        $this->assertEquals([$id], $ids);
    }

    public function testFailedJobTagsHaveAnExpiration()
    {
        Queue::push(new Jobs\FailingJob());
        $this->work();
        $ttl = Redis::connection('horizon')->pttl('failed:first');
        $this->assertNotNull($ttl);
        $this->assertGreaterThan(0, $ttl);
    }
}
