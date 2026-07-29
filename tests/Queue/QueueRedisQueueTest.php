<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Jobs\RedisJob;
use Hypervel\Queue\LuaScripts;
use Hypervel\Queue\Queue;
use Hypervel\Queue\RedisQueue;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Override;
use Symfony\Component\Uid\Uuid;

class QueueRedisQueueTest extends TestCase
{
    public function testBulkUsesNestedTransactionOnStandaloneRedisAndHonorsJobDelays(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnFalse();
        $connection->shouldReceive('pipeline')
            ->once()
            ->andReturnUsing(static function (callable $callback): array {
                $callback();

                return [];
            });
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static function (callable $callback): array {
                $callback();

                return [];
            });

        $queue = $this->createBulkQueue($connection);
        $queue->bulk([
            new RedisBulkPropertyDelayJob,
            new RedisBulkAttributeDelayJob,
            'plain',
        ], ['data'], 'critical');

        $this->assertSame([
            [4, RedisBulkPropertyDelayJob::class, ['data'], 'critical'],
            [9, RedisBulkAttributeDelayJob::class, ['data'], 'critical'],
        ], $queue->delayed);
        $this->assertSame([
            ['plain', ['data'], 'critical'],
        ], $queue->pushed);
    }

    public function testBulkUsesTransactionWithoutPipelineOnRedisCluster(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnTrue();
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static function (callable $callback): array {
                $callback();

                return [];
            });
        $connection->shouldNotReceive('pipeline');

        $queue = $this->createBulkQueue($connection);
        $queue->bulk(['first', 'second']);

        $this->assertSame([
            ['first', '', null],
            ['second', '', null],
        ], $queue->pushed);
        $this->assertSame([], $queue->delayed);
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
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('eval')->once()->with(LuaScripts::push(), 2, 'queues:default', 'queues:default:notify', json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => null]));
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();
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
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('eval')->once()->with(LuaScripts::push(), 2, 'queues:default', 'queues:default:notify', json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'custom' => 'taylor', 'id' => 'foo', 'attempts' => 0, 'delay' => null]));
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['custom' => 'taylor'];
        });

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();

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
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('eval')->once()->with(LuaScripts::push(), 2, 'queues:default', 'queues:default:notify', json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => null]));
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->with(JobQueueing::class)->andReturn(false)->once();
        $events->shouldReceive('hasListeners')->with(JobQueued::class)->andReturn(false)->once();
        $events->shouldNotReceive('dispatch');

        $container->shouldReceive('bound')->with('events')->andReturn(true)->twice();
        $container->shouldReceive('make')->with('events')->andReturn($events)->twice();

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
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
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('eval')->once()->with(LuaScripts::push(), 2, 'queues:default', 'queues:default:notify', json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'custom' => 'taylor', 'bar' => 'foo', 'id' => 'foo', 'attempts' => 0, 'delay' => null]));
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['custom' => 'taylor'];
        });

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['bar' => 'foo'];
        });

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();

        Queue::createPayloadUsing(null);
    }

    public function testDelayedPushProperlyPushesJobOntoRedis(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['availableAt', 'getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->expects($this->once())->method('availableAt')->with(1)->willReturn(2);

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::later(),
            1,
            'queues:default:delayed',
            2,
            json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 1])
        );
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $id = $queue->later(1, 'foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('bound')->with('events')->twice();
    }

    public function testDelayedPushWithDateTimeProperlyPushesJobOntoRedis(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $uuid = $this->mockUuid();

        $date = CarbonImmutable::now()->addSeconds(5);
        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['availableAt', 'getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->expects($this->once())->method('availableAt')->with($date)->willReturn(5);

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(false);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::later(),
            1,
            'queues:default:delayed',
            5,
            json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 5])
        );
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $queue->later($date, 'foo', ['data']);
        $container->shouldHaveReceived('bound')->with('events')->twice();
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
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::push(),
            2,
            'queues:{default}',
            'queues:{default}:notify',
            json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => null])
        );
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $this->assertSame('foo', $queue->push('foo', ['data']));
        $container->shouldHaveReceived('bound')->with('events')->twice();
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
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redisProxy->shouldReceive('eval')->once()->andReturn(null);
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
        $redisProxy->shouldReceive('isCluster')->once()->andReturn(true);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::later(),
            1,
            'queues:{default}:delayed',
            2,
            json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 1])
        );
        $redis->shouldReceive('connection')->twice()->andReturn($redisProxy);

        $this->assertSame('foo', $queue->later(1, 'foo', ['data']));
        $container->shouldHaveReceived('bound')->with('events')->twice();
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
        $queue = $this->getMockBuilder(RedisQueue::class)
            ->onlyMethods(['availableAt'])
            ->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])
            ->getMock();
        $queue->expects($this->once())->method('availableAt')->with(60)->willReturn(123);

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
            123
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
        $queue = $this->getMockBuilder(RedisQueue::class)
            ->onlyMethods(['availableAt'])
            ->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])
            ->getMock();
        $queue->expects($this->once())->method('availableAt')->with(30)->willReturn(456);

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
            456
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

    private function createBulkQueue(RedisProxy $connection): BulkTestRedisQueue
    {
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')->once()->with('default')->andReturn($connection);

        return new BulkTestRedisQueue($redis, 'default', 'default');
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

class BulkTestRedisQueue extends RedisQueue
{
    public array $pushed = [];

    public array $delayed = [];

    #[Override]
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        $this->pushed[] = [
            is_object($job) ? $job::class : $job,
            $data,
            $queue,
        ];

        return null;
    }

    #[Override]
    public function later(
        DateInterval|DateTimeInterface|int $delay,
        object|string $job,
        mixed $data = '',
        ?string $queue = null,
    ): mixed {
        $this->delayed[] = [
            $delay,
            is_object($job) ? $job::class : $job,
            $data,
            $queue,
        ];

        return null;
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
