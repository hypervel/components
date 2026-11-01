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
use Hypervel\Auth\GenericUser;
use Hypervel\Auth\Recaller;
use Hypervel\Auth\SessionGuard;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Session\Session;
use Hypervel\Cookie\CookieJar;
use Hypervel\Coroutine\Waiter;
use Hypervel\Events\Dispatcher;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Session\Store;
use Hypervel\Support\Testing\Fakes\EventFake;
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
        [$guard, $provider, $session] = $this->getRealGuard(Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']));

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $credentials = ['email' => 'foo@bar.com', 'password' => 'secret'];
        $provider->expects('retrieveByCredentials')->with($credentials)->andReturn($user);
        $provider->expects('validateCredentials')->with($user, $credentials)->andReturn(true);
        $provider->expects('rehashPasswordIfRequired')->with($user, $credentials);

        $this->assertNull($guard->basic('email'));
        $this->assertSame($user, $guard->user());
        $this->assertSame(10, $session->get($guard->getName()));
    }

    public function testBasicReturnsNullWhenAlreadyLoggedIn(): void
    {
        [$guard, $provider] = $this->getRealGuard(Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']));

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $guard->setUser($user);
        $provider->shouldNotReceive('retrieveByCredentials');

        $this->assertNull($guard->basic('email'));
        $this->assertSame($user, $guard->user());
    }

    public function testBasicReturnsResponseOnFailure(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        [$guard, $provider] = $this->getRealGuard(Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']));

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $credentials = ['email' => 'foo@bar.com', 'password' => 'secret'];
        $provider->expects('retrieveByCredentials')->with($credentials)->andReturn($user);
        $provider->expects('validateCredentials')->with($user, $credentials)->andReturn(false);
        $provider->shouldNotReceive('rehashPasswordIfRequired');

        $guard->basic('email');
    }

    public function testBasicWithExtraConditions(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard(Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']));

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $credentials = ['email' => 'foo@bar.com', 'password' => 'secret', 'active' => 1];
        $provider->expects('retrieveByCredentials')->with($credentials)->andReturn($user);
        $provider->expects('validateCredentials')->with($user, $credentials)->andReturn(true);
        $provider->expects('rehashPasswordIfRequired')->with($user, $credentials);

        $this->assertNull($guard->basic('email', ['active' => 1]));
        $this->assertSame($user, $guard->user());
        $this->assertSame(10, $session->get($guard->getName()));
    }

    public function testBasicWithExtraArrayConditions(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard(Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']));

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $credentials = ['email' => 'foo@bar.com', 'password' => 'secret', 'active' => 1, 'type' => [1, 2, 3]];
        $provider->expects('retrieveByCredentials')->with($credentials)->andReturn($user);
        $provider->expects('validateCredentials')->with($user, $credentials)->andReturn(true);
        $provider->expects('rehashPasswordIfRequired')->with($user, $credentials);

        $this->assertNull($guard->basic('email', ['active' => 1, 'type' => [1, 2, 3]]));
        $this->assertSame($user, $guard->user());
        $this->assertSame(10, $session->get($guard->getName()));
    }

    public function testAttemptCallsRetrieveByCredentials(): void
    {
        $guard = $this->getGuard();
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $timebox = $guard->getTimebox();
        $timebox->expects('call')->andReturnUsing(function (Closure $callback) use ($timebox): bool {
            return $callback($timebox);
        });
        $guard->getProvider()->expects('retrieveByCredentials')->with(['foo']);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $guard->attempt(['foo']);

        $events->assertDispatchedOnce(Attempting::class);
        $events->assertDispatchedOnce(Failed::class);
        $events->assertNotDispatched(Validated::class);
    }

    public function testAttemptReturnsUserInterface(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['login'])->setConstructorArgs(['default', $provider, $session, $app, $timebox])->getMock();
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $timebox->expects('call')->andReturnUsing(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox->expects('returnEarly')->getMock());
        });
        $user = $this->createStub(Authenticatable::class);
        $guard->getProvider()->expects('retrieveByCredentials')->andReturn($user);
        $guard->getProvider()->expects('validateCredentials')->with($user, ['foo'])->andReturn(true);
        $guard->getProvider()->expects('rehashPasswordIfRequired')->with($user, ['foo']);
        $guard->expects($this->once())->method('login')->with($user);
        $this->assertTrue($guard->attempt(['foo']));

        $events->assertDispatchedOnce(Attempting::class);
        $events->assertDispatchedOnce(Validated::class);
    }

    public function testAttemptReturnsFalseIfUserNotGiven(): void
    {
        $mock = $this->getGuard();
        $events = new EventFake(new Dispatcher);
        $mock->setDispatcher($events);
        $timebox = $mock->getTimebox();
        $timebox->expects('call')->andReturnUsing(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox);
        });
        $mock->getProvider()->expects('retrieveByCredentials')->andReturn(null);
        $mock->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $this->assertFalse($mock->attempt(['foo']));

        $events->assertDispatchedOnce(Attempting::class);
        $events->assertDispatchedOnce(Failed::class);
        $events->assertNotDispatched(Validated::class);
    }

    public function testAttemptAndWithCallbacks(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName'])->setConstructorArgs(['default', $provider, $session, $app, $timebox])->getMock();
        $events = new EventFake(new Dispatcher);
        $mock->setDispatcher($events);
        $timebox->shouldReceive('call')->andReturnUsing(function (Closure $callback) use ($timebox): bool {
            return $callback($timebox->shouldReceive('returnEarly')->getMock());
        });
        $user = m::mock(Authenticatable::class);
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $user->expects('getAuthIdentifier')->andReturn('bar');
        $user->expects('getAuthPassword')->andReturn(null);
        $mock->getSession()->expects('put')->with('foo', 'bar');
        $mock->getSession()->expects('remove')->with('password_hash_default');
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

        $events->assertDispatchedTimes(Attempting::class, 3);
        $events->assertDispatchedOnce(Login::class);
        $events->assertDispatchedOnce(Authenticated::class);
        $events->assertDispatchedTimes(Validated::class, 2);
        $events->assertDispatchedTimes(Failed::class, 2);
    }

    public function testAttemptRehashesPasswordWhenRequired(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['login'])->setConstructorArgs(['default', $provider, $session, $app, $timebox])->getMock();
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $timebox->expects('call')->andReturnUsing(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox->expects('returnEarly')->getMock());
        });
        $user = $this->createStub(Authenticatable::class);
        $guard->getProvider()->expects('retrieveByCredentials')->andReturn($user);
        $guard->getProvider()->expects('validateCredentials')->with($user, ['foo'])->andReturn(true);
        $guard->getProvider()->expects('rehashPasswordIfRequired')->with($user, ['foo']);
        $guard->expects($this->once())->method('login')->with($user);
        $this->assertTrue($guard->attempt(['foo']));

        $events->assertDispatchedOnce(Attempting::class);
        $events->assertDispatchedOnce(Validated::class);
    }

    public function testAttemptDoesntRehashPasswordWhenDisabled(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['login'])
            ->setConstructorArgs(['default', $provider, $session, $app, $timebox, false])
            ->getMock();
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $timebox->expects('call')->andReturnUsing(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox->expects('returnEarly')->getMock());
        });
        $user = $this->createStub(Authenticatable::class);
        $guard->getProvider()->expects('retrieveByCredentials')->andReturn($user);
        $guard->getProvider()->expects('validateCredentials')->with($user, ['foo'])->andReturn(true);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $guard->expects($this->once())->method('login')->with($user);
        $this->assertTrue($guard->attempt(['foo']));

        $events->assertDispatchedOnce(Attempting::class);
        $events->assertDispatchedOnce(Validated::class);
    }

    public function testLoginStoresIdentifierInSession(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = $this->getMockBuilder(SessionGuard::class)->onlyMethods(['getName'])->setConstructorArgs(['default', $provider, $session, $app])->getMock();
        $user = m::mock(Authenticatable::class);
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $user->expects('getAuthIdentifier')->andReturn('bar');
        $user->expects('getAuthPassword')->andReturn(null);
        $mock->getSession()->expects('put')->with('foo', 'bar');
        $mock->getSession()->expects('remove')->with('password_hash_default');
        $session->expects('regenerate');
        $mock->login($user);
    }

    public function testLoginStoresPasswordHashInSession(): void
    {
        [$guard, , $session] = $this->getRealGuard();
        $sessionId = $session->getId();
        $user = new GenericUser(['id' => 'foo', 'password' => 'bar']);

        $guard->login($user);

        $this->assertSame('foo', $session->get($guard->getName()));
        $this->assertSame(
            hash_hmac('sha256', 'bar', 'base-key-for-password-hash-mac'),
            $session->get('password_hash_default')
        );
        $this->assertNotSame($sessionId, $session->getId());
    }

    public function testLoginWithGenericUserWithoutPasswordDoesNotStorePasswordHash(): void
    {
        [$guard, , $session] = $this->getRealGuard();
        $user = new GenericUser(['id' => 'foo']);
        $warnings = [];

        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_WARNING);

        try {
            $guard->login($user);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame('foo', $session->get($guard->getName()));
        $this->assertFalse($session->has('password_hash_default'));
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
        $events = new EventFake(new Dispatcher);
        $mock->setDispatcher($events);
        $user = m::mock(Authenticatable::class);
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $user->expects('getAuthIdentifier')->andReturn('bar');
        $user->expects('getAuthPassword')->andReturn(null);
        $mock->getSession()->expects('put')->with('foo', 'bar');
        $mock->getSession()->expects('remove')->with('password_hash_default');
        $session->expects('regenerate');
        $mock->login($user);

        $events->assertDispatchedOnce(Login::class);
        $events->assertDispatchedOnce(Authenticated::class);
    }

    public function testFailedAttemptFiresFailedEvent(): void
    {
        $guard = $this->getGuard();
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $timebox = $guard->getTimebox();
        $timebox->expects('call')->andReturnUsing(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox);
        });
        $guard->getProvider()->expects('retrieveByCredentials')->with(['foo'])->andReturn(null);
        $guard->getProvider()->shouldNotReceive('rehashPasswordIfRequired');
        $guard->attempt(['foo']);

        $events->assertDispatchedOnce(Attempting::class);
        $events->assertDispatchedOnce(Failed::class);
        $events->assertNotDispatched(Validated::class);
    }

    public function testAuthenticateReturnsUserWhenUserIsNotNull(): void
    {
        $user = new GenericUser([]);
        $guard = $this->getGuard();
        $guard->setUser($user);

        $this->assertEquals($user, $guard->authenticate());
    }

    public function testSetUserFiresAuthenticatedEvent(): void
    {
        $user = new GenericUser([]);
        $guard = $this->getGuard();
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $guard->setUser($user);

        $events->assertDispatchedOnce(Authenticated::class);
    }

    public function testAuthenticateThrowsWhenUserIsNull(): void
    {
        $this->expectExceptionObject(new AuthenticationException('Unauthenticated.'));

        [$guard] = $this->getRealGuard();

        $guard->authenticate();
    }

    public function testHasUserReturnsTrueWhenUserIsNotNull(): void
    {
        $user = new GenericUser([]);
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
        $user = new GenericUser([]);
        $mock = $this->getGuard();
        $mock->setUser($user);
        $this->assertTrue($mock->check());
        $this->assertFalse($mock->guest());
    }

    public function testIsAuthedReturnsFalseWhenUserIsNull(): void
    {
        [$guard] = $this->getRealGuard();

        $this->assertFalse($guard->check());
        $this->assertTrue($guard->guest());
    }

    public function testUserMethodReturnsCachedUser(): void
    {
        $user = new GenericUser([]);
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
        [$guard, $provider, $session] = $this->getRealGuard();
        $session->put($guard->getName(), 1);
        $user = new GenericUser([]);
        $provider->expects('retrieveById')->with(1)->andReturn($user);

        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->getUser());
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

        $cookie = new Cookie('bar');
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
        [$guard, , $session] = $this->getRealGuard();
        $guard->setCookieJar(new CookieJar);
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $user = new GenericUser(['id' => 10, 'remember_token' => null]);
        $guard->setUser($user);
        $session->put($guard->getName(), $user->getAuthIdentifier());
        $guard->logout();

        $this->assertNull($session->get($guard->getName()));
        $events->assertDispatchedOnce(Authenticated::class);
        $events->assertDispatchedOnce(Logout::class);
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
        $user = new GenericUser([]);
        $mock->expects($this->once())->method('getName')->willReturn('foo');
        $mock->expects($this->exactly(2))->method('getRecallerName')->willReturn($recallerName = 'bar');
        $mock->expects($this->once())->method('recaller')->willReturn(new Recaller('id|token|hash'));

        $cookie = new Cookie('bar');
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
        $user = new GenericUser([]);
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
        [$guard, , $session] = $this->getRealGuard();
        $guard->setCookieJar(new CookieJar);
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $user = new GenericUser(['id' => 10]);
        $guard->setUser($user);
        $session->put($guard->getName(), $user->getAuthIdentifier());
        $guard->logoutCurrentDevice();
        $guard->logoutCurrentDevice();

        $this->assertNull($session->get($guard->getName()));
        $events->assertDispatchedOnce(Authenticated::class);
        $events->assertDispatchedTimes(CurrentDeviceLogout::class, 2);
        $events->assertDispatched(CurrentDeviceLogout::class, fn (CurrentDeviceLogout $event): bool => $event->guard === 'default' && $event->user === $user);
        $events->assertDispatched(CurrentDeviceLogout::class, fn (CurrentDeviceLogout $event): bool => $event->guard === 'default' && $event->user === null);
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
        $guard->getSession()->expects('put')->with('password_hash_default', $expectedHash);
        $session->expects('regenerate');
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthIdentifier')->times(2)->andReturn('foo');
        $user->expects('getAuthPassword')->times(2)->andReturn('bar');
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
        $guard->getSession()->shouldReceive('remove')->once()->with('password_hash_default');
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
        $guard->getSession()->expects('put')->with('password_hash_default', $expectedHash);
        $session->expects('regenerate');
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthIdentifier')->times(2)->andReturn('foo');
        $user->expects('getAuthPassword')->times(2)->andReturn('bar');
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
        $guard->getSession()->expects('put')->with('password_hash_default', hash_hmac('sha256', 'foo', 'base-key-for-password-hash-mac'));
        $session->expects('regenerate');
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthIdentifier')->times(2)->andReturn('foo');
        $user->expects('getAuthPassword')->times(2)->andReturn('foo');
        $user->expects('getRememberToken')->times(2)->andReturn(null);
        $user->expects('setRememberToken');
        $provider->expects('updateRememberToken');
        $guard->login($user, true);
    }

    public function testLoginUsingIdLogsInWithUser(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard();

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $provider->expects('retrieveById')->with(10)->andReturn($user);

        $this->assertSame($user, $guard->loginUsingId(10));
        $this->assertSame($user, $guard->user());
        $this->assertSame(10, $session->get($guard->getName()));
    }

    public function testLoginUsingIdFailure(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard();

        $provider->expects('retrieveById')->with(11)->andReturn(null);

        $this->assertFalse($guard->loginUsingId(11));
        $this->assertNull($guard->user());
        $this->assertNull($session->get($guard->getName()));
    }

    public function testOnceUsingIdSetsUser(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard();

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $provider->expects('retrieveById')->with(10)->andReturn($user);

        $this->assertSame($user, $guard->onceUsingId(10));
        $this->assertSame($user, $guard->user());
        $this->assertNull($session->get($guard->getName()));
    }

    public function testOnceUsingIdFailure(): void
    {
        [$guard, $provider] = $this->getRealGuard();

        $provider->expects('retrieveById')->with(11)->andReturn(null);

        $this->assertFalse($guard->onceUsingId(11));
        $this->assertNull($guard->user());
    }

    #[DataProvider('rememberCookiePasswords')]
    public function testUserUsesRememberCookieIfItExists(?string $passwordHash): void
    {
        $guard = $this->getGuard();
        [$guard, $provider, $session] = $this->getRealGuard(Request::create('/', 'GET', [], [
            $guard->getRecallerName() => 'id|recaller|' . $guard->hashPasswordForCookie($passwordHash),
        ]));
        $sessionId = $session->getId();
        $user = m::mock(Authenticatable::class);
        $provider->expects('retrieveByToken')->with('id', 'recaller')->andReturn($user);
        $user->expects('getAuthIdentifier')->twice()->andReturn('bar');
        $user->expects('getAuthPassword')->twice()->andReturn($passwordHash);
        $events = m::mock(DispatcherContract::class);
        $events->shouldReceive('hasListeners')->andReturnUsing(static fn (string $event): bool => $event === Login::class);
        $rememberedLogins = [];
        $events->expects('dispatch')->twice()->with(m::on(function (Login $event) use ($guard, &$rememberedLogins): bool {
            $rememberedLogins[] = $guard->viaRemember();

            return true;
        }));
        $guard->setDispatcher($events);

        $this->assertSame($user, $guard->user());
        $this->assertTrue($guard->viaRemember());
        $this->assertSame('bar', $session->get($guard->getName()));
        $this->assertNotSame($sessionId, $rememberedSessionId = $session->getId());
        $this->assertFalse($session->has('password_hash_default'));

        $guard->login($user);
        $this->assertFalse($guard->viaRemember());
        $this->assertSame([true, false], $rememberedLogins);
        $this->assertNotSame($rememberedSessionId, $session->getId());
        $this->assertSame(
            $passwordHash === null ? null : $guard->hashPasswordForCookie($passwordHash),
            $session->get('password_hash_default')
        );
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

    public function testUserReturnsNullWhenRememberCookieHasEmptyToken(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $cookieRequest = Request::create('/', 'GET', [], [$guard->getRecallerName() => 'id||baz']);
        $app->shouldReceive('make')->with('request')->andReturn($cookieRequest);
        $guard->getSession()->expects('get')->with($guard->getName())->andReturn(null);
        $guard->getProvider()->shouldReceive('retrieveByToken')->never();
        $this->assertNull($guard->user());
        $this->assertFalse($guard->viaRemember());
    }

    public function testUserReturnsNullWhenPasswordlessUserHasInvalidRememberCookieHash(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $cookieRequest = Request::create('/', 'GET', [], [$guard->getRecallerName() => 'id|recaller|baz']);
        $app->shouldReceive('make')->with('request')->andReturn($cookieRequest);
        $session->expects('get')->with($guard->getName())->andReturn(null);
        $user = m::mock(Authenticatable::class);
        $provider->expects('retrieveByToken')->with('id', 'recaller')->andReturn($user);
        $user->expects('getAuthPassword')->andReturn(null);

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

    public function testHashPasswordForCookieAcceptsNullPassword(): void
    {
        $guard = $this->getGuard();
        $deprecations = [];

        set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED);

        try {
            $hash = $guard->hashPasswordForCookie(null);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
        $this->assertSame($guard->hashPasswordForCookie(''), $hash);
    }

    public function testLoginOnceSetsUser(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard();

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $provider->expects('retrieveByCredentials')->with(['foo'])->andReturn($user);
        $provider->expects('validateCredentials')->with($user, ['foo'])->andReturn(true);
        $provider->expects('rehashPasswordIfRequired')->with($user, ['foo']);

        $this->assertTrue($guard->once(['foo']));
        $this->assertSame($user, $guard->user());
        $this->assertNull($session->get($guard->getName()));
    }

    public function testLoginOnceFailure(): void
    {
        [$guard, $provider] = $this->getRealGuard();

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $provider->expects('retrieveByCredentials')->with(['foo'])->andReturn($user);
        $provider->expects('validateCredentials')->with($user, ['foo'])->andReturn(false);
        $provider->shouldNotReceive('rehashPasswordIfRequired');

        $this->assertFalse($guard->once(['foo']));
        $this->assertNull($guard->user());
    }

    public function testForgetUserSetsUserToNull(): void
    {
        $user = new GenericUser([]);
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

    public function testLoginListenersSeeTheNewUserBeforeAuthenticatedIsFired(): void
    {
        $guard = $this->getGuard();
        $guard->setUser(m::mock(Authenticatable::class));
        $user = m::mock(Authenticatable::class);
        $user->expects('getAuthIdentifier')->andReturn(42);
        $user->expects('getAuthPassword')->andReturn(null);
        $guard->getSession()->expects('put')->with($guard->getName(), 42);
        $guard->getSession()->expects('remove')->with('password_hash_default');
        $guard->getSession()->expects('regenerate')->with(true);
        $events = $this->mockEventDispatcher();
        $events->expects('dispatch')->with(m::type(Login::class))->ordered()
            ->andReturnUsing(function (Login $event) use ($guard, $user): void {
                $this->assertSame($user, $event->user);
                $this->assertTrue($guard->hasUser());
                $this->assertSame($user, $guard->getUser());
                $this->assertSame($user, $guard->user());
            });
        $events->expects('dispatch')->with(m::type(Authenticated::class))->ordered();
        $guard->setDispatcher($events);

        $guard->login($user);

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
        $user->expects('getAuthPassword')->andReturn(null);
        $session->expects('put')->with($guard->getName(), 42);
        $session->expects('remove')->with('password_hash_default');
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

        $nextUser = m::mock(Authenticatable::class);
        $nextUser->expects('getAuthIdentifier')->andReturn(42);
        $nextUser->expects('getAuthPassword')->andReturn(null);
        $session->expects('put')->with($mock->getName(), 42);
        $session->expects('remove')->with('password_hash_default');
        $session->expects('regenerate')->with(true);
        $events = $this->mockEventDispatcher();
        $events->expects('dispatch')->with(m::type(Login::class))->ordered()
            ->andReturnUsing(function (Login $event) use ($mock, $nextUser): void {
                $this->assertSame($nextUser, $event->user);
                $this->assertSame($nextUser, $mock->getUser());
                $this->assertSame($nextUser, $mock->user());
            });
        $events->expects('dispatch')->with(m::type(Authenticated::class))->ordered();
        $mock->setDispatcher($events);

        $mock->login($nextUser);

        $this->assertSame($nextUser, $mock->user());
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
        $this->expectExceptionMessageIsOrContains('Cookie jar has not been set.');

        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);

        $guard->getCookieJar();
    }

    public function testAttemptingRegistersEventListener(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);

        $dispatcher = m::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('listen')
            ->with(Attempting::class, m::type('callable'))
            ->once();

        $guard->setDispatcher($dispatcher);
        $guard->attempting(function (): void {});
    }

    public function testAttemptSkipsEventDispatchWhenNoListenersAreRegistered(): void
    {
        $guard = $this->getGuard();
        $guard->setDispatcher($events = m::mock(DispatcherContract::class));
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
     * Create a guard backed by a real session and a mocked user provider.
     *
     * @return array{SessionGuard, UserProvider, Store}
     */
    protected function getRealGuard(?Request $request = null): array
    {
        $session = new Store('test', new ArraySessionHandler(10));
        $provider = m::mock(UserProvider::class);
        $app = new Container;
        $app->instance('request', $request ?? Request::create('/', 'GET'));

        return [new SessionGuard('default', $provider, $session, $app, timeboxDuration: 0), $provider, $session];
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
    protected function mockEventDispatcher(): DispatcherContract
    {
        $events = m::mock(DispatcherContract::class);
        $events->shouldReceive('hasListeners')->byDefault()->andReturn(true);

        return $events;
    }
}
