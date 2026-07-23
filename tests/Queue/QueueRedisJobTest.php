<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Container\Container;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Queue\RedisQueue;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

class QueueRedisJobTest extends TestCase
{
    public function testFireProperlyCallsTheJobHandler(): void
    {
        $job = $this->getJob();
        $job->getContainer()->shouldReceive('make')->once()->with('foo')->andReturn($handler = m::mock(stdClass::class));
        $handler->shouldReceive('fire')->once()->with($job, ['data']);

        $job->fire();
    }

    public function testDeleteRemovesTheJobFromRedis(): void
    {
        $job = $this->getJob();
        $job->getRedisQueue()->shouldReceive('deleteReserved')->once()
            ->with('default', $job);

        $job->delete();
    }

    public function testReleaseProperlyReleasesJobOntoRedis(): void
    {
        $job = $this->getJob();
        $job->getRedisQueue()->shouldReceive('deleteAndRelease')->once()
            ->with('default', $job, 1);

        $job->release(1);
    }

    /**
     * Create a Redis job fixture.
     */
    protected function getJob(): RedisJob
    {
        return new RedisJob(
            m::mock(Container::class),
            m::mock(RedisQueue::class),
            json_encode(['job' => 'foo', 'data' => ['data'], 'attempts' => 1], JSON_THROW_ON_ERROR),
            json_encode(['job' => 'foo', 'data' => ['data'], 'attempts' => 2], JSON_THROW_ON_ERROR),
            'connection-name',
            'default',
        );
    }
}
