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
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @internal
 * @coversNothing
 */
class BroadcasterTest extends TestCase
{
    protected Container $container;

    protected FakeBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(Container::class);
        $this->container->shouldReceive('bound')->with(BindingRegistrar::class)->andReturnFalse()->byDefault();

        $this->broadcaster = new FakeBroadcaster($this->container);
    }

    public function testExtractingParametersWhileCheckingForUserAccess()
    {
        $callback = function ($user, BroadcasterTestEloquentModelStub $model, $nonModel) {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd.{model}.{nonModel}', 'asd.1.something', $callback);
        $this->assertCount(2, $parameters);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $parameters[0]);
        $this->assertSame('1', $parameters[0]->boundValue);
        $this->assertSame('something', $parameters[1]);

        $callback = function ($user, BroadcasterTestEloquentModelStub $model, BroadcasterTestEloquentModelStub $model2, $something) {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd.{model}.{model2}.{nonModel}', 'asd.1.uid.something', $callback);
        $this->assertCount(3, $parameters);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $parameters[0]);
        $this->assertSame('1', $parameters[0]->boundValue);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $parameters[1]);
        $this->assertSame('uid', $parameters[1]->boundValue);
        $this->assertSame('something', $parameters[2]);

        $callback = function ($user) {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd', 'asd', $callback);
        $this->assertEquals([], $parameters);

        $callback = function ($user, $something) {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd', 'asd', $callback);
        $this->assertEquals([], $parameters);

        // Test Explicit Binding...
        $binder = m::mock(BindingRegistrar::class);
        $binder->shouldReceive('getBindingCallback')->times(2)->with('model')->andReturn(function () {
            return 'bound';
        });
        $this->container->shouldReceive('bound')->with(BindingRegistrar::class)->andReturnTrue();
        $this->container->shouldReceive('make')->with(BindingRegistrar::class)->andReturn($binder);
        $callback = function ($user, $model) {
        };
        $parameters = $this->broadcaster->extractAuthParameters('something.{model}', 'something.1', $callback);
        $this->assertEquals(['bound'], $parameters);
    }

    public function testCanUseChannelClasses()
    {
        $parameters = $this->broadcaster->extractAuthParameters('asd.{model}.{nonModel}', 'asd.1.something', DummyBroadcastingChannel::class);
        $this->assertCount(2, $parameters);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $parameters[0]);
        $this->assertSame('1', $parameters[0]->boundValue);
        $this->assertSame('something', $parameters[1]);
    }

    public function testModelRouteBinding()
    {
        $binder = m::mock(BindingRegistrar::class);
        $routeModelCallback = RouteBinding::forModel($this->container, BroadcasterTestEloquentModelStub::class);

        $binder->shouldReceive('getBindingCallback')->times(2)->with('model')->andReturn($routeModelCallback);
        $this->container->shouldReceive('bound')->with(BindingRegistrar::class)->andReturnTrue();
        $this->container->shouldReceive('make')->with(BindingRegistrar::class)->andReturn($binder);
        $this->container->shouldReceive('make')->with(BroadcasterTestEloquentModelStub::class)->andReturn(new BroadcasterTestEloquentModelStub);
        $callback = function ($user, $model) {
        };
        $parameters = $this->broadcaster->extractAuthParameters('something.{model}', 'something.1', $callback);
        $this->assertCount(1, $parameters);
        $this->assertInstanceOf(BroadcasterTestEloquentModelStub::class, $parameters[0]);
        $this->assertSame('1', $parameters[0]->boundValue);
    }

    public function testUnknownChannelAuthHandlerTypeThrowsException()
    {
        $this->expectException(Exception::class);

        $this->broadcaster->extractAuthParameters('asd.{model}.{nonModel}', 'asd.1.something', 'notClassString');
    }

    public function testCanRegisterChannelsAsClasses()
    {
        $this->broadcaster->channel('something', function () {
        });

        $this->broadcaster->channel('somethingelse', DummyBroadcastingChannel::class);
    }

    public function testNotFoundThrowsHttpException()
    {
        $this->expectException(HttpException::class);

        $callback = function ($user, BroadcasterTestEloquentModelNotFoundStub $model) {
        };
        $this->broadcaster->extractAuthParameters('asd.{model}', 'asd.1', $callback);
    }

    public function testCanRegisterChannelsWithoutOptions()
    {
        $this->broadcaster->channel('somechannel', function () {
        });
    }

    public function testCanRegisterChannelsWithOptions()
    {
        $options = ['a' => ['b', 'c']];
        $this->broadcaster->channel('somechannel', function () {
        }, $options);
    }

    public function testCanRetrieveChannelsOptions()
    {
        $options = ['a' => ['b', 'c']];
        $this->broadcaster->channel('somechannel', function () {
        }, $options);

        $this->assertEquals(
            $options,
            $this->broadcaster->retrieveChannelOptions('somechannel')
        );
    }

    public function testCanRetrieveChannelsOptionsUsingAChannelNameContainingArgs()
    {
        $options = ['a' => ['b', 'c']];
        $this->broadcaster->channel('somechannel.{id}.test.{text}', function () {
        }, $options);

        $this->assertEquals(
            $options,
            $this->broadcaster->retrieveChannelOptions('somechannel.23.test.mytext')
        );
    }

    public function testCanRetrieveChannelsOptionsWhenMultipleChannelsAreRegistered()
    {
        $options = ['a' => ['b', 'c']];
        $this->broadcaster->channel('somechannel', function () {
        });
        $this->broadcaster->channel('someotherchannel', function () {
        }, $options);

        $this->assertEquals(
            $options,
            $this->broadcaster->retrieveChannelOptions('someotherchannel')
        );
    }

    public function testDontRetrieveChannelsOptionsWhenChannelDoesntExists()
    {
        $options = ['a' => ['b', 'c']];
        $this->broadcaster->channel('somechannel', function () {
        }, $options);

        $this->assertEquals(
            [],
            $this->broadcaster->retrieveChannelOptions('someotherchannel')
        );
    }

    public function testRetrieveUserWithoutGuard()
    {
        $this->broadcaster->channel('somechannel', function () {
        });

        $request = m::mock(Request::class);
        $request->shouldReceive('user')
            ->once()
            ->withNoArgs()
            ->andReturn(new DummyUser);

        $this->assertInstanceOf(
            DummyUser::class,
            $this->broadcaster->retrieveUser($request, 'somechannel')
        );
    }

    public function testRetrieveUserWithOneGuardUsingAStringForSpecifyingGuard()
    {
        $this->broadcaster->channel('somechannel', function () {
        }, ['guards' => 'myguard']);

        $request = m::mock(Request::class);
        $request->shouldReceive('user')
            ->once()
            ->with('myguard')
            ->andReturn(new DummyUser);

        $this->assertInstanceOf(
            DummyUser::class,
            $this->broadcaster->retrieveUser($request, 'somechannel')
        );
    }

    public function testRetrieveUserWithMultipleGuardsAndRespectGuardsOrder()
    {
        $this->broadcaster->channel('somechannel', function () {
        }, ['guards' => ['myguard1', 'myguard2']]);
        $this->broadcaster->channel('someotherchannel', function () {
        }, ['guards' => ['myguard2', 'myguard1']]);

        $request = m::mock(Request::class);
        $request->shouldReceive('user')
            ->once()
            ->with('myguard1')
            ->andReturn(null);
        $request->shouldReceive('user')
            ->twice()
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

    public function testRetrieveUserDontUseDefaultGuardWhenOneGuardSpecified()
    {
        $this->broadcaster->channel('somechannel', function () {
        }, ['guards' => 'myguard']);

        $request = m::mock(Request::class);
        $request->shouldReceive('user')
            ->once()
            ->with('myguard')
            ->andReturn(null);
        $request->shouldNotReceive('user')
            ->withNoArgs();

        $this->broadcaster->retrieveUser($request, 'somechannel');
    }

    public function testRetrieveUserDontUseDefaultGuardWhenMultipleGuardsSpecified()
    {
        $this->broadcaster->channel('somechannel', function () {
        }, ['guards' => ['myguard1', 'myguard2']]);

        $request = m::mock(Request::class);
        $request->shouldReceive('user')
            ->once()
            ->with('myguard1')
            ->andReturn(null);
        $request->shouldReceive('user')
            ->once()
            ->with('myguard2')
            ->andReturn(null);
        $request->shouldNotReceive('user')
            ->withNoArgs();

        $this->broadcaster->retrieveUser($request, 'somechannel');
    }

    public function testUserAuthenticationWithValidUser()
    {
        $this->broadcaster->resolveAuthenticatedUserUsing(function ($request) {
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

    public function testUserAuthenticationWithInvalidUser()
    {
        $this->broadcaster->resolveAuthenticatedUserUsing(function ($request) {
            return null;
        });

        $user = $this->broadcaster->resolveAuthenticatedUser(
            Request::create('http://exa.com/foo?socket_id=1234.1234#boom')
        );

        $this->assertNull($user);
    }

    public function testUserAuthenticationWithoutResolve()
    {
        $this->assertNull($this->broadcaster->resolveAuthenticatedUser(
            Request::create('http://exa.com/foo?socket_id=1234.1234#boom')
        ));
    }

    #[DataProvider('channelNameMatchPatternProvider')]
    public function testChannelNameMatchPattern($channel, $pattern, $shouldMatch)
    {
        $this->assertEquals($shouldMatch, $this->broadcaster->channelNameMatchesPattern($channel, $pattern));
    }

    public static function channelNameMatchPatternProvider()
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

    public function testChannelsAreSharedAcrossBroadcasterInstances()
    {
        // Simulate boot time: register channel on first broadcaster instance
        $broadcasterA = new FakeBroadcaster(m::mock(Container::class));
        $broadcasterA->channel('App.Models.User.{id}', function ($user, $id) {
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
    public function __construct(
        protected Container $container
    ) {
    }

    public function auth(Request $request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse(Request $request, mixed $result): mixed
    {
        return null;
    }

    public function broadcast(array $channels, string $event, array $payload = []): void
    {
    }

    public function extractAuthParameters(string $pattern, string $channel, callable|string $callback): array
    {
        return parent::extractAuthParameters($pattern, $channel, $callback);
    }

    public function retrieveChannelOptions(string $channel): array
    {
        return parent::retrieveChannelOptions($channel);
    }

    public function retrieveUser(Request $request, string $channel): mixed
    {
        return parent::retrieveUser($request, $channel);
    }

    public function channelNameMatchesPattern(string $channel, string $pattern): bool
    {
        return parent::channelNameMatchesPattern($channel, $pattern);
    }
}

class BroadcasterTestEloquentModelStub extends Model
{
    public string $boundValue = '';

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding(mixed $value, ?string $field = null): ?self
    {
        $instance = new static;
        $instance->boundValue = (string) $value;

        return $instance;
    }
}

class BroadcasterTestEloquentModelNotFoundStub extends Model
{
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding(mixed $value, ?string $field = null): ?self
    {
        return null;
    }
}

class DummyBroadcastingChannel
{
    public function join($user, BroadcasterTestEloquentModelStub $model, $nonModel)
    {
    }
}

class DummyUser implements Authenticatable
{
    public function getAuthIdentifierName(): string
    {
        return 'dummy_user';
    }

    public function getAuthIdentifier(): mixed
    {
        return 'dummy_user';
    }

    public function getAuthPassword(): string
    {
        return 'dummy_password';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(string $value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
