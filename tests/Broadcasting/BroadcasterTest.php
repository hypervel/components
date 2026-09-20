<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Exception;
use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Routing\BindingRegistrar;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Routing\RouteBinding;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BroadcasterTest extends TestCase
{
    protected Container $container;

    protected FakeBroadcaster $broadcaster;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(Container::class);
        $this->container->shouldReceive('bound')->with(BindingRegistrar::class)->andReturnFalse()->byDefault();

        $this->broadcaster = new FakeBroadcaster($this->container);
    }

    public function testExtractingParametersWhileCheckingForUserAccess(): void
    {
        $callback = function (mixed $user, BroadcasterTestEloquentModelStub $model, string $nonModel): void {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd.{model}.{nonModel}', 'asd.1.something', $callback);
        $this->assertCount(2, $parameters);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $parameters[0]);
        $this->assertSame('1', $parameters[0]->boundValue);
        $this->assertSame('something', $parameters[1]);

        $callback = function (mixed $user, BroadcasterTestEloquentModelStub $model, BroadcasterTestEloquentModelStub $model2, string $something): void {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd.{model}.{model2}.{nonModel}', 'asd.1.uid.something', $callback);
        $this->assertCount(3, $parameters);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $parameters[0]);
        $this->assertSame('1', $parameters[0]->boundValue);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $parameters[1]);
        $this->assertSame('uid', $parameters[1]->boundValue);
        $this->assertSame('something', $parameters[2]);

        $callback = function (mixed $user): void {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd', 'asd', $callback);
        $this->assertSame([], $parameters);

        $callback = function (mixed $user, mixed $something): void {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd', 'asd', $callback);
        $this->assertSame([], $parameters);

        // Test Explicit Binding...
        $binder = m::mock(BindingRegistrar::class);
        $binder->expects('getBindingCallback')->times(2)->with('model')->andReturn(function (): string {
            return 'bound';
        });
        $this->container->shouldReceive('bound')->with(BindingRegistrar::class)->andReturnTrue();
        $this->container->shouldReceive('make')->with(BindingRegistrar::class)->andReturn($binder);
        $callback = function (mixed $user, mixed $model): void {
        };
        $parameters = $this->broadcaster->extractAuthParameters('something.{model}', 'something.1', $callback);
        $this->assertEquals(['bound'], $parameters);
    }

    public function testCanUseChannelClasses(): void
    {
        $parameters = $this->broadcaster->extractAuthParameters('asd.{model}.{nonModel}', 'asd.1.something', DummyBroadcastingChannel::class);
        $this->assertCount(2, $parameters);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $parameters[0]);
        $this->assertSame('1', $parameters[0]->boundValue);
        $this->assertSame('something', $parameters[1]);
    }

    #[DataProvider('callableChannelHandlers')]
    public function testCallableChannelHandlersReceiveTheUserAndBoundModel(callable $handler): void
    {
        $user = new DummyUser;
        $request = m::mock(Request::class);
        $request->expects('user')->withNoArgs()->andReturn($user);

        $this->broadcaster->channel('orders.{order}', $handler);

        $result = $this->broadcaster->verifyAccess($request, 'orders.5');

        $this->assertSame($user, $result['user']);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $result['order']);
        $this->assertSame('5', $result['order']->boundValue);
    }

    /**
     * Provide channel handlers represented by arrays, objects and strings.
     */
    public static function callableChannelHandlers(): array
    {
        return [
            'method array' => [[new BroadcasterTestCallableChannelHandler, 'authorize']],
            'invokable object' => [new BroadcasterTestCallableChannelHandler],
            'static method string' => [BroadcasterTestCallableChannelHandler::class . '::authorizeStatic'],
        ];
    }

    public function testModelRouteBinding(): void
    {
        $binder = m::mock(BindingRegistrar::class);
        $routeModelCallback = RouteBinding::forModel($this->container, BroadcasterTestEloquentModelStub::class);

        $binder->expects('getBindingCallback')->times(2)->with('model')->andReturn($routeModelCallback);
        $this->container->shouldReceive('bound')->with(BindingRegistrar::class)->andReturnTrue();
        $this->container->shouldReceive('make')->with(BindingRegistrar::class)->andReturn($binder);
        $this->container->shouldReceive('make')->with(BroadcasterTestEloquentModelStub::class)->andReturn(new BroadcasterTestEloquentModelStub);
        $callback = function (mixed $user, mixed $model): void {
        };
        $parameters = $this->broadcaster->extractAuthParameters('something.{model}', 'something.1', $callback);
        $this->assertCount(1, $parameters);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $parameters[0]);
        $this->assertSame('1', $parameters[0]->boundValue);
    }

    public function testUnknownChannelAuthHandlerTypeThrowsException(): void
    {
        $this->expectException(Exception::class);

        $this->broadcaster->extractAuthParameters('asd.{model}.{nonModel}', 'asd.1.something', 'notClassString');
    }

    public function testCanRegisterChannelsAsClasses(): void
    {
        $this->broadcaster->channel('something', function (): void {
        });

        $this->broadcaster->channel('somethingelse', DummyBroadcastingChannel::class);
    }

    public function testNotFoundThrowsHttpException(): void
    {
        $this->expectException(HttpException::class);

        $callback = function (mixed $user, BroadcasterTestEloquentModelNotFoundStub $model): void {
        };
        $this->broadcaster->extractAuthParameters('asd.{model}', 'asd.1', $callback);
    }

    public function testCanRegisterChannelsWithoutOptions(): void
    {
        $this->broadcaster->channel('somechannel', function (): void {
        });
    }

    public function testCanRegisterChannelsWithOptions(): void
    {
        $options = ['a' => ['b', 'c']];
        $this->broadcaster->channel('somechannel', function (): void {
        }, $options);
    }

    public function testCanRetrieveChannelsOptions(): void
    {
        $options = ['a' => ['b', 'c']];
        $this->broadcaster->channel('somechannel', function (): void {
        }, $options);

        $this->assertEquals(
            $options,
            $this->broadcaster->retrieveChannelOptions('somechannel')
        );
    }

    public function testCanRetrieveChannelsOptionsUsingAChannelNameContainingArgs(): void
    {
        $options = ['a' => ['b', 'c']];
        $this->broadcaster->channel('somechannel.{id}.test.{text}', function (): void {
        }, $options);

        $this->assertEquals(
            $options,
            $this->broadcaster->retrieveChannelOptions('somechannel.23.test.mytext')
        );
    }

    public function testCanRetrieveChannelsOptionsWhenMultipleChannelsAreRegistered(): void
    {
        $options = ['a' => ['b', 'c']];
        $this->broadcaster->channel('somechannel', function (): void {
        });
        $this->broadcaster->channel('someotherchannel', function (): void {
        }, $options);

        $this->assertEquals(
            $options,
            $this->broadcaster->retrieveChannelOptions('someotherchannel')
        );
    }

    public function testDontRetrieveChannelsOptionsWhenChannelDoesntExists(): void
    {
        $options = ['a' => ['b', 'c']];
        $this->broadcaster->channel('somechannel', function (): void {
        }, $options);

        $this->assertEquals(
            [],
            $this->broadcaster->retrieveChannelOptions('someotherchannel')
        );
    }

    public function testRetrieveUserWithoutGuard(): void
    {
        $this->broadcaster->channel('somechannel', function (): void {
        });

        $request = m::mock(Request::class);
        $request->expects('user')
            ->withNoArgs()
            ->andReturn(new DummyUser);

        $this->assertInstanceOf(
            DummyUser::class,
            $this->broadcaster->retrieveUser($request, 'somechannel')
        );
    }

    public function testRetrieveUserWithOneGuardUsingAStringForSpecifyingGuard(): void
    {
        $this->broadcaster->channel('somechannel', function (): void {
        }, ['guards' => 'myguard']);

        $request = m::mock(Request::class);
        $request->expects('user')
            ->with('myguard')
            ->andReturn(new DummyUser);

        $this->assertInstanceOf(
            DummyUser::class,
            $this->broadcaster->retrieveUser($request, 'somechannel')
        );
    }

    public function testRetrieveUserWithMultipleGuardsAndRespectGuardsOrder(): void
    {
        $this->broadcaster->channel('somechannel', function (): void {
        }, ['guards' => ['myguard1', 'myguard2']]);
        $this->broadcaster->channel('someotherchannel', function (): void {
        }, ['guards' => ['myguard2', 'myguard1']]);

        $request = m::mock(Request::class);
        $request->expects('user')
            ->with('myguard1')
            ->andReturn(null);
        $request->expects('user')
            ->times(2)
            ->with('myguard2')
            ->andReturn(new DummyUser)
            ->ordered('user');

        $this->assertInstanceOf(
            DummyUser::class,
            $this->broadcaster->retrieveUser($request, 'somechannel')
        );

        $this->assertInstanceOf(
            DummyUser::class,
            $this->broadcaster->retrieveUser($request, 'someotherchannel')
        );
    }

    public function testRetrieveUserDontUseDefaultGuardWhenOneGuardSpecified(): void
    {
        $this->broadcaster->channel('somechannel', function (): void {
        }, ['guards' => 'myguard']);

        $request = m::mock(Request::class);
        $request->expects('user')
            ->with('myguard')
            ->andReturn(null);
        $request->shouldNotReceive('user')
            ->withNoArgs();

        $this->broadcaster->retrieveUser($request, 'somechannel');
    }

    public function testRetrieveUserDontUseDefaultGuardWhenMultipleGuardsSpecified(): void
    {
        $this->broadcaster->channel('somechannel', function (): void {
        }, ['guards' => ['myguard1', 'myguard2']]);

        $request = m::mock(Request::class);
        $request->expects('user')
            ->with('myguard1')
            ->andReturn(null);
        $request->expects('user')
            ->with('myguard2')
            ->andReturn(null);
        $request->shouldNotReceive('user')
            ->withNoArgs();

        $this->broadcaster->retrieveUser($request, 'somechannel');
    }

    public function testUserAuthenticationWithValidUser(): void
    {
        $this->broadcaster->resolveAuthenticatedUserUsing(function (Request $request): array {
            return ['id' => '12345', 'socket' => $request->input('socket_id')];
        });

        $user = $this->broadcaster->resolveAuthenticatedUser(
            Request::create('http://exa.com/foo?socket_id=1234.1234#boom')
        );

        $this->assertSame([
            'id' => '12345',
            'socket' => '1234.1234',
        ], $user);
    }

    public function testUserAuthenticationWithInvalidUser(): void
    {
        $this->broadcaster->resolveAuthenticatedUserUsing(function (Request $request): ?array {
            return null;
        });

        $user = $this->broadcaster->resolveAuthenticatedUser(
            Request::create('http://exa.com/foo?socket_id=1234.1234#boom')
        );

        $this->assertNull($user);
    }

    public function testUserAuthenticationWithoutResolve(): void
    {
        $this->assertNull($this->broadcaster->resolveAuthenticatedUser(
            Request::create('http://exa.com/foo?socket_id=1234.1234#boom')
        ));
    }

    public function testChannelAuthorizerRewritesTheNameBeforeGuardLookupBindingAndInvocation(): void
    {
        $request = m::mock(Request::class);
        $user = new DummyUser;
        $request->expects('user')->times(2)->with('members')->andReturn($user);

        $calls = 0;
        $receivedUser = null;
        $receivedOrder = null;

        Broadcaster::authorizeChannelsUsing(function (Request $request, string $channel) use (&$calls): ?string {
            ++$calls;

            return $channel === 'application.tenant.orders.5'
                ? 'application.orders.5'
                : null;
        });

        $this->broadcaster->channel(
            'application.orders.{order}',
            function (DummyUser $user, BroadcasterTestEloquentModelStub $order) use (&$receivedUser, &$receivedOrder): bool {
                $receivedUser = $user;
                $receivedOrder = $order;

                return true;
            },
            ['guards' => ['members']],
        );

        $this->assertTrue(
            $this->broadcaster->verifyAccess(
                $request,
                'application.tenant.orders.5',
                guarded: true,
            ),
        );
        $this->assertSame(1, $calls);
        $this->assertSame($user, $receivedUser);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $receivedOrder);
        $this->assertSame('5', $receivedOrder->boundValue);
    }

    public function testChannelAuthorizerDeniesBeforeTheChannelCallbackRuns(): void
    {
        $callbackRan = false;

        Broadcaster::authorizeChannelsUsing(static fn (Request $request, string $channel): ?string => null);

        $this->broadcaster->channel('orders', function () use (&$callbackRan): bool {
            $callbackRan = true;

            return true;
        });

        try {
            $this->broadcaster->verifyAccess(m::mock(Request::class), 'orders');
            $this->fail('Channel authorization should have been denied.');
        } catch (AccessDeniedHttpException) {
            $this->assertFalse($callbackRan);
        }
    }

    public function testChannelAuthorizerLeavesUnchangedNamesOnTheNativePath(): void
    {
        $request = m::mock(Request::class);
        $user = new DummyUser;
        $request->expects('user')->withNoArgs()->andReturn($user);

        Broadcaster::authorizeChannelsUsing(
            static fn (Request $request, string $channel): string => $channel,
        );

        $this->broadcaster->channel(
            'orders.{order}',
            static fn (DummyUser $authenticatedUser, string $order): bool => $authenticatedUser === $user
                && $order === '5',
        );

        $this->assertTrue($this->broadcaster->verifyAccess($request, 'orders.5'));
    }

    public function testChannelAuthorizerIsSingleOwner(): void
    {
        $request = m::mock(Request::class);
        $request->expects('user')->withNoArgs()->andReturn(new DummyUser);

        Broadcaster::authorizeChannelsUsing(static fn (Request $request, string $channel): ?string => null);
        Broadcaster::authorizeChannelsUsing(
            static fn (Request $request, string $channel): string => $channel,
        );

        $this->broadcaster->channel('orders', static fn (): bool => true);

        $this->assertTrue($this->broadcaster->verifyAccess($request, 'orders'));
    }

    public function testPassingNullRemovesChannelAuthorizer(): void
    {
        $request = m::mock(Request::class);
        $request->expects('user')->withNoArgs()->andReturn(new DummyUser);

        Broadcaster::authorizeChannelsUsing(static fn (Request $request, string $channel): ?string => null);
        Broadcaster::authorizeChannelsUsing(null);

        $this->broadcaster->channel('orders', static fn (): bool => true);

        $this->assertTrue($this->broadcaster->verifyAccess($request, 'orders'));
    }

    public function testBaseChannelResponsePathPreservesPublicOverrides(): void
    {
        $request = m::mock(Request::class);
        $request->expects('user')->withNoArgs()->andReturn(new DummyUser);

        $broadcaster = new PublicResponseOnlyBroadcaster($this->container);
        $broadcaster->channel('orders', static fn (): array => ['allowed' => true]);

        $this->assertSame(
            ['result' => ['allowed' => true]],
            $broadcaster->verifyAccess($request, 'orders'),
        );
    }

    public function testFormatsChannelsWithoutFormatter(): void
    {
        $this->assertSame(
            ['orders', 'private-users'],
            $this->broadcaster->formatOutgoingChannels(['orders', 'private-users']),
        );
    }

    public function testFormatterReceivesRawChannelsBeforeStringification(): void
    {
        $channel = new StringableBroadcastChannel('orders');
        $receivedChannels = null;

        Broadcaster::formatChannelsUsing(function (array $channels) use (&$receivedChannels): array {
            $receivedChannels = $channels;

            return $channels;
        });

        $this->assertSame(['orders'], $this->broadcaster->formatOutgoingChannels([$channel]));
        $this->assertSame([$channel], $receivedChannels);
        $this->assertSame(1, $channel->stringConversions);
    }

    public function testPassingNullRemovesChannelFormatter(): void
    {
        Broadcaster::formatChannelsUsing(
            fn (array $channels): array => array_map(
                static fn (mixed $channel): string => 'formatted.' . $channel,
                $channels,
            ),
        );

        $this->assertSame(
            ['formatted.orders'],
            $this->broadcaster->formatOutgoingChannels(['orders']),
        );

        Broadcaster::formatChannelsUsing(null);

        $this->assertSame(['orders'], $this->broadcaster->formatOutgoingChannels(['orders']));
    }

    public function testFlushStateClearsChannelsOptionsFormatterAndAuthorizer(): void
    {
        $this->broadcaster->channel('orders', static fn (): bool => true, ['guards' => ['web']]);
        Broadcaster::formatChannelsUsing(
            static fn (array $channels): array => ['formatted.' . $channels[0]],
        );
        Broadcaster::authorizeChannelsUsing(
            static fn (Request $request, string $channel): string => 'authorized.' . $channel,
        );

        Broadcaster::flushState();

        $this->assertSame([], $this->broadcaster->getChannels()->all());
        $this->assertSame([], $this->broadcaster->retrieveChannelOptions('orders'));
        $this->assertSame(['orders'], $this->broadcaster->formatOutgoingChannels(['orders']));

        $request = m::mock(Request::class);
        $request->expects('user')->withNoArgs()->andReturn(new DummyUser);
        $this->broadcaster->channel('orders', static fn (): bool => true);
        $this->assertTrue($this->broadcaster->verifyAccess($request, 'orders'));
    }

    public function testChannelFormatterIsSharedAcrossBroadcasterInstances(): void
    {
        $broadcasterA = new FakeBroadcaster(m::mock(Container::class));
        $broadcasterB = new FakeBroadcaster(m::mock(Container::class));

        $broadcasterA::formatChannelsUsing(
            static fn (array $channels): array => ['formatted.' . $channels[0]],
        );

        $this->assertSame(
            ['formatted.orders'],
            $broadcasterB->formatOutgoingChannels(['orders']),
        );
    }

    #[DataProvider('channelNameMatchPatternProvider')]
    public function testChannelNameMatchPattern(string $channel, string $pattern, bool $shouldMatch): void
    {
        $this->assertSame($shouldMatch, $this->broadcaster->channelNameMatchesPattern($channel, $pattern));
    }

    /**
     * Provide channel names and their expected pattern matches.
     */
    public static function channelNameMatchPatternProvider(): array
    {
        return [
            ['something', 'something', true],
            ['something.23', 'something.{id}', true],
            ['something.23.test', 'something.{id}.test', true],
            ['something.23.test.42', 'something.{id}.test.{id2}', true],
            ['something-23:test-42', 'something-{id}:test-{id2}', true],
            ['something..test.42', 'something.{id}.test.{id2}', false],
            ['23:string:test', '{id}:string:{text}', true],
            ['something.23', 'something', false],
            ['something.23.test.42', 'something.test.{id}', false],
            ['something-23-test-42', 'something-{id}-test', false],
            ['23:test', '{id}:test:abcd', false],
            ['customer.order.1', 'order.{id}', false],
            ['customerorder.1', 'order.{id}', false],
        ];
    }

    public function testChannelsAreSharedAcrossBroadcasterInstances(): void
    {
        // Simulate boot time: register channel on first broadcaster instance
        $broadcasterA = new FakeBroadcaster(m::mock(Container::class));
        $broadcasterA->channel('App.Models.User.{id}', function (mixed $user, string $id): bool {
            return (int) $user->id === (int) $id;
        });

        // Simulate auth request time: create a second broadcaster instance
        $broadcasterB = new FakeBroadcaster(m::mock(Container::class));

        // The second instance should see the channel registered on the first
        $channels = $broadcasterB->getChannels();

        $this->assertCount(1, $channels);
        $this->assertArrayHasKey('App.Models.User.{id}', $channels->toArray());
    }
}

class FakeBroadcaster extends Broadcaster
{
    /**
     * Create a new broadcaster instance.
     */
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * Authenticate the incoming request.
     */
    public function auth(Request $request): mixed
    {
        return null;
    }

    /**
     * Return the valid authentication response.
     */
    public function validAuthenticationResponse(Request $request, mixed $result): mixed
    {
        return $result;
    }

    /**
     * Broadcast the given event.
     */
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
    }

    /**
     * Extract the parameters from the given pattern and channel.
     */
    public function extractAuthParameters(string $pattern, string $channel, callable|string $callback): array
    {
        return parent::extractAuthParameters($pattern, $channel, $callback);
    }

    /**
     * Retrieve options for the given channel.
     */
    public function retrieveChannelOptions(string $channel): array
    {
        return parent::retrieveChannelOptions($channel);
    }

    /**
     * Retrieve the authenticated user for the channel.
     */
    public function retrieveUser(Request $request, string $channel): mixed
    {
        return parent::retrieveUser($request, $channel);
    }

    /**
     * Determine whether the channel matches the pattern.
     */
    public function channelNameMatchesPattern(string $channel, string $pattern): bool
    {
        return parent::channelNameMatchesPattern($channel, $pattern);
    }

    /**
     * Format outgoing channel names.
     */
    public function formatOutgoingChannels(array $channels): array
    {
        return parent::formatChannels($channels);
    }

    /**
     * Verify access to the given channel.
     */
    public function verifyAccess(Request $request, string $channel, bool $guarded = false): mixed
    {
        return parent::verifyUserCanAccessChannel($request, $channel, $guarded);
    }
}

class PublicResponseOnlyBroadcaster extends FakeBroadcaster
{
    /**
     * Return the valid authentication response.
     */
    public function validAuthenticationResponse(Request $request, mixed $result): mixed
    {
        return ['result' => $result];
    }
}

class StringableBroadcastChannel
{
    public int $stringConversions = 0;

    /**
     * Create a new channel instance.
     */
    public function __construct(protected string $name)
    {
    }

    /**
     * Get the channel name.
     */
    public function __toString(): string
    {
        ++$this->stringConversions;

        return $this->name;
    }
}

class BroadcasterTestEloquentModelStub extends Model
{
    public string $boundValue = '';

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

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

class BroadcasterTestEloquentModelNotFoundStub extends Model
{
    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Retrieve the model for a bound value.
     */
    public function resolveRouteBinding(mixed $value, ?string $field = null): ?self
    {
        return null;
    }
}

class DummyBroadcastingChannel
{
    /**
     * Authorize access to the channel.
     */
    public function join(mixed $user, BroadcasterTestEloquentModelStub $model, string $nonModel): void
    {
    }
}

class BroadcasterTestCallableChannelHandler
{
    /**
     * Return the user and model passed to the channel handler.
     */
    public function authorize(DummyUser $user, BroadcasterTestEloquentModelStub $order): array
    {
        return ['user' => $user, 'order' => $order];
    }

    /**
     * Return the user and model passed to the static channel handler.
     */
    public static function authorizeStatic(DummyUser $user, BroadcasterTestEloquentModelStub $order): array
    {
        return ['user' => $user, 'order' => $order];
    }

    /**
     * Return the user and model passed to the invokable channel handler.
     */
    public function __invoke(DummyUser $user, BroadcasterTestEloquentModelStub $order): array
    {
        return ['user' => $user, 'order' => $order];
    }
}

class DummyUser implements Authenticatable
{
    /**
     * Get the name of the unique identifier for the user.
     */
    public function getAuthIdentifierName(): string
    {
        return 'dummy_user';
    }

    /**
     * Get the unique identifier for the user.
     */
    public function getAuthIdentifier(): string
    {
        return 'dummy_user';
    }

    /**
     * Get the password for the user.
     */
    public function getAuthPassword(): string
    {
        return 'dummy_password';
    }

    /**
     * Get the password attribute name for the user.
     */
    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    /**
     * Get the remember token for the user.
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    /**
     * Set the remember token for the user.
     */
    public function setRememberToken(string $value): void
    {
    }

    /**
     * Get the remember token attribute name for the user.
     */
    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
