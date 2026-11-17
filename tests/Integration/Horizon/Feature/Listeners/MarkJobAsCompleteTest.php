<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature\Listeners;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Events\JobDeleted;
use Hypervel\Horizon\JobPayload;
use Hypervel\Horizon\Listeners\MarkJobAsComplete;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class MarkJobAsCompleteTest extends IntegrationTestCase
{
    use VerifiesDoubles;

    public function testHandle(): void
    {
        $payload = Double::for(JobPayload::class)->strict();
        $payload->expects('tags')->returns([]);
        $payload->expects('isSilenced')->returns(false);

        $job = Double::for(RedisJob::class)->strict();
        $job->expects('hasFailed')->times(2)->returns(false);

        $event = new JobDeleted($job, '{"id":"1"}');
        $event->payload = $payload;

        $jobs = Double::for(JobRepository::class)->strict();
        $jobs->expects('completed')->with($payload, false, false);

        $tags = Double::for(TagRepository::class)->strict();
        $tags->expects('monitored')->with([])->returns([]);

        $listener = new MarkJobAsComplete($jobs, $tags);

        $listener->handle($event);
    }

    public function testHandleWithTag(): void
    {
        $payload = Double::for(JobPayload::class)->strict();
        $payload->expects('tags')->returns(['tag']);
        $payload->expects('isSilenced')->returns(false);

        $job = Double::for(RedisJob::class)->strict();
        $job->expects('hasFailed')->times(2)->returns(false);

        $event = new JobDeleted($job, '{"id":"1"}');
        $event->payload = $payload;
        $event->connectionName = 'redis';
        $event->queue = 'default';

        $jobs = Double::for(JobRepository::class)->strict();
        $jobs->expects('completed')->with($payload, false, false);
        $jobs->expects('remember')->with('redis', 'default', $payload);

        $tags = Double::for(TagRepository::class)->strict();
        $tags->expects('monitored')->with(['tag'])->returns(['tag']);

        $listener = new MarkJobAsComplete($jobs, $tags);

        $listener->handle($event);
    }
}
