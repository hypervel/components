<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Container\Container;
use Hypervel\Queue\LuaScripts;
use Hypervel\Queue\Queue;
use Hypervel\Queue\RedisQueue;
use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\UuidFactoryInterface;
use Ramsey\Uuid\UuidInterface;

/**
 * @internal
 * @coversNothing
 */
class QueueRedisQueueTest extends TestCase
{
    protected function tearDown(): void
    {
        Uuid::setFactory(new UuidFactory());
    }

    public function testPushProperlyPushesJobOntoRedis()
    {
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(RedisFactory::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('eval')->once()->with(LuaScripts::push(), 2, 'queues:default', 'queues:default:notify', json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'id' => 'foo', 'attempts' => 0]));
        $redis->shouldReceive('get')->once()->andReturn($redisProxy);

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('has')->with(EventDispatcherInterface::class)->twice();
    }

    public function testPushProperlyPushesJobOntoRedisWithCustomPayloadHook()
    {
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(RedisFactory::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('eval')->once()->with(LuaScripts::push(), 2, 'queues:default', 'queues:default:notify', json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'custom' => 'taylor', 'id' => 'foo', 'attempts' => 0]));
        $redis->shouldReceive('get')->once()->andReturn($redisProxy);

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['custom' => 'taylor'];
        });

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('has')->with(EventDispatcherInterface::class)->twice();

        Queue::createPayloadUsing(null);
    }

    public function testPushProperlyPushesJobOntoRedisWithTwoCustomPayloadHook()
    {
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['getRandomId'])->setConstructorArgs([$redis = m::mock(RedisFactory::class), 'default', 'default'])->getMock();
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('eval')->once()->with(LuaScripts::push(), 2, 'queues:default', 'queues:default:notify', json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'custom' => 'taylor', 'bar' => 'foo', 'id' => 'foo', 'attempts' => 0]));
        $redis->shouldReceive('get')->once()->andReturn($redisProxy);

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['custom' => 'taylor'];
        });

        Queue::createPayloadUsing(function ($connection, $queue, $payload) {
            return ['bar' => 'foo'];
        });

        $id = $queue->push('foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('has')->with(EventDispatcherInterface::class)->twice();

        Queue::createPayloadUsing(null);
    }

    public function testDelayedPushProperlyPushesJobOntoRedis()
    {
        $uuid = $this->mockUuid();

        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['availableAt', 'getRandomId'])->setConstructorArgs([$redis = m::mock(RedisFactory::class), 'default', 'default'])->getMock();
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->expects($this->once())->method('availableAt')->with(1)->willReturn(2);

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('zadd')->once()->with(
            'queues:default:delayed',
            2,
            json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'id' => 'foo', 'attempts' => 0])
        );
        $redis->shouldReceive('get')->once()->andReturn($redisProxy);

        $id = $queue->later(1, 'foo', ['data']);
        $this->assertSame('foo', $id);
        $container->shouldHaveReceived('has')->with(EventDispatcherInterface::class)->twice();
    }

    public function testDelayedPushWithDateTimeProperlyPushesJobOntoRedis()
    {
        $uuid = $this->mockUuid();

        $date = Carbon::now();
        $queue = $this->getMockBuilder(RedisQueue::class)->onlyMethods(['availableAt', 'getRandomId'])->setConstructorArgs([$redis = m::mock(RedisFactory::class), 'default', 'default'])->getMock();
        $queue->setContainer($container = m::spy(Container::class));
        $queue->setConnectionName('default');
        $queue->expects($this->once())->method('getRandomId')->willReturn('foo');
        $queue->expects($this->once())->method('availableAt')->with($date)->willReturn(2);

        $redisProxy = m::mock(RedisProxy::class);
        $redisProxy->shouldReceive('zadd')->once()->with(
            'queues:default:delayed',
            2,
            json_encode(['uuid' => $uuid, 'displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'data' => ['data'], 'id' => 'foo', 'attempts' => 0])
        );
        $redis->shouldReceive('get')->once()->andReturn($redisProxy);

        $queue->later($date, 'foo', ['data']);
        $container->shouldHaveReceived('has')->with(EventDispatcherInterface::class)->twice();
    }

    protected function mockUuid(): UuidInterface
    {
        $uuid = Str::uuid();

        $uuidFactory = m::mock(UuidFactoryInterface::class);
        $uuidFactory->shouldReceive('uuid4')->andReturn($uuid);
        Uuid::setFactory($uuidFactory);

        return $uuid;
    }
}
