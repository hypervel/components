<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Feature\Listeners;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Events\JobDeleted;
use Hypervel\Horizon\JobPayload;
use Hypervel\Horizon\Listeners\MarkJobAsComplete;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class MarkJobAsCompleteTest extends IntegrationTestCase
{
    public function testHandle(): void
    {
        $payload = m::mock(JobPayload::class);
        $payload->shouldReceive('tags')->once()->andReturn([]);
        $payload->shouldReceive('isSilenced')->once()->andReturn(false);

        $job = m::mock(RedisJob::class);
        $job->shouldReceive('hasFailed')->twice()->andReturn(false);

        $event = m::mock(JobDeleted::class);
        $event->payload = $payload;
        $event->job = $job;

        $jobs = m::mock(JobRepository::class);
        $jobs->shouldReceive('completed')->once()->with($payload, false, false);

        $tags = m::mock(TagRepository::class);
        $tags->shouldReceive('monitored')->once()->with([])->andReturn([]);

        $listener = new MarkJobAsComplete($jobs, $tags);

        $listener->handle($event);
    }

    public function testHandleWithTag(): void
    {
        $payload = m::mock(JobPayload::class);
        $payload->shouldReceive('tags')->once()->andReturn(['tag']);
        $payload->shouldReceive('isSilenced')->once()->andReturn(false);

        $job = m::mock(RedisJob::class);
        $job->shouldReceive('hasFailed')->twice()->andReturn(false);

        $event = m::mock(JobDeleted::class);
        $event->payload = $payload;
        $event->job = $job;
        $event->connectionName = 'redis';
        $event->queue = 'default';

        $jobs = m::mock(JobRepository::class);
        $jobs->shouldReceive('completed')->once()->with($payload, false, false);
        $jobs->shouldReceive('remember')->once()->with('redis', 'default', $payload);

        $tags = m::mock(TagRepository::class);
        $tags->shouldReceive('monitored')->once()->with(['tag'])->andReturn(['tag']);

        $listener = new MarkJobAsComplete($jobs, $tags);

        $listener->handle($event);
    }
}
