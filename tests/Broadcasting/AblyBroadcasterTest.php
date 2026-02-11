<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Ably\AblyRest;
use Hyperf\HttpServer\Request;
use Hypervel\Auth\AuthManager;
use Hypervel\Broadcasting\Broadcasters\AblyBroadcaster;
use Hypervel\Contracts\Container\Container;
use Hypervel\HttpMessage\Exceptions\AccessDeniedHttpException;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AblyBroadcasterTest extends TestCase
{
    protected AblyBroadcaster $broadcaster;

    protected AblyRest $ably;

    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(Container::class);
        $this->ably = m::mock(AblyRest::class, ['abcd:efg']);
        $this->broadcaster = m::mock(AblyBroadcaster::class, [$this->container, $this->ably])->makePartial();
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

    protected function getMockRequestWithUserForChannel(string $channel): Request
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn($channel);

        $user = m::mock('User');
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $authManager = m::mock(AuthManager::class);
        $authManager->shouldReceive('user')->andReturn($user);

        $this->container->shouldReceive('get')
            ->with(AuthManager::class)
            ->andReturn($authManager);

        return $request;
    }

    protected function getMockRequestWithoutUserForChannel(string $channel): Request
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn($channel);

        $authManager = m::mock(AuthManager::class);
        $authManager->shouldReceive('user')->andReturn(null);

        $this->container->shouldReceive('get')
            ->with(AuthManager::class)
            ->andReturn($authManager);

        return $request;
    }
}
