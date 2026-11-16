<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Events\Dispatcher;
use Hypervel\Horizon\Events\JobPushed;
use Hypervel\Horizon\Events\JobsMigrated;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingCanceled;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\LuaScripts;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Horizon\UnitTestCase;
use Hypervel\Tests\Queue\Fixtures\IntegerQueueName;
use JMac\Testing\Double;
use PHPUnit\Framework\Attributes\TestWith;
use RedisException;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\parallel;

class RedisQueueTest extends UnitTestCase
{
    #[TestWith(['push'])]
    #[TestWith(['later'])]
    #[TestWith(['bulk'])]
    #[TestWith(['failed-bulk'])]
    public function testLifecyclePayloadCorrelationIsIndependentOfHorizonMetadata(string $method): void
    {
        $stored = [];
        $failure = $method === 'failed-bulk' ? new RuntimeException('Redis unavailable.') : null;
        [$queue, $events] = $this->publishingQueue($stored, $failure);
        $queueing = $terminal = [];
        $events->listen(JobQueueing::class, static function (JobQueueing $event) use (&$queueing): void {
            $queueing[] = $event->payload;
        });
        $events->listen([JobQueued::class, JobQueueingFailed::class], static function (JobQueued|JobQueueingFailed $event) use (&$terminal): void {
            $terminal[] = $event->payload;
        });

        try {
            match ($method) {
                'push' => $queue->push('first'),
                'later' => $queue->later(30, 'first'),
                default => $queue->bulk(['first', 'second']),
            };
            $this->assertNull($failure);
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }

        $this->assertCount(str_contains($method, 'bulk') ? 2 : 1, $queueing);
        $this->assertSame($queueing, $terminal);

        if ($failure === null) {
            foreach ($stored as $index => $payload) {
                $decoded = json_decode($payload, true);
                $this->assertArrayHasKey('pushedAt', $decoded);
                $this->assertSame('job', $decoded['type']);
                $this->assertSame(json_decode($queueing[$index], true)['uuid'], $decoded['uuid']);
                $this->assertArrayNotHasKey('pushedAt', json_decode($terminal[$index], true));
            }
        }
    }

    #[TestWith(['push'])]
    #[TestWith(['later'])]
    #[TestWith(['bulk'])]
    public function testPostPushFailurePreservesAcceptanceAndNotifiesEveryMember(string $method): void
    {
        $stored = [];
        [$queue, $events] = $this->publishingQueue($stored);
        $exception = new RuntimeException('Horizon monitoring failed.');
        $events->listen(JobPushed::class, static fn () => throw $exception);
        $queued = $failed = [];
        $events->listen(JobQueued::class, static function (JobQueued $event) use (&$queued): void {
            $queued[] = $event->job;
        });
        $events->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event;
        });
        $cache = new Repository(new WorkerArrayStore);
        $job = new stdClass;
        $this->assertTrue((new UniqueLock($cache))->acquireForDispatch($job));
        $jobs = $method === 'bulk' ? [$job, new stdClass] : [$job];

        try {
            match ($method) {
                'push' => $queue->push($job),
                'later' => $queue->later(30, $job),
                'bulk' => $queue->bulk($jobs),
            };
            $this->fail('The monitoring failure should be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertCount(count($jobs), $stored);
        $this->assertSame($jobs, $queued);
        $this->assertSame([], $failed);
        $this->assertFalse(DispatchLockContext::has($job));
        $this->assertTrue($cache->lock(UniqueLock::getKey($job))->isLocked());
    }

    public function testPostPushCancellationStillPublishesTheQueuedOutcome(): void
    {
        $stored = [];
        [$queue, $events] = $this->publishingQueue($stored);
        $cancellation = new CanceledException;
        $events->listen(JobPushed::class, static fn () => throw $cancellation);
        $queued = $unqueued = [];
        $events->listen(JobQueued::class, static function (JobQueued $event) use (&$queued): void {
            $queued[] = $event->job;
        });
        $events->listen([JobQueueingFailed::class, JobQueueingCanceled::class], static function (object $event) use (&$unqueued): void {
            $unqueued[] = $event;
        });

        try {
            $queue->push('job');
            $this->fail('The post-push hook should cancel.');
        } catch (CanceledException $caught) {
            $this->assertSame($cancellation, $caught);
        }

        $this->assertCount(1, $stored);
        $this->assertSame(['job'], $queued);
        $this->assertSame([], $unqueued);
    }

    public function testReadyNowReadsTheClusterSafeQueueKey(): void
    {
        [$connection, $client] = $this->redisConnection(cluster: true);
        $client->expects('lLen')->with('queues:{critical}')->returns(3);
        $client->expects('lLen')->with('queues:{0}')->returns(4);

        $redis = Double::for(Factory::class)->strict();
        $redis->expects('connection')->times(3)->with('default')->returns($connection);

        $queue = new RedisQueue($redis, 'default', 'default');

        $this->assertSame(3, $queue->readyNow('critical'));
        $this->assertSame(4, $queue->readyNow(IntegerQueueName::Zero));
    }

    #[TestWith(['critical'])]
    #[TestWith(['{critical}'])]
    public function testMigrationEventsReportTheQueueNameOnCluster(string $name): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::create(2026, 1, 1));
        $key = 'queues:{critical}';
        [$connection, $client] = $this->redisConnection(cluster: true);
        $client->allows('clearLastError')->returns(true);
        $client->expects('eval')
            ->times(2)
            ->with(LuaScripts::migrateExpiredJobs(), [$key . ':delayed', $key, $key . ':notify', $now->getTimestamp(), RedisQueue::DEFAULT_MIGRATION_BATCH_SIZE], 3)
            ->returns([]);
        $client->expects('eval')
            ->with(LuaScripts::migrateExpiredJobs(), [$key . ':reserved', $key, $key . ':notify', $now->getTimestamp(), RedisQueue::DEFAULT_MIGRATION_BATCH_SIZE], 3)
            ->returns([]);
        $client->expects('eval')
            ->with(LuaScripts::pop(), [$key, $key . ':reserved', $key . ':notify', $now->getTimestamp() + RedisQueue::DEFAULT_RETRY_AFTER], 3)
            ->returns([]);

        $queues = [];
        $queue = $this->queueRecordingMigrations($connection, $queues);

        $this->assertNull($queue->pop($name));

        $queue->migrateExpiredJobs($key . ':delayed', $key);

        $this->assertSame([$name, $name, '{critical}'], $queues);
    }

    public function testConcurrentPopsReportTheirOwnQueueNames(): void
    {
        [$connection, $client] = $this->redisConnection(cluster: true);
        $client->allows('clearLastError')->returns(true);
        $client->allows('eval')->resolves(function (): array {
            usleep(5000);

            return [];
        });

        $queues = [];
        $queue = $this->queueRecordingMigrations($connection, $queues);

        parallel([
            fn (): ?Job => $queue->pop('critical'),
            fn (): ?Job => $queue->pop('low'),
        ]);

        $this->assertEqualsCanonicalizing(['critical', 'critical', 'low', 'low'], $queues);
    }

    public function testFailedPopDoesNotLeakItsQueueNameIntoLaterMigrations(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::create(2026, 1, 1));
        $exception = new RedisException('Connection lost');
        [$connection, $client] = $this->redisConnection();
        $client->allows('getLastError')->returns(null);
        $client->expects('eval')
            ->with(LuaScripts::migrateExpiredJobs(), ['queues:critical:delayed', 'queues:critical', 'queues:critical:notify', $now->getTimestamp(), RedisQueue::DEFAULT_MIGRATION_BATCH_SIZE], 3)
            ->throws($exception);
        $client->expects('eval')
            ->with(LuaScripts::migrateExpiredJobs(), ['queues:low:delayed', 'queues:low', 'queues:low:notify', $now->getTimestamp(), RedisQueue::DEFAULT_MIGRATION_BATCH_SIZE], 3)
            ->returns([]);

        $queues = [];
        $queue = $this->queueRecordingMigrations($connection, $queues);

        try {
            $queue->pop('critical');
            $this->fail('The pop should have failed.');
        } catch (RedisException $caught) {
            $this->assertSame($exception, $caught);
        }

        $queue->migrateExpiredJobs('queues:low:delayed', 'queues:low');

        $this->assertSame(['low'], $queues);
    }

    /**
     * Create a Horizon queue that records the queue name of each migration event.
     */
    private function queueRecordingMigrations(RedisProxy $connection, array &$queues): RedisQueue
    {
        $redis = Double::for(Factory::class)->strict();
        $redis->allows('connection')->with('default')->returns($connection);

        $container = new Container;
        $events = new Dispatcher($container);
        $container->instance(DispatcherContract::class, $events);
        $events->listen(JobsMigrated::class, function (JobsMigrated $event) use (&$queues): void {
            $queues[] = $event->queue;
        });

        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer($container);
        $queue->setConnectionName('redis');

        return $queue;
    }

    /**
     * Record transport payloads while exercising the actual Horizon enqueue lifecycle.
     *
     * @return array{RedisQueue, Dispatcher}
     */
    private function publishingQueue(array &$stored, ?RuntimeException $failure = null): array
    {
        [$connection, , $rawConnection] = $this->redisConnection();
        $rawConnection->expects('evalWithShaCache')->resolves(static function (string $script, array $keys, array $arguments) use (&$stored, $failure): int {
            if ($failure !== null) {
                throw $failure;
            }

            if ($script === LuaScripts::bulk()) {
                for ($index = 1; $index < count($arguments); $index += 2) {
                    $stored[] = $arguments[$index];
                }

                return intdiv(count($arguments), 2);
            }

            $stored[] = $arguments[$script === LuaScripts::later() ? 1 : 0];

            return 1;
        });
        $redis = Double::for(Factory::class)->strict();
        $redis->allows('connection')->with('default')->returns($connection);
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository);
        $events = new Dispatcher($container);
        $container->instance('events', $events);
        $container->instance(DispatcherContract::class, $events);
        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer($container);
        $queue->setConnectionName('redis');

        return [$queue, $events];
    }
}
