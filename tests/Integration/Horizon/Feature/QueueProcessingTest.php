<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Events\JobPending;
use Hypervel\Horizon\Events\JobPushed;
use Hypervel\Horizon\Events\JobReserved;
use Hypervel\Horizon\Events\JobsMigrated;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Queue as BaseQueue;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use ReflectionMethod;

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

    public function testDirectRawPushPreservesExistingHorizonClassification(): void
    {
        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $queue->pushRaw(json_encode([
            'id' => 'classified-raw-id',
            'displayName' => 'Classified Raw Job',
            'type' => 'event',
            'tags' => ['stored-tag'],
            'silenced' => true,
            'pushedAt' => '1.0',
        ]));

        $payload = json_decode(Redis::connection('horizon')->hget('classified-raw-id', 'payload'), true);
        $this->assertSame('event', $payload['type']);
        $this->assertSame(['stored-tag'], $payload['tags']);
        $this->assertTrue($payload['silenced']);
        $this->assertNotSame('1.0', $payload['pushedAt']);
    }

    public function testAfterCommitJobsAreStampedWhenTheyReachRedis(): void
    {
        /** @var DatabaseTransactionsManager $transactions */
        $transactions = $this->app->make('db.transactions');
        $transactions->begin('horizon-test', 1);

        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $queue->push(new AfterCommitHorizonJob);
        $queue->later(60, new AfterCommitHorizonJob);

        $queueKey = $this->getQueueRedisKey($queue);
        $this->assertSame(0, Redis::connection('default')->lLen($queueKey));
        $this->assertSame(0, Redis::connection('default')->zCard($queueKey . ':delayed'));

        $publishedAfter = microtime(true);
        $transactions->commit('horizon-test', 1, 0);

        $immediate = json_decode(Redis::connection('default')->lIndex($queueKey, 0), true);
        $delayed = json_decode(Redis::connection('default')->zRange($queueKey . ':delayed', 0, 0)[0], true);
        $this->assertGreaterThanOrEqual($publishedAfter, (float) $immediate['pushedAt']);
        $this->assertGreaterThanOrEqual($publishedAfter, (float) $delayed['pushedAt']);
        $this->assertSame(['first', 'second'], $immediate['tags']);
        $this->assertSame(['first', 'second'], $delayed['tags']);
    }

    public function testBulkEventsSurroundConfirmedRedisStorage(): void
    {
        $events = [];
        Event::listen(JobPending::class, function (JobPending $event) use (&$events): void {
            $events[] = ['pending', $event->payload->id()];
        });
        Event::listen(JobPushed::class, function (JobPushed $event) use (&$events): void {
            $events[] = ['pushed', $event->payload->id()];
        });

        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $queue->bulk([
            new Jobs\BasicJob,
            new Jobs\BasicJob,
        ]);

        $this->assertSame(
            ['pending', 'pending', 'pushed', 'pushed'],
            array_column($events, 0),
        );
        $this->assertSame($events[0][1], $events[2][1]);
        $this->assertSame($events[1][1], $events[3][1]);
        $this->assertNotSame($events[0][1], $events[1][1]);
    }

    public function testFailedBulkDoesNotRaisePushedEvents(): void
    {
        $pending = 0;
        $pushed = 0;
        Event::listen(JobPending::class, function () use (&$pending): void {
            ++$pending;
        });
        Event::listen(JobPushed::class, function () use (&$pushed): void {
            ++$pushed;
        });

        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        Redis::connection('default')->set($this->getQueueRedisKey($queue), 'wrong-type');

        try {
            $queue->bulk([
                new Jobs\BasicJob,
                new Jobs\BasicJob,
            ]);
            $this->fail('Expected the Redis batch to fail.');
        } catch (LuaScriptException) {
        }

        $this->assertSame(2, $pending);
        $this->assertSame(0, $pushed);
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
        /** @var RedisQueue $queue */
        $queue = Queue::connection('redis');
        $queueKey = $this->getQueueRedisKey($queue);
        Redis::connection('default')->rpush($queueKey, '{invalid');
        Redis::connection('default')->rpush("{$queueKey}:notify", 1);

        $this->work();

        $this->assertSame(0, Redis::connection('default')->llen($queueKey));
        $this->assertSame(0, Redis::connection('default')->zcard("{$queueKey}:reserved"));
        $this->assertSame(0, Redis::connection('default')->llen("{$queueKey}:notify"));
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

    private function getQueueRedisKey(RedisQueue $queue, ?string $name = null): string
    {
        return (new ReflectionMethod($queue, 'getQueueRedisKey'))->invoke($queue, $name);
    }
}

class RedisQueueWithExposedLastPushed extends RedisQueue
{
    public function rememberLastPushed(object|string $job): void
    {
        $this->setLastPushed($job);
    }
}

class AfterCommitHorizonJob extends Jobs\BasicJob implements ShouldQueueAfterCommit
{
}
