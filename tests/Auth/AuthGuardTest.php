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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthGuardTest extends TestCase
{
    use VerifiesDoubles;

    public function testBasicReturnsNullOnValidAttempt(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard(Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']));

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $credentials = ['email' => 'foo@bar.com', 'password' => 'secret'];
        $provider->expects('retrieveByCredentials')->with($credentials)->returns($user);
        $provider->expects('validateCredentials')->with($user, $credentials)->returns(true);
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
        $provider->expects('retrieveByCredentials')->never();

        $this->assertNull($guard->basic('email'));
        $this->assertSame($user, $guard->user());
    }

    public function testBasicReturnsResponseOnFailure(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        [$guard, $provider] = $this->getRealGuard(Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']));

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $credentials = ['email' => 'foo@bar.com', 'password' => 'secret'];
        $provider->expects('retrieveByCredentials')->with($credentials)->returns($user);
        $provider->expects('validateCredentials')->with($user, $credentials)->returns(false);
        $provider->expects('rehashPasswordIfRequired')->never();

        $guard->basic('email');
    }

    public function testBasicWithExtraConditions(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard(Request::create('/', 'GET', [], [], [], ['PHP_AUTH_USER' => 'foo@bar.com', 'PHP_AUTH_PW' => 'secret']));

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $credentials = ['email' => 'foo@bar.com', 'password' => 'secret', 'active' => 1];
        $provider->expects('retrieveByCredentials')->with($credentials)->returns($user);
        $provider->expects('validateCredentials')->with($user, $credentials)->returns(true);
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
        $provider->expects('retrieveByCredentials')->with($credentials)->returns($user);
        $provider->expects('validateCredentials')->with($user, $credentials)->returns(true);
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
        $timebox->expects('call')->resolves(function (Closure $callback) use ($timebox): bool {
            return $callback($timebox);
        });
        $guard->getProvider()->expects('retrieveByCredentials')->with(['foo']);
        $guard->getProvider()->expects('rehashPasswordIfRequired')->never();
        $guard->attempt(['foo']);

        $events->assertDispatchedOnce(Attempting::class);
        $events->assertDispatchedOnce(Failed::class);
        $events->assertNotDispatched(Validated::class);
    }

    public function testAttemptReturnsUserInterface(): void
    {
        [$guard, $provider] = $this->getRealGuard();
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $user = new GenericUser(['id' => 10]);
        $provider->expects('retrieveByCredentials')->returns($user);
        $provider->expects('validateCredentials')->with($user, ['foo'])->returns(true);
        $provider->expects('rehashPasswordIfRequired')->with($user, ['foo']);
        $this->assertTrue($guard->attempt(['foo']));
        $this->assertSame($user, $guard->getUser());

        $events->assertDispatchedOnce(Attempting::class);
        $events->assertDispatchedOnce(Validated::class);
    }

    public function testAttemptReturnsFalseIfUserNotGiven(): void
    {
        $mock = $this->getGuard();
        $events = new EventFake(new Dispatcher);
        $mock->setDispatcher($events);
        $timebox = $mock->getTimebox();
        $timebox->expects('call')->resolves(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox);
        });
        $mock->getProvider()->expects('retrieveByCredentials')->returns(null);
        $mock->getProvider()->expects('rehashPasswordIfRequired')->never();
        $this->assertFalse($mock->attempt(['foo']));

        $events->assertDispatchedOnce(Attempting::class);
        $events->assertDispatchedOnce(Failed::class);
        $events->assertNotDispatched(Validated::class);
    }

    public function testAttemptAndWithCallbacks(): void
    {
        [$mock, $provider, $session] = $this->getRealGuard();
        $events = new EventFake(new Dispatcher);
        $mock->setDispatcher($events);
        $user = new GenericUser(['id' => 'bar']);
        $provider->expects('retrieveByCredentials')->times(3)->with(['foo'])->returns($user);
        $provider->expects('validateCredentials')->times(3)->returns(true, true, false);
        $provider->expects('rehashPasswordIfRequired')->with($user, ['foo']);

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
        $this->assertSame('bar', $session->get($mock->getName()));

        $events->assertDispatchedTimes(Attempting::class, 3);
        $events->assertDispatchedOnce(Login::class);
        $events->assertDispatchedOnce(Authenticated::class);
        $events->assertDispatchedTimes(Validated::class, 2);
        $events->assertDispatchedTimes(Failed::class, 2);
    }

    public function testAttemptRehashesPasswordWhenRequired(): void
    {
        [$guard, $provider] = $this->getRealGuard();
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $user = new GenericUser(['id' => 10]);
        $provider->expects('retrieveByCredentials')->returns($user);
        $provider->expects('validateCredentials')->with($user, ['foo'])->returns(true);
        $provider->expects('rehashPasswordIfRequired')->with($user, ['foo']);
        $this->assertTrue($guard->attempt(['foo']));
        $this->assertSame($user, $guard->getUser());

        $events->assertDispatchedOnce(Attempting::class);
        $events->assertDispatchedOnce(Validated::class);
    }

    public function testAttemptDoesntRehashPasswordWhenDisabled(): void
    {
        $provider = Double::for(UserProvider::class);
        $guard = new SessionGuard('default', $provider, new Store('test', new ArraySessionHandler(10)), new Container, rehashOnLogin: false, timeboxDuration: 0);
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $user = new GenericUser(['id' => 10]);
        $provider->expects('retrieveByCredentials')->returns($user);
        $provider->expects('validateCredentials')->with($user, ['foo'])->returns(true);
        $provider->expects('rehashPasswordIfRequired')->never();
        $this->assertTrue($guard->attempt(['foo']));
        $this->assertSame($user, $guard->getUser());

        $events->assertDispatchedOnce(Attempting::class);
        $events->assertDispatchedOnce(Validated::class);
    }

    public function testLoginStoresIdentifierInSession(): void
    {
        [$guard, , $session] = $this->getRealGuard();
        $sessionId = $session->getId();
        $guard->login(new GenericUser(['id' => 'bar']));
        $this->assertSame('bar', $session->get($guard->getName()));
        $this->assertNotSame($sessionId, $session->getId());
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
        [$guard] = $this->getRealGuard();
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $guard->login(new GenericUser(['id' => 'bar']));

        $events->assertDispatchedOnce(Login::class);
        $events->assertDispatchedOnce(Authenticated::class);
    }

    public function testFailedAttemptFiresFailedEvent(): void
    {
        $guard = $this->getGuard();
        $events = new EventFake(new Dispatcher);
        $guard->setDispatcher($events);
        $timebox = $guard->getTimebox();
        $timebox->expects('call')->resolves(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox);
        });
        $guard->getProvider()->expects('retrieveByCredentials')->with(['foo'])->returns(null);
        $guard->getProvider()->expects('rehashPasswordIfRequired')->never();
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
        $guard->getSession()->expects('get')->never();

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
        $mock->getSession()->allows('isStarted')->returns($sessionStarted);
        $mock->getSession()->expects('get')->returns(null);
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
        $provider->expects('retrieveById')->with(1)->returns($user);

        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->getUser());
    }

    public function testLogoutRemovesSessionTokenAndRememberMeCookie(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard();
        $guard->setCookieJar($cookies = new CookieJar);
        $guard->getRequest()->cookies->set($guard->getRecallerName(), '10|a|hash');
        $user = new GenericUser(['id' => 10, 'remember_token' => 'a']);
        $provider->expects('updateRememberToken');
        $session->put($guard->getName(), 10);
        // A cookie queued earlier in the request is replaced by the one that forgets it...
        $cookies->queue($cookies->make($guard->getRecallerName(), 'stale'));
        $guard->setUser($user);
        $guard->logout();
        $this->assertNull($guard->getUser());
        $this->assertNull($session->get($guard->getName()));
        $this->assertNotSame('a', $user->getRememberToken());
        $this->assertLessThan(time(), $cookies->queued($guard->getRecallerName())->getExpiresTime());
        $this->assertSame('', (string) $cookies->queued($guard->getRecallerName())->getValue());
    }

    public function testLogoutDoesNotEnqueueRememberMeCookieForDeletionIfCookieDoesntExist(): void
    {
        [$guard, , $session] = $this->getRealGuard();
        $guard->setCookieJar($cookies = new CookieJar);
        $user = new GenericUser(['id' => 10, 'remember_token' => null]);
        $session->put($guard->getName(), 10);
        $cookies->queue($cookies->make($guard->getRecallerName(), 'stale'));
        $guard->setUser($user);
        $guard->logout();
        $this->assertNull($guard->getUser());
        $this->assertNull($session->get($guard->getName()));
        $this->assertFalse($cookies->hasQueued($guard->getRecallerName()));
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
        [$guard, $provider] = $this->getRealGuard();
        $guard->setCookieJar(new CookieJar);
        $user = new GenericUser(['id' => 10, 'remember_token' => null]);

        $provider->expects('updateRememberToken')->never();

        $guard->setUser($user);
        $guard->logout();

        $this->assertNull($user->getRememberToken());
    }

    public function testLogoutCurrentDeviceRemovesRememberMeCookie(): void
    {
        [$guard, , $session] = $this->getRealGuard();
        $guard->setCookieJar($cookies = new CookieJar);
        $guard->getRequest()->cookies->set($guard->getRecallerName(), '10|a|hash');
        $user = new GenericUser(['id' => 10, 'remember_token' => 'a']);
        $session->put($guard->getName(), 10);
        $cookies->queue($cookies->make($guard->getRecallerName(), 'stale'));
        $guard->setUser($user);
        $guard->logoutCurrentDevice();
        $this->assertNull($guard->getUser());
        $this->assertNull($session->get($guard->getName()));
        $this->assertSame('a', $user->getRememberToken());
        $this->assertLessThan(time(), $cookies->queued($guard->getRecallerName())->getExpiresTime());
        $this->assertSame('', (string) $cookies->queued($guard->getRecallerName())->getValue());
    }

    public function testLogoutCurrentDeviceDoesNotEnqueueRememberMeCookieForDeletionIfCookieDoesntExist(): void
    {
        [$guard, , $session] = $this->getRealGuard();
        $guard->setCookieJar($cookies = new CookieJar);
        $user = new GenericUser(['id' => 10]);
        $session->put($guard->getName(), 10);
        $cookies->queue($cookies->make($guard->getRecallerName(), 'stale'));
        $guard->setUser($user);
        $guard->logoutCurrentDevice();
        $this->assertNull($guard->getUser());
        $this->assertNull($session->get($guard->getName()));
        $this->assertFalse($cookies->hasQueued($guard->getRecallerName()));
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
        [$guard, $provider, $session] = $this->getRealGuard();
        $guard->setCookieJar($cookies = new CookieJar);
        $expectedHash = hash_hmac('sha256', 'bar', 'base-key-for-password-hash-mac');
        $user = new GenericUser(['id' => 'foo', 'password' => 'bar', 'remember_token' => 'recaller']);
        $provider->expects('updateRememberToken')->never();
        $guard->login($user, true);

        $cookie = $cookies->queued($guard->getRecallerName());
        $this->assertSame('foo|recaller|' . $expectedHash, $cookie->getValue());
        $this->assertEqualsWithDelta(time() + 576000 * 60, $cookie->getExpiresTime(), 5);
        $this->assertSame('foo', $session->get($guard->getName()));
        $this->assertSame($expectedHash, $session->get('password_hash_default'));
        $this->assertSame('recaller', $user->getRememberToken());
    }

    public function testLoginMethodQueuesCookieWhenRememberingPasswordlessUser(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $guard->setCookieJar($cookie);
        $foreverCookie = new Cookie($guard->getRecallerName(), 'foo');
        $expectedHash = hash_hmac('sha256', '', 'base-key-for-password-hash-mac');
        $cookie->expects('make')->with($guard->getRecallerName(), 'foo|recaller|' . $expectedHash, 576000)->returns($foreverCookie);
        $cookie->expects('queue')->with(Argument::same($foreverCookie));
        $guard->getSession()->expects('put')->with($guard->getName(), 'foo');
        $guard->getSession()->expects('remove')->with('password_hash_default');
        $session->expects('regenerate');
        $user = Double::for(Authenticatable::class);
        $user->allows('getAuthIdentifier')->returns('foo');
        $user->allows('getAuthPassword')->returns(null);
        $user->allows('getRememberToken')->returns('recaller');
        $user->expects('setRememberToken')->never();
        $provider->expects('updateRememberToken')->never();
        $guard->login($user, true);
    }

    public function testLoginMethodQueuesCookieWhenRememberingAndAllowsOverride(): void
    {
        [$guard, $provider] = $this->getRealGuard();
        $guard->setRememberDuration(5000);
        $guard->setCookieJar($cookies = new CookieJar);
        $expectedHash = hash_hmac('sha256', 'bar', 'base-key-for-password-hash-mac');
        $user = new GenericUser(['id' => 'foo', 'password' => 'bar', 'remember_token' => 'recaller']);
        $provider->expects('updateRememberToken')->never();
        $guard->login($user, true);

        $cookie = $cookies->queued($guard->getRecallerName());
        $this->assertSame('foo|recaller|' . $expectedHash, $cookie->getValue());
        $this->assertEqualsWithDelta(time() + 5000 * 60, $cookie->getExpiresTime(), 5);
        $this->assertSame('recaller', $user->getRememberToken());
    }

    public function testLoginMethodCreatesRememberTokenIfOneDoesntExist(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard();
        $guard->setCookieJar($cookies = new CookieJar);
        $expectedHash = hash_hmac('sha256', 'foo', 'base-key-for-password-hash-mac');
        $user = new GenericUser(['id' => 'foo', 'password' => 'foo', 'remember_token' => null]);
        $provider->expects('updateRememberToken');
        $guard->login($user, true);

        $this->assertNotEmpty($user->getRememberToken());
        $this->assertSame(
            'foo|' . $user->getRememberToken() . '|' . $expectedHash,
            $cookies->queued($guard->getRecallerName())->getValue()
        );
        $this->assertSame($expectedHash, $session->get('password_hash_default'));
    }

    public function testLoginUsingIdLogsInWithUser(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard();

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $provider->expects('retrieveById')->with(10)->returns($user);

        $this->assertSame($user, $guard->loginUsingId(10));
        $this->assertSame($user, $guard->user());
        $this->assertSame(10, $session->get($guard->getName()));
    }

    public function testLoginUsingIdFailure(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard();

        $provider->expects('retrieveById')->with(11)->returns(null);

        $this->assertFalse($guard->loginUsingId(11));
        $this->assertNull($guard->user());
        $this->assertNull($session->get($guard->getName()));
    }

    public function testOnceUsingIdSetsUser(): void
    {
        [$guard, $provider, $session] = $this->getRealGuard();

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $provider->expects('retrieveById')->with(10)->returns($user);

        $this->assertSame($user, $guard->onceUsingId(10));
        $this->assertSame($user, $guard->user());
        $this->assertNull($session->get($guard->getName()));
    }

    public function testOnceUsingIdFailure(): void
    {
        [$guard, $provider] = $this->getRealGuard();

        $provider->expects('retrieveById')->with(11)->returns(null);

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
        $user = Double::for(Authenticatable::class);
        $provider->expects('retrieveByToken')->with('id', 'recaller')->returns($user);
        $user->expects('getAuthIdentifier')->times(2)->returns('bar');
        $user->expects('getAuthPassword')->times(2)->returns($passwordHash);
        $events = Double::for(DispatcherContract::class);
        $events->allows('hasListeners')->resolves(static fn (string $event): bool => $event === Login::class);
        $rememberedLogins = [];
        $events->expects('dispatch')->times(2)->with(Argument::satisfies(function (Login $event) use ($guard, &$rememberedLogins): bool {
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
        $app->instance('request', $cookieRequest);
        $guard->getSession()->expects('get')->with($guard->getName())->returns(null);
        $guard->getProvider()->expects('retrieveByToken')->with('id', 'recaller')->returns(null);
        $this->assertNull($guard->user());
        $this->assertFalse($guard->viaRemember());
    }

    public function testUserReturnsNullWhenRememberCookieHasEmptyToken(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $cookieRequest = Request::create('/', 'GET', [], [$guard->getRecallerName() => 'id||baz']);
        $app->instance('request', $cookieRequest);
        $guard->getSession()->expects('get')->with($guard->getName())->returns(null);
        $guard->getProvider()->expects('retrieveByToken')->never();
        $this->assertNull($guard->user());
        $this->assertFalse($guard->viaRemember());
    }

    public function testUserReturnsNullWhenPasswordlessUserHasInvalidRememberCookieHash(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $cookieRequest = Request::create('/', 'GET', [], [$guard->getRecallerName() => 'id|recaller|baz']);
        $app->instance('request', $cookieRequest);
        $session->expects('get')->with($guard->getName())->returns(null);
        $user = Double::for(Authenticatable::class);
        $provider->expects('retrieveByToken')->with('id', 'recaller')->returns($user);
        $user->expects('getAuthPassword')->returns(null);

        $this->assertNull($guard->user());
        $this->assertFalse($guard->viaRemember());
    }

    #[DataProvider('invalidRememberCookieHashes')]
    public function testUserRejectsRememberCookieWithInvalidPasswordHash(string $cookieHash): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $cookieRequest = Request::create('/', 'GET', [], [$guard->getRecallerName() => 'id|recaller|' . $cookieHash]);
        $app->instance('request', $cookieRequest);
        $session->expects('get')->with($guard->getName())->returns(null);
        $user = Double::for(Authenticatable::class);
        $provider->expects('retrieveByToken')->with('id', 'recaller')->returns($user);
        $user->expects('getAuthPassword')->returns('baz');
        $session->expects('put')->never();
        $session->expects('regenerate')->never();

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
        $provider->expects('retrieveByCredentials')->with(['foo'])->returns($user);
        $provider->expects('validateCredentials')->with($user, ['foo'])->returns(true);
        $provider->expects('rehashPasswordIfRequired')->with($user, ['foo']);

        $this->assertTrue($guard->once(['foo']));
        $this->assertSame($user, $guard->user());
        $this->assertNull($session->get($guard->getName()));
    }

    public function testLoginOnceFailure(): void
    {
        [$guard, $provider] = $this->getRealGuard();

        $user = new GenericUser(['id' => 10, 'password' => 'secret']);
        $provider->expects('retrieveByCredentials')->with(['foo'])->returns($user);
        $provider->expects('validateCredentials')->with($user, ['foo'])->returns(false);
        $provider->expects('rehashPasswordIfRequired')->never();

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
        $user = Double::for(Authenticatable::class);
        $guard->getSession()->expects('get')->times(2)->with($guard->getName())->returns(null, 42);
        $guard->getProvider()->expects('retrieveById')->with(42)->returns($user);

        $this->assertNull($guard->user());
        $guard->forgetUser();

        $this->assertSame($user, $guard->user());
    }

    public function testAuthenticatedListenerCanReadAndReplaceResolvedUser(): void
    {
        $guard = $this->getGuard();
        $user = Double::for(Authenticatable::class);
        $replacement = Double::for(Authenticatable::class);
        $guard->getSession()->expects('get')->with($guard->getName())->returns(1);
        $guard->getProvider()->expects('retrieveById')->with(1)->returns($user);

        $events = $this->mockEventDispatcher();
        $events->expects('dispatch')->times(2)->with(Argument::type(Authenticated::class))
            ->resolves(function (Authenticated $event) use ($guard, $user, $replacement): void {
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
        $app->instance('request', $request);
        $sessionId = 'old-session-id';
        $session->allows('getId')->resolves(function () use (&$sessionId): string {
            return $sessionId;
        });
        $session->expects('get')->with($guard->getName())->returns(null);
        $user = Double::for(Authenticatable::class);
        $provider->expects('retrieveByToken')->with('id', 'recaller')->returns($user);
        $provider->expects('retrieveById')->never();
        $user->expects('getAuthPassword')->returns('baz');
        $user->expects('getAuthIdentifier')->returns('id');
        $session->expects('put')->with($guard->getName(), 'id');
        $session->expects('regenerate')->with(true)
            ->resolves(function () use ($guard, $user, &$sessionId): bool {
                // Database session deletion can invoke query listeners before the ID changes.
                $this->assertTrue($guard->hasUser());
                $this->assertSame($user, $guard->getUser());
                $this->assertSame($user, $guard->user());
                $sessionId = 'new-session-id';

                return true;
            });
        $events = $this->mockEventDispatcher();
        $events->expects('dispatch')->with(Argument::type(Login::class))
            ->resolves(function (Login $event) use ($guard, $user): void {
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
        $guard->setUser(Double::for(Authenticatable::class));
        $user = Double::for(Authenticatable::class);
        $user->expects('getAuthIdentifier')->returns(42);
        $user->expects('getAuthPassword')->returns(null);
        $guard->getSession()->expects('put')->with($guard->getName(), 42);
        $guard->getSession()->expects('remove')->with('password_hash_default');
        $guard->getSession()->expects('regenerate')->with(true);
        $events = $this->mockEventDispatcher();
        $events->expects('dispatch')->with(Argument::type(Login::class))->ordered()
            ->resolves(function (Login $event) use ($guard, $user): void {
                $this->assertSame($user, $event->user);
                $this->assertTrue($guard->hasUser());
                $this->assertSame($user, $guard->getUser());
                $this->assertSame($user, $guard->user());
            });
        $events->expects('dispatch')->with(Argument::type(Authenticated::class))->ordered();
        $guard->setDispatcher($events);

        $guard->login($user);

        $this->assertSame($user, $guard->user());
    }

    public function testLoggedInUserSurvivesIndependentSessionRotation(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $guard = new SessionGuard('default', $provider, $session, $app);
        $sessionId = 'session-id';
        $session->allows('getId')->resolves(function () use (&$sessionId): string {
            return $sessionId;
        });
        $session->expects('regenerate')->times(2)->resolves(function () use (&$sessionId): bool {
            $sessionId .= '-rotated';

            return true;
        });
        $user = Double::for(Authenticatable::class);
        $user->expects('getAuthIdentifier')->returns(42);
        $user->expects('getAuthPassword')->returns(null);
        $session->expects('put')->with($guard->getName(), 42);
        $session->expects('remove')->with('password_hash_default');
        $session->expects('get')->never();
        $provider->expects('retrieveById')->never();
        $events = $this->mockEventDispatcher();
        $events->expects('dispatch')->with(Argument::type(Login::class));
        $events->expects('dispatch')->with(Argument::type(Authenticated::class));
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
        $session->allows('getId')->resolves(function () use (&$sessionId): string {
            return $sessionId;
        });
        $session->allows('isStarted')->resolves(function () use (&$sessionStarted): bool {
            return $sessionStarted;
        });
        $session->expects('get')->times(2)->with($guard->getName())->returns(null, 42);
        $user = Double::for(Authenticatable::class);
        $provider->expects('retrieveById')->with(42)->returns($user);

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
        $session->expects('get')->times(2)->with($guard->getName())->returns(null, 42);
        $user = Double::for(Authenticatable::class);
        $provider->expects('retrieveById')->with(42)->returns($user);

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
        $session->allows('isStarted')->returns(false);

        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);
        $user = Double::for(Authenticatable::class);

        $guard->setUser($user);

        $this->assertTrue($guard->hasUser());
        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->getUser());
    }

    public function testUserSetBeforeSessionStartCanBeReplacedAfterSessionStarts(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $sessionStarted = false;
        $session->allows('isStarted')->resolves(function () use (&$sessionStarted): bool {
            return $sessionStarted;
        });
        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);

        $unstartedUser = Double::for(Authenticatable::class);
        $guard->setUser($unstartedUser);

        $session->expects('get')->never();

        $this->assertSame($unstartedUser, $guard->user());

        $sessionStarted = true;
        $user = Double::for(Authenticatable::class);
        $guard->setUser($user);

        $this->assertSame($user, $guard->user());
        $this->assertSame($user, $guard->getUser());
    }

    public function testForgetUserClearsUserSetBeforeSessionStart(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $session->allows('isStarted')->returns(false);

        $guard = new SessionGuard('default', $provider, $session, $app, $timebox);
        $user = Double::for(Authenticatable::class);
        $guard->setUser($user);

        $this->assertTrue($guard->hasUser());

        $guard->forgetUser();

        $this->assertFalse($guard->hasUser());
        $this->assertNull($guard->getUser());
    }

    public function testLoggedOutFlagMakesUserReturnNull(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = Double::for(new SessionGuard('default', $provider, $session, $app))->passthru();
        $mock->expects('clearUserDataFromStorage');

        $user = Double::for(Authenticatable::class);
        $user->allows('getRememberToken')->returns(null);

        $mock->setUser($user);
        $this->assertSame($user, $mock->user());

        $mock->logout();

        $this->assertNull($mock->user());

        $nextUser = Double::for(Authenticatable::class);
        $nextUser->expects('getAuthIdentifier')->returns(42);
        $nextUser->expects('getAuthPassword')->returns(null);
        $session->expects('put')->with($mock->getName(), 42);
        $session->expects('remove')->with('password_hash_default');
        $session->expects('regenerate')->with(true);
        $events = $this->mockEventDispatcher();
        $events->expects('dispatch')->with(Argument::type(Login::class))->ordered()
            ->resolves(function (Login $event) use ($mock, $nextUser): void {
                $this->assertSame($nextUser, $event->user);
                $this->assertSame($nextUser, $mock->getUser());
                $this->assertSame($nextUser, $mock->user());
            });
        $events->expects('dispatch')->with(Argument::type(Authenticated::class))->ordered();
        $mock->setDispatcher($events);

        $mock->login($nextUser);

        $this->assertSame($nextUser, $mock->user());
    }

    public function testIdReturnsNullAfterLogout(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $mock = Double::for(new SessionGuard('default', $provider, $session, $app))->passthru();
        $mock->expects('clearUserDataFromStorage');

        $user = Double::for(Authenticatable::class);
        $user->allows('getRememberToken')->returns(null);
        $user->allows('getAuthIdentifier')->returns(42);

        $mock->setUser($user);
        $this->assertSame(42, $mock->id());

        $mock->logout();

        $this->assertNull($mock->id());
    }

    public function testGetRequestResolvesFromContainer(): void
    {
        [$session, $provider, $request, $cookie, $timebox, $app] = $this->getMocks();
        $freshRequest = Request::create('/new-path', 'POST');
        $app->instance('request', $freshRequest);

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

        $dispatcher = Double::for(DispatcherContract::class);
        $dispatcher->expects('listen')
            ->with(Attempting::class, Argument::type('callable'));

        $guard->setDispatcher($dispatcher);
        $guard->attempting(function (): void {});
    }

    public function testAttemptSkipsEventDispatchWhenNoListenersAreRegistered(): void
    {
        $guard = $this->getGuard();
        $guard->setDispatcher($events = Double::for(DispatcherContract::class));
        $events->allows('hasListeners')->returns(false);
        $events->expects('dispatch')->never();

        $timebox = $guard->getTimebox();
        $timebox->expects('call')->resolves(function (Closure $callback, int $microseconds) use ($timebox): bool {
            return $callback($timebox);
        });

        $guard->getProvider()->expects('retrieveByCredentials')->with(['foo'])->returns(null);
        $guard->getProvider()->expects('rehashPasswordIfRequired')->never();

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
        $provider = Double::for(UserProvider::class);
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
        $session = Double::for(Session::class);
        $session->allows('isStarted')->returns(true);
        $session->allows('getId')->returns('test-session-id');

        $app = new Container;
        $app->instance('request', Request::create('/', 'GET'));

        return [
            $session,
            Double::for(UserProvider::class),
            Request::create('/', 'GET'),
            Double::for(CookieJar::class),
            Double::for(Timebox::class),
            $app,
        ];
    }

    /**
     * Create an event dispatcher with listeners enabled.
     */
    protected function mockEventDispatcher(): DispatcherContract
    {
        $events = Double::for(DispatcherContract::class);
        $events->allows('hasListeners')->returns(true);

        return $events;
    }
}
