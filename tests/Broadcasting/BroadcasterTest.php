<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Exception;
use Hyperf\Context\RequestContext;
use Hyperf\Database\Model\Booted;
use Hyperf\HttpMessage\Server\Request as ServerRequest;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Request;
use Hypervel\Auth\AuthManager;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Auth\Contracts\Guard;
use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Database\Eloquent\Model;
use Hypervel\HttpMessage\Exceptions\HttpException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @internal
 * @coversNothing
 */
class BroadcasterTest extends TestCase
{
    protected ContainerInterface $container;

    protected FakeBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(ContainerInterface::class);

        $this->broadcaster = new FakeBroadcaster($this->container);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        m::close();

        FakeBroadcaster::flushChannels();
    }

    public function testExtractingParametersWhileCheckingForUserAccess()
    {
        Booted::$container[BroadcasterTestEloquentModelStub::class] = true;

        $callback = function ($user, BroadcasterTestEloquentModelStub $model, $nonModel) {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd.{model}.{nonModel}', 'asd.1.something', $callback);
        $this->assertEquals(['model.1.instance', 'something'], $parameters);

        $callback = function ($user, BroadcasterTestEloquentModelStub $model, BroadcasterTestEloquentModelStub $model2, $something) {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd.{model}.{model2}.{nonModel}', 'asd.1.uid.something', $callback);
        $this->assertEquals(['model.1.instance', 'model.uid.instance', 'something'], $parameters);

        $callback = function ($user) {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd', 'asd', $callback);
        $this->assertEquals([], $parameters);

        $callback = function ($user, $something) {
        };
        $parameters = $this->broadcaster->extractAuthParameters('asd', 'asd', $callback);
        $this->assertEquals([], $parameters);
    }

    public function testCanUseChannelClasses()
    {
        $parameters = $this->broadcaster->extractAuthParameters('asd.{model}.{nonModel}', 'asd.1.something', DummyBroadcastingChannel::class);
        $this->assertEquals(['model.1.instance', 'something'], $parameters);
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
        Booted::$container[BroadcasterTestEloquentModelNotFoundStub::class] = true;

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

        $authManager = m::mock(AuthManager::class);
        $authManager->shouldReceive('user')
            ->once()
            ->withNoArgs()
            ->andReturn(new DummyUser());

        $this->container->shouldReceive('get')
            ->once()
            ->with(AuthManager::class)
            ->andReturn($authManager);

        $this->assertInstanceOf(
            DummyUser::class,
            $this->broadcaster->retrieveUser('somechannel')
        );
    }

    public function testRetrieveUserWithOneGuardUsingAStringForSpecifyingGuard()
    {
        $this->broadcaster->channel('somechannel', function () {
        }, ['guards' => 'myguard']);

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('user')
            ->once()
            ->withNoArgs()
            ->andReturn(new DummyUser());
        $authManager = m::mock(AuthManager::class);
        $authManager->shouldReceive('guard')
            ->once()
            ->with('myguard')
            ->andReturn($guard);

        $this->container->shouldReceive('get')
            ->once()
            ->with(AuthManager::class)
            ->andReturn($authManager);

        $this->assertInstanceOf(
            DummyUser::class,
            $this->broadcaster->retrieveUser('somechannel')
        );
    }

    public function testRetrieveUserWithMultipleGuardsAndRespectGuardsOrder()
    {
        $this->broadcaster->channel('somechannel', function () {
        }, ['guards' => ['myguard1', 'myguard2']]);
        $this->broadcaster->channel('someotherchannel', function () {
        }, ['guards' => ['myguard2', 'myguard1']]);

        $guard1 = m::mock(Guard::class);
        $guard1->shouldReceive('user')
            ->once()
            ->andReturn(null);
        $guard2 = m::mock(Guard::class);
        $guard2->shouldReceive('user')
            ->twice()
            ->andReturn(new DummyUser());
        $authManager = m::mock(AuthManager::class);
        $authManager->shouldReceive('guard')
            ->once()
            ->with('myguard1')
            ->andReturn($guard1);
        $authManager->shouldReceive('guard')
            ->twice()
            ->with('myguard2')
            ->andReturn($guard2);
        $authManager->shouldNotReceive('guard')
            ->withNoArgs();

        $this->container->shouldReceive('get')
            ->twice()
            ->with(AuthManager::class)
            ->andReturn($authManager);

        $this->assertInstanceOf(
            DummyUser::class,
            $this->broadcaster->retrieveUser('somechannel')
        );

        $this->assertInstanceOf(
            DummyUser::class,
            $this->broadcaster->retrieveUser('someotherchannel')
        );
    }

    public function testRetrieveUserDontUseDefaultGuardWhenOneGuardSpecified()
    {
        $this->broadcaster->channel('somechannel', function () {
        }, ['guards' => 'myguard']);

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('user')
            ->once()
            ->andReturn(new DummyUser());
        $authManager = m::mock(AuthManager::class);
        $authManager->shouldReceive('guard')
            ->once()
            ->with('myguard')
            ->andReturn($guard);
        $authManager->shouldNotReceive('guard')
            ->withNoArgs();

        $this->container->shouldReceive('get')
            ->once()
            ->with(AuthManager::class)
            ->andReturn($authManager);

        $this->broadcaster->retrieveUser('somechannel');
    }

    public function testRetrieveUserDontUseDefaultGuardWhenMultipleGuardsSpecified()
    {
        $this->broadcaster->channel('somechannel', function () {
        }, ['guards' => ['myguard1', 'myguard2']]);

        $guard = m::mock(Guard::class);
        $guard->shouldReceive('user')
            ->twice()
            ->andReturn(null);
        $authManager = m::mock(AuthManager::class);
        $authManager->shouldReceive('guard')
            ->once()
            ->with('myguard1')
            ->andReturn($guard);
        $authManager->shouldReceive('guard')
            ->once()
            ->with('myguard2')
            ->andReturn($guard);
        $authManager->shouldNotReceive('guard')
            ->withNoArgs();

        $this->container->shouldReceive('get')
            ->once()
            ->with(AuthManager::class)
            ->andReturn($authManager);

        $this->broadcaster->retrieveUser('somechannel');
    }

    public function testUserAuthenticationWithValidUser()
    {
        $this->broadcaster->resolveAuthenticatedUserUsing(function ($request) {
            return ['id' => '12345', 'socket' => $request->input('socket_id')];
        });

        $this->mockRequest('http://exa.com/foo?socket_id=1234.1234#boom');
        $user = $this->broadcaster->resolveAuthenticatedUser(new Request());

        $this->assertSame([
            'id' => '12345',
            'socket' => '1234.1234',
        ], $user);
    }

    private function mockRequest(?string $uri = null): void
    {
        $request = new ServerRequest('GET', $uri ?: 'http://example.com/foo?bar=baz#boom');
        parse_str($request->getUri()->getQuery(), $result);
        $request = $request->withQueryParams($result);

        RequestContext::set($request);
    }

    public function testUserAuthenticationWithInvalidUser()
    {
        $this->broadcaster->resolveAuthenticatedUserUsing(function ($request) {
            return null;
        });

        $this->mockRequest('http://exa.com/foo?socket_id=1234.1234#boom');
        $user = $this->broadcaster->resolveAuthenticatedUser(new Request());

        $this->assertNull($user);
    }

    public function testUserAuthenticationWithoutResolve()
    {
        $this->mockRequest('http://exa.com/foo?socket_id=1234.1234#boom');
        $this->assertNull($this->broadcaster->resolveAuthenticatedUser(new Request()));
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
        $container = m::mock(ContainerInterface::class);

        // Simulate boot time: register channel on first broadcaster instance
        $broadcasterA = new FakeBroadcaster($container);
        $broadcasterA->channel('App.Models.User.{id}', function ($user, $id) {
            return (int) $user->id === (int) $id;
        });

        // Simulate auth request time: create a second broadcaster instance
        $broadcasterB = new FakeBroadcaster($container);

        // The second instance should see the channel registered on the first
        $channels = $broadcasterB->getChannels();

        $this->assertCount(1, $channels);
        $this->assertArrayHasKey('App.Models.User.{id}', $channels->toArray());
    }
}

class FakeBroadcaster extends Broadcaster
{
    public function __construct(
        protected ContainerInterface $container
    ) {
    }

    public function auth(RequestInterface $request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse(RequestInterface $request, mixed $result): mixed
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

    public function retrieveUser(string $channel): mixed
    {
        return parent::retrieveUser($channel);
    }

    public function channelNameMatchesPattern(string $channel, string $pattern): bool
    {
        return parent::channelNameMatchesPattern($channel, $pattern);
    }
}

class BroadcasterTestEloquentModelStub extends Model
{
    public function getRouteKeyName()
    {
        return 'id';
    }

    public function where($key, $value)
    {
        $this->value = $value;

        return $this;
    }

    public function firstOrFail()
    {
        return "model.{$this->value}.instance";
    }
}

class BroadcasterTestEloquentModelNotFoundStub extends Model
{
    public function getRouteKeyName()
    {
        return 'id';
    }

    public function where($key, $value)
    {
        $this->value = $value;

        return $this;
    }

    public function firstOrFail()
    {
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
}
