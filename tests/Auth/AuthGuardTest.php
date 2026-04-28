<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\Events\Attempting;
use Hypervel\Auth\Events\Authenticated;
use Hypervel\Auth\Events\CurrentDeviceLogout;
use Hypervel\Auth\Events\Failed;
use Hypervel\Auth\Events\Login;
use Hypervel\Auth\Events\Logout;
use Hypervel\Auth\Events\Validated;
use Hypervel\Auth\SessionGuard;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Session\Session;
use Hypervel\Cookie\CookieJar;
use Hypervel\Support\Timebox;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthGuardTest extends TestCase
{
    public function testBasicReturnsNullOnValidAttempt()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $basicRequest = Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']);
        $app->shouldReceive('make')->with('request')->andReturn($basicRequest);
        $guard = m::mock(SessionGuard::class . '[check,attempt]', ['default', $provider, $session, $app]);
        $guard->shouldReceive('check')->once()->andReturn(false);
        $guard->shouldReceive('attempt')->once()->with(['email' => 'foo@bar.com', 'password' => 'secret'])->andReturn(true);

        $guard->basic('email');
    }

    public function testBasicReturnsNullWhenAlreadyLoggedIn()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class . '[check]', ['default', $provider, $session, $app]);
        $guard->shouldReceive('check')->once()->andReturn(true);
        $guard->shouldReceive('attempt')->never();

        $guard->basic('email');
    }

    public function testBasicReturnsResponseOnFailure()
    {
        $this->expectException(UnauthorizedHttpException::class);

        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $basicRequest = Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']);
        $app->shouldReceive('make')->with('request')->andReturn($basicRequest);
        $guard = m::mock(SessionGuard::class . '[check,attempt]', ['default', $provider, $session, $app]);
        $guard->shouldReceive('check')->once()->andReturn(false);
        $guard->shouldReceive('attempt')->once()->with(['email' => 'foo@bar.com', 'password' => 'secret'])->andReturn(false);
        $guard->basic('email');
    }

    public function testBasicWithExtraConditions()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $basicRequest = Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']);
        $app->shouldReceive('make')->with('request')->andReturn($basicRequest);
        $guard = m::mock(SessionGuard::class . '[check,attempt]', ['default', $provider, $session, $app]);
        $guard->shouldReceive('check')->once()->andReturn(false);
        $guard->shouldReceive('attempt')->once()->with(['email' => 'foo@bar.com', 'password' => 'secret', 'active' => 1])->andReturn(true);

        $guard->basic('email', ['active' => 1]);
    }

    public function testBasicWithExtraArrayConditions()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $basicRequest = Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']);
        $app->shouldReceive('make')->with('request')->andReturn($basicRequest);
        $guard = m::mock(SessionGuard::class . '[check,attempt]', ['default', $provider, $session, $app]);
        $guard->shouldReceive('check')->once()->andReturn(false);
        $guard->shouldReceive('attempt')->once()->with(['email' => 'foo@bar.com', 'password' => 'secret', 'active' => 1, 'type' => [1, 2, 3]])->andReturn(true);

        $guard->basic('email', ['active' => 1, 'type' => [1, 2, 3]]);
    }

    public function testAttemptCallsRetrieveByCredentials()
    {
        $guard = $this->getGuard();
        $guard->setDispatcher($events = $this->mockEventDispatcher());
        $timebox = $guard->getTimebox();
        $timebox->shouldReceive('call')->once()->andReturnUsing(function ($callback) use ($timebox) {
            return $callback($timebox);
        });
        $events->shouldReceive('dispatch')->once()->with(m::type(Attempting::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(Failed::class));
        $events->shouldNotReceive('dispatch')->with(m::type(Validated::class));
        $guard->getProvider()->shouldReceive('retrieveByCredentials')->once()->with(['foo']);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $guard->attempt(['foo']);
    }

    public function testAttemptReturnsUserInterface()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['login'])->setConstructorArgs(['default', $provider, $session, $app, $timebox])->getMock();
        $guard->setDispatcher($events = $this->mockEventDispatcher());
        $timebox->shouldReceive('call')->once()->andReturnUsing(function ($callback, $microseconds) use ($timebox) {
            return $callback($timebox->shouldReceive('returnEarly')->once()->getMock());
        });
        $events->shouldReceive('dispatch')->once()->with(m::type(Attempting::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(Validated::class));
        $user = $this->createStub(Authenticatable::class);
        $guard->getProvider()->shouldReceive('retrieveByCredentials')->once()->andReturn($user);
        $guard->getProvider()->shouldReceive('validateCredentials')->with($user, ['foo'])->andReturn(true);
        $guard->getProvider()->shouldReceive('rehashPasswordIfRequired')->with($user, ['foo'])->once();
        $guard->expects($this->once())->method('login')->with($this->equalTo($user));
        $this->assertTrue($guard->attempt(['foo']));
    }

    public function testAttemptReturnsFalseIfUserNotGiven()
    {
        $mock = $this->getGuard();
        $mock->setDispatcher($events = $this->mockEventDispatcher());
        $timebox = $mock->getTimebox();
        $timebox->shouldReceive('call')->once()->andReturnUsing(function ($callback, $microseconds) use ($timebox) {
            return $callback($timebox);
        });
        $events->shouldReceive('dispatch')->once()->with(m::type(Attempting::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(Failed::class));
        $events->shouldNotReceive('dispatch')->with(m::type(Validated::class));
        $mock->getProvider()->shouldReceive('retrieveByCredentials')->once()->andReturn(null);
        $mock->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $this->assertFalse($mock->attempt(['foo']));
    }

    public function testAttemptAndWithCallbacks()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName'])->setConstructorArgs(['default', $provider, $session, $app, $timebox])->getMock();
        $mock->setDispatcher($events = $this->mockEventDispatcher());
        $timebox->shouldReceive('call')->andReturnUsing(function ($callback) use ($timebox) {
            return $callback($timebox->shouldReceive('returnEarly')->getMock());
        });
        $user = m::mock(Authenticatable::class);
        $events->shouldReceive('dispatch')->times(3)->with(m::type(Attempting::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(Login::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(Authenticated::class));
        $events->shouldReceive('dispatch')->twice()->with(m::type(Validated::class));
        $events->shouldReceive('dispatch')->twice()->with(m::type(Failed::class));
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $user->shouldReceive('getAuthIdentifier')->once()->andReturn('bar');
        $mock->getSession()->shouldReceive('put')->with('foo', 'bar')->once();
        $session->shouldReceive('regenerate')->once();
        $mock->getProvider()->shouldReceive('retrieveByCredentials')->times(3)->with(['foo'])->andReturn($user);
        $mock->getProvider()->shouldReceive('validateCredentials')->twice()->andReturnTrue();
        $mock->getProvider()->shouldReceive('validateCredentials')->once()->andReturnFalse();
        $mock->getProvider()->shouldReceive('rehashPasswordIfRequired')->with($user, ['foo'])->once();

        $this->assertTrue($mock->attemptWhen(['foo'], function ($user, $guard) {
            static::assertInstanceOf(Authenticatable::class, $user);
            static::assertInstanceOf(SessionGuard::class, $guard);

            return true;
        }));

        $this->assertFalse($mock->attemptWhen(['foo'], function ($user, $guard) {
            static::assertInstanceOf(Authenticatable::class, $user);
            static::assertInstanceOf(SessionGuard::class, $guard);

            return false;
        }));

        $executed = false;

        $this->assertFalse($mock->attemptWhen(['foo'], function () use (&$executed) {
            return $executed = true;
        }));

        $this->assertFalse($executed);
    }

    public function testAttemptRehashesPasswordWhenRequired()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['login'])->setConstructorArgs(['default', $provider, $session, $app, $timebox])->getMock();
        $guard->setDispatcher($events = $this->mockEventDispatcher());
        $timebox->shouldReceive('call')->once()->andReturnUsing(function ($callback, $microseconds) use ($timebox) {
            return $callback($timebox->shouldReceive('returnEarly')->once()->getMock());
        });
        $events->shouldReceive('dispatch')->once()->with(m::type(Attempting::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(Validated::class));
        $user = $this->createStub(Authenticatable::class);
        $guard->getProvider()->shouldReceive('retrieveByCredentials')->once()->andReturn($user);
        $guard->getProvider()->shouldReceive('validateCredentials')->with($user, ['foo'])->andReturn(true);
        $guard->getProvider()->shouldReceive('rehashPasswordIfRequired')->with($user, ['foo'])->once();
        $guard->expects($this->once())->method('login')->with($this->equalTo($user));
        $this->assertTrue($guard->attempt(['foo']));
    }

    public function testAttemptDoesntRehashPasswordWhenDisabled()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['login'])
            ->setConstructorArgs(['default', $provider, $session, $app, $timebox, false])
            ->getMock();
        $guard->setDispatcher($events = $this->mockEventDispatcher());
        $timebox->shouldReceive('call')->once()->andReturnUsing(function ($callback, $microseconds) use ($timebox) {
            return $callback($timebox->shouldReceive('returnEarly')->once()->getMock());
        });
        $events->shouldReceive('dispatch')->once()->with(m::type(Attempting::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(Validated::class));
        $user = $this->createStub(Authenticatable::class);
        $guard->getProvider()->shouldReceive('retrieveByCredentials')->once()->andReturn($user);
        $guard->getProvider()->shouldReceive('validateCredentials')->with($user, ['foo'])->andReturn(true);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $guard->expects($this->once())->method('login')->with($this->equalTo($user));
        $this->assertTrue($guard->attempt(['foo']));
    }

    public function testLoginStoresIdentifierInSession()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $user = m::mock(Authenticatable::class);
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $user->shouldReceive('getAuthIdentifier')->once()->andReturn('bar');
        $mock->getSession()->shouldReceive('put')->with('foo', 'bar')->once();
        $session->shouldReceive('regenerate')->once();
        $mock->login($user);
    }

    public function testSessionGuardIsMacroable()
    {
        $guard = $this->getGuard();

        $guard->macro('foo', function () {
            return 'bar';
        });

        $this->assertSame(
            'bar',
            $guard->foo()
        );
    }

    public function testLoginFiresLoginAndAuthenticatedEvents()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->setDispatcher($events = $this->mockEventDispatcher());
        $user = m::mock(Authenticatable::class);
        $events->shouldReceive('dispatch')->once()->with(m::type(Login::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(Authenticated::class));
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $user->shouldReceive('getAuthIdentifier')->once()->andReturn('bar');
        $mock->getSession()->shouldReceive('put')->with('foo', 'bar')->once();
        $session->shouldReceive('regenerate')->once();
        $mock->login($user);
    }

    public function testFailedAttemptFiresFailedEvent()
    {
        $guard = $this->getGuard();
        $guard->setDispatcher($events = $this->mockEventDispatcher());
        $timebox = $guard->getTimebox();
        $timebox->shouldReceive('call')->once()->andReturnUsing(function ($callback, $microseconds) use ($timebox) {
            return $callback($timebox);
        });
        $events->shouldReceive('dispatch')->once()->with(m::type(Attempting::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(Failed::class));
        $events->shouldNotReceive('dispatch')->with(m::type(Validated::class));
        $guard->getProvider()->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn(null);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $guard->attempt(['foo']);
    }

    public function testAuthenticateReturnsUserWhenUserIsNotNull()
    {
        $user = m::mock(Authenticatable::class);
        $guard = $this->getGuard();
        $guard->setUser($user);

        $this->assertEquals($user, $guard->authenticate());
    }

    public function testSetUserFiresAuthenticatedEvent()
    {
        $user = m::mock(Authenticatable::class);
        $guard = $this->getGuard();
        $guard->setDispatcher($events = $this->mockEventDispatcher());
        $events->shouldReceive('dispatch')->once()->with(m::type(Authenticated::class));
        $guard->setUser($user);
    }

    public function testAuthenticateThrowsWhenUserIsNull()
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Unauthenticated.');

        $guard = $this->getGuard();
        $guard->getSession()->shouldReceive('get')->once()->andReturn(null);

        $guard->authenticate();
    }

    public function testHasUserReturnsTrueWhenUserIsNotNull()
    {
        $user = m::mock(Authenticatable::class);
        $guard = $this->getGuard();
        $guard->setUser($user);

        $this->assertTrue($guard->hasUser());
    }

    public function testHasUserReturnsFalseWhenUserIsNull()
    {
        $guard = $this->getGuard();
        $guard->getSession()->shouldNotReceive('get');

        $this->assertFalse($guard->hasUser());
    }

    public function testIsAuthedReturnsTrueWhenUserIsNotNull()
    {
        $user = m::mock(Authenticatable::class);
        $mock = $this->getGuard();
        $mock->setUser($user);
        $this->assertTrue($mock->check());
        $this->assertFalse($mock->guest());
    }

    public function testIsAuthedReturnsFalseWhenUserIsNull()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['user'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->expects($this->exactly(2))->method('user')->willReturn(null);
        $this->assertFalse($mock->check());
        $this->assertTrue($mock->guest());
    }

    public function testUserMethodReturnsCachedUser()
    {
        $user = m::mock(Authenticatable::class);
        $mock = $this->getGuard();
        $mock->setUser($user);
        $this->assertSame($user, $mock->user());
    }

    public function testNullIsReturnedForUserIfNoUserFound()
    {
        $mock = $this->getGuard();
        $mock->getSession()->shouldReceive('get')->once()->andReturn(null);
        $this->assertNull($mock->user());
    }

    public function testUserIsSetToRetrievedUser()
    {
        $mock = $this->getGuard();
        $mock->getSession()->shouldReceive('get')->once()->andReturn(1);
        $user = m::mock(Authenticatable::class);
        $mock->getProvider()->shouldReceive('retrieveById')->once()->with(1)->andReturn($user);
        $this->assertSame($user, $mock->user());
        $this->assertSame($user, $mock->getUser());
    }

    public function testLogoutRemovesSessionTokenAndRememberMeCookie()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName', 'getRecallerName', 'recaller'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->setCookieJar($cookies = m::mock(CookieJar::class));
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getRememberToken')->once()->andReturn('a');
        $user->shouldReceive('setRememberToken')->once();
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $mock->expects($this->exactly(2))->method('getRecallerName')->willReturn($recallerName = 'bar');
        $mock->expects($this->once())->method('recaller')->willReturn(new \Hypervel\Auth\Recaller('id|token|hash'));
        $provider->shouldReceive('updateRememberToken')->once();

        $cookie = m::mock(Cookie::class);
        $cookies->shouldReceive('forget')->once()->with('bar')->andReturn($cookie);
        $cookies->shouldReceive('queue')->once()->with($cookie);
        $cookies->shouldReceive('unqueue')->once()->with($recallerName);
        $mock->getSession()->shouldReceive('remove')->once()->with('foo');
        $mock->setUser($user);
        $mock->logout();
        $this->assertNull($mock->getUser());
    }

    public function testLogoutDoesNotEnqueueRememberMeCookieForDeletionIfCookieDoesntExist()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName', 'getRecallerName', 'recaller'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->setCookieJar($cookies = m::mock(CookieJar::class));
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getRememberToken')->andReturn(null);
        $mock->expects($this->once())->method('getRecallerName')->willReturn($recallerName = 'bar');
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $mock->expects($this->once())->method('recaller')->willReturn(null);

        $cookies->shouldReceive('unqueue')->with($recallerName);

        $mock->getSession()->shouldReceive('remove')->once()->with('foo');
        $mock->setUser($user);
        $mock->logout();
        $this->assertNull($mock->getUser());
    }

    public function testLogoutFiresLogoutEvent()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['clearUserDataFromStorage'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->expects($this->once())->method('clearUserDataFromStorage');
        $mock->setDispatcher($events = $this->mockEventDispatcher());
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getRememberToken')->andReturn(null);
        $events->shouldReceive('dispatch')->once()->with(m::type(Authenticated::class));
        $mock->setUser($user);
        $events->shouldReceive('dispatch')->once()->with(m::type(Logout::class));
        $mock->logout();
    }

    public function testLogoutDoesNotSetRememberTokenIfNotPreviouslySet()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['clearUserDataFromStorage'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->expects($this->once())->method('clearUserDataFromStorage');
        $user = m::mock(Authenticatable::class);

        $user->shouldReceive('getRememberToken')->andReturn(null);
        $user->shouldNotReceive('setRememberToken');
        $provider->shouldNotReceive('updateRememberToken');

        $mock->setUser($user);
        $mock->logout();
    }

    public function testLogoutCurrentDeviceRemovesRememberMeCookie()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName', 'getRecallerName', 'recaller'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->setCookieJar($cookies = m::mock(CookieJar::class));
        $user = m::mock(Authenticatable::class);
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $mock->expects($this->exactly(2))->method('getRecallerName')->willReturn($recallerName = 'bar');
        $mock->expects($this->once())->method('recaller')->willReturn(new \Hypervel\Auth\Recaller('id|token|hash'));

        $cookie = m::mock(Cookie::class);
        $cookies->shouldReceive('forget')->once()->with('bar')->andReturn($cookie);
        $cookies->shouldReceive('queue')->once()->with($cookie);
        $cookies->shouldReceive('unqueue')->once()->with($recallerName);
        $mock->getSession()->shouldReceive('remove')->once()->with('foo');
        $mock->setUser($user);
        $mock->logoutCurrentDevice();
        $this->assertNull($mock->getUser());
    }

    public function testLogoutCurrentDeviceDoesNotEnqueueRememberMeCookieForDeletionIfCookieDoesntExist()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName', 'getRecallerName', 'recaller'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->setCookieJar($cookies = m::mock(CookieJar::class));
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getRememberToken')->andReturn(null);
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $mock->expects($this->once())->method('getRecallerName')->willReturn($recallerName = 'bar');
        $mock->expects($this->once())->method('recaller')->willReturn(null);
        $cookies->shouldReceive('unqueue')->once()->with($recallerName);

        $mock->getSession()->shouldReceive('remove')->once()->with('foo');
        $mock->setUser($user);
        $mock->logoutCurrentDevice();
        $this->assertNull($mock->getUser());
    }

    public function testLogoutCurrentDeviceFiresLogoutEvent()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['clearUserDataFromStorage'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->expects($this->once())->method('clearUserDataFromStorage');
        $mock->setDispatcher($events = $this->mockEventDispatcher());
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getRememberToken')->andReturn(null);
        $events->shouldReceive('dispatch')->once()->with(m::type(Authenticated::class));
        $mock->setUser($user);
        $events->shouldReceive('dispatch')->once()->with(m::type(CurrentDeviceLogout::class));
        $mock->logoutCurrentDevice();
    }

    public function testLoginMethodQueuesCookieWhenRemembering()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $guard->setCookieJar($cookie);
        $foreverCookie = new Cookie($guard->getRecallerName(), 'foo');
        $expectedHash = hash_hmac('sha256', 'bar', 'base-key-for-password-hash-mac');
        $cookie->shouldReceive('make')->once()->with($guard->getRecallerName(), 'foo|recaller|' . $expectedHash, 576000)->andReturn($foreverCookie);
        $cookie->shouldReceive('queue')->once()->with($foreverCookie);
        $guard->getSession()->shouldReceive('put')->once()->with($guard->getName(), 'foo');
        $session->shouldReceive('regenerate')->once();
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn('foo');
        $user->shouldReceive('getAuthPassword')->andReturn('bar');
        $user->shouldReceive('getRememberToken')->andReturn('recaller');
        $user->shouldReceive('setRememberToken')->never();
        $provider->shouldReceive('updateRememberToken')->never();
        $guard->login($user, true);
    }

    public function testLoginMethodQueuesCookieWhenRememberingAndAllowsOverride()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $guard->setRememberDuration(5000);
        $guard->setCookieJar($cookie);
        $foreverCookie = new Cookie($guard->getRecallerName(), 'foo');
        $expectedHash = hash_hmac('sha256', 'bar', 'base-key-for-password-hash-mac');
        $cookie->shouldReceive('make')->once()->with($guard->getRecallerName(), 'foo|recaller|' . $expectedHash, 5000)->andReturn($foreverCookie);
        $cookie->shouldReceive('queue')->once()->with($foreverCookie);
        $guard->getSession()->shouldReceive('put')->once()->with($guard->getName(), 'foo');
        $session->shouldReceive('regenerate')->once();
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn('foo');
        $user->shouldReceive('getAuthPassword')->andReturn('bar');
        $user->shouldReceive('getRememberToken')->andReturn('recaller');
        $user->shouldReceive('setRememberToken')->never();
        $provider->shouldReceive('updateRememberToken')->never();
        $guard->login($user, true);
    }

    public function testLoginMethodCreatesRememberTokenIfOneDoesntExist()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $guard->setCookieJar($cookie);
        $foreverCookie = new Cookie($guard->getRecallerName(), 'foo');
        $cookie->shouldReceive('make')->once()->andReturn($foreverCookie);
        $cookie->shouldReceive('queue')->once()->with($foreverCookie);
        $guard->getSession()->shouldReceive('put')->once()->with($guard->getName(), 'foo');
        $session->shouldReceive('regenerate')->once();
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn('foo');
        $user->shouldReceive('getAuthPassword')->andReturn('foo');
        $user->shouldReceive('getRememberToken')->andReturn(null);
        $user->shouldReceive('setRememberToken')->once();
        $provider->shouldReceive('updateRememberToken')->once();
        $guard->login($user, true);
    }

    public function testLoginUsingIdLogsInWithUser()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();

        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app])->makePartial();

        $user = m::mock(Authenticatable::class);
        $guard->getProvider()->shouldReceive('retrieveById')->once()->with(10)->andReturn($user);
        $guard->shouldReceive('login')->once()->with($user, false);

        $this->assertSame($user, $guard->loginUsingId(10));
    }

    public function testLoginUsingIdFailure()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app])->makePartial();

        $guard->getProvider()->shouldReceive('retrieveById')->once()->with(11)->andReturn(null);
        $guard->shouldNotReceive('login');

        $this->assertFalse($guard->loginUsingId(11));
    }

    public function testOnceUsingIdSetsUser()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app])->makePartial();

        $user = m::mock(Authenticatable::class);
        $guard->getProvider()->shouldReceive('retrieveById')->once()->with(10)->andReturn($user);
        $guard->shouldReceive('setUser')->once()->with($user);

        $this->assertSame($user, $guard->onceUsingId(10));
    }

    public function testOnceUsingIdFailure()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app])->makePartial();

        $guard->getProvider()->shouldReceive('retrieveById')->once()->with(11)->andReturn(null);
        $guard->shouldNotReceive('setUser');

        $this->assertFalse($guard->onceUsingId(11));
    }

    public function testUserUsesRememberCookieIfItExists()
    {
        $guard = $this->getGuard();
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $cookieRequest = Request::create('/', 'GET', [], [$guard->getRecallerName() => 'id|recaller|baz']);
        $app->shouldReceive('make')->with('request')->andReturn($cookieRequest);
        $guard = new SessionGuard('default', $provider, $session, $app);
        $guard->getSession()->shouldReceive('get')->once()->with($guard->getName())->andReturn(null);
        $user = m::mock(Authenticatable::class);
        $guard->getProvider()->shouldReceive('retrieveByToken')->once()->with('id', 'recaller')->andReturn($user);
        $user->shouldReceive('getAuthIdentifier')->once()->andReturn('bar');
        $guard->getSession()->shouldReceive('put')->with($guard->getName(), 'bar')->once();
        $session->shouldReceive('regenerate')->once();
        $this->assertSame($user, $guard->user());
        $this->assertTrue($guard->viaRemember());
    }

    public function testLoginOnceSetsUser()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app, $timebox])->makePartial();
        $user = m::mock(Authenticatable::class);
        $timebox->shouldReceive('call')->once()->andReturnUsing(function ($callback) use ($timebox) {
            return $callback($timebox->shouldReceive('returnEarly')->once()->getMock());
        });
        $guard->getProvider()->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user);
        $guard->getProvider()->shouldReceive('validateCredentials')->once()->with($user, ['foo'])->andReturn(true);
        $guard->getProvider()->shouldReceive('rehashPasswordIfRequired')->with($user, ['foo'])->once();
        $guard->shouldReceive('setUser')->once()->with($user);
        $this->assertTrue($guard->once(['foo']));
    }

    public function testLoginOnceFailure()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app, $timebox])->makePartial();
        $user = m::mock(Authenticatable::class);
        $timebox->shouldReceive('call')->once()->andReturnUsing(function ($callback) use ($timebox) {
            return $callback($timebox);
        });
        $guard->getProvider()->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn($user);
        $guard->getProvider()->shouldReceive('validateCredentials')->once()->with($user, ['foo'])->andReturn(false);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $this->assertFalse($guard->once(['foo']));
    }

    public function testForgetUserSetsUserToNull()
    {
        $user = m::mock(Authenticatable::class);
        $guard = $this->getGuard();
        $guard->setUser($user);
        $this->assertTrue($guard->hasUser());
        $guard->forgetUser();
        $this->assertFalse($guard->hasUser());
    }

    // =========================================================================
    // Context / Architecture Tests (Hypervel-specific)
    // =========================================================================

    public function testSetUserBeforeSessionStartUsesUnstartedKey()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $session->shouldReceive('isStarted')->andReturn(false);

        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);
        $user = m::mock(Authenticatable::class);

        $guard->setUser($user);

        $this->assertTrue($guard->hasUser());
        $this->assertSame($user, $guard->user());
    }

    public function testUnstartedUserTakesPrecedenceOverSessionLookup()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();

        // First, set user before session is started
        $session->shouldReceive('isStarted')->andReturn(false);
        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);

        $unstartedUser = m::mock(Authenticatable::class);
        $guard->setUser($unstartedUser);

        // Session should NOT be queried when unstarted user exists
        $session->shouldNotReceive('get');

        $this->assertSame($unstartedUser, $guard->user());
    }

    public function testForgetUserClearsBothStartedAndUnstartedKeys()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $session->shouldReceive('isStarted')->andReturn(false);

        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);
        $user = m::mock(Authenticatable::class);
        $guard->setUser($user);

        $this->assertTrue($guard->hasUser());

        $guard->forgetUser();

        $this->assertFalse($guard->hasUser());
    }

    public function testLoggedOutFlagMakesUserReturnNull()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)
            ->onlyMethods(['clearUserDataFromStorage'])
            ->setConstructorArgs(['default', $provider, $session, $app])
            ->getMock();
        $mock->expects($this->once())->method('clearUserDataFromStorage');

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getRememberToken')->andReturn(null);

        $mock->setUser($user);
        $this->assertSame($user, $mock->user());

        $mock->logout();

        $this->assertNull($mock->user());
    }

    public function testIdReturnsNullAfterLogout()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)
            ->onlyMethods(['clearUserDataFromStorage'])
            ->setConstructorArgs(['default', $provider, $session, $app])
            ->getMock();
        $mock->expects($this->once())->method('clearUserDataFromStorage');

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getRememberToken')->andReturn(null);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $mock->setUser($user);
        $this->assertSame(42, $mock->id());

        $mock->logout();

        $this->assertNull($mock->id());
    }

    public function testGetRequestResolvesFromContainer()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $freshRequest = \Symfony\Component\HttpFoundation\Request::create('/new-path', 'POST');
        $app->shouldReceive('make')->with('request')->andReturn($freshRequest);

        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);

        $this->assertSame($freshRequest, $guard->getRequest());
    }

    public function testGetCookieJarThrowsWhenUnset()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cookie jar has not been set.');

        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);

        $guard->getCookieJar();
    }

    public function testAttemptingRegistersEventListener()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);

        $dispatcher = m::mock(\Hypervel\Contracts\Events\Dispatcher::class);
        $dispatcher->shouldReceive('listen')
            ->with(Attempting::class, m::type('callable'))
            ->once();

        $guard->setDispatcher($dispatcher);
        $guard->attempting(function () {});
    }

    public function testAttemptSkipsEventDispatchWhenNoListenersAreRegistered()
    {
        $guard = $this->getGuard();
        $guard->setDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(false);
        $events->shouldNotReceive('dispatch');

        $timebox = $guard->getTimebox();
        $timebox->shouldReceive('call')->once()->andReturnUsing(function ($callback, $microseconds) use ($timebox) {
            return $callback($timebox);
        });

        $guard->getProvider()->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn(null);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');

        $this->assertFalse($guard->attempt(['foo']));
    }

    public function testViaRememberReturnsFalseByDefault()
    {
        $guard = $this->getGuard();

        $this->assertFalse($guard->viaRemember());
    }

    public function testGetNameReturnsConsistentValue()
    {
        $guard = $this->getGuard();

        $first = $guard->getName();
        $second = $guard->getName();

        $this->assertSame($first, $second);

        // A guard with a different name should return a different value
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $otherGuard = new SessionGuard('api', $provider, $session, $app, $timebox);

        $this->assertNotSame($guard->getName(), $otherGuard->getName());
    }

    public function testGetRecallerNameReturnsConsistentValue()
    {
        $guard = $this->getGuard();

        $first = $guard->getRecallerName();
        $second = $guard->getRecallerName();

        $this->assertSame($first, $second);

        // A guard with a different name should return a different value
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $otherGuard = new SessionGuard('api', $provider, $session, $app, $timebox);

        $this->assertNotSame($guard->getRecallerName(), $otherGuard->getRecallerName());
    }

    public function testGetNameContainsGuardName()
    {
        $guard = $this->getGuard();

        $this->assertStringContainsString('default', $guard->getName());
        $this->assertStringStartsWith('login_default_', $guard->getName());
    }

    protected function getGuard()
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();

        return new SessionGuard('default', $provider, $session, $app, $timebox);
    }

    protected function getMocks()
    {
        $session = m::mock(Session::class);
        $session->shouldReceive('isStarted')->andReturn(true)->byDefault();
        $session->shouldReceive('getId')->andReturn('test-session-id')->byDefault();

        $app = m::mock(Container::class);
        $app->shouldReceive('make')->with('request')->andReturn(Request::create('/', 'GET'))->byDefault();

        return [
            $session,
            m::mock(UserProvider::class),
            Request::create('/', 'GET'),
            m::mock(CookieJar::class),
            m::mock(Timebox::class),
            $app,
        ];
    }

    protected function mockEventDispatcher(): Dispatcher
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->byDefault()->andReturn(true);

        return $events;
    }
}
