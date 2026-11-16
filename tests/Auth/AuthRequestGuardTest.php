<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\RequestGuard;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class AuthRequestGuardTest extends TestCase
{
    use VerifiesDoubles;

    public function testCallbackReceivesRequestAndProvider(): void
    {
        $request = Request::create('/');
        $provider = Double::for(UserProvider::class);
        RequestContext::set($request);

        $receivedRequest = null;
        $receivedProvider = null;

        $guard = new RequestGuard('custom', function ($req, $prov) use (&$receivedRequest, &$receivedProvider) {
            $receivedRequest = $req;
            $receivedProvider = $prov;

            return Double::for(Authenticatable::class);
        }, $this->app, $provider);

        $guard->user();

        $this->assertSame($request, $receivedRequest);
        $this->assertSame($provider, $receivedProvider);
    }

    public function testUserReturnsCachedUserOnSubsequentCalls(): void
    {
        RequestContext::set(Request::create('/'));

        $callCount = 0;
        $user = Double::for(Authenticatable::class);

        $guard = new RequestGuard('custom', function () use (&$callCount, $user) {
            ++$callCount;

            return $user;
        }, $this->app);

        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->user());
        $this->assertSame(1, $callCount);
    }

    public function testNullUserIsCachedViaSentinel(): void
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

    public function testHasUserReturnsTrueWhenUserExists(): void
    {
        RequestContext::set(Request::create('/'));

        $guard = new RequestGuard('custom', fn () => Double::for(Authenticatable::class), $this->app);
        $guard->user();

        $this->assertTrue($guard->hasUser());
    }

    public function testHasUserReturnsFalseWhenNoUser(): void
    {
        $guard = new RequestGuard('custom', fn () => null, $this->app);

        $this->assertFalse($guard->hasUser());
    }

    public function testHasUserReturnsFalseAfterNullUserCached(): void
    {
        RequestContext::set(Request::create('/'));

        $guard = new RequestGuard('custom', fn () => null, $this->app);
        $guard->user();

        $this->assertFalse($guard->hasUser());
    }

    public function testSetUserOverridesCachedUser(): void
    {
        RequestContext::set(Request::create('/'));

        $originalUser = Double::for(Authenticatable::class);
        $newUser = Double::for(Authenticatable::class);

        $guard = new RequestGuard('custom', fn () => $originalUser, $this->app);
        $guard->user();

        $guard->setUser($newUser);

        $this->assertSame($newUser, $guard->user());
        $this->assertTrue($guard->hasUser());
    }

    public function testSetUserOverridesTheResolverAcrossRequestChanges(): void
    {
        RequestContext::set(Request::create('/first'));

        $resolvedUser = Double::for(Authenticatable::class);
        $explicitUser = Double::for(Authenticatable::class);
        $guard = new RequestGuard('custom', fn () => $resolvedUser, $this->app);

        $guard->setUser($explicitUser);
        RequestContext::set(Request::create('/second'));

        $this->assertSame($explicitUser, $guard->user());
    }

    public function testForgetUserClearsCachedUser(): void
    {
        RequestContext::set(Request::create('/'));

        $guard = new RequestGuard('custom', fn () => Double::for(Authenticatable::class), $this->app);
        $guard->user();

        $this->assertTrue($guard->hasUser());

        $guard->forgetUser();

        $this->assertFalse($guard->hasUser());
    }

    public function testTwoGuardNamesDoNotCollideInContext(): void
    {
        RequestContext::set(Request::create('/'));

        $user1 = Double::for(Authenticatable::class);
        $user2 = Double::for(Authenticatable::class);

        $guard1 = new RequestGuard('guard_one', fn () => $user1, $this->app);
        $guard2 = new RequestGuard('guard_two', fn () => $user2, $this->app);

        $this->assertSame($user1, $guard1->user());
        $this->assertSame($user2, $guard2->user());
    }

    public function testNewGuardWithTheSameNameStartsWithoutThePreviousGuardsUsers(): void
    {
        RequestContext::set(Request::create('/'));
        $previousGuard = new RequestGuard('custom', fn () => Double::for(Authenticatable::class), $this->app);
        $previousGuard->user();
        $previousGuard->setUser(Double::for(Authenticatable::class));
        $callCount = 0;

        $guard = new RequestGuard('custom', function () use (&$callCount) {
            ++$callCount;

            return null;
        }, $this->app);

        $this->assertFalse($guard->hasUser());
        $this->assertNull($guard->user());
        $this->assertSame(1, $callCount);
    }

    public function testReplacingRequestInContextChangesWhatGuardSees(): void
    {
        $request1 = Request::create('/one');
        $request2 = Request::create('/two');
        RequestContext::set($request1);

        $seenRequests = [];

        $guard = new RequestGuard('custom', function ($req) use (&$seenRequests) {
            $seenRequests[] = $req;

            return Double::for(Authenticatable::class);
        }, $this->app);

        // First call sees request1
        $guard->user();

        // A replacement request must run its own authentication callback.
        RequestContext::set($request2);
        $this->assertFalse($guard->hasUser());

        // Second call should see request2
        $guard->user();

        $this->assertSame($request1, $seenRequests[0]);
        $this->assertSame($request2, $seenRequests[1]);
    }

    public function testAuthContextKeysIncludeOnlyDurableExplicitState(): void
    {
        $guard = new RequestGuard('custom', fn () => null, $this->app);

        $this->assertSame([
            (fn (): string => $this->getExplicitUserContextKey())->call($guard),
        ], $guard->getAuthContextKeys());
    }

    public function testValidateCallsCallbackWithCredentialsRequest(): void
    {
        $request = Request::create('/');
        $provider = Double::for(UserProvider::class);

        $guard = new RequestGuard('custom', function ($req) {
            return $req instanceof Request ? Double::for(Authenticatable::class) : null;
        }, $this->app, $provider);

        $this->assertTrue($guard->validate(['request' => $request]));
    }

    public function testValidateReturnsFalseWhenCallbackReturnsNull(): void
    {
        $request = Request::create('/');

        $guard = new RequestGuard('custom', fn () => null, $this->app);

        $this->assertFalse($guard->validate(['request' => $request]));
    }
}
