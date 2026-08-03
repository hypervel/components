<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\Broadcasters\RedisBroadcaster;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Contracts\Routing\BindingRegistrar;
use Hypervel\Http\Request;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use JsonException;
use Mockery as m;
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

    public function testAuthCallValidAuthenticationResponseWithPrivateChannelWhenCallbackReturnTrue(): void
    {
        $this->broadcaster->channel('test', function () {
            return true;
        });

        $this->assertSame(
            json_encode(true),
            $this->broadcaster->auth(
                $this->getMockRequestWithUserForChannel('private-test')
            ),
        );
    }

    public function testAuthRejectsMissingChannelName(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn(null);

        $this->broadcaster->auth($request);
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenCallbackReturnFalse(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return false;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('private-test')
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenRequestUserNotFound(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return true;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithoutUserForChannel('private-test')
        );
    }

    public function testAuthCallValidAuthenticationResponseWithPresenceChannelWhenCallbackReturnAnArray(): void
    {
        $returnData = [1, 2, 3, 4];
        $this->broadcaster->channel('test', function () use ($returnData) {
            return $returnData;
        });

        $this->assertSame(
            json_encode([
                'channel_data' => [
                    'user_id' => 42,
                    'user_info' => $returnData,
                ],
            ]),
            $this->broadcaster->auth(
                $this->getMockRequestWithUserForChannel('presence-test')
            ),
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenCallbackReturnNull(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('presence-test')
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenRequestUserNotFound(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return [1, 2, 3, 4];
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithoutUserForChannel('presence-test')
        );
    }

    public function testAuthUsesRewrittenChannelForConfiguredGuardAndPresenceUser(): void
    {
        $broadcaster = m::mock(
            RedisBroadcaster::class,
            [$this->container, $this->redis, 'default', 'redis.'],
        )->makePartial();
        $user = m::mock('User');
        $user->shouldReceive('getAuthIdentifier')->once()->andReturn(42);

        $request = m::mock(Request::class);
        $request->shouldReceive('input')
            ->with('channel_name')
            ->andReturn('redis.presence-application.tenant.orders.5');
        $request->shouldReceive('user')->times(3)->with('members')->andReturn($user);
        $request->shouldNotReceive('user')->withNoArgs();

        $calls = 0;
        Broadcaster::authorizeChannelsUsing(function (Request $request, string $channel) use (&$calls): ?string {
            ++$calls;

            return $channel === 'application.tenant.orders.5'
                ? 'application.orders.5'
                : null;
        });

        $broadcaster->channel(
            'application.orders.{order}',
            static fn ($authenticatedUser, string $order): array|false => $authenticatedUser === $user && $order === '5'
                ? ['role' => 'viewer']
                : false,
            ['guards' => ['members']],
        );

        $this->assertSame(
            json_encode([
                'channel_data' => [
                    'user_id' => 42,
                    'user_info' => ['role' => 'viewer'],
                ],
            ]),
            $broadcaster->auth($request),
        );
        $this->assertSame(1, $calls);
    }

    public function testAuthDoesNotRemoveConfiguredPrefixFromTheMiddleOfAChannel(): void
    {
        $broadcaster = m::mock(
            RedisBroadcaster::class,
            [$this->container, $this->redis, 'default', 'redis.'],
        )->makePartial();
        $broadcaster->channel('orders.redis.audit', static fn (): bool => true);

        $this->assertSame(
            json_encode(true),
            $broadcaster->auth(
                $this->getMockRequestWithUserForChannel('private-orders.redis.audit')
            ),
        );
    }

    public function testAuthLeavesAChannelWithoutTheConfiguredPrefixUnchanged(): void
    {
        $broadcaster = m::mock(
            RedisBroadcaster::class,
            [$this->container, $this->redis, 'default', 'redis.'],
        )->makePartial();
        $broadcaster->channel('orders', static fn (): bool => true);

        $this->assertSame(
            json_encode(true),
            $broadcaster->auth(
                $this->getMockRequestWithUserForChannel('private-orders')
            ),
        );
    }

    public function testValidAuthenticationResponseWithPrivateChannel(): void
    {
        $request = $this->getMockRequestWithUserForChannel('private-test');

        $this->assertEquals(
            json_encode(true),
            $this->broadcaster->validAuthenticationResponse($request, true)
        );
    }

    public function testValidAuthenticationResponseWithPresenceChannel(): void
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

    public function testPresenceAuthenticationThrowsWhenUserDataCannotBeEncoded(): void
    {
        $this->expectException(JsonException::class);

        $this->broadcaster->validAuthenticationResponse(
            $this->getMockRequestWithUserForChannel('presence-test'),
            ['invalid' => NAN],
        );
    }

    public function testBroadcastUsesPublishPerChannelOnCluster(): void
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

    public function testBroadcastUsesEvalOnNonCluster(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnFalse();
        $connection->shouldReceive('eval')->once();
        $connection->shouldNotReceive('publish');

        $this->redis->shouldReceive('connection')->once()->andReturn($connection);

        $broadcaster = new RedisBroadcaster($this->container, $this->redis);
        $broadcaster->broadcast(['test-channel'], 'test-event', ['data' => 'value']);
    }

    public function testClusterBroadcastLeavesRedisPrefixToNativePublishAfterFormattingChannels(): void
    {
        Broadcaster::formatChannelsUsing(
            static fn (array $channels): array => array_map(
                static fn (mixed $channel): string => 'application.' . $channel,
                $channels,
            ),
        );

        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnTrue();
        $connection->shouldReceive('publish')
            ->once()
            ->with('application.orders', m::type('string'));

        $this->redis->shouldReceive('connection')->once()->andReturn($connection);

        (new RedisBroadcaster(
            $this->container,
            $this->redis,
            prefix: 'redis.',
        ))->broadcast(['orders'], 'OrderCreated');
    }

    public function testLuaBroadcastAddsRedisPrefixAfterFormattingChannels(): void
    {
        Broadcaster::formatChannelsUsing(
            static fn (array $channels): array => array_map(
                static fn (mixed $channel): string => 'application.' . $channel,
                $channels,
            ),
        );

        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnFalse();
        $connection->shouldReceive('eval')
            ->once()
            ->with(
                m::type('string'),
                0,
                m::type('string'),
                'redis.application.orders',
            );

        $this->redis->shouldReceive('connection')->once()->andReturn($connection);

        (new RedisBroadcaster(
            $this->container,
            $this->redis,
            prefix: 'redis.',
        ))->broadcast(['orders'], 'OrderCreated');
    }

    public function testBroadcastThrowsWhenPayloadCannotBeEncoded(): void
    {
        $this->expectException(JsonException::class);

        $this->redis->shouldReceive('connection')->once()->andReturn(
            m::mock(RedisProxy::class)
        );

        $this->broadcaster->broadcast(
            ['test-channel'],
            'test-event',
            ['invalid' => NAN],
        );
    }

    public function testBroadcastPayloadDoesNotDuplicateSocketInData(): void
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
