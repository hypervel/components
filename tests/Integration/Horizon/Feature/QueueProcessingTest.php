<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\JobReserved;
use Hypervel\Horizon\Events\JobsMigrated;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Queue as BaseQueue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;

class QueueProcessingTest extends IntegrationTestCase
{
    public function testLegacyJobsCanBeProcessedWithoutErrors()
    {
        Queue::push('Hypervel\Tests\Integration\Horizon\Feature\Jobs\LegacyJob');
        $this->work();
    }

    public function testCompletedJobsAreNotNormallyStoredInCompletedDatabase()
    {
        Queue::push(new Jobs\BasicJob);
        $this->work();
        $this->assertSame(0, $this->monitoredJobs('first'));
        $this->assertSame(0, $this->monitoredJobs('second'));
    }

    public function testPendingJobsAreStoredInPendingJobDatabase()
    {
        $id = Queue::push(new Jobs\BasicJob);
        $this->assertSame(1, $this->recentJobs());
        $this->assertSame('pending', Redis::connection('horizon')->hget($id, 'status'));
    }

    public function testPendingDelayedJobsAreStoredInPendingJobDatabase(): void
    {
        $id = Queue::later(1, new Jobs\BasicJob);
        $this->assertSame(1, $this->recentJobs());
        $this->assertSame('pending', Redis::connection('horizon')->hget($id, 'status'));

        $payload = json_decode(Redis::connection('horizon')->hget($id, 'payload'), true);
        $this->assertSame(1, $payload['delay']);
    }

    public function testImmediateAndDelayedPayloadHooksReceiveTheResolvedQueue(): void
    {
        $queues = [];
        BaseQueue::createPayloadUsing(function (string $connection, string $queue) use (&$queues): array {
            $queues[] = $queue;

            return [];
        });

        try {
            /** @var RedisQueue $queue */
            $queue = Queue::connection('redis');
            $queue->push(new Jobs\BasicJob, queue: 'critical');
            $queue->later(1, new Jobs\BasicJob, queue: 'critical');
        } finally {
            BaseQueue::createPayloadUsing(null);
        }

        $this->assertSame(['queues:critical', 'queues:critical'], $queues);
    }

    public function testDirectRawPushDoesNotInheritThePreviousJob(): void
    {
        Queue::push(new Jobs\BasicJob);

        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $queue->pushRaw('{"id":"raw-id","displayName":"Raw Job"}');

        $payload = json_decode(Redis::connection('horizon')->hget('raw-id', 'payload'), true);
        $this->assertSame([], $payload['tags']);
    }

    public function testPayloadPreparationFailureStillConsumesThePreviousJob(): void
    {
        $queue = new RedisQueueWithExposedLastPushed(
            app('redis'),
            'default',
            'default',
        );
        $queue->setContainer($this->app)->setConnectionName('redis');
        $queue->rememberLastPushed(new Jobs\BasicJob);

        try {
            $queue->pushRaw('{invalid');
            $this->fail('Expected the invalid payload to be rejected.');
        } catch (InvalidPayloadException) {
        }

        $queue->pushRaw('{"id":"raw-after-failure","displayName":"Raw Job"}');

        $payload = json_decode(Redis::connection('horizon')->hget('raw-after-failure', 'payload'), true);
        $this->assertSame([], $payload['tags']);
    }

    public function testPendingJobsAreStoredWithTheirTags()
    {
        $id = Queue::push(new Jobs\BasicJob);
        $payload = json_decode(Redis::connection('horizon')->hget($id, 'payload'), true);
        $this->assertEquals(['first', 'second'], $payload['tags']);
    }

    public function testPendingJobsAreStoredWithTheirType()
    {
        $id = Queue::push(new Jobs\BasicJob);
        $payload = json_decode(Redis::connection('horizon')->hget($id, 'payload'), true);
        $this->assertSame('job', $payload['type']);
    }

    public function testPendingJobsAreNoLongerInPendingDatabaseAfterBeingWorked()
    {
        Queue::push(new Jobs\BasicJob);
        $this->work();

        $recent = resolve(JobRepository::class)->getRecent();
        $this->assertSame('completed', $recent[0]->status);
    }

    public function testPendingJobIsMarkedAsReservedDuringProcessing()
    {
        $id = Queue::push(new Jobs\BasicJob);

        $status = null;
        Event::listen(JobReserved::class, function ($event) use ($id, &$status) {
            $status = Redis::connection('horizon')->hget($id, 'status');
        });

        $this->work();

        $this->assertSame('reserved', $status);
    }

    public function testStaleReservedJobsAreMarkedAsPendingAfterMigrating()
    {
        $id = Queue::later(CarbonImmutable::now()->addSeconds(0), new Jobs\BasicJob);

        Redis::connection('horizon')->hset($id, 'status', 'reserved');

        $status = null;
        Event::listen(JobsMigrated::class, function ($event) use ($id, &$status) {
            $status = Redis::connection('horizon')->hget($id, 'status');
        });

        $this->work();

        $this->assertSame('pending', $status);
    }

    public function testInvalidRawPayloadIsTerminallyRemovedWithoutHorizonTelemetryFailure(): void
    {
        Redis::connection('default')->rpush('queues:default', '{invalid');
        Redis::connection('default')->rpush('queues:default:notify', 1);

        $this->work();

        $this->assertSame(0, Redis::connection('default')->llen('queues:default'));
        $this->assertSame(0, Redis::connection('default')->zcard('queues:default:reserved'));
        $this->assertSame(0, Redis::connection('default')->llen('queues:default:notify'));
    }

    public function testMigratedTelemetryRetainsValidPayloadsFromMixedInput(): void
    {
        $event = new JobsMigrated([
            '{"id":"valid"}',
            '{invalid',
            '{"id":1}',
        ]);

        $this->assertCount(1, $event->payloads);
        $this->assertSame('valid', $event->payloads->first()->id());
    }
}

class RedisQueueWithExposedLastPushed extends RedisQueue
{
    public function rememberLastPushed(object|string $job): void
    {
        $this->setLastPushed($job);
    }
}
