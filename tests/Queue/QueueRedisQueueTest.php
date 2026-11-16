<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateInterval;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingCanceled;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Queue\LuaScripts;
use Hypervel\Queue\Queue;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Queue\RedisQueue;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Tests\Queue\Fixtures\IntegerQueueName;
use Hypervel\Tests\Queue\Fixtures\UnitQueueName;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Uid\Uuid;
use Throwable;

class QueueRedisQueueTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('totalSizeMethods')]
    public function testTotalsCountPhysicalQueuesInsidePinnedConnection(string $totalMethod, string $command, array $firstArguments, array $secondArguments): void
    {
        $routes = new QueueRoutes;
        $routes->forward('emails', 'reports:high');
        Container::getInstance()->instance('queue.routes', $routes);
        $pinned = false;
        $connection = Double::for(QueueRedisProxy::class);
        $connection->expects('withPinnedConnection')->resolves(function (callable $callback) use (&$pinned): int {
            $pinned = true;

            try {
                return $callback();
            } finally {
                $pinned = false;
            }
        });
        $connection->expects('isCluster')->returns(false);
        $connection->expects($command)->with(...$firstArguments)->resolves(function () use (&$pinned): int {
            $this->assertTrue($pinned);

            return 5;
        });
        $connection->expects($command)->with(...$secondArguments)->resolves(function () use (&$pinned): int {
            $this->assertTrue($pinned);

            return 7;
        });
        $redis = Double::for(Redis::class);
        $redis->allows('connection')->with(null)->returns($connection);
        $queue = Double::for(RedisQueue::class)->passthru(new RedisQueue($redis, 'default'));
        $queue->expects('allQueueNames')->resolves(function () use (&$pinned): Collection {
            $this->assertTrue($pinned);

            return new Collection(['emails', 'reports:high']);
        });
        $this->assertSame(12, $queue->{$totalMethod}());
    }

    /**
     * Provide aggregate methods and their physical Redis commands.
     */
    public static function totalSizeMethods(): array
    {
        return [
            'all jobs' => [
                'totalSize', 'eval',
                [LuaScripts::size(), 3, 'queues:emails', 'queues:emails:delayed', 'queues:emails:reserved'],
                [LuaScripts::size(), 3, 'queues:reports:high', 'queues:reports:high:delayed', 'queues:reports:high:reserved'],
            ],
            'pending jobs' => ['totalPendingSize', 'llen', ['queues:emails'], ['queues:reports:high']],
            'delayed jobs' => ['totalDelayedSize', 'zcard', ['queues:emails:delayed'], ['queues:reports:high:delayed']],
            'reserved jobs' => ['totalReservedSize', 'zcard', ['queues:emails:reserved'], ['queues:reports:high:reserved']],
        ];
    }

    public function testBulkRespectsDelayAttributeWhenPushingOntoRedis(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $connection = Double::for(QueueRedisProxy::class);
        $connection->expects('isCluster')->returns(false);
        $connection->expects('evalWithShaCache')
            ->with(Argument::all(function (string $script, array $keys, array $arguments): bool {
                $this->assertSame(LuaScripts::bulk(), $script);
                $this->assertSame([
                    'queues:critical',
                    'queues:critical:notify',
                    'queues:critical:delayed',
                ], $keys);
                $this->assertSame([1005, 1010, 'i'], [$arguments[0], $arguments[2], $arguments[4]]);
                $this->assertSame([
                    RedisBulkPropertyDelayJob::class,
                    RedisBulkAttributeDelayJob::class,
                    'plain',
                ], [
                    json_decode($arguments[1], true)['displayName'],
                    json_decode($arguments[3], true)['displayName'],
                    json_decode($arguments[5], true)['displayName'],
                ]);

                return true;
            }))
            ->returns(3);
        $redis = Double::for(Redis::class);
        $redis->expects('connection')->with('default')->returns($connection);

        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer(new Container);

        $this->assertNull($queue->bulk([
            new RedisBulkPropertyDelayJob,
            new RedisBulkAttributeDelayJob,
            'plain',
        ], ['data'], 'critical'));
    }

    public function testBulkUsesOneClusterSafeLuaCall(): void
    {
        $connection = Double::for(QueueRedisProxy::class);
        $connection->expects('isCluster')->returns(true);
        $connection->expects('evalWithShaCache')
            ->with(
                LuaScripts::bulk(),
                ['queues:{default}', 'queues:{default}:notify', 'queues:{default}:delayed'],
                Argument::satisfies(static fn (array $arguments): bool => $arguments[0] === 'i'
                    && json_decode($arguments[1], true)['job'] === 'first'
                    && $arguments[2] === 'i'
                    && json_decode($arguments[3], true)['job'] === 'second'),
            )
            ->returns(2);
        $redis = Double::for(Redis::class);
        $redis->expects('connection')->with('default')->returns($connection);

        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer(new Container);

        $this->assertNull($queue->bulk(['first', 'second']));
    }

    public function testBulkRedisFailureIsNotReplacedByAFailingFailureListener(): void
    {
        $exception = new RuntimeException('Redis unavailable.');
        $connection = Double::for(QueueRedisProxy::class);
        $connection->expects('isCluster')->returns(false);
        $connection->expects('evalWithShaCache')->throws($exception);
        $redis = Double::for(Redis::class);
        $redis->expects('connection')->with('default')->returns($connection);

        Container::setInstance($container = new Container);
        $events = new EventDispatcher($container);
        $container->instance('events', $events);
        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer($container);
        $queue->setConnectionName('redis');

        $listener = new RuntimeException('Listener failed.');
        $failed = [];
        $events->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event;
        });
        $events->listen(JobQueueingFailed::class, static fn () => throw $listener);
        $handler = Double::for(ExceptionHandler::class);
        $handler->expects('report')->with($listener);
        $container->instance(ExceptionHandler::class, $handler);

        try {
            $queue->bulk(['first', 'second']);
            $this->fail('Expected the Redis failure to be rethrown.');
        } catch (RuntimeException $actual) {
            $this->assertSame($exception, $actual);
        }

        $this->assertSame(['first', 'second'], array_column($failed, 'job'));
        $this->assertSame([true, true], array_column($failed, 'sendAttempted'));
    }

    #[TestWith(['push', false])]
    #[TestWith(['push', true])]
    #[TestWith(['bulk', false])]
    #[TestWith(['bulk', true])]
    public function testCancellationRaisesCanceledEventsInsteadOfFailureEvents(string $method, bool $cleanupThrows): void
    {
        $cancellation = new CanceledException;
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Redis cleanup failed.', previous: $cancellation);
        }
        $connection = Double::for(QueueRedisProxy::class);
        $connection->expects('isCluster')->returns(false);
        $connection->expects('evalWithShaCache')->throws($cancellation);
        $redis = Double::for(Redis::class);
        $redis->allows('connection')->with('default')->returns($connection);

        Container::setInstance($container = new Container);
        $events = new EventDispatcher($container);
        $container->instance('events', $events);
        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer($container);
        $queue->setConnectionName('redis');
        $failed = 0;
        $events->listen(JobQueueingFailed::class, static function () use (&$failed): void {
            ++$failed;
        });
        $canceled = [];
        $events->listen(JobQueueingCanceled::class, static function (JobQueueingCanceled $event) use (&$canceled): void {
            $canceled[] = $event;
        });

        try {
            $method === 'bulk' ? $queue->bulk(['first', 'second']) : $queue->push('first');
            $this->fail('Expected the cancellation to propagate.');
        } catch (Throwable $actual) {
            $this->assertSame($cancellation, $actual);
        }

        $this->assertSame(0, $failed);
        $this->assertSame($method === 'bulk' ? ['first', 'second'] : ['first'], array_column($canceled, 'job'));

        foreach ($canceled as $event) {
            $this->assertSame($cancellation, $event->cancellation);
            $this->assertTrue($event->sendAttempted);
            $this->assertFalse($event->queued);
        }
    }

    public function testEmptyBulkDoesNotResolveRedis(): void
    {
        $redis = Double::for(Redis::class);
        $redis->expects('connection')->never();

        $this->assertNull((new RedisQueue($redis, 'default', 'default'))->bulk([]));
    }

    public function testBulkAcceptsDispatchOwnershipOnlyAfterRedisConfirmsEveryJob(): void
    {
        $connection = Double::for(QueueRedisProxy::class);
        $connection->expects('isCluster')->returns(false);
        $connection->expects('evalWithShaCache')->returns(2);
        $redis = Double::for(Redis::class);
        $redis->expects('connection')->with('default')->returns($connection);
        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer(new Container);
        $first = new RedisBulkOwnedJob;
        $second = new RedisBulkOwnedJob;
        $cache = Double::for(CacheRepository::class);
        DispatchLockContext::registerDebounce($first, $cache, 'first', 'owner-1');
        DispatchLockContext::registerDebounce($second, $cache, 'second', 'owner-2');

        $queue->bulk([$first, $second]);

        $this->assertFalse(DispatchLockContext::has($first));
        $this->assertFalse(DispatchLockContext::has($second));
    }

    public function testBulkAcceptsEveryDispatchBeforeRaisingSuccessEvents(): void
    {
        $connection = Double::for(QueueRedisProxy::class);
        $connection->expects('isCluster')->returns(false);
        $connection->expects('evalWithShaCache')->returns(2);
        $redis = Double::for(Redis::class);
        $redis->expects('connection')->with('default')->returns($connection);
        $queue = new RedisQueue($redis, 'default', 'default');
        $dispatcher = new EventDispatcher($container = new Container);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $queue->setConnectionName('redis');
        $first = new RedisBulkOwnedJob;
        $second = new RedisBulkOwnedJob;
        $cache = Double::for(CacheRepository::class);
        DispatchLockContext::registerDebounce($first, $cache, 'first', 'owner-1');
        DispatchLockContext::registerDebounce($second, $cache, 'second', 'owner-2');
        $exception = new RuntimeException('Listener failed.');
        $notified = [];

        $dispatcher->listen(JobQueued::class, static function (JobQueued $event) use (&$notified): void {
            $notified[] = $event->job;
        });
        $dispatcher->listen(JobQueued::class, static function () use ($exception): never {
            throw $exception;
        });

        try {
            $queue->bulk([$first, $second]);
            $this->fail('Expected the success listener to fail.');
        } catch (RuntimeException $actual) {
            $this->assertSame($exception, $actual);
        }

        $this->assertFalse(DispatchLockContext::has($first));
        $this->assertFalse(DispatchLockContext::has($second));
        $this->assertSame([$first, $second], $notified);
    }

    public function testBulkRetainsDispatchOwnershipWhenRedisDoesNotConfirmEveryJob(): void
    {
        $connection = Double::for(QueueRedisProxy::class);
        $connection->expects('isCluster')->returns(false);
        $connection->expects('evalWithShaCache')->returns(1);
        $redis = Double::for(Redis::class);
        $redis->expects('connection')->with('default')->returns($connection);
        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer(new Container);
        $first = new RedisBulkOwnedJob;
        $second = new RedisBulkOwnedJob;
        $cache = Double::for(CacheRepository::class);
        DispatchLockContext::registerDebounce($first, $cache, 'first', 'owner-1');
        DispatchLockContext::registerDebounce($second, $cache, 'second', 'owner-2');
        $caught = null;

        try {
            $queue->bulk([$first, $second]);
        } catch (RuntimeException $caught) {
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('Redis did not confirm every queued job in the batch.', $caught->getMessage());
        $this->assertTrue(DispatchLockContext::has($first));
        $this->assertTrue(DispatchLockContext::has($second));
    }

    public function testBulkDefersAfterCommitMembersWithoutLosingOwnership(): void
    {
        $connection = Double::for(QueueRedisProxy::class);
        $connection->expects('isCluster')->returns(false);
        $storedJobs = [];
        $connection->expects('evalWithShaCache')->times(2)->resolves(
            static function (string $script, array $keys, array $arguments) use (&$storedJobs): int {
                $storedJobs[] = json_decode($arguments[1], true)['displayName'];

                return 1;
            }
        );
        $redis = Double::for(Redis::class);
        $redis->expects('connection')->times(2)->with('default')->returns($connection);
        $transactions = new DatabaseTransactionsManager;
        $transactions->begin('default', 1);
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer($container);
        $deferred = new RedisBulkAfterCommitJob;
        DispatchLockContext::registerDebounce($deferred, Double::for(CacheRepository::class), 'deferred', 'owner');

        $queue->bulk(['immediate', $deferred]);

        $this->assertSame(['immediate'], $storedJobs);
        $this->assertTrue(DispatchLockContext::has($deferred));

        $transactions->commit('default', 1, 0);

        $this->assertSame(['immediate', RedisBulkAfterCommitJob::class], $storedJobs);
        $this->assertFalse(DispatchLockContext::has($deferred));
    }

    public function testJobQueueingAndQueuedEventsAreSkippedWhenNoListenersAreRegistered(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = Double::for(Redis::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $queue->setContainer($container->instance());
        $queue->setConnectionName('default');

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(false);
        $redisProxy->expects('evalWithShaCache')->with(LuaScripts::push(), ['queues:default', 'queues:default:notify'], [json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'stringJob' => true, 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => null])]);
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $events = Double::for(Dispatcher::class);
        $events->expects('hasListeners')->with(JobPayloadFinalizing::class)->returns(false);
        $events->expects('hasListeners')->with(JobQueueing::class)->returns(false);
        $events->expects('hasListeners')->with(JobQueued::class)->returns(false);
        $events->expects('dispatch')->never();

        $container->allows('bound')->with('queue.routes')->returns(false);
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('bound')->with('events')->returns(true)->times(3);
        $container->expects('make')->with('events')->returns($events)->times(3);

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
    }

    public function testFinalPayloadListenerMutatesTheBrokerAndTerminalPayload(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();
        $queue = $this->getMockBuilder(RedisQueue::class)
            ->onlyMethods(['getRandomId'])
            ->setConstructorArgs([$redis = Double::for(Redis::class), 'default', 'default'])
            ->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('job-id');
        $dispatcher = new EventDispatcher($container = new Container);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $queue->setConnectionName('redis');
        $events = [];

        $dispatcher->listen(JobPayloadFinalizing::class, static function (JobPayloadFinalizing $event) use (&$events): void {
            $events[] = $event;
            $payload = $event->payload();
            $payload['telemetry'] = 'final';
            $event->payload = json_encode($payload, JSON_THROW_ON_ERROR);
        });
        $dispatcher->listen(JobQueueing::class, static function (JobQueueing $event) use (&$events): void {
            $events[] = $event;
        });
        $dispatcher->listen(JobQueued::class, static function (JobQueued $event) use (&$events): void {
            $events[] = $event;
        });

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(false);
        $redisProxy->expects('evalWithShaCache')->with(Argument::all(
            static fn (string $script, array $keys, array $arguments): bool => $script === LuaScripts::push()
                && $keys === ['queues:default', 'queues:default:notify']
                && json_decode($arguments[0], true)['telemetry'] === 'final',
        ));
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $this->assertSame('job-id', $queue->push('foo', ['data']));
        $this->assertSame([
            JobPayloadFinalizing::class,
            JobQueueing::class,
            JobQueued::class,
        ], array_map(static fn (object $event): string => $event::class, $events));
        $this->assertSame('final', $events[1]->payload()['telemetry']);
        $this->assertSame('final', $events[2]->payload()['telemetry']);
        $this->assertSame((string) $uuid, $events[2]->payload()['uuid']);
    }

    public function testFinalPayloadListenerFailureRaisesFailureBeforeQueueingAndBrokerAccess(): void
    {
        $exception = new RuntimeException('Unable to finalize payload.');
        $queue = $this->getMockBuilder(RedisQueue::class)
            ->onlyMethods(['getRandomId'])
            ->setConstructorArgs([$redis = Double::for(Redis::class), 'default', 'default'])
            ->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('job-id');
        $dispatcher = new EventDispatcher($container = new Container);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $queue->setConnectionName('redis');

        $dispatcher->listen(JobPayloadFinalizing::class, static function () use ($exception): never {
            throw $exception;
        });
        $dispatcher->listen(JobQueueing::class, function (): void {
            $this->fail('JobQueueing must not be dispatched after finalization fails.');
        });
        $failure = null;
        $dispatcher->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failure): void {
            $failure = $event;
        });
        $redis->expects('connection')->never();

        $caught = null;

        try {
            $queue->push('foo', ['data']);
        } catch (RuntimeException $caught) {
        }

        $this->assertSame($exception, $caught);
        $this->assertInstanceOf(JobQueueingFailed::class, $failure);
        $this->assertSame($exception, $failure->exception);
        $this->assertFalse($failure->sendAttempted);
    }

    public function testPushRaisesFailedEventWhenRedisThrows(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();
        $exception = new RuntimeException('Redis unavailable.');

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = Double::for(Redis::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $queue->setContainer($container->instance());
        $queue->setConnectionName('default');

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(false);
        $redisProxy->expects('evalWithShaCache')->throws($exception);
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $events = Double::for(Dispatcher::class);
        $events->expects('hasListeners')->with(JobPayloadFinalizing::class)->returns(false);
        $events->expects('hasListeners')->with(JobQueueing::class)->returns(true);
        $events->expects('hasListeners')->with(JobQueueingFailed::class)->returns(true);
        $events->expects('dispatch')
            ->with(Argument::type(JobQueueing::class))
            ->resolves(function (JobQueueing $event) use ($uuid, $now): void {
                $this->assertSame('foo', $event->job);
                $this->assertSame('default', $event->connectionName);
                $this->assertNull($event->queue);
                $this->assertSame(['uuid' => (string) $uuid, 'displayName' => 'foo', 'job' => 'foo', 'stringJob' => true, 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => null], $event->payload());
            })
            ->ordered();
        $events->expects('dispatch')
            ->with(Argument::type(JobQueueingFailed::class))
            ->resolves(function (JobQueueingFailed $event) use ($exception): void {
                $this->assertSame('foo', $event->job);
                $this->assertSame('default', $event->connectionName);
                $this->assertNull($event->queue);
                $this->assertNull($event->delay);
                $this->assertSame($exception, $event->exception);
                $this->assertTrue($event->sendAttempted);
            })
            ->ordered();

        $container->allows('bound')->with('queue.routes')->returns(false);
        $container->allows('make')->with(QueueRoutes::class)->returns(new QueueRoutes);
        $container->expects('bound')->with('events')->returns(true)->times(3);
        $container->expects('make')->with('events')->returns($events)->times(3);

        $this->expectExceptionObject($exception);

        $queue->push('foo', ['data']);
    }

    public function testDelayedPushRoundsUpFractionalDeadlines(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $now = CarbonImmutable::now();
        $uuid = $this->mockUuid();

        $redis = Double::for(Redis::class);
        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis, 'default', 'default'])->getMock();
        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $queue->setContainer($container->instance());
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(false);
        $redisProxy->expects('evalWithShaCache')->with(
            LuaScripts::later(),
            ['queues:default:delayed'],
            [1002, json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'stringJob' => true, 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 1])]
        );
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $id = $queue->later(1, 'foo', ['data']);
        $this->assertSame('foo', $id);
        $container->received('bound')->with('events')->times(3);
    }

    public function testDelayedDateTimePushRoundsUpFractionalDeadlines(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $now = CarbonImmutable::now();
        $uuid = $this->mockUuid();

        $date = CarbonImmutable::createFromTimestampUTC('1001.100000');
        $redis = Double::for(Redis::class);
        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis, 'default', 'default'])->getMock();
        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $queue->setContainer($container->instance());
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(false);
        $redisProxy->expects('evalWithShaCache')->with(
            LuaScripts::later(),
            ['queues:default:delayed'],
            [1002, json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'stringJob' => true, 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 1])]
        );
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $queue->later($date, 'foo', ['data']);
        $container->received('bound')->with('events')->times(3);
    }

    public function testDelayedPushWithIntervalNeverRunsBeforeRequestedLifetime(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $now = CarbonImmutable::now();
        $uuid = $this->mockUuid();
        $delay = new DateInterval('PT1S');

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = Double::for(Redis::class), 'default', 'default'])->getMock();
        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $queue->setContainer($container->instance());
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(false);
        $redisProxy->expects('evalWithShaCache')->with(
            LuaScripts::later(),
            ['queues:default:delayed'],
            [1002, json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'stringJob' => true, 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 1])]
        );
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $queue->later($delay, 'foo', ['data']);
        $container->received('bound')->with('events')->times(3);
    }

    public function testGetQueueRemainsUnchangedForNonCluster(): void
    {
        $queue = new RedisQueue(Double::for(Redis::class), 'default', 'default');

        $this->assertSame('queues:default', $queue->getQueue(null));
        $this->assertSame('queues:default', $queue->getQueue(''));
        $this->assertSame('queues:0', $queue->getQueue('0'));
        $this->assertSame('queues:emails', $queue->getQueue('emails'));
        $this->assertSame('queues:emails', $queue->getQueue(RedisQueueName::Emails));
        $this->assertSame('queues:Emails', $queue->getQueue(UnitQueueName::Emails));
        $this->assertSame('queues:0', $queue->getQueue(IntegerQueueName::Zero));
    }

    public function testGetQueueRemainsUnchangedForCluster(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new RedisQueue($redis, 'default', 'default');
        $redis->expects('connection')->never();

        // getQueue() does not add hash tags.
        $this->assertSame('queues:default', $queue->getQueue(null));
        $this->assertSame('queues:default', $queue->getQueue(''));
        $this->assertSame('queues:0', $queue->getQueue('0'));
        $this->assertSame('queues:emails', $queue->getQueue('emails'));
    }

    public function testGetRedisKeyReturnsPlainKeyForNonCluster(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new TestableRedisQueue($redis, 'default', 'default');
        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(false);
        $redis->expects('connection')->returns($redisProxy);

        $this->assertSame('queues:default', $queue->testGetQueueRedisKey(null));
        $this->assertSame('queues:default', $queue->testGetQueueRedisKey(''));
        $this->assertSame('queues:0', $queue->testGetQueueRedisKey('0'));
        $this->assertSame('queues:emails', $queue->testGetQueueRedisKey('emails'));
    }

    public function testGetRedisKeyWrapsWithHashTagsForPhpRedisCluster(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new TestableRedisQueue($redis, 'default', 'default');
        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redis->expects('connection')->returns($redisProxy);

        $this->assertSame('queues:{default}', $queue->testGetQueueRedisKey(null));
        $this->assertSame('queues:{default}', $queue->testGetQueueRedisKey(''));
        $this->assertSame('queues:{0}', $queue->testGetQueueRedisKey('0'));
        $this->assertSame('queues:{emails}', $queue->testGetQueueRedisKey('emails'));
    }

    // REMOVED: Predis cluster coverage. Hypervel uses phpredis exclusively.

    public function testGetRedisKeyDoesNotDoubleWrapExistingHashTags(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new TestableRedisQueue($redis, '{default}', 'default');
        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redis->expects('connection')->returns($redisProxy);

        $this->assertSame('queues:{default}', $queue->testGetQueueRedisKey(null));
        $this->assertSame('queues:{custom}', $queue->testGetQueueRedisKey('{custom}'));
    }

    public function testGetRedisKeySkipsWrappingWhenQueueNameContainsBraces(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new TestableRedisQueue($redis, 'default', 'default');
        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redis->expects('connection')->returns($redisProxy);

        // Queue name already contains hash tags — skip wrapping.
        $this->assertSame('queues:process-{batch}-results', $queue->testGetQueueRedisKey('process-{batch}-results'));
    }

    public function testGetRedisKeyWrapsEmptyHashTagOnCluster(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new TestableRedisQueue($redis, 'default', 'default');
        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redis->expects('connection')->returns($redisProxy);

        // Empty braces '{}' are not a valid hash tag — still wrap the name.
        $this->assertSame('queues:{my{}queue}', $queue->testGetQueueRedisKey('my{}queue'));
    }

    public function testGetRedisKeyWrapsUnmatchedOpeningBrace(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new TestableRedisQueue($redis, 'default', 'default');
        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redis->expects('connection')->returns($redisProxy);

        // An unmatched '{' is not a valid hash tag — still wrap the name.
        $this->assertSame('queues:{my{broken}', $queue->testGetQueueRedisKey('my{broken'));
    }

    public function testGetRedisKeyWrapsUnmatchedClosingBrace(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new TestableRedisQueue($redis, 'default', 'default');
        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redis->expects('connection')->returns($redisProxy);

        // An unmatched '}' is not a valid hash tag — still wrap the name.
        $this->assertSame('queues:{broken}queue}', $queue->testGetQueueRedisKey('broken}queue'));
    }

    public function testGetRedisKeyWrapsEmptyFirstHashTagFollowedByValidPair(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new TestableRedisQueue($redis, 'default', 'default');
        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redis->expects('connection')->returns($redisProxy);

        // Redis hashes the whole key when the first '{}' is empty, even if a
        // later pair is valid. Wrap the name to keep related keys in one slot.
        $this->assertSame('queues:{foo{}{bar}}', $queue->testGetQueueRedisKey('foo{}{bar}'));
    }

    public function testPushUsesGetRedisKeyForLuaScript(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();

        $redis = Double::for(Redis::class);
        $queue = $this->getMockBuilder(RedisQueue::class)
            ->onlyMethods(['getRandomId'])
            ->setConstructorArgs([$redis, 'default', 'default'])
            ->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $queue->setContainer($container->instance());
        $queue->setConnectionName('default');

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redisProxy->expects('evalWithShaCache')->with(
            LuaScripts::push(),
            ['queues:{default}', 'queues:{default}:notify'],
            [json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'stringJob' => true, 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => null])]
        );
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $this->assertSame('foo', $queue->push('foo', ['data']));
        $container->received('bound')->with('events')->times(3);
    }

    public function testPushPassesUnchangedQueueToCreatePayload(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $this->mockUuid();

        $redis = Double::for(Redis::class);
        $queue = $this->getMockBuilder(RedisQueue::class)
            ->onlyMethods(['getRandomId'])
            ->setConstructorArgs([$redis, 'default', 'default'])
            ->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer(new Container);
        $queue->setConnectionName('default');

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redisProxy->expects('evalWithShaCache')->returns(null);
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $receivedQueue = null;

        Queue::createPayloadUsing(function (string $connection, ?string $queue) use (&$receivedQueue): array {
            $receivedQueue = $queue;

            return [];
        });

        try {
            $queue->push('foo', ['data']);
        } finally {
            Queue::createPayloadUsing(null);
        }

        $this->assertSame('queues:default', $receivedQueue);
    }

    public function testLaterUsesClusterSafeRedisKeyForDelayedSet(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)
            ->onlyMethods(['availableAt', 'getRandomId'])
            ->setConstructorArgs([$redis = Double::for(Redis::class), 'default', 'default'])
            ->getMock();
        $container = Double::for(Container::class, override: true)->passthru(new Container);
        $queue->setContainer($container->instance());
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->expects($this->once())->method('availableAt')->with(1)->willReturn(2);

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redisProxy->expects('evalWithShaCache')->with(
            LuaScripts::later(),
            ['queues:{default}:delayed'],
            [2, json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'stringJob' => true, 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 1])]
        );
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $this->assertSame('foo', $queue->later(1, 'foo', ['data']));
        $container->received('bound')->with('events')->times(3);
    }

    public function testSizeUsesGetRedisKeyOnCluster(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new RedisQueue($redis, 'default', 'default');
        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redisProxy->expects('eval')->with(
            LuaScripts::size(),
            3,
            'queues:{default}',
            'queues:{default}:delayed',
            'queues:{default}:reserved'
        )->returns(5);
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $this->assertSame(5, $queue->size());
    }

    public function testPopUsesClusterSafeRedisKeys(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $queue = new RedisQueue($redis = Double::for(Redis::class), 'default', 'default');

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redisProxy->expects('eval')->with(
            LuaScripts::migrateExpiredJobs(),
            3,
            'queues:{default}:delayed',
            'queues:{default}',
            'queues:{default}:notify',
            Argument::type('int'),
            -1
        )->returns([]);
        $redisProxy->expects('eval')->with(
            LuaScripts::migrateExpiredJobs(),
            3,
            'queues:{default}:reserved',
            'queues:{default}',
            'queues:{default}:notify',
            Argument::type('int'),
            -1
        )->returns([]);
        $redisProxy->expects('eval')->with(
            LuaScripts::pop(),
            3,
            'queues:{default}',
            'queues:{default}:reserved',
            'queues:{default}:notify',
            1061
        )->returns([]);
        $redis->expects('connection')->times(4)->returns($redisProxy);

        $this->assertNull($queue->pop());
    }

    public function testPoppedJobPreservesZeroQueueAndDefaultsEmptyQueue(): void
    {
        $payload = json_encode([
            'id' => 'job-id',
            'job' => 'job',
            'attempts' => 0,
            'data' => [],
        ], JSON_THROW_ON_ERROR);

        foreach ([['0', '0'], ['', 'default']] as [$requested, $expected]) {
            $queue = $this->getMockBuilder(RedisQueue::class)
                ->onlyMethods(['getQueueRedisKey', 'migrate', 'retrieveNextJob'])
                ->setConstructorArgs([Double::for(Redis::class), 'default', 'default'])
                ->getMock();
            $queue->setContainer(new Container);
            $queue->setConnectionName('redis');
            $queue->expects($this->once())->method('getQueueRedisKey')->with($requested)->willReturn("queues:{$expected}");
            $queue->expects($this->once())->method('migrate')->with("queues:{$expected}");
            $queue->expects($this->once())->method('retrieveNextJob')->with("queues:{$expected}", true)->willReturn([$payload, $payload, 1]);

            $job = $queue->pop($requested);

            $this->assertInstanceOf(RedisJob::class, $job);
            $this->assertSame($expected, $job->getQueue());
        }
    }

    public function testPoppedRawZeroIsNotMistakenForAnEmptyQueue(): void
    {
        $queue = $this->getMockBuilder(RedisQueue::class)
            ->onlyMethods(['getQueueRedisKey', 'migrate', 'retrieveNextJob'])
            ->setConstructorArgs([Double::for(Redis::class), 'default', 'default'])
            ->getMock();
        $queue->setContainer(new Container);
        $queue->setConnectionName('redis');
        $queue->expects($this->once())->method('getQueueRedisKey')->with(null)->willReturn('queues:default');
        $queue->expects($this->once())->method('migrate')->with('queues:default');
        $queue->expects($this->once())->method('retrieveNextJob')->with('queues:default', true)->willReturn(['0', '0', false]);

        $job = $queue->pop();

        $this->assertInstanceOf(RedisJob::class, $job);
        $this->assertSame('0', $job->getRawBody());
        $this->assertSame(1, $job->attempts());
    }

    public function testDeleteReservedUsesClusterSafeRedisKey(): void
    {
        $queue = new RedisQueue($redis = Double::for(Redis::class), 'default', 'default');
        $job = Double::for(RedisJob::class);
        $job->expects('getReservedJob')->returns('reserved-payload');

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redisProxy->expects('zrem')->with('queues:{emails}:reserved', 'reserved-payload')->returns(1);
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $queue->deleteReserved('emails', $job);
    }

    public function testDeleteAndReleaseUsesClusterSafeRedisKeys(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $queue = new RedisQueue($redis = Double::for(Redis::class), 'default', 'default');

        $job = Double::for(RedisJob::class);
        $job->expects('getReservedJob')->returns('reserved-payload');

        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redisProxy->expects('eval')->with(
            LuaScripts::release(),
            2,
            'queues:{emails}:delayed',
            'queues:{emails}:reserved',
            'reserved-payload',
            1031
        );
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $queue->deleteAndRelease('emails', $job, 30);
    }

    public function testClearUsesGetRedisKeyOnCluster(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new RedisQueue($redis, 'default', 'default');
        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redisProxy->expects('eval')->with(
            LuaScripts::clear(),
            4,
            'queues:{default}',
            'queues:{default}:delayed',
            'queues:{default}:reserved',
            'queues:{default}:notify'
        )->returns(3);
        $redis->expects('connection')->times(2)->returns($redisProxy);

        $this->assertSame(3, $queue->clear('default'));
    }

    public function testIsClusterConnectionCachesResult(): void
    {
        $redis = Double::for(Redis::class);
        $queue = new TestableRedisQueue($redis, 'default', 'default');
        $redisProxy = Double::for(QueueRedisProxy::class);
        $redisProxy->expects('isCluster')->returns(true);
        $redis->expects('connection')->returns($redisProxy);

        $this->assertTrue($queue->testIsClusterConnection());
        $this->assertTrue($queue->testIsClusterConnection());
        $this->assertTrue($queue->testIsClusterConnection());
    }

    public function testSizeResolvesTheQueueNameFromAnEnum(): void
    {
        $redis = Double::for(Redis::class);
        $connection = Double::for(QueueRedisProxy::class);
        $redis->expects('connection')->times(2)->with(null)->returns($connection);
        $connection->expects('isCluster')->returns(false);
        $connection->expects('eval')->with(
            LuaScripts::size(),
            3,
            'queues:emails',
            'queues:emails:delayed',
            'queues:emails:reserved'
        )->returns(5);
        $queue = new RedisQueue($redis, 'default');

        $this->assertSame(5, $queue->size(RedisQueueName::Emails));
    }

    /**
     * Fix the UUID used by queue payloads.
     */
    protected function mockUuid(): Uuid
    {
        $uuid = Str::uuid();

        Str::createUuidsUsing(fn (): Uuid => $uuid);

        return $uuid;
    }
}

enum RedisQueueName: string
{
    case Emails = 'emails';
}

class TestableRedisQueue extends RedisQueue
{
    /**
     * Expose the physical Redis queue key.
     */
    public function testGetQueueRedisKey(?string $queue = null): string
    {
        return $this->getQueueRedisKey($queue);
    }

    /**
     * Expose the cached connection topology.
     */
    public function testIsClusterConnection(): bool
    {
        return $this->isClusterConnection();
    }
}

class RedisBulkPropertyDelayJob
{
    public int $delay = 4;
}

#[Delay(9)]
class RedisBulkAttributeDelayJob
{
}

class RedisBulkOwnedJob
{
}

class RedisBulkAfterCommitJob implements ShouldQueueAfterCommit
{
}

/**
 * Declare the dynamic Redis commands exercised by the queue tests for native doubles.
 */
abstract class QueueRedisProxy extends RedisProxy
{
    /**
     * Execute a cached Lua script.
     */
    abstract public function evalWithShaCache(string $script, array $keys = [], array $arguments = []): mixed;

    /**
     * Execute a Lua script with separate keys and arguments.
     */
    abstract public function eval(string $script, int $numberOfKeys, mixed ...$arguments): mixed;

    /**
     * Get the number of list members.
     */
    abstract public function llen(string $key): int;

    /**
     * Get the number of sorted-set members.
     */
    abstract public function zcard(string $key): int;

    /**
     * Remove a sorted-set member.
     */
    abstract public function zrem(string $key, string $member): int;
}
