<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\Broadcasters\RedisBroadcaster;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Contracts\Routing\BindingRegistrar;
use Hypervel\Http\Request;
use Hypervel\Redis\RedisProxy;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RedisBroadcasterTest extends TestCase
{
    protected RedisBroadcaster $broadcaster;

    protected Container $container;

    protected Redis|m\MockInterface $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(Container::class);
        $this->container->shouldReceive('bound')->with(BindingRegistrar::class)->andReturnFalse()->byDefault();
        $this->redis = m::mock(Redis::class);
        $this->broadcaster = m::mock(RedisBroadcaster::class, [$this->container, $this->redis])->makePartial();
    }

    public function testAuthCallValidAuthenticationResponseWithPrivateChannelWhenCallbackReturnTrue()
    {
        $this->broadcaster->channel('test', function () {
            return true;
        });

        $this->broadcaster->shouldReceive('validAuthenticationResponse')->once();

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('private-test')
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenCallbackReturnFalse()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return false;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('private-test')
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenRequestUserNotFound()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return true;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithoutUserForChannel('private-test')
        );
    }

    public function testAuthCallValidAuthenticationResponseWithPresenceChannelWhenCallbackReturnAnArray()
    {
        $returnData = [1, 2, 3, 4];
        $this->broadcaster->channel('test', function () use ($returnData) {
            return $returnData;
        });

        $this->broadcaster->shouldReceive('validAuthenticationResponse')
            ->once();

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('presence-test')
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenCallbackReturnNull()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('presence-test')
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenRequestUserNotFound()
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return [1, 2, 3, 4];
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithoutUserForChannel('presence-test')
        );
    }

    public function testValidAuthenticationResponseWithPrivateChannel()
    {
        $request = $this->getMockRequestWithUserForChannel('private-test');

        $this->assertEquals(
            json_encode(true),
            $this->broadcaster->validAuthenticationResponse($request, true)
        );
    }

    public function testValidAuthenticationResponseWithPresenceChannel()
    {
        $request = $this->getMockRequestWithUserForChannel('presence-test');

        $this->assertEquals(
            json_encode([
                'channel_data' => [
                    'user_id' => 42,
                    'user_info' => [
                        'a' => 'b',
                        'c' => 'd',
                    ],
                ],
            ]),
            $this->broadcaster->validAuthenticationResponse($request, [
                'a' => 'b',
                'c' => 'd',
            ])
        );
    }

    public function testBroadcastUsesPublishPerChannelOnCluster()
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnTrue();
        $connection->shouldReceive('publish')->once()->with('test-channel-1', m::type('string'));
        $connection->shouldReceive('publish')->once()->with('test-channel-2', m::type('string'));
        $connection->shouldNotReceive('eval');

        $this->redis->shouldReceive('connection')->once()->andReturn($connection);

        $broadcaster = new RedisBroadcaster($this->container, $this->redis);
        $broadcaster->broadcast(['test-channel-1', 'test-channel-2'], 'test-event', ['data' => 'value']);
    }

    public function testBroadcastUsesEvalOnNonCluster()
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnFalse();
        $connection->shouldReceive('eval')->once();
        $connection->shouldNotReceive('publish');

        $this->redis->shouldReceive('connection')->once()->andReturn($connection);

        $broadcaster = new RedisBroadcaster($this->container, $this->redis);
        $broadcaster->broadcast(['test-channel'], 'test-event', ['data' => 'value']);
    }

    public function testBroadcastPayloadDoesNotDuplicateSocketInData()
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->andReturnFalse();
        $connection->shouldReceive('eval')->once()->withArgs(function ($script, $numKeys, $payload) {
            $decoded = json_decode($payload, true);

            // socket should be at top level only, not inside data
            return $decoded['socket'] === 'test-socket'
                && ! isset($decoded['data']['socket']);
        });

        $this->redis->shouldReceive('connection')->andReturn($connection);

        $broadcaster = new RedisBroadcaster($this->container, $this->redis);
        $broadcaster->broadcast(['test-channel'], 'test-event', ['message' => 'hello', 'socket' => 'test-socket']);
    }

    protected function getMockRequestWithUserForChannel(string $channel): Request
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn($channel);

        $user = m::mock('User');
        $user->shouldReceive('getAuthIdentifierForBroadcasting')->andReturn(42);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $request->shouldReceive('user')->andReturn($user);

        return $request;
    }

    protected function getMockRequestWithoutUserForChannel(string $channel): Request
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn($channel);

        $request->shouldReceive('user')->andReturn(null);

        return $request;
    }
}
