<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Closure;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\Events\Attempting;
use Hypervel\Auth\Events\Authenticated;
use Hypervel\Auth\Events\CurrentDeviceLogout;
use Hypervel\Auth\Events\Failed;
use Hypervel\Auth\Events\Login;
use Hypervel\Auth\Events\Logout;
use Hypervel\Auth\Events\Validated;
use Hypervel\Auth\Recaller;
use Hypervel\Auth\SessionGuard;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Session\Session;
use Hypervel\Cookie\CookieJar;
use Hypervel\Coroutine\Waiter;
use Hypervel\Support\Timebox;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthGuardTest extends TestCase
{
    public function testBasicReturnsNullOnValidAttempt(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $basicRequest = Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']);
        $app->shouldReceive('make')->with('request')->andReturn($basicRequest);
        $guard = m::mock(SessionGuard::class . '[check,attempt]', ['default', $provider, $session, $app]);
        $guard->expects('check')->andReturn(false);
        $guard->expects('attempt')->with(['email' => 'foo@bar.com', 'password' => 'secret'])->andReturn(true);

        $guard->basic('email');
    }

    public function testBasicReturnsNullWhenAlreadyLoggedIn(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class . '[check]', ['default', $provider, $session, $app]);
        $guard->expects('check')->andReturn(true);
        $guard->shouldReceive('attempt')->never();

        $guard->basic('email');
    }

    public function testBasicReturnsResponseOnFailure(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $basicRequest = Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']);
        $app->shouldReceive('make')->with('request')->andReturn($basicRequest);
        $guard = m::mock(SessionGuard::class . '[check,attempt]', ['default', $provider, $session, $app]);
        $guard->expects('check')->andReturn(false);
        $guard->expects('attempt')->with(['email' => 'foo@bar.com', 'password' => 'secret'])->andReturn(false);
        $guard->basic('email');
    }

    public function testBasicWithExtraConditions(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $basicRequest = Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']);
        $app->shouldReceive('make')->with('request')->andReturn($basicRequest);
        $guard = m::mock(SessionGuard::class . '[check,attempt]', ['default', $provider, $session, $app]);
        $guard->expects('check')->andReturn(false);
        $guard->expects('attempt')->with(['email' => 'foo@bar.com', 'password' => 'secret', 'active' => 1])->andReturn(true);

        $guard->basic('email', ['active' => 1]);
    }

    public function testBasicWithExtraArrayConditions(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $basicRequest = Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']);
        $app->shouldReceive('make')->with('request')->andReturn($basicRequest);
        $guard = m::mock(SessionGuard::class . '[check,attempt]', ['default', $provider, $session, $app]);
        $guard->expects('check')->andReturn(false);
        $guard->expects('attempt')->with(['email' => 'foo@bar.com', 'password' => 'secret', 'active' => 1, 'type' => [1, 2, 3]])->andReturn(true);

        $guard->basic('email', ['active' => 1, 'type' => [1, 2, 3]]);
    }

    public function testAttemptCallsRetrieveByCredentials(): void
    {
        $guard = $this->getGuard();
        $events = $this->mockEventDispatcher();
        $guard->setDispatcher($events);
        $timebox = $guard->getTimebox();
        $timebox->expects('call')->andReturnUsing(function (Closure $callback) use ($timebox): bool {
            return $callback($timebox);
        });
        $events->expects('dispatch')->with(m::type(Attempting::class));
        $events->expects('dispatch')->with(m::type(Failed::class));
        $events->shouldNotReceive('dispatch')->with(m::type(Validated::class));
        $guard->getProvider()->expects('retrieveByCredentials')->with(['foo']);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $guard->attempt(['foo']);
    }

    public function testAttemptReturnsUserInterface(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['login'])->setConstructorArgs(['default', $provider, $session, $app, $timebox])->getMock();
        $events = $this->mockEventDispatcher();
        $guard->setDispatcher($events);
        $timebox->expects('call')->andReturnUsing(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox->expects('returnEarly')->getMock());
        });
        $events->expects('dispatch')->with(m::type(Attempting::class));
        $events->expects('dispatch')->with(m::type(Validated::class));
        $user = $this->createStub(Authenticatable::class);
        $guard->getProvider()->expects('retrieveByCredentials')->andReturn($user);
        $guard->getProvider()->expects('validateCredentials')->with($user, ['foo'])->andReturn(true);
        $guard->getProvider()->expects('rehashPasswordIfRequired')->with($user, ['foo']);
        $guard->expects($this->once())->method('login')->with($user);
        $this->assertTrue($guard->attempt(['foo']));
    }

    public function testAttemptReturnsFalseIfUserNotGiven(): void
    {
        $mock = $this->getGuard();
        $events = $this->mockEventDispatcher();
        $mock->setDispatcher($events);
        $timebox = $mock->getTimebox();
        $timebox->expects('call')->andReturnUsing(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox);
        });
        $events->expects('dispatch')->with(m::type(Attempting::class));
        $events->expects('dispatch')->with(m::type(Failed::class));
        $events->shouldNotReceive('dispatch')->with(m::type(Validated::class));
        $mock->getProvider()->expects('retrieveByCredentials')->andReturn(null);
        $mock->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $this->assertFalse($mock->attempt(['foo']));
    }

    public function testAttemptAndWithCallbacks(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName'])->setConstructorArgs(['default', $provider, $session, $app, $timebox])->getMock();
        $events = $this->mockEventDispatcher();
        $mock->setDispatcher($events);
        $timebox->shouldReceive('call')->andReturnUsing(function (Closure $callback) use ($timebox): bool {
            return $callback($timebox->shouldReceive('returnEarly')->getMock());
        });
        $user = m::mock(Authenticatable::class);
        $events->expects('dispatch')->times(3)->with(m::type(Attempting::class));
        $events->expects('dispatch')->with(m::type(Login::class));
        $events->expects('dispatch')->with(m::type(Authenticated::class));
        $events->expects('dispatch')->times(2)->with(m::type(Validated::class));
        $events->expects('dispatch')->times(2)->with(m::type(Failed::class));
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $user->expects('getAuthIdentifier')->andReturn('bar');
        $mock->getSession()->expects('put')->with('foo', 'bar');
        $session->expects('regenerate');
        $mock->getProvider()->expects('retrieveByCredentials')->times(3)->with(['foo'])->andReturn($user);
        $mock->getProvider()->expects('validateCredentials')->times(2)->andReturnTrue();
        $mock->getProvider()->expects('validateCredentials')->andReturnFalse();
        $mock->getProvider()->expects('rehashPasswordIfRequired')->with($user, ['foo']);

        $this->assertTrue($mock->attemptWhen(['foo'], function (Authenticatable $user, SessionGuard $guard): bool {
            $this->assertInstanceOf(Authenticatable::class, $user);
            $this->assertInstanceOf(SessionGuard::class, $guard);

            return true;
        }));

        $this->assertFalse($mock->attemptWhen(['foo'], function (Authenticatable $user, SessionGuard $guard): bool {
            $this->assertInstanceOf(Authenticatable::class, $user);
            $this->assertInstanceOf(SessionGuard::class, $guard);

            return false;
        }));

        $executed = false;

        $this->assertFalse($mock->attemptWhen(['foo'], function () use (&$executed): bool {
            return $executed = true;
        }));

        $this->assertFalse($executed);
    }

    public function testAttemptRehashesPasswordWhenRequired(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['login'])->setConstructorArgs(['default', $provider, $session, $app, $timebox])->getMock();
        $events = $this->mockEventDispatcher();
        $guard->setDispatcher($events);
        $timebox->expects('call')->andReturnUsing(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox->expects('returnEarly')->getMock());
        });
        $events->expects('dispatch')->with(m::type(Attempting::class));
        $events->expects('dispatch')->with(m::type(Validated::class));
        $user = $this->createStub(Authenticatable::class);
        $guard->getProvider()->expects('retrieveByCredentials')->andReturn($user);
        $guard->getProvider()->expects('validateCredentials')->with($user, ['foo'])->andReturn(true);
        $guard->getProvider()->expects('rehashPasswordIfRequired')->with($user, ['foo']);
        $guard->expects($this->once())->method('login')->with($user);
        $this->assertTrue($guard->attempt(['foo']));
    }

    public function testAttemptDoesntRehashPasswordWhenDisabled(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['login'])
            ->setConstructorArgs(['default', $provider, $session, $app, $timebox, false])
            ->getMock();
        $events = $this->mockEventDispatcher();
        $guard->setDispatcher($events);
        $timebox->expects('call')->andReturnUsing(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox->expects('returnEarly')->getMock());
        });
        $events->expects('dispatch')->with(m::type(Attempting::class));
        $events->expects('dispatch')->with(m::type(Validated::class));
        $user = $this->createStub(Authenticatable::class);
        $guard->getProvider()->expects('retrieveByCredentials')->andReturn($user);
        $guard->getProvider()->expects('validateCredentials')->with($user, ['foo'])->andReturn(true);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $guard->expects($this->once())->method('login')->with($user);
        $this->assertTrue($guard->attempt(['foo']));
    }

    public function testLoginStoresIdentifierInSession(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $user = m::mock(Authenticatable::class);
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $user->expects('getAuthIdentifier')->andReturn('bar');
        $mock->getSession()->expects('put')->with('foo', 'bar');
        $session->expects('regenerate');
        $mock->login($user);
    }

    public function testSessionGuardIsMacroable(): void
    {
        $guard = $this->getGuard();

        $guard->macro('foo', function (): string {
            return 'bar';
        });

        $this->assertSame(
            'bar',
            $guard->foo()
        );
    }

    public function testLoginFiresLoginAndAuthenticatedEvents(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $events = $this->mockEventDispatcher();
        $mock->setDispatcher($events);
        $user = m::mock(Authenticatable::class);
        $events->expects('dispatch')->with(m::type(Login::class));
        $events->expects('dispatch')->with(m::type(Authenticated::class));
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $user->expects('getAuthIdentifier')->andReturn('bar');
        $mock->getSession()->expects('put')->with('foo', 'bar');
        $session->expects('regenerate');
        $mock->login($user);
    }

    public function testFailedAttemptFiresFailedEvent(): void
    {
        $guard = $this->getGuard();
        $events = $this->mockEventDispatcher();
        $guard->setDispatcher($events);
        $timebox = $guard->getTimebox();
        $timebox->expects('call')->andReturnUsing(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox);
        });
        $events->expects('dispatch')->with(m::type(Attempting::class));
        $events->expects('dispatch')->with(m::type(Failed::class));
        $events->shouldNotReceive('dispatch')->with(m::type(Validated::class));
        $guard->getProvider()->expects('retrieveByCredentials')->with(['foo'])->andReturn(null);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $guard->attempt(['foo']);
    }

    public function testAuthenticateReturnsUserWhenUserIsNotNull(): void
    {
        $user = m::mock(Authenticatable::class);
        $guard = $this->getGuard();
        $guard->setUser($user);

        $this->assertEquals($user, $guard->authenticate());
    }

    public function testSetUserFiresAuthenticatedEvent(): void
    {
        $user = m::mock(Authenticatable::class);
        $guard = $this->getGuard();
        $events = $this->mockEventDispatcher();
        $events->expects('dispatch')->with(m::type(Authenticated::class));
        $guard->setDispatcher($events);
        $guard->setUser($user);
    }

    public function testAuthenticateThrowsWhenUserIsNull(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Unauthenticated.');

        $guard = $this->getGuard();
        $guard->getSession()->expects('get')->andReturn(null);

        $guard->authenticate();
    }

    public function testHasUserReturnsTrueWhenUserIsNotNull(): void
    {
        $user = m::mock(Authenticatable::class);
        $guard = $this->getGuard();
        $guard->setUser($user);

        $this->assertTrue($guard->hasUser());
    }

    public function testHasUserReturnsFalseWhenUserIsNull(): void
    {
        $guard = $this->getGuard();
        $guard->getSession()->shouldNotReceive('get');

        $this->assertFalse($guard->hasUser());
        $this->assertNull($guard->getUser());
    }

    public function testIsAuthedReturnsTrueWhenUserIsNotNull(): void
    {
        $user = m::mock(Authenticatable::class);
        $mock = $this->getGuard();
        $mock->setUser($user);
        $this->assertTrue($mock->check());
        $this->assertFalse($mock->guest());
    }

    public function testIsAuthedReturnsFalseWhenUserIsNull(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['user'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->expects($this->exactly(2))->method('user')->willReturn(null);
        $this->assertFalse($mock->check());
        $this->assertTrue($mock->guest());
    }

    public function testUserMethodReturnsCachedUser(): void
    {
        $user = m::mock(Authenticatable::class);
        $mock = $this->getGuard();
        $mock->setUser($user);
        $this->assertSame($user, $mock->user());
        $this->assertSame($user, $mock->getUser());
    }

    #[DataProvider('sessionStartedStates')]
    public function testNullIsReturnedForUserIfNoUserFound(bool $sessionStarted): void
    {
        $mock = $this->getGuard();
        $mock->getSession()->shouldReceive('isStarted')->andReturn($sessionStarted);
        $mock->getSession()->expects('get')->andReturn(null);
        $this->assertNull($mock->user());
        $this->assertNull($mock->getUser());
        $this->assertFalse($mock->hasUser());
        $this->assertNull($mock->user());
    }

    /**
     * Provide session states in HTTP and console contexts.
     */
    public static function sessionStartedStates(): array
    {
        return [
            'started' => [true],
            'unstarted' => [false],
        ];
    }

    public function testUserIsSetToRetrievedUser(): void
    {
        $mock = $this->getGuard();
        $mock->getSession()->expects('get')->andReturn(1);
        $user = m::mock(Authenticatable::class);
        $mock->getProvider()->expects('retrieveById')->with(1)->andReturn($user);
        $this->assertSame($user, $mock->user());
        $this->assertSame($user, $mock->getUser());
    }

    public function testLogoutRemovesSessionTokenAndRememberMeCookie(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName', 'getRecallerName', 'recaller'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $cookies = m::mock(CookieJar::class);
        $mock->setCookieJar($cookies);
        $user = m::mock(Authenticatable::class);
        $user->expects('getRememberToken')->andReturn('a');
        $user->expects('setRememberToken');
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $mock->expects($this->exactly(2))->method('getRecallerName')->willReturn($recallerName = 'bar');
        $mock->expects($this->once())->method('recaller')->willReturn(new Recaller('id|token|hash'));
        $provider->expects('updateRememberToken');

        $cookie = m::mock(Cookie::class);
        $cookies->expects('forget')->with('bar')->andReturn($cookie);
        $cookies->expects('queue')->with($cookie);
        $cookies->expects('unqueue')->with($recallerName);
        $mock->getSession()->expects('remove')->with('foo');
        $mock->setUser($user);
        $mock->logout();
        $this->assertNull($mock->getUser());
    }

    public function testLogoutDoesNotEnqueueRememberMeCookieForDeletionIfCookieDoesntExist(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName', 'getRecallerName', 'recaller'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $cookies = m::mock(CookieJar::class);
        $mock->setCookieJar($cookies);
        $user = m::mock(Authenticatable::class);
        $user->expects('getRememberToken')->andReturn(null);
        $mock->expects($this->once())->method('getRecallerName')->willReturn($recallerName = 'bar');
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $mock->expects($this->once())->method('recaller')->willReturn(null);

        $cookies->expects('unqueue')->with($recallerName);

        $mock->getSession()->expects('remove')->with('foo');
        $mock->setUser($user);
        $mock->logout();
        $this->assertNull($mock->getUser());
    }

    public function testLogoutFiresLogoutEvent(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['clearUserDataFromStorage'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->expects($this->once())->method('clearUserDataFromStorage');
        $events = $this->mockEventDispatcher();
        $mock->setDispatcher($events);
        $user = m::mock(Authenticatable::class);
        $user->expects('getRememberToken')->andReturn(null);
        $events->expects('dispatch')->with(m::type(Authenticated::class));
        $mock->setUser($user);
        $events->expects('dispatch')->with(m::type(Logout::class));
        $mock->logout();
    }

    public function testLogoutDoesNotSetRememberTokenIfNotPreviouslySet(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['clearUserDataFromStorage'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->expects($this->once())->method('clearUserDataFromStorage');
        $user = m::mock(Authenticatable::class);

        $user->expects('getRememberToken')->andReturn(null);
        $user->shouldNotReceive('setRememberToken');
        $provider->shouldNotReceive('updateRememberToken');

        $mock->setUser($user);
        $mock->logout();
    }

    public function testLogoutCurrentDeviceRemovesRememberMeCookie(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName', 'getRecallerName', 'recaller'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $cookies = m::mock(CookieJar::class);
        $mock->setCookieJar($cookies);
        $user = m::mock(Authenticatable::class);
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $mock->expects($this->exactly(2))->method('getRecallerName')->willReturn($recallerName = 'bar');
        $mock->expects($this->once())->method('recaller')->willReturn(new Recaller('id|token|hash'));

        $cookie = m::mock(Cookie::class);
        $cookies->expects('forget')->with('bar')->andReturn($cookie);
        $cookies->expects('queue')->with($cookie);
        $cookies->expects('unqueue')->with($recallerName);
        $mock->getSession()->expects('remove')->with('foo');
        $mock->setUser($user);
        $mock->logoutCurrentDevice();
        $this->assertNull($mock->getUser());
    }

    public function testLogoutCurrentDeviceDoesNotEnqueueRememberMeCookieForDeletionIfCookieDoesntExist(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName', 'getRecallerName', 'recaller'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $cookies = m::mock(CookieJar::class);
        $mock->setCookieJar($cookies);
        $user = m::mock(Authenticatable::class);
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $mock->expects($this->once())->method('getRecallerName')->willReturn($recallerName = 'bar');
        $mock->expects($this->once())->method('recaller')->willReturn(null);
        $cookies->expects('unqueue')->with($recallerName);

        $mock->getSession()->expects('remove')->with('foo');
        $mock->setUser($user);
        $mock->logoutCurrentDevice();
        $this->assertNull($mock->getUser());
    }

    public function testLogoutCurrentDeviceFiresLogoutEvent(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['clearUserDataFromStorage'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $mock->expects($this->once())->method('clearUserDataFromStorage');
        $events = $this->mockEventDispatcher();
        $mock->setDispatcher($events);
        $user = m::mock(Authenticatable::class);
        $events->expects('dispatch')->with(m::type(Authenticated::class));
        $mock->setUser($user);
        $events->expects('dispatch')->with(m::type(CurrentDeviceLogout::class));
        $mock->logoutCurrentDevice();
    }

    public function testLoginMethodQueuesCookieWhenRemembering(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $guard->setCookieJar($cookie);
        $foreverCookie = new Cookie($guard->getRecallerName(), 'foo');
        $expectedHash = hash_hmac('sha256', 'bar', 'base-key-for-password-hash-mac');
        $cookie->expects('make')->with($guard->getRecallerName(), 'foo|recaller|' . $expectedHash, 576000)->andReturn($foreverCookie);
        $cookie->expects('queue')->with($foreverCookie);
        $guard->getSession()->expects('put')->with($guard->getName(), 'foo');
        $session->expects('regenerate');
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthIdentifier')->times(2)->andReturn('foo');
        $user->expects('getAuthPassword')->andReturn('bar');
        $user->expects('getRememberToken')->times(2)->andReturn('recaller');
        $user->shouldReceive('setRememberToken')->never();
        $provider->shouldReceive('updateRememberToken')->never();
        $guard->login($user, true);
    }

    public function testLoginMethodQueuesCookieWhenRememberingPasswordlessUser(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $guard->setCookieJar($cookie);
        $foreverCookie = new Cookie($guard->getRecallerName(), 'foo');
        $expectedHash = hash_hmac('sha256', '', 'base-key-for-password-hash-mac');
        $cookie->shouldReceive('make')->once()->with($guard->getRecallerName(), 'foo|recaller|' . $expectedHash, 576000)->andReturn($foreverCookie);
        $cookie->shouldReceive('queue')->once()->with($foreverCookie);
        $guard->getSession()->shouldReceive('put')->once()->with($guard->getName(), 'foo');
        $session->shouldReceive('regenerate')->once();
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn('foo');
        $user->shouldReceive('getAuthPassword')->andReturn(null);
        $user->shouldReceive('getRememberToken')->andReturn('recaller');
        $user->shouldReceive('setRememberToken')->never();
        $provider->shouldReceive('updateRememberToken')->never();
        $guard->login($user, true);
    }

    public function testLoginMethodQueuesCookieWhenRememberingAndAllowsOverride(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $guard->setRememberDuration(5000);
        $guard->setCookieJar($cookie);
        $foreverCookie = new Cookie($guard->getRecallerName(), 'foo');
        $expectedHash = hash_hmac('sha256', 'bar', 'base-key-for-password-hash-mac');
        $cookie->expects('make')->with($guard->getRecallerName(), 'foo|recaller|' . $expectedHash, 5000)->andReturn($foreverCookie);
        $cookie->expects('queue')->with($foreverCookie);
        $guard->getSession()->expects('put')->with($guard->getName(), 'foo');
        $session->expects('regenerate');
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthIdentifier')->times(2)->andReturn('foo');
        $user->expects('getAuthPassword')->andReturn('bar');
        $user->expects('getRememberToken')->times(2)->andReturn('recaller');
        $user->shouldReceive('setRememberToken')->never();
        $provider->shouldReceive('updateRememberToken')->never();
        $guard->login($user, true);
    }

    public function testLoginMethodCreatesRememberTokenIfOneDoesntExist(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $guard->setCookieJar($cookie);
        $foreverCookie = new Cookie($guard->getRecallerName(), 'foo');
        $cookie->expects('make')->andReturn($foreverCookie);
        $cookie->expects('queue')->with($foreverCookie);
        $guard->getSession()->expects('put')->with($guard->getName(), 'foo');
        $session->expects('regenerate');
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthIdentifier')->times(2)->andReturn('foo');
        $user->expects('getAuthPassword')->andReturn('foo');
        $user->expects('getRememberToken')->times(2)->andReturn(null);
        $user->expects('setRememberToken');
        $provider->expects('updateRememberToken');
        $guard->login($user, true);
    }

    public function testLoginUsingIdLogsInWithUser(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();

        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app])->makePartial();

        $user = m::mock(Authenticatable::class);
        $guard->getProvider()->expects('retrieveById')->with(10)->andReturn($user);
        $guard->expects('login')->with($user, false);

        $this->assertSame($user, $guard->loginUsingId(10));
    }

    public function testLoginUsingIdFailure(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app])->makePartial();

        $guard->getProvider()->expects('retrieveById')->with(11)->andReturn(null);
        $guard->shouldNotReceive('login');

        $this->assertFalse($guard->loginUsingId(11));
    }

    public function testOnceUsingIdSetsUser(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app])->makePartial();

        $user = m::mock(Authenticatable::class);
        $guard->getProvider()->expects('retrieveById')->with(10)->andReturn($user);
        $guard->expects('setUser')->with($user);

        $this->assertSame($user, $guard->onceUsingId(10));
    }

    public function testOnceUsingIdFailure(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app])->makePartial();

        $guard->getProvider()->expects('retrieveById')->with(11)->andReturn(null);
        $guard->shouldNotReceive('setUser');

        $this->assertFalse($guard->onceUsingId(11));
    }

    #[DataProvider('rememberCookiePasswords')]
    public function testUserUsesRememberCookieIfItExists(?string $passwordHash): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $cookieRequest = Request::create('/', 'GET', [], [
            $guard->getRecallerName() => 'id|recaller|' . $guard->hashPasswordForCookie($passwordHash),
        ]);
        $app->shouldReceive('make')->with('request')->andReturn($cookieRequest);
        $guard->getSession()->expects('get')->with($guard->getName())->andReturn(null);
        $user = m::mock(Authenticatable::class);
        $guard->getProvider()->expects('retrieveByToken')->with('id', 'recaller')->andReturn($user);
        $user->expects('getAuthIdentifier')->andReturn('bar');
        $user->expects('getAuthPassword')->andReturn($passwordHash);
        $guard->getSession()->expects('put')->with($guard->getName(), 'bar');
        $session->expects('regenerate');
        $this->assertSame($user, $guard->user());
        $this->assertTrue($guard->viaRemember());
    }

    /**
     * Provide password hashes supported by remember cookies.
     */
    public static function rememberCookiePasswords(): array
    {
        return [
            'password hash' => ['baz'],
            'passwordless user' => [null],
        ];
    }

    public function testUserReturnsNullWhenRememberCookieTokenDoesNotMatchAnyUser(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $cookieRequest = Request::create('/', 'GET', [], [$guard->getRecallerName() => 'id|recaller|baz']);
        $app->shouldReceive('make')->with('request')->andReturn($cookieRequest);
        $guard->getSession()->expects('get')->with($guard->getName())->andReturn(null);
        $guard->getProvider()->expects('retrieveByToken')->with('id', 'recaller')->andReturn(null);
        $this->assertNull($guard->user());
        $this->assertFalse($guard->viaRemember());
    }

    #[DataProvider('invalidRememberCookieHashes')]
    public function testUserRejectsRememberCookieWithInvalidPasswordHash(string $cookieHash): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $cookieRequest = Request::create('/', 'GET', [], [$guard->getRecallerName() => 'id|recaller|' . $cookieHash]);
        $app->shouldReceive('make')->with('request')->andReturn($cookieRequest);
        $session->expects('get')->with($guard->getName())->andReturn(null);
        $user = m::mock(Authenticatable::class);
        $provider->expects('retrieveByToken')->with('id', 'recaller')->andReturn($user);
        $user->expects('getAuthPassword')->andReturn('baz');
        $session->shouldNotReceive('put');
        $session->shouldNotReceive('regenerate');

        $this->assertNull($guard->user());
        $this->assertFalse($guard->viaRemember());
    }

    /**
     * Provide stale and unsupported legacy remember-cookie hashes.
     */
    public static function invalidRememberCookieHashes(): array
    {
        return [
            'stale HMAC' => [hash_hmac('sha256', 'old-password-hash', 'base-key-for-password-hash-mac')],
            'legacy raw hash' => ['baz'],
        ];
    }

    public function testLoginOnceSetsUser(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app, $timebox])->makePartial();
        $user = m::mock(Authenticatable::class);
        $timebox->expects('call')->andReturnUsing(function (Closure $callback) use ($timebox): bool {
            return $callback($timebox->expects('returnEarly')->getMock());
        });
        $guard->getProvider()->expects('retrieveByCredentials')->with(['foo'])->andReturn($user);
        $guard->getProvider()->expects('validateCredentials')->with($user, ['foo'])->andReturn(true);
        $guard->getProvider()->expects('rehashPasswordIfRequired')->with($user, ['foo']);
        $guard->expects('setUser')->with($user);
        $this->assertTrue($guard->once(['foo']));
    }

    public function testLoginOnceFailure(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = m::mock(SessionGuard::class, ['default', $provider, $session, $app, $timebox])->makePartial();
        $user = m::mock(Authenticatable::class);
        $timebox->expects('call')->andReturnUsing(function (Closure $callback) use ($timebox): bool {
            return $callback($timebox);
        });
        $guard->getProvider()->expects('retrieveByCredentials')->with(['foo'])->andReturn($user);
        $guard->getProvider()->expects('validateCredentials')->with($user, ['foo'])->andReturn(false);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $this->assertFalse($guard->once(['foo']));
    }

    public function testForgetUserSetsUserToNull(): void
    {
        $user = m::mock(Authenticatable::class);
        $guard = $this->getGuard();
        $guard->setUser($user);
        $this->assertTrue($guard->hasUser());
        $guard->forgetUser();
        $this->assertNull($guard->getUser());
        $this->assertFalse($guard->hasUser());
    }

    // =========================================================================
    // Context / Architecture Tests (Hypervel-specific)
    // =========================================================================

    public function testForgetUserClearsCachedMiss(): void
    {
        $guard = $this->getGuard();
        $user = m::mock(Authenticatable::class);
        $guard->getSession()->expects('get')->twice()->with($guard->getName())->andReturn(null, 42);
        $guard->getProvider()->expects('retrieveById')->with(42)->andReturn($user);

        $this->assertNull($guard->user());
        $guard->forgetUser();

        $this->assertSame($user, $guard->user());
    }

    public function testAuthenticatedListenerCanReadAndReplaceResolvedUser(): void
    {
        $guard = $this->getGuard();
        $user = m::mock(Authenticatable::class);
        $replacement = m::mock(Authenticatable::class);
        $guard->getSession()->expects('get')->with($guard->getName())->andReturn(1);
        $guard->getProvider()->expects('retrieveById')->with(1)->andReturn($user);

        $events = $this->mockEventDispatcher();
        $events->expects('dispatch')->twice()->with(m::type(Authenticated::class))
            ->andReturnUsing(function (Authenticated $event) use ($guard, $user, $replacement): void {
                $this->assertTrue($guard->hasUser());
                $this->assertSame($event->user, $guard->getUser());
                $this->assertSame($event->user, $guard->user());

                if ($event->user === $user) {
                    $guard->setUser($replacement);
                }
            });
        $guard->setDispatcher($events);

        $this->assertSame($replacement, $guard->user());
        $this->assertSame($replacement, $guard->getUser());
    }

    public function testRememberedUserIsCachedBeforeAndAfterSessionRotation(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $request->cookies->set($guard->getRecallerName(), 'id|recaller|' . $guard->hashPasswordForCookie('baz'));
        $app->shouldReceive('make')->with('request')->andReturn($request);
        $sessionId = 'old-session-id';
        $session->shouldReceive('getId')->andReturnUsing(function () use (&$sessionId): string {
            return $sessionId;
        });
        $session->expects('get')->with($guard->getName())->andReturn(null);
        $user = m::mock(Authenticatable::class);
        $provider->expects('retrieveByToken')->with('id', 'recaller')->andReturn($user);
        $provider->shouldNotReceive('retrieveById');
        $user->expects('getAuthPassword')->andReturn('baz');
        $user->expects('getAuthIdentifier')->andReturn('id');
        $session->expects('put')->with($guard->getName(), 'id');
        $session->expects('regenerate')->with(true)
            ->andReturnUsing(function () use ($guard, $user, &$sessionId): bool {
                // Database session deletion can invoke query listeners before the ID changes.
                $this->assertTrue($guard->hasUser());
                $this->assertSame($user, $guard->getUser());
                $this->assertSame($user, $guard->user());
                $sessionId = 'new-session-id';

                return true;
            });
        $events = $this->mockEventDispatcher();
        $events->expects('dispatch')->with(m::type(Login::class))
            ->andReturnUsing(function (Login $event) use ($guard, $user): void {
                $this->assertSame($user, $event->user);
                $this->assertTrue($guard->hasUser());
                $this->assertSame($user, $guard->getUser());
                $this->assertSame($user, $guard->user());
            });
        $guard->setDispatcher($events);

        $this->assertSame($user, $guard->user());
        $this->assertTrue($guard->hasUser());
        $this->assertSame($user, $guard->getUser());
        $this->assertSame($user, $guard->user());
    }

    public function testLoggedInUserSurvivesIndependentSessionRotation(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $sessionId = 'session-id';
        $session->shouldReceive('getId')->andReturnUsing(function () use (&$sessionId): string {
            return $sessionId;
        });
        $session->expects('regenerate')->twice()->andReturnUsing(function () use (&$sessionId): bool {
            $sessionId .= '-rotated';

            return true;
        });
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthIdentifier')->andReturn(42);
        $session->expects('put')->with($guard->getName(), 42);
        $session->shouldNotReceive('get');
        $provider->shouldNotReceive('retrieveById');
        $events = $this->mockEventDispatcher();
        $events->expects('dispatch')->with(m::type(Login::class));
        $events->expects('dispatch')->with(m::type(Authenticated::class));
        $guard->setDispatcher($events);

        $guard->login($user);
        $session->regenerate();

        $this->assertTrue($guard->hasUser());
        $this->assertSame($user, $guard->getUser());
        $this->assertSame($user, $guard->user());
    }

    #[DataProvider('sessionLookupTransitions')]
    public function testMissingUserIsResolvedAgainWhenSessionStateChanges(bool $sessionStarted, string $nextSessionId): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $sessionId = 'session-id';
        $session->shouldReceive('getId')->andReturnUsing(function () use (&$sessionId): string {
            return $sessionId;
        });
        $session->shouldReceive('isStarted')->andReturnUsing(function () use (&$sessionStarted): bool {
            return $sessionStarted;
        });
        $session->expects('get')->twice()->with($guard->getName())->andReturn(null, 42);
        $user = m::mock(Authenticatable::class);
        $provider->expects('retrieveById')->with(42)->andReturn($user);

        $this->assertNull($guard->user());
        $this->assertNull($guard->user());

        $sessionStarted = true;
        $sessionId = $nextSessionId;

        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->getUser());
    }

    /**
     * Provide session transitions that invalidate a failed user lookup.
     */
    public static function sessionLookupTransitions(): array
    {
        return [
            'session starts with the same ID' => [false, 'session-id'],
            'started session changes ID' => [true, 'new-session-id'],
        ];
    }

    public function testMissingUserIsNotInheritedByAChildRequest(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $session->expects('get')->twice()->with($guard->getName())->andReturn(null, 42);
        $user = m::mock(Authenticatable::class);
        $provider->expects('retrieveById')->with(42)->andReturn($user);

        $this->assertNull($guard->user());

        (new Waiter)->wait(function () use ($guard, $user): void {
            $this->assertSame($user, $guard->user());
            $this->assertSame($user, $guard->user());
        }, copyContext: true);

        $this->assertNull($guard->getUser());
        $this->assertNull($guard->user());
    }

    public function testSetUserBeforeSessionStartCachesTheUser(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $session->shouldReceive('isStarted')->andReturn(false);

        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);
        $user = m::mock(Authenticatable::class);

        $guard->setUser($user);

        $this->assertTrue($guard->hasUser());
        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->getUser());
    }

    public function testUserSetBeforeSessionStartCanBeReplacedAfterSessionStarts(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $sessionStarted = false;
        $session->shouldReceive('isStarted')->andReturnUsing(function () use (&$sessionStarted): bool {
            return $sessionStarted;
        });
        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);

        $unstartedUser = m::mock(Authenticatable::class);
        $guard->setUser($unstartedUser);

        $session->shouldNotReceive('get');

        $this->assertSame($unstartedUser, $guard->user());

        $sessionStarted = true;
        $user = m::mock(Authenticatable::class);
        $guard->setUser($user);

        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->getUser());
    }

    public function testForgetUserClearsUserSetBeforeSessionStart(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $session->shouldReceive('isStarted')->andReturn(false);

        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);
        $user = m::mock(Authenticatable::class);
        $guard->setUser($user);

        $this->assertTrue($guard->hasUser());

        $guard->forgetUser();

        $this->assertFalse($guard->hasUser());
        $this->assertNull($guard->getUser());
    }

    public function testLoggedOutFlagMakesUserReturnNull(): void
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

    public function testIdReturnsNullAfterLogout(): void
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

    public function testGetRequestResolvesFromContainer(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $freshRequest = Request::create('/new-path', 'POST');
        $app->shouldReceive('make')->with('request')->andReturn($freshRequest);

        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);

        $this->assertSame($freshRequest, $guard->getRequest());
    }

    public function testGetCookieJarThrowsWhenUnset(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cookie jar has not been set.');

        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);

        $guard->getCookieJar();
    }

    public function testAttemptingRegistersEventListener(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('listen')
            ->with(Attempting::class, m::type('callable'))
            ->once();

        $guard->setDispatcher($dispatcher);
        $guard->attempting(function (): void {});
    }

    public function testAttemptSkipsEventDispatchWhenNoListenersAreRegistered(): void
    {
        $guard = $this->getGuard();
        $guard->setDispatcher($events = m::mock(Dispatcher::class));
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(false);
        $events->shouldNotReceive('dispatch');

        $timebox = $guard->getTimebox();
        $timebox->shouldReceive('call')->once()->andReturnUsing(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox);
        });

        $guard->getProvider()->shouldReceive('retrieveByCredentials')->once()->with(['foo'])->andReturn(null);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');

        $this->assertFalse($guard->attempt(['foo']));
    }

    public function testViaRememberReturnsFalseByDefault(): void
    {
        $guard = $this->getGuard();

        $this->assertFalse($guard->viaRemember());
    }

    public function testGetNameReturnsConsistentValue(): void
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

    public function testGetRecallerNameReturnsConsistentValue(): void
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

    public function testGetNameContainsGuardName(): void
    {
        $guard = $this->getGuard();

        $this->assertStringContainsString('default', $guard->getName());
        $this->assertSame('login_default_' . hash('xxh128', SessionGuard::class), $guard->getName());
        $this->assertSame('remember_default_' . hash('xxh128', SessionGuard::class), $guard->getRecallerName());
    }

    /**
     * Create a guard with mocked dependencies.
     */
    protected function getGuard(): SessionGuard
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();

        return new SessionGuard('default', $provider, $session, $app, $timebox);
    }

    /**
     * Create the guard's dependencies.
     */
    protected function getMocks(): array
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

    /**
     * Create an event dispatcher with listeners enabled.
     */
    protected function mockEventDispatcher(): Dispatcher
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->byDefault()->andReturn(true);

        return $events;
    }
}
