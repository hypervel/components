<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\JobPayload;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;

/**
 * @internal
 * @coversNothing
 */
class JobRetrievalTest extends IntegrationTestCase
{
    public function testPendingJobsCanBeRetrieved()
    {
        $ids = [];

        $ids[] = Queue::push(new Jobs\BasicJob());
        $ids[] = Queue::push(new Jobs\BasicJob());
        $ids[] = Queue::push(new Jobs\BasicJob());
        $ids[] = Queue::push(new Jobs\BasicJob());
        $ids[] = Queue::push(new Jobs\BasicJob());

        $repository = resolve(JobRepository::class);

        $recent = $repository->getRecent();

        // Test getting all jobs...
        $this->assertCount(5, $recent);
        $this->assertSame(Jobs\BasicJob::class, $recent->first()->name);
        $this->assertEquals($ids[4], $recent->first()->id);
        $this->assertEquals($ids[0], $recent->last()->id);
        $this->assertSame(0, $recent->first()->index);
        $this->assertSame(4, $recent->last()->index);

        // Test pagination...
        $recent = $repository->getRecent(1);
        $this->assertCount(3, $recent);
        $this->assertEquals($ids[2], $recent->first()->id);
        $this->assertEquals($ids[0], $recent->last()->id);
        $this->assertSame(2, $recent->first()->index);
        $this->assertSame(4, $recent->last()->index);

        /* // Test no results... */
        $recent = $repository->getRecent(4);
        $this->assertCount(0, $recent);
    }

    public function testRecentJobsAreCorrectlyTrimmedAndExpired()
    {
        $ids = [];

        $ids[] = Queue::push(new Jobs\BasicJob());
        $ids[] = Queue::push(new Jobs\BasicJob());
        $ids[] = Queue::push(new Jobs\BasicJob());
        $ids[] = Queue::push(new Jobs\BasicJob());
        $ids[] = Queue::push(new Jobs\BasicJob());

        $repository = resolve(JobRepository::class);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(3));

        $this->assertSame(5, Redis::connection('horizon')->zcard('recent_jobs'));

        $repository->trimRecentJobs();
        $this->assertSame(0, Redis::connection('horizon')->zcard('recent_jobs'));

        // Assert job record has a TTL...
        $repository->completed(new JobPayload(json_encode(['id' => $ids[0]])));
        $this->assertGreaterThan(0, Redis::connection('horizon')->ttl($ids[0]));

        CarbonImmutable::setTestNow();
    }

    public function testPaginatingLargeJobResultsGivesCorrectAmounts()
    {
        $ids = [];

        for ($i = 0; $i < 75; ++$i) {
            $ids[] = Queue::push(new Jobs\BasicJob());
        }

        $repository = resolve(JobRepository::class);

        $pending = $repository->getRecent();
        $this->assertCount(50, $pending);

        $pending = $repository->getRecent($pending->last()->index);
        $this->assertCount(25, $pending);
    }
}
