<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core;

use FriendsOfHyperf\Redis\Subscriber\Exception\SocketException;
use FriendsOfHyperf\Redis\Subscriber\Subscriber as RedisSubscriber;
use Hypervel\Redis\Subscriber;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

/**
 * @internal
 * @covers \Hypervel\Redis\Subscriber
 */
class RedisSubscriberTest extends TestCase
{
    public function testSubscribe()
    {
        // Arrange
        $config = ['host' => 'localhost'];
        $channels = ['channel1', 'channel2'];
        $mockRedisSubscriber = m::mock(RedisSubscriber::class);
        $mockChannel = m::mock('stdClass');

        // Set up the channel to return a message once and then null
        $message = new stdClass();
        $message->payload = 'test-payload';
        $message->channel = 'channel1';

        $mockChannel->shouldReceive('pop')
            ->once()
            ->andReturn($message);
        $mockChannel->shouldReceive('pop')
            ->once()
            ->andReturnNull();

        $mockRedisSubscriber->shouldReceive('subscribe')
            ->once()
            ->with(...$channels);

        $mockRedisSubscriber->shouldReceive('channel')
            ->twice()
            ->andReturn($mockChannel);

        $mockRedisSubscriber->closed = true;

        $subscriber = new Subscriber($config, $mockRedisSubscriber);

        $callbackCalled = false;
        $callback = function ($payload, $channel) use (&$callbackCalled, $message) {
            $callbackCalled = true;
            $this->assertEquals($message->payload, $payload);
            $this->assertEquals($message->channel, $channel);
        };

        // Act
        $subscriber->subscribe($channels, $callback);

        // Assert
        $this->assertTrue($callbackCalled);
    }

    public function testSubscribeThrowsExceptionWhenConnectionClosedAbnormally()
    {
        // Arrange
        $config = ['host' => 'localhost'];
        $channels = ['channel1'];
        $mockRedisSubscriber = m::mock(RedisSubscriber::class);
        $mockChannel = m::mock('stdClass');

        $mockChannel->shouldReceive('pop')
            ->once()
            ->andReturnNull();

        $mockRedisSubscriber->shouldReceive('subscribe')
            ->once()
            ->with(...$channels);

        $mockRedisSubscriber->shouldReceive('channel')
            ->once()
            ->andReturn($mockChannel);

        $mockRedisSubscriber->closed = false;

        $subscriber = new Subscriber($config, $mockRedisSubscriber);

        $callback = function () {
            // Empty callback
        };

        // Assert & Act
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Redis connection is disconnected abnormally.');

        $subscriber->subscribe($channels, $callback);
    }

    public function testPsubscribe()
    {
        // Arrange
        $config = ['host' => 'localhost'];
        $patterns = ['channel*'];
        $mockRedisSubscriber = m::mock(RedisSubscriber::class);
        $mockChannel = m::mock('stdClass');

        // Set up the channel to return a message once and then null
        $message = new stdClass();
        $message->payload = 'test-payload';
        $message->channel = 'channel1';

        $mockChannel->shouldReceive('pop')
            ->once()
            ->andReturn($message);
        $mockChannel->shouldReceive('pop')
            ->once()
            ->andReturnNull();

        $mockRedisSubscriber->shouldReceive('psubscribe')
            ->once()
            ->with(...$patterns);

        $mockRedisSubscriber->shouldReceive('channel')
            ->twice()
            ->andReturn($mockChannel);

        $mockRedisSubscriber->closed = true;

        $subscriber = new Subscriber($config, $mockRedisSubscriber);

        $callbackCalled = false;
        $callback = function ($payload, $channel) use (&$callbackCalled, $message) {
            $callbackCalled = true;
            $this->assertEquals($message->payload, $payload);
            $this->assertEquals($message->channel, $channel);
        };

        // Act
        $subscriber->psubscribe($patterns, $callback);

        // Assert
        $this->assertTrue($callbackCalled);
    }

    public function testPsubscribeThrowsExceptionWhenConnectionClosedAbnormally()
    {
        // Arrange
        $config = ['host' => 'localhost'];
        $patterns = ['channel*'];
        $mockRedisSubscriber = m::mock(RedisSubscriber::class);
        $mockChannel = m::mock('stdClass');

        $mockChannel->shouldReceive('pop')
            ->once()
            ->andReturnNull();

        $mockRedisSubscriber->shouldReceive('psubscribe')
            ->once()
            ->with(...$patterns);

        $mockRedisSubscriber->shouldReceive('channel')
            ->once()
            ->andReturn($mockChannel);

        $mockRedisSubscriber->closed = false;

        $subscriber = new Subscriber($config, $mockRedisSubscriber);

        $callback = function () {
            // Empty callback
        };

        // Assert & Act
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Redis connection is disconnected abnormally.');

        $subscriber->psubscribe($patterns, $callback);
    }
}
