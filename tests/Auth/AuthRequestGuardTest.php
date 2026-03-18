<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\RequestGuard;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class AuthRequestGuardTest extends TestCase
{
    public function testCallbackReceivesRequestAndProvider()
    {
        $container = new Container();
        $request = m::mock(Request::class);
        $provider = m::mock(UserProvider::class);
        $container->instance('request', $request);

        $receivedRequest = null;
        $receivedProvider = null;

        $guard = new RequestGuard('custom', function ($req, $prov) use (&$receivedRequest, &$receivedProvider) {
            $receivedRequest = $req;
            $receivedProvider = $prov;

            return m::mock(Authenticatable::class);
        }, $container, $provider);

        $guard->user();

        $this->assertSame($request, $receivedRequest);
        $this->assertSame($provider, $receivedProvider);
    }

    public function testUserReturnsCachedUserOnSubsequentCalls()
    {
        $container = new Container();
        $container->instance('request', m::mock(Request::class));

        $callCount = 0;
        $user = m::mock(Authenticatable::class);

        $guard = new RequestGuard('custom', function () use (&$callCount, $user) {
            ++$callCount;

            return $user;
        }, $container);

        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->user());
        $this->assertSame(1, $callCount);
    }

    public function testNullUserIsCachedViaSentinel()
    {
        $container = new Container();
        $container->instance('request', m::mock(Request::class));

        $callCount = 0;

        $guard = new RequestGuard('custom', function () use (&$callCount) {
            ++$callCount;

            return null;
        }, $container);

        $this->assertNull($guard->user());
        $this->assertNull($guard->user());
        $this->assertSame(1, $callCount);
    }

    public function testHasUserReturnsTrueWhenUserExists()
    {
        $container = new Container();
        $container->instance('request', m::mock(Request::class));

        $guard = new RequestGuard('custom', fn () => m::mock(Authenticatable::class), $container);
        $guard->user();

        $this->assertTrue($guard->hasUser());
    }

    public function testHasUserReturnsFalseWhenNoUser()
    {
        $container = new Container();

        $guard = new RequestGuard('custom', fn () => null, $container);

        $this->assertFalse($guard->hasUser());
    }

    public function testHasUserReturnsFalseAfterNullUserCached()
    {
        $container = new Container();
        $container->instance('request', m::mock(Request::class));

        $guard = new RequestGuard('custom', fn () => null, $container);
        $guard->user();

        $this->assertFalse($guard->hasUser());
    }

    public function testSetUserOverridesCachedUser()
    {
        $container = new Container();
        $container->instance('request', m::mock(Request::class));

        $originalUser = m::mock(Authenticatable::class);
        $newUser = m::mock(Authenticatable::class);

        $guard = new RequestGuard('custom', fn () => $originalUser, $container);
        $guard->user();

        $guard->setUser($newUser);

        $this->assertSame($newUser, $guard->user());
        $this->assertTrue($guard->hasUser());
    }

    public function testForgetUserClearsCachedUser()
    {
        $container = new Container();
        $container->instance('request', m::mock(Request::class));

        $guard = new RequestGuard('custom', fn () => m::mock(Authenticatable::class), $container);
        $guard->user();

        $this->assertTrue($guard->hasUser());

        $guard->forgetUser();

        $this->assertFalse($guard->hasUser());
    }

    public function testTwoGuardNamesDoNotCollideInContext()
    {
        $container = new Container();
        $container->instance('request', m::mock(Request::class));

        $user1 = m::mock(Authenticatable::class);
        $user2 = m::mock(Authenticatable::class);

        $guard1 = new RequestGuard('guard_one', fn () => $user1, $container);
        $guard2 = new RequestGuard('guard_two', fn () => $user2, $container);

        $this->assertSame($user1, $guard1->user());
        $this->assertSame($user2, $guard2->user());
    }

    public function testReplacingRequestInContainerChangesWhatGuardSees()
    {
        $container = new Container();
        $request1 = m::mock(Request::class);
        $request2 = m::mock(Request::class);
        $container->instance('request', $request1);

        $seenRequests = [];

        $guard = new RequestGuard('custom', function ($req) use (&$seenRequests) {
            $seenRequests[] = $req;

            return m::mock(Authenticatable::class);
        }, $container);

        // First call sees request1
        $guard->user();

        // Replace request and clear cached user
        $container->instance('request', $request2);
        $guard->forgetUser();

        // Second call should see request2
        $guard->user();

        $this->assertSame($request1, $seenRequests[0]);
        $this->assertSame($request2, $seenRequests[1]);
    }

    public function testValidateCallsCallbackWithCredentialsRequest()
    {
        $container = new Container();
        $request = m::mock(Request::class);
        $provider = m::mock(UserProvider::class);

        $guard = new RequestGuard('custom', function ($req) {
            return $req instanceof Request ? m::mock(Authenticatable::class) : null;
        }, $container, $provider);

        $this->assertTrue($guard->validate(['request' => $request]));
    }

    public function testValidateReturnsFalseWhenCallbackReturnsNull()
    {
        $container = new Container();
        $request = m::mock(Request::class);

        $guard = new RequestGuard('custom', fn () => null, $container);

        $this->assertFalse($guard->validate(['request' => $request]));
    }
}
