<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\RequestGuard;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class AuthRequestGuardTest extends TestCase
{
    public function testCallbackReceivesRequestAndProvider()
    {
        $request = Request::create('/');
        $provider = m::mock(UserProvider::class);
        RequestContext::set($request);

        $receivedRequest = null;
        $receivedProvider = null;

        $guard = new RequestGuard('custom', function ($req, $prov) use (&$receivedRequest, &$receivedProvider) {
            $receivedRequest = $req;
            $receivedProvider = $prov;

            return m::mock(Authenticatable::class);
        }, $this->app, $provider);

        $guard->user();

        $this->assertSame($request, $receivedRequest);
        $this->assertSame($provider, $receivedProvider);
    }

    public function testUserReturnsCachedUserOnSubsequentCalls()
    {
        RequestContext::set(Request::create('/'));

        $callCount = 0;
        $user = m::mock(Authenticatable::class);

        $guard = new RequestGuard('custom', function () use (&$callCount, $user) {
            ++$callCount;

            return $user;
        }, $this->app);

        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->user());
        $this->assertSame(1, $callCount);
    }

    public function testNullUserIsCachedViaSentinel()
    {
        RequestContext::set(Request::create('/'));

        $callCount = 0;

        $guard = new RequestGuard('custom', function () use (&$callCount) {
            ++$callCount;

            return null;
        }, $this->app);

        $this->assertNull($guard->user());
        $this->assertNull($guard->user());
        $this->assertSame(1, $callCount);
    }

    public function testHasUserReturnsTrueWhenUserExists()
    {
        RequestContext::set(Request::create('/'));

        $guard = new RequestGuard('custom', fn () => m::mock(Authenticatable::class), $this->app);
        $guard->user();

        $this->assertTrue($guard->hasUser());
    }

    public function testHasUserReturnsFalseWhenNoUser()
    {
        $guard = new RequestGuard('custom', fn () => null, $this->app);

        $this->assertFalse($guard->hasUser());
    }

    public function testHasUserReturnsFalseAfterNullUserCached()
    {
        RequestContext::set(Request::create('/'));

        $guard = new RequestGuard('custom', fn () => null, $this->app);
        $guard->user();

        $this->assertFalse($guard->hasUser());
    }

    public function testSetUserOverridesCachedUser()
    {
        RequestContext::set(Request::create('/'));

        $originalUser = m::mock(Authenticatable::class);
        $newUser = m::mock(Authenticatable::class);

        $guard = new RequestGuard('custom', fn () => $originalUser, $this->app);
        $guard->user();

        $guard->setUser($newUser);

        $this->assertSame($newUser, $guard->user());
        $this->assertTrue($guard->hasUser());
    }

    public function testForgetUserClearsCachedUser()
    {
        RequestContext::set(Request::create('/'));

        $guard = new RequestGuard('custom', fn () => m::mock(Authenticatable::class), $this->app);
        $guard->user();

        $this->assertTrue($guard->hasUser());

        $guard->forgetUser();

        $this->assertFalse($guard->hasUser());
    }

    public function testTwoGuardNamesDoNotCollideInContext()
    {
        RequestContext::set(Request::create('/'));

        $user1 = m::mock(Authenticatable::class);
        $user2 = m::mock(Authenticatable::class);

        $guard1 = new RequestGuard('guard_one', fn () => $user1, $this->app);
        $guard2 = new RequestGuard('guard_two', fn () => $user2, $this->app);

        $this->assertSame($user1, $guard1->user());
        $this->assertSame($user2, $guard2->user());
    }

    public function testReplacingRequestInContextChangesWhatGuardSees()
    {
        $request1 = Request::create('/one');
        $request2 = Request::create('/two');
        RequestContext::set($request1);

        $seenRequests = [];

        $guard = new RequestGuard('custom', function ($req) use (&$seenRequests) {
            $seenRequests[] = $req;

            return m::mock(Authenticatable::class);
        }, $this->app);

        // First call sees request1
        $guard->user();

        // Replace request and clear cached user
        RequestContext::set($request2);
        $guard->forgetUser();

        // Second call should see request2
        $guard->user();

        $this->assertSame($request1, $seenRequests[0]);
        $this->assertSame($request2, $seenRequests[1]);
    }

    public function testValidateCallsCallbackWithCredentialsRequest()
    {
        $request = Request::create('/');
        $provider = m::mock(UserProvider::class);

        $guard = new RequestGuard('custom', function ($req) {
            return $req instanceof Request ? m::mock(Authenticatable::class) : null;
        }, $this->app, $provider);

        $this->assertTrue($guard->validate(['request' => $request]));
    }

    public function testValidateReturnsFalseWhenCallbackReturnsNull()
    {
        $request = Request::create('/');

        $guard = new RequestGuard('custom', fn () => null, $this->app);

        $this->assertFalse($guard->validate(['request' => $request]));
    }
}
