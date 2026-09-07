<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateInterval;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Queue\LuaScripts;
use Hypervel\Queue\Queue;
use Hypervel\Queue\RedisQueue;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

class QueueRedisQueueTest extends TestCase
{
    #[DataProvider('totalSizeMethods')]
    public function testTotalsUseQueueSizeOverridesInsidePinnedConnection(string $totalMethod, string $sizeMethod): void
    {
        $pinned = false;
        $connection = m::mock(RedisProxy::class);
        $connection->expects('withPinnedConnection')->andReturnUsing(function (callable $callback) use (&$pinned): int {
            $pinned = true;

            try {
                return $callback();
            } finally {
                $pinned = false;
            }
        });
        $redis = m::mock(Redis::class);
        $redis->expects('connection')->with(null)->andReturn($connection);
        $queue = m::mock(RedisQueue::class, [$redis, 'default'])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $queue->expects('allQueueNames')->andReturnUsing(function () use (&$pinned): Collection {
            $this->assertTrue($pinned);

            return new Collection(['emails', 'reports:high']);
        });
        $queue->shouldReceive($sizeMethod)->twice()->andReturnUsing(function (string $name) use (&$pinned): int {
            $this->assertTrue($pinned);

            return match ($name) {
                'emails' => 5,
                'reports:high' => 7,
            };
        });

        $this->assertSame(12, $queue->{$totalMethod}());
    }

    /**
     * Provide aggregate methods and their per-queue extension points.
     */
    public static function totalSizeMethods(): array
    {
        return [
            'all jobs' => ['totalSize', 'size'],
            'pending jobs' => ['totalPendingSize', 'pendingSize'],
            'delayed jobs' => ['totalDelayedSize', 'delayedSize'],
            'reserved jobs' => ['totalReservedSize', 'reservedSize'],
        ];
    }

    public function testBulkUsesOneLuaCallAndHonorsJobDelays(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $connection = $this->mockRedisProxyWithShaCache();
        $connection->shouldReceive('isCluster')->once()->andReturnFalse();
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->withArgs(function (string $script, array $keys, array $arguments): bool {
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
            })
            ->andReturn(3);
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')->once()->with('default')->andReturn($connection);

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
        $connection = $this->mockRedisProxyWithShaCache();
        $connection->shouldReceive('isCluster')->once()->andReturnTrue();
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->with(
                LuaScripts::bulk(),
                ['queues:{default}', 'queues:{default}:notify', 'queues:{default}:delayed'],
                m::on(static fn (array $arguments): bool => $arguments[0] === 'i'
                    && json_decode($arguments[1], true)['job'] === 'first'
                    && $arguments[2] === 'i'
                    && json_decode($arguments[3], true)['job'] === 'second'),
            )
            ->andReturn(2);
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')->once()->with('default')->andReturn($connection);

        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer(new Container);

        $this->assertNull($queue->bulk(['first', 'second']));
    }

    public function testEmptyBulkDoesNotResolveRedis(): void
    {
        $redis = m::mock(Redis::class);
        $redis->shouldNotReceive('connection');

        $this->assertNull((new RedisQueue($redis, 'default', 'default'))->bulk([]));
    }

    public function testBulkAcceptsDispatchOwnershipOnlyAfterRedisConfirmsEveryJob(): void
    {
        $connection = $this->mockRedisProxyWithShaCache();
        $connection->shouldReceive('isCluster')->once()->andReturnFalse();
        $connection->shouldReceive('evalWithShaCache')->once()->andReturn(2);
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')->once()->with('default')->andReturn($connection);
        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer(new Container);
        $first = new RedisBulkOwnedJob;
        $second = new RedisBulkOwnedJob;
        $cache = m::mock(CacheRepository::class);
        DispatchLockContext::registerDebounce($first, $cache, 'first', 'owner-1');
        DispatchLockContext::registerDebounce($second, $cache, 'second', 'owner-2');

        $queue->bulk([$first, $second]);

        $this->assertFalse(DispatchLockContext::has($first));
        $this->assertFalse(DispatchLockContext::has($second));
    }

    public function testBulkAcceptsEveryDispatchBeforeRaisingSuccessEvents(): void
    {
        $connection = $this->mockRedisProxyWithShaCache();
        $connection->shouldReceive('isCluster')->once()->andReturnFalse();
        $connection->shouldReceive('evalWithShaCache')->once()->andReturn(2);
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')->once()->with('default')->andReturn($connection);
        $queue = new RedisQueue($redis, 'default', 'default');
        $dispatcher = new EventDispatcher($container = new Container);
        $container->instance('events', $dispatcher);
        $queue->setContainer($container);
        $queue->setConnectionName('redis');
        $first = new RedisBulkOwnedJob;
        $second = new RedisBulkOwnedJob;
        $cache = m::mock(CacheRepository::class);
        DispatchLockContext::registerDebounce($first, $cache, 'first', 'owner-1');
        DispatchLockContext::registerDebounce($second, $cache, 'second', 'owner-2');
        $exception = new RuntimeException('Listener failed.');

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
    }

    public function testBulkRetainsDispatchOwnershipWhenRedisDoesNotConfirmEveryJob(): void
    {
        $connection = $this->mockRedisProxyWithShaCache();
        $connection->shouldReceive('isCluster')->once()->andReturnFalse();
        $connection->shouldReceive('evalWithShaCache')->once()->andReturn(1);
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')->once()->with('default')->andReturn($connection);
        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer(new Container);
        $first = new RedisBulkOwnedJob;
        $second = new RedisBulkOwnedJob;
        $cache = m::mock(CacheRepository::class);
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
        $connection = $this->mockRedisProxyWithShaCache();
        $connection->shouldReceive('isCluster')->once()->andReturnFalse();
        $storedJobs = [];
        $connection->shouldReceive('evalWithShaCache')->twice()->andReturnUsing(
            static function (string $script, array $keys, array $arguments) use (&$storedJobs): int {
                $storedJobs[] = json_decode($arguments[1], true)['displayName'];

                return 1;
            }
        );
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')->twice()->with('default')->andReturn($connection);
        $transactions = new DatabaseTransactionsManager;
        $transactions->begin('default', 1);
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $queue = new RedisQueue($redis, 'default', 'default');
        $queue->setContainer($container);
        $deferred = new RedisBulkAfterCommitJob;
        DispatchLockContext::registerDebounce($deferred, m::mock(CacheRepository::class), 'deferred', 'owner');

        $queue->bulk(['immediate', $deferred]);

        $this->assertSame(['immediate'], $storedJobs);
        $this->assertTrue(DispatchLockContext::has($deferred));

        $transactions->commit('default', 1, 0);

        $this->assertSame(['immediate', RedisBulkAfterCommitJob::class], $storedJobs);
        $this->assertFalse(DispatchLockContext::has($deferred));
    }

    public function testPushProperlyPushesJobOntoRedis(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('evalWithShaCache')->once()->with(LuaScripts::push(), ['queues:default', 'queues:default:notify'], [json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => null])]);
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testPushProperlyPushesJobOntoRedisWithCustomPayloadHook(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('evalWithShaCache')->once()->with(LuaScripts::push(), ['queues:default', 'queues:default:notify'], [json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'custom' => 'taylor', 'id' => 'foo', 'attempts' => 0, 'delay' => null])]);
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['custom' => 'taylor'];
        });

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);

        Queue::createPayloadUsing(null);
    }

    public function testJobQueueingAndQueuedEventsAreSkippedWhenNoListenersAreRegistered(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::mock(Container::class));
        $queue->setConnectionName('default');

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('evalWithShaCache')->once()->with(LuaScripts::push(), ['queues:default', 'queues:default:notify'], [json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => null])]);
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobPayloadFinalizing::class)->andReturn(false)->once();
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturn(false)->once();
        $events->shouldReceive('hasListeners')->with(JobQueued::class)->andReturn(false)->once();
        $events->shouldNotReceive('dispatch');

        $container->shouldReceive('bound')->with('events')->andReturn(true)->times(3);
        $container->shouldReceive('make')->with('events')->andReturn($events)->times(3);

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
            ->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])
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

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturnFalse();
        $redisProxy->shouldReceive('evalWithShaCache')->once()->withArgs(
            static fn (string $script, array $keys, array $arguments): bool => $script === LuaScripts::push()
                && $keys === ['queues:default', 'queues:default:notify']
                && json_decode($arguments[0], true)['telemetry'] === 'final',
        );
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

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
            ->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])
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
        $redis->shouldNotReceive('connection');

        $caught = null;

        try {
            $queue->push('foo', ['data']);
        } catch (RuntimeException $caught) {
        }

        $this->assertSame($exception, $caught);
        $this->assertInstanceOf(JobQueueingFailed::class, $failure);
        $this->assertSame($exception, $failure->exception);
    }

    public function testPushRaisesFailedEventWhenRedisThrows(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();
        $exception = new RuntimeException('Redis unavailable.');

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::mock(Container::class));
        $queue->setConnectionName('default');

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturnFalse();
        $redisProxy->shouldReceive('evalWithShaCache')->once()->andThrow($exception);
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobPayloadFinalizing::class)->andReturnFalse()->once();
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturnTrue()->once();
        $events->shouldReceive('hasListeners')->with(JobQueueingFailed::class)->andReturnTrue()->once();
        $events->shouldReceive('dispatch')
            ->withArgs(function (JobQueueing $event) use ($uuid, $now): bool {
                $this->assertSame('foo', $event->job);
                $this->assertSame('default', $event->connectionName);
                $this->assertNull($event->queue);
                $this->assertSame(['uuid' => (string) $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => null], $event->payload());

                return true;
            })
            ->ordered()
            ->once();
        $events->shouldReceive('dispatch')
            ->withArgs(function (JobQueueingFailed $event) use ($exception): bool {
                $this->assertSame('foo', $event->job);
                $this->assertSame('default', $event->connectionName);
                $this->assertNull($event->queue);
                $this->assertNull($event->delay);
                $this->assertSame($exception, $event->exception);

                return true;
            })
            ->ordered()
            ->once();

        $container->shouldReceive('bound')->with('events')->andReturnTrue()->times(3);
        $container->shouldReceive('make')->with('events')->andReturn($events)->times(3);

        $this->expectExceptionObject($exception);

        $queue->push('foo', ['data']);
    }

    public function testPushProperlyPushesJobOntoRedisWithTwoCustomPayloadHook(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('evalWithShaCache')->once()->with(LuaScripts::push(), ['queues:default', 'queues:default:notify'], [json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'custom' => 'taylor', 'bar' => 'foo', 'id' => 'foo', 'attempts' => 0, 'delay' => null])]);
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['custom' => 'taylor'];
        });

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['bar' => 'foo'];
        });

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);

        Queue::createPayloadUsing(null);
    }

    public function testDelayedPushProperlyPushesJobOntoRedis(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $now = CarbonImmutable::now();
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('evalWithShaCache')->once()->with(
            LuaScripts::later(),
            ['queues:default:delayed'],
            [1002, json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 1])]
        );
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $id = $queue->later(1, 'foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testDelayedPushWithDateTimeProperlyPushesJobOntoRedis(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $now = CarbonImmutable::now();
        $uuid = $this->mockUuid();

        $date = CarbonImmutable::createFromTimestampUTC('1001.100000');
        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('evalWithShaCache')->once()->with(
            LuaScripts::later(),
            ['queues:default:delayed'],
            [1002, json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 1])]
        );
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $queue->later($date, 'foo', ['data']);
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testDelayedPushWithIntervalNeverRunsBeforeRequestedLifetime(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));
        $now = CarbonImmutable::now();
        $uuid = $this->mockUuid();
        $delay = new DateInterval('PT1S');

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('evalWithShaCache')->once()->with(
            LuaScripts::later(),
            ['queues:default:delayed'],
            [1002, json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 1])]
        );
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $queue->later($delay, 'foo', ['data']);
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testGetQueueRemainsUnchangedForNonCluster(): void
    {
        $queue = new RedisQueue(m::mock(Redis::class), 'default', 'default');

        $this->assertSame('queues:default', $queue->getQueue(null));
        $this->assertSame('queues:default', $queue->getQueue(''));
        $this->assertSame('queues:0', $queue->getQueue('0'));
        $this->assertSame('queues:emails', $queue->getQueue('emails'));
    }

    public function testGetQueueRemainsUnchangedForCluster(): void
    {
        $queue = new RedisQueue($redis = m::mock(Redis::class), 'default', 'default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->andReturn(true);
        $redis->shouldReceive('connection')->never();

        $this->assertSame('queues:default', $queue->getQueue(null));
        $this->assertSame('queues:default', $queue->getQueue(''));
        $this->assertSame('queues:0', $queue->getQueue('0'));
        $this->assertSame('queues:emails', $queue->getQueue('emails'));
    }

    public function testGetRedisKeyReturnsPlainKeyForNonCluster(): void
    {
        $queue = new TestableRedisQueue($redis = m::mock(Redis::class), 'default', 'default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redis->shouldReceive('connection')->once()->andReturn($redisProxy);

        $this->assertSame('queues:default', $queue->testGetQueueRedisKey(null));
        $this->assertSame('queues:default', $queue->testGetQueueRedisKey(''));
        $this->assertSame('queues:0', $queue->testGetQueueRedisKey('0'));
        $this->assertSame('queues:emails', $queue->testGetQueueRedisKey('emails'));
    }

    public function testGetRedisKeyWrapsWithHashTagsForCluster(): void
    {
        $queue = new TestableRedisQueue($redis = m::mock(Redis::class), 'default', 'default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redis->shouldReceive('connection')->once()->andReturn($redisProxy);

        $this->assertSame('queues:{default}', $queue->testGetQueueRedisKey(null));
        $this->assertSame('queues:{default}', $queue->testGetQueueRedisKey(''));
        $this->assertSame('queues:{0}', $queue->testGetQueueRedisKey('0'));
        $this->assertSame('queues:{emails}', $queue->testGetQueueRedisKey('emails'));
    }

    public function testGetRedisKeyDoesNotDoubleWrapExistingHashTags(): void
    {
        $queue = new TestableRedisQueue($redis = m::mock(Redis::class), '{default}', 'default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redis->shouldReceive('connection')->once()->andReturn($redisProxy);

        $this->assertSame('queues:{default}', $queue->testGetQueueRedisKey(null));
        $this->assertSame('queues:{custom}', $queue->testGetQueueRedisKey('{custom}'));
        $this->assertSame('queues:process-{batch}-results', $queue->testGetQueueRedisKey('process-{batch}-results'));
    }

    public function testGetRedisKeyWrapsInvalidHashTagsOnCluster(): void
    {
        $queue = new TestableRedisQueue($redis = m::mock(Redis::class), 'default', 'default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redis->shouldReceive('connection')->once()->andReturn($redisProxy);

        $this->assertSame('queues:{my{}queue}', $queue->testGetQueueRedisKey('my{}queue'));
        $this->assertSame('queues:{my{broken}', $queue->testGetQueueRedisKey('my{broken'));
        $this->assertSame('queues:{broken}queue}', $queue->testGetQueueRedisKey('broken}queue'));
        $this->assertSame('queues:{foo{}{bar}}', $queue->testGetQueueRedisKey('foo{}{bar}'));
    }

    public function testPushUsesClusterSafeRedisKeyForLuaScript(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)
            ->onlyMethods(['getRandomId'])
            ->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])
            ->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redisProxy->shouldReceive('evalWithShaCache')->once()->with(
            LuaScripts::push(),
            ['queues:{default}', 'queues:{default}:notify'],
            [json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => null])]
        );
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $this->assertSame('foo', $queue->push('foo', ['data']));
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testPushPassesLogicalQueueToPayloadCallbacksOnCluster(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)
            ->onlyMethods(['getRandomId'])
            ->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])
            ->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer(m::spy(Container::class));
        $queue->setConnectionName('default');

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redisProxy->shouldReceive('evalWithShaCache')->once()->andReturn(null);
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $receivedQueue = null;

        Queue::createPayloadUsing(function ($connection, $queue) use (&$receivedQueue) {
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
            ->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])
            ->getMock();
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->expects($this->once())->method('availableAt')->with(1)->willReturn(2);

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldAllowMockingMethod('evalWithShaCache');
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redisProxy->shouldReceive('evalWithShaCache')->once()->with(
            LuaScripts::later(),
            ['queues:{default}:delayed'],
            [2, json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 1])]
        );
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $this->assertSame('foo', $queue->later(1, 'foo', ['data']));
        $container->shouldHaveReceived('bound')->with('events')->times(3);
    }

    public function testSizeUsesClusterSafeRedisKeys(): void
    {
        $queue = new RedisQueue($redis = m::mock(Redis::class), 'default', 'default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::size(),
            3,
            'queues:{default}',
            'queues:{default}:delayed',
            'queues:{default}:reserved'
        )->andReturn(5);
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $this->assertSame(5, $queue->size());
    }

    public function testPopUsesClusterSafeRedisKeys(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $queue = new RedisQueue($redis = m::mock(Redis::class), 'default', 'default');

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::migrateExpiredJobs(),
            3,
            'queues:{default}:delayed',
            'queues:{default}',
            'queues:{default}:notify',
            m::type('int'),
            -1
        )->andReturn([]);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::migrateExpiredJobs(),
            3,
            'queues:{default}:reserved',
            'queues:{default}',
            'queues:{default}:notify',
            m::type('int'),
            -1
        )->andReturn([]);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::pop(),
            3,
            'queues:{default}',
            'queues:{default}:reserved',
            'queues:{default}:notify',
            1061
        )->andReturn([]);
        $redis->shouldReceive('connection')->times(4)->andReturn($redisProxy);

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
                ->setConstructorArgs([m::mock(Redis::class), 'default', 'default'])
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
            ->setConstructorArgs([m::mock(Redis::class), 'default', 'default'])
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
        $queue = new RedisQueue($redis = m::mock(Redis::class), 'default', 'default');
        $job = m::mock(RedisJob::class);
        $job->shouldReceive('getReservedJob')->once()->andReturn('reserved-payload');

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redisProxy->shouldReceive('zrem')->once()->with('queues:{emails}:reserved', 'reserved-payload')->andReturn(1);
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $queue->deleteReserved('emails', $job);
    }

    public function testDeleteAndReleaseUsesClusterSafeRedisKeys(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC('1000.900000'));

        $queue = new RedisQueue($redis = m::mock(Redis::class), 'default', 'default');

        $job = m::mock(RedisJob::class);
        $job->shouldReceive('getReservedJob')->once()->andReturn('reserved-payload');

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::release(),
            2,
            'queues:{emails}:delayed',
            'queues:{emails}:reserved',
            'reserved-payload',
            1031
        );
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $queue->deleteAndRelease('emails', $job, 30);
    }

    public function testClearUsesClusterSafeRedisKeys(): void
    {
        $queue = new RedisQueue($redis = m::mock(Redis::class), 'default', 'default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::clear(),
            4,
            'queues:{default}',
            'queues:{default}:delayed',
            'queues:{default}:reserved',
            'queues:{default}:notify'
        )->andReturn(3);
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $this->assertSame(3, $queue->clear('default'));
    }

    public function testIsClusterConnectionCachesResult(): void
    {
        $queue = new TestableRedisQueue($redis = m::mock(Redis::class), 'default', 'default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redis->shouldReceive('connection')->once()->andReturn($redisProxy);

        $this->assertTrue($queue->testIsClusterConnection());
        $this->assertTrue($queue->testIsClusterConnection());
        $this->assertTrue($queue->testIsClusterConnection());
    }

    protected function mockUuid(): Uuid
    {
        $uuid = Str::uuid();

        Str::createUuidsUsing(fn () => $uuid);

        return $uuid;
    }

    private function mockRedisProxyWithShaCache(): RedisProxy
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldAllowMockingMethod('evalWithShaCache');

        return $connection;
    }
}

class TestableRedisQueue extends RedisQueue
{
    public function testGetQueueRedisKey(?string $queue = null): string
    {
        return $this->getQueueRedisKey($queue);
    }

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
