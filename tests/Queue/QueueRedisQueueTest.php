<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Queue\LuaScripts;
use Hypervel\Queue\Queue;
use Hypervel\Queue\RedisQueue;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 * @coversNothing
 */
class QueueRedisQueueTest extends TestCase
{
    public function testPushProperlyPushesJobOntoRedis()
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('eval')->once()->with(LuaScripts::push(), 2, 'queues:default', 'queues:default:notify', json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => null]));
        $redis->shouldReceive('connection')->once()->andReturn($redisProxy);

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('has')->with(Dispatcher::class)->twice();
    }

    public function testPushProperlyPushesJobOntoRedisWithCustomPayloadHook()
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('eval')->once()->with(LuaScripts::push(), 2, 'queues:default', 'queues:default:notify', json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'custom' => 'taylor', 'id' => 'foo', 'attempts' => 0, 'delay' => null]));
        $redis->shouldReceive('connection')->once()->andReturn($redisProxy);

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['custom' => 'taylor'];
        });

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('has')->with(Dispatcher::class)->twice();

        Queue::createPayloadUsing(null);
    }

    public function testPushProperlyPushesJobOntoRedisWithTwoCustomPayloadHook()
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('eval')->once()->with(LuaScripts::push(), 2, 'queues:default', 'queues:default:notify', json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'custom' => 'taylor', 'bar' => 'foo', 'id' => 'foo', 'attempts' => 0, 'delay' => null]));
        $redis->shouldReceive('connection')->once()->andReturn($redisProxy);

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['custom' => 'taylor'];
        });

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['bar' => 'foo'];
        });

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('has')->with(Dispatcher::class)->twice();

        Queue::createPayloadUsing(null);
    }

    public function testDelayedPushProperlyPushesJobOntoRedis()
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['availableAt', 'getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->expects($this->once())->method('availableAt')->with(1)->willReturn(2);

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::later(),
            1,
            'queues:default:delayed',
            2,
            json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 1])
        );
        $redis->shouldReceive('connection')->once()->andReturn($redisProxy);

        $id = $queue->later(1, 'foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('has')->with(Dispatcher::class)->twice();
    }

    public function testDelayedPushWithDateTimeProperlyPushesJobOntoRedis()
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);
        $uuid = $this->mockUuid();

        $date = Carbon::now();
        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['availableAt', 'getRandomId'])->setConstructorArgs([$redis = m::mock(Redis::class), 'default', 'default'])->getMock();
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->expects($this->once())->method('availableAt')->with($date)->willReturn(5);

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('eval')->once()->with(
            LuaScripts::later(),
            1,
            'queues:default:delayed',
            5,
            json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'createdAt' => $now->getTimestamp(), 'id' => 'foo', 'attempts' => 0, 'delay' => 5])
        );
        $redis->shouldReceive('connection')->once()->andReturn($redisProxy);

        $queue->later($date->addSeconds(5), 'foo', ['data']);
        $container->shouldHaveReceived('has')->with(Dispatcher::class)->twice();
    }

    protected function mockUuid(): Uuid
    {
        $uuid = Str::uuid();

        Str::createUuidsUsing(fn () => $uuid);

        return $uuid;
    }
}
