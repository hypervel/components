<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\Broadcasters\PusherBroadcaster;
use Hypervel\Container\Container as ApplicationContainer;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Routing\BindingRegistrar;
use Hypervel\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Redirector;
use Hypervel\Routing\ResponseFactory;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Pusher\Pusher;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PusherBroadcasterTest extends TestCase
{
    protected Container $container;

    protected PusherBroadcaster $broadcaster;

    protected Pusher $pusher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(Container::class);
        $this->container->shouldReceive('bound')->with(BindingRegistrar::class)->andReturnFalse()->byDefault();
        $this->pusher = m::mock(Pusher::class);
        $this->broadcaster = m::mock(PusherBroadcaster::class, [$this->container, $this->pusher])->makePartial();
    }

    public function testAuthCallValidAuthenticationResponseWithPrivateChannelWhenCallbackReturnTrue(): void
    {
        $this->broadcaster->channel('test', function () {
            return true;
        });

        $this->pusher->shouldReceive('authorizeChannel')
            ->once()
            ->andReturn(json_encode(['auth' => 'signed']));

        $this->assertSame(
            ['auth' => 'signed'],
            $this->broadcaster->auth(
                $this->getMockRequestWithUserForChannel('private-test')
            ),
        );
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

        $this->pusher->shouldReceive('authorizePresenceChannel')
            ->once()
            ->andReturn(json_encode(['auth' => 'signed']));

        $this->assertSame(
            ['auth' => 'signed'],
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

    public function testAuthUsesRewrittenChannelForConfiguredGuardAndOriginalNameForPresenceSignature(): void
    {
        $wireChannel = 'presence-application.tenant.orders.5';
        $calls = 0;
        $boundOrder = null;
        $user = m::mock('User');
        $user->shouldReceive('getAuthIdentifier')->once()->andReturn(42);

        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn($wireChannel);
        $request->shouldReceive('input')->with('socket_id')->andReturn('abcd.1234');
        $request->shouldReceive('input')->with('callback', false)->andReturn(false);
        $request->shouldReceive('user')->times(3)->with('members')->andReturn($user);
        $request->shouldNotReceive('user')->withNoArgs();

        Broadcaster::authorizeChannelsUsing(function (Request $request, string $channel) use (&$calls): ?string {
            ++$calls;

            return $channel === 'application.tenant.orders.5'
                ? 'application.orders.5'
                : null;
        });

        $this->broadcaster->channel(
            'application.orders.{order}',
            function ($authenticatedUser, PusherBroadcasterTestEloquentModelStub $order) use ($user, &$boundOrder): array|false {
                $boundOrder = $order;

                return $authenticatedUser === $user ? ['role' => 'viewer'] : false;
            },
            ['guards' => ['members']],
        );

        $this->pusher->shouldReceive('authorizePresenceChannel')
            ->once()
            ->with($wireChannel, 'abcd.1234', '42', ['role' => 'viewer'])
            ->andReturn(json_encode(['auth' => 'signed']));

        $this->assertSame(
            ['auth' => 'signed'],
            $this->broadcaster->auth($request),
        );
        $this->assertSame(1, $calls);
        $this->assertInstanceOf(PusherBroadcasterTestEloquentModelStub::class, $boundOrder);
        $this->assertSame('5', $boundOrder->boundValue);
    }

    public function testValidAuthenticationResponseCallPusherSocketAuthMethodWithPrivateChannel(): void
    {
        $request = $this->getMockRequestWithUserForChannel('private-test');

        $data = [
            'auth' => 'abcd:efgh',
        ];

        $this->pusher->shouldReceive('authorizeChannel')
            ->once()
            ->andReturn(json_encode($data));

        $this->assertEquals(
            $data,
            $this->broadcaster->validAuthenticationResponse($request, true)
        );
    }

    public function testValidAuthenticationResponseCallPusherPresenceAuthMethodWithPresenceChannel(): void
    {
        $request = $this->getMockRequestWithUserForChannel('presence-test');

        $data = [
            'auth' => 'abcd:efgh',
            'channel_data' => [
                'user_id' => 42,
                'user_info' => [1, 2, 3, 4],
            ],
        ];

        $this->pusher->shouldReceive('authorizePresenceChannel')
            ->once()
            ->andReturn(json_encode($data));

        $this->assertEquals(
            $data,
            $this->broadcaster->validAuthenticationResponse($request, true)
        );
    }

    public function testUserAuthenticationForPusher(): void
    {
        $authenticateUser = [
            'auth' => '278d425bdf160c739803:4708d583dada6a56435fb8bc611c77c359a31eebde13337c16ab43aa6de336ba',
            'user_data' => json_encode(['id' => '12345']),
        ];

        $this->pusher
            ->shouldReceive('authenticateUser')
            ->andReturn(json_encode($authenticateUser));

        $this->broadcaster->resolveAuthenticatedUserUsing(function () {
            return ['id' => '12345'];
        });

        $response = $this->broadcaster->resolveAuthenticatedUser(
            $this->getMockRequestWithUserForChannel('presence-test')
        );

        $this->assertSame($authenticateUser, $response);
    }

    public function testBroadcastUsesFormattedChannelNames(): void
    {
        Broadcaster::formatChannelsUsing(
            static fn (array $channels): array => array_map(
                static fn (mixed $channel): string => 'application.' . $channel,
                $channels,
            ),
        );

        $this->pusher->shouldReceive('trigger')
            ->once()
            ->with(['application.orders'], 'OrderCreated', ['id' => 1], []);

        $this->broadcaster->broadcast(['orders'], 'OrderCreated', ['id' => 1]);
    }

    public function testJsonpCallbackReturnsJsonWithoutExplicitOptIn(): void
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn('private-test');
        $request->shouldReceive('input')->with('socket_id')->andReturn('abcd.1234');
        $request->shouldReceive('input')->with('callback', false)->andReturn('myCallback');
        $request->shouldReceive('user')->andReturn(m::mock('User'));

        $data = ['auth' => 'abcd:efgh'];

        $this->pusher->shouldReceive('authorizeChannel')
            ->once()
            ->andReturn(json_encode($data));

        $response = $this->broadcaster->validAuthenticationResponse($request, true);

        $this->assertSame($data, $response);
    }

    public function testJsonpCallbackReturnsJsonpWhenExplicitlyEnabled(): void
    {
        $container = ApplicationContainer::getInstance();
        $container->singleton(
            ResponseFactoryContract::class,
            fn () => new ResponseFactory(
                m::mock(ViewFactory::class),
                m::mock(Redirector::class),
            )
        );

        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn('private-test');
        $request->shouldReceive('input')->with('socket_id')->andReturn('abcd.1234');
        $request->shouldReceive('input')->with('callback', false)->andReturn('myCallback');
        $request->shouldReceive('input')->with('callback')->andReturn('myCallback');
        $request->shouldReceive('user')->andReturn(m::mock('User'));

        $data = ['auth' => 'abcd:efgh'];

        $this->pusher->shouldReceive('authorizeChannel')
            ->once()
            ->andReturn(json_encode($data));

        $broadcaster = m::mock(
            PusherBroadcaster::class,
            [$this->container, $this->pusher, true],
        )->makePartial();

        $this->assertInstanceOf(
            JsonResponse::class,
            $broadcaster->validAuthenticationResponse($request, true),
        );
    }

    public function testExplicitJsonpOptInWithoutCallbackReturnsJson(): void
    {
        $request = $this->getMockRequestWithUserForChannel('private-test');
        $data = ['auth' => 'abcd:efgh'];

        $this->pusher->shouldReceive('authorizeChannel')
            ->once()
            ->andReturn(json_encode($data));

        $broadcaster = m::mock(
            PusherBroadcaster::class,
            [$this->container, $this->pusher, true],
        )->makePartial();

        $this->assertSame(
            $data,
            $broadcaster->validAuthenticationResponse($request, true),
        );
    }

    protected function getMockRequestWithUserForChannel(string $channel): Request
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn($channel);
        $request->shouldReceive('input')->with('socket_id')->andReturn('abcd.1234');
        $request->shouldReceive('input')->with('callback', false)->andReturn(false);

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

class PusherBroadcasterTestEloquentModelStub extends Model
{
    public string $boundValue = '';

    public function resolveRouteBinding(mixed $value, ?string $field = null): ?self
    {
        $instance = new static;
        $instance->boundValue = (string) $value;

        return $instance;
    }
}
