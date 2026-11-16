<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\Broadcasters\PusherBroadcaster;
use Hypervel\Container\Container as ApplicationContainer;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Redirector;
use Hypervel\Routing\ResponseFactory;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Pusher\Pusher;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PusherBroadcasterTest extends TestCase
{
    use VerifiesDoubles;

    protected Container $container;

    protected PusherBroadcaster $broadcaster;

    protected Pusher $pusher;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new ApplicationContainer;
        $this->pusher = Double::for(Pusher::class);
        $this->broadcaster = new PusherBroadcaster($this->container, $this->pusher);
    }

    public function testAuthCallValidAuthenticationResponseWithPrivateChannelWhenCallbackReturnTrue(): void
    {
        $this->broadcaster->channel('test', function (): bool {
            return true;
        });

        $data = ['auth' => 'abcd:efgh'];

        $this->pusher->expects('authorizeChannel')
            ->with('private-test', 'abcd.1234')
            ->returns(json_encode($data));

        $this->assertSame(
            $data,
            $this->broadcaster->auth(
                $this->getMockRequestWithUserForChannel('private-test')
            ),
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenCallbackReturnFalse(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function (): bool {
            return false;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('private-test')
        );
    }

    public function testAuthRejectsArrayChannelName(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $request = $this->getMockRequestWithUserForChannel(['private-test']);

        $this->broadcaster->auth($request);
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenRequestUserNotFound(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function (): bool {
            return true;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithoutUserForChannel('private-test')
        );
    }

    public function testAuthCallValidAuthenticationResponseWithPresenceChannelWhenCallbackReturnAnArray(): void
    {
        $returnData = [1, 2, 3, 4];
        $this->broadcaster->channel('test', function () use ($returnData): array {
            return $returnData;
        });

        $data = ['auth' => 'abcd:efgh', 'channel_data' => ['user_id' => 42, 'user_info' => $returnData]];

        $this->pusher->expects('authorizePresenceChannel')
            ->with('presence-test', 'abcd.1234', '42', $returnData)
            ->returns(json_encode($data));

        $this->assertSame(
            $data,
            $this->broadcaster->auth(
                $this->getMockRequestWithUserForChannel('presence-test')
            ),
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenCallbackReturnNull(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function (): void {
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('presence-test')
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenRequestUserNotFound(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function (): array {
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
        $user = Double::for(Authenticatable::class);
        $user->expects('getAuthIdentifier')->returns(42);

        $request = Double::for(Request::class, override: true);
        $request->allows('input')->with('channel_name')->returns($wireChannel);
        $request->allows('input')->with('socket_id')->returns('abcd.1234');
        $request->allows('input')->with('callback', false)->returns(false);
        $request->expects('user')->times(3)->with('members')->returns($user);
        $request->expects('user')->never()->with(Argument::none());

        Broadcaster::authorizeChannelsUsing(function (Request $request, string $channel) use (&$calls): ?string {
            ++$calls;

            return $channel === 'application.tenant.orders.5'
                ? 'application.orders.5'
                : null;
        });

        $this->broadcaster->channel(
            'application.orders.{order}',
            function (object $authenticatedUser, PusherBroadcasterTestEloquentModelStub $order) use ($user, &$boundOrder): array|false {
                $boundOrder = $order;

                return $authenticatedUser === $user ? ['role' => 'viewer'] : false;
            },
            ['guards' => ['members']],
        );

        $this->pusher->expects('authorizePresenceChannel')
            ->with($wireChannel, 'abcd.1234', '42', ['role' => 'viewer'])
            ->returns(json_encode(['auth' => 'signed']));

        $this->assertSame(
            ['auth' => 'signed'],
            $this->broadcaster->auth($request->instance()),
        );
        $this->assertSame(1, $calls);
        $this->assertInstanceOf(PusherBroadcasterTestEloquentModelStub::class, $boundOrder);
        $this->assertSame('5', $boundOrder->boundValue);
    }

    public function testBroadcastTriggersEncryptedChannelsSeparately(): void
    {
        $this->pusher->expects('trigger')->with(['private-a', 'b'], 'event', ['foo' => 'bar'], [])->returns((object) []);
        $this->pusher->expects('trigger')->with(['private-encrypted-c'], 'event', ['foo' => 'bar'], [])->returns((object) []);
        $this->pusher->expects('trigger')->with(['private-encrypted-d'], 'event', ['foo' => 'bar'], [])->returns((object) []);

        $this->broadcaster->broadcast(
            ['private-a', 'private-encrypted-c', 'b', 'private-encrypted-d'],
            'event',
            ['foo' => 'bar']
        );
    }

    public function testValidAuthenticationResponseCallPusherSocketAuthMethodWithPrivateChannel(): void
    {
        $request = $this->getMockRequestWithUserForChannel('private-test');

        $data = [
            'auth' => 'abcd:efgh',
        ];

        $this->pusher->expects('authorizeChannel')
            ->returns(json_encode($data));

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

        $this->pusher->expects('authorizePresenceChannel')
            ->returns(json_encode($data));

        $this->assertEquals(
            $data,
            $this->broadcaster->validAuthenticationResponse($request, true)
        );
    }

    public function testUserAuthenticationForPusher(): void
    {
        $this->broadcaster = new PusherBroadcaster($this->container, new Pusher(
            '278d425bdf160c739803',
            '7ad3773142a6692b25b8',
            '12345',
        ));

        $this->broadcaster->resolveAuthenticatedUserUsing(function (): array {
            return ['id' => '12345'];
        });

        $response = $this->broadcaster->resolveAuthenticatedUser(
            Request::create('/?socket_id=1234.1234')
        );

        // The result is hard-coded from the Pusher docs
        // See: https://pusher.com/docs/channels/library_auth_reference/auth-signatures/#user-authentication
        $this->assertSame([
            'auth' => '278d425bdf160c739803:4708d583dada6a56435fb8bc611c77c359a31eebde13337c16ab43aa6de336ba',
            'user_data' => json_encode(['id' => '12345']),
        ], $response);
    }

    public function testBroadcastUsesFormattedChannelNames(): void
    {
        Broadcaster::formatChannelsUsing(
            static fn (array $channels): array => array_map(
                static fn (mixed $channel): string => 'application.' . $channel,
                $channels,
            ),
        );

        $this->pusher->expects('trigger')
            ->with(['application.orders'], 'OrderCreated', ['id' => 1], [])->returns((object) []);

        $this->broadcaster->broadcast(['orders'], 'OrderCreated', ['id' => 1]);
    }

    public function testJsonpCallbackReturnsJsonWithoutExplicitOptIn(): void
    {
        $request = $this->getMockRequestWithUserForChannel('private-test');
        $request->request->set('callback', 'myCallback');

        $data = ['auth' => 'abcd:efgh'];

        $this->pusher->expects('authorizeChannel')
            ->returns(json_encode($data));

        $response = $this->broadcaster->validAuthenticationResponse($request, true);

        $this->assertSame($data, $response);
    }

    public function testJsonpCallbackReturnsJsonpWhenExplicitlyEnabled(): void
    {
        $container = ApplicationContainer::getInstance();
        $container->singleton(
            ResponseFactoryContract::class,
            fn (): ResponseFactory => new ResponseFactory(
                Double::for(ViewFactory::class),
                Double::for(Redirector::class),
            )
        );

        $request = $this->getMockRequestWithUserForChannel('private-test');
        $request->request->set('callback', 'myCallback');

        $data = ['auth' => 'abcd:efgh'];

        $this->pusher->expects('authorizeChannel')
            ->returns(json_encode($data));

        $broadcaster = new PusherBroadcaster($this->container, $this->pusher, true);

        $response = $broadcaster->validAuthenticationResponse($request, true);

        $this->assertInstanceOf(
            JsonResponse::class,
            $response,
        );
        $this->assertStringStartsWith('/**/myCallback(', (string) $response->getContent());
    }

    public function testExplicitJsonpOptInWithoutCallbackReturnsJson(): void
    {
        $request = $this->getMockRequestWithUserForChannel('private-test');
        $data = ['auth' => 'abcd:efgh'];

        $this->pusher->expects('authorizeChannel')
            ->returns(json_encode($data));

        $broadcaster = new PusherBroadcaster($this->container, $this->pusher, true);

        $this->assertSame(
            $data,
            $broadcaster->validAuthenticationResponse($request, true),
        );
    }

    /**
     * Create a channel request with an authenticated user.
     */
    protected function getMockRequestWithUserForChannel(string|array $channel): Request
    {
        $request = Request::create('/', 'POST', ['channel_name' => $channel, 'socket_id' => 'abcd.1234']);

        $user = new class {
            /**
             * Get the user's broadcast identifier.
             */
            public function getAuthIdentifierForBroadcasting(): int
            {
                return 42;
            }

            /**
             * Get the user's authentication identifier.
             */
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        };

        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /**
     * Create a channel request without an authenticated user.
     */
    protected function getMockRequestWithoutUserForChannel(string $channel): Request
    {
        return Request::create('/', 'POST', ['channel_name' => $channel]);
    }
}

class PusherBroadcasterTestEloquentModelStub extends Model
{
    public string $boundValue = '';

    /**
     * Retrieve the model for a bound value.
     */
    public function resolveRouteBinding(mixed $value, ?string $field = null): ?self
    {
        $instance = new static;
        $instance->boundValue = (string) $value;

        return $instance;
    }
}
