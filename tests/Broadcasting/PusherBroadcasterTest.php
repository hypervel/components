<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\Broadcasters\PusherBroadcaster;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Routing\BindingRegistrar;
use Hypervel\Http\Request;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Pusher\Pusher;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @internal
 * @coversNothing
 */
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

    protected function tearDown(): void
    {
        parent::tearDown();
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

        $this->broadcaster->shouldReceive('validAuthenticationResponse')->once();

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

    public function testValidAuthenticationResponseCallPusherSocketAuthMethodWithPrivateChannel()
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

    public function testValidAuthenticationResponseCallPusherPresenceAuthMethodWithPresenceChannel()
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

    public function testUserAuthenticationForPusher()
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

    public function testDecodePusherResponseWithJsonpCallback()
    {
        // Register ResponseFactory so the response() helper works
        $container = \Hypervel\Container\Container::getInstance();
        $container->singleton(
            \Hypervel\Contracts\Routing\ResponseFactory::class,
            fn () => new \Hypervel\Routing\ResponseFactory(
                m::mock(\Hypervel\Contracts\View\Factory::class),
                m::mock(\Hypervel\Routing\Redirector::class),
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

        $response = $this->broadcaster->validAuthenticationResponse($request, true);

        $this->assertInstanceOf(\Hypervel\Http\JsonResponse::class, $response);
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
