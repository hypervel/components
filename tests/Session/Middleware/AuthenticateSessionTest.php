<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session\Middleware;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Auth\AuthManager;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Http\Request;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Session\Middleware\AuthenticateSession;
use Hypervel\Session\Store;
use Hypervel\Tests\TestCase;
use Mockery as m;

class AuthenticateSessionTest extends TestCase
{
    public function testHandleWithoutSession(): void
    {
        $request = new Request;
        $next = fn () => 'next-1';

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->never();

        $middleware = new AuthenticateSession($authFactory);
        $response = $middleware->handle($request, $next);
        $this->assertSame('next-1', $response);
    }

    public function testHandleWithSessionWithoutRequestUser(): void
    {
        $request = new Request;

        // set session:
        $request->setHypervelSession(new Store('name', new ArraySessionHandler(1)));

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->never();

        $next = fn () => 'next-2';
        $middleware = new AuthenticateSession($authFactory);
        $response = $middleware->handle($request, $next);
        $this->assertSame('next-2', $response);
    }

    public function testHandleWithSessionWithoutAuthPassword(): void
    {
        $user = new class {
            /**
             * Get the user's authentication password.
             */
            public function getAuthPassword(): ?string
            {
                return null;
            }
        };

        $request = new Request;

        // set session:
        $request->setHypervelSession(new Store('name', new ArraySessionHandler(1)));
        // set a password-less user:
        $request->setUserResolver(fn () => $user);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->never();

        $next = fn () => 'next-3';
        $middleware = new AuthenticateSession($authFactory);
        $response = $middleware->handle($request, $next);

        $this->assertSame('next-3', $response);
    }

    public function testHandleWithSessionWithUserAuthPasswordOnRequestViaRememberFalse(): void
    {
        $user = new class {
            /**
             * Get the user's authentication password.
             */
            public function getAuthPassword(): string
            {
                return 'my-pass-(*&^%$#!@';
            }
        };

        $request = new Request;
        $request->setUserResolver(fn () => $user);

        $session = new Store('name', new ArraySessionHandler(1));
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->once()->andReturn(false);
        $authFactory->shouldReceive('getDefaultDriver')->times(3)->andReturn('web');
        $authFactory->shouldReceive('user')->once()->andReturn(null);
        // expected MAC for current password when storing in session:
        $authFactory->shouldReceive('hashPasswordForCookie')->times(2)->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        $middleware = new AuthenticateSession($authFactory);
        $response = $middleware->handle($request, fn () => 'next-4');

        $this->assertSame('mac:my-pass-(*&^%$#!@', $session->get('password_hash_web'));
        $this->assertSame('next-4', $response);
    }

    public function testHandleWithInvalidPasswordHash(): void
    {
        $user = new class {
            /**
             * Get the user's authentication password.
             */
            public function getAuthPassword(): string
            {
                return 'my-pass-(*&^%$#!@';
            }
        };

        $request = new Request(cookies: ['recaller-name' => 'a|b|invalid-mac']);
        $request->setUserResolver(fn () => $user);

        $session = new Store('name', new ArraySessionHandler(1));
        $session->put('a', '1');
        $session->put('b', '2');
        // set session:
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->once()->andReturn(true);
        $authFactory->shouldReceive('getRecallerName')->once()->andReturn('recaller-name');
        $authFactory->shouldReceive('logoutCurrentDevice')->once()->andReturn(null);
        $authFactory->shouldReceive('getDefaultDriver')->once()->andReturn('web');
        // expected MAC for current password (won't match cookie):
        $authFactory->shouldReceive('hashPasswordForCookie')->once()->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        $this->assertNotNull($session->get('a'));
        $this->assertNotNull($session->get('b'));
        AuthenticateSession::redirectUsing(fn ($request) => 'i-wanna-go-home');

        // act:
        $middleware = new AuthenticateSession($authFactory);

        $message = '';
        try {
            $middleware->handle($request, fn () => 'next-7');
        } catch (AuthenticationException $e) {
            $message = $e->getMessage();
            $this->assertSame('i-wanna-go-home', $e->redirectTo($request));
        }
        $this->assertSame('Unauthenticated.', $message);

        // ensure session is flushed:
        $this->assertNull($session->get('a'));
        $this->assertNull($session->get('b'));
    }

    public function testAuthManagerRedirectGuestsToConfiguresSessionMismatchRedirect(): void
    {
        $manager = new AuthManager(new Container);
        $manager->redirectGuestsTo('/login');

        // Isolate AuthenticateSession's own slot so this test fails if the
        // aggregate stops configuring session-mismatch redirects directly.
        AuthenticationException::flushState();

        $user = new class {
            public function getAuthPassword(): string
            {
                return 'my-pass-(*&^%$#!@';
            }
        };

        $request = new Request(cookies: ['recaller-name' => 'a|b|invalid-mac']);
        $request->setUserResolver(fn () => $user);

        $session = new Store('name', new ArraySessionHandler(1));
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->andReturn(true);
        $authFactory->shouldReceive('getRecallerName')->once()->andReturn('recaller-name');
        $authFactory->shouldReceive('logoutCurrentDevice')->once()->andReturn(null);
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
        $authFactory->shouldReceive('hashPasswordForCookie')->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        try {
            (new AuthenticateSession($authFactory))->handle($request, fn () => 'next');
        } catch (AuthenticationException $exception) {
            $this->assertSame('/login', $exception->redirectTo($request));

            return;
        }

        $this->fail('AuthenticationException was not thrown.');
    }

    public function testHandleWithInvalidIncookiePasswordHashViaRememberTrue(): void
    {
        $user = new class {
            /**
             * Get the user's authentication password.
             */
            public function getAuthPassword(): string
            {
                return 'my-pass-(*&^%$#!@';
            }
        };

        $request = new Request(cookies: ['recaller-name' => 'a|b|invalid-mac']);
        $request->setUserResolver(fn () => $user);

        $session = new Store('name', new ArraySessionHandler(1));
        $session->put('a', '1');
        $session->put('b', '2');
        // set session:
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->once()->andReturn(true);
        $authFactory->shouldReceive('getRecallerName')->once()->andReturn('recaller-name');
        $authFactory->shouldReceive('logoutCurrentDevice')->once();
        $authFactory->shouldReceive('getDefaultDriver')->once()->andReturn('web');
        // expected MAC for current password (won't match cookie):
        $authFactory->shouldReceive('hashPasswordForCookie')->once()->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        $middleware = new AuthenticateSession($authFactory);
        // act:
        try {
            $message = '';
            $middleware->handle($request, fn () => 'next-6');
        } catch (AuthenticationException $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('Unauthenticated.', $message);

        // ensure session is flushed
        $this->assertNull($session->get('password_hash_web'));
        $this->assertNull($session->get('a'));
        $this->assertNull($session->get('b'));
    }

    public function testHandleWithValidIncookieInvalidInsessionHashViaRememberTrue(): void
    {
        $user = new class {
            /**
             * Get the user's authentication password.
             */
            public function getAuthPassword(): string
            {
                return 'my-pass-(*&^%$#!@';
            }
        };

        $request = new Request(cookies: ['recaller-name' => 'a|b|mac:my-pass-(*&^%$#!@']);
        $request->setUserResolver(fn () => $user);

        $session = new Store('name', new ArraySessionHandler(1));
        $session->put('a', '1');
        $session->put('b', '2');
        $session->put('password_hash_web', 'invalid-password');
        // set session on the request:
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->once()->andReturn(true);
        $authFactory->shouldReceive('getRecallerName')->once()->andReturn('recaller-name');
        $authFactory->shouldReceive('logoutCurrentDevice')->once()->andReturn(null);
        $authFactory->shouldReceive('getDefaultDriver')->times(3)->andReturn('web');
        // expected MAC for current password (matches cookie but not session):
        $authFactory->shouldReceive('hashPasswordForCookie')->times(2)->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        // act:
        $middleware = new AuthenticateSession($authFactory);
        try {
            $message = '';
            $middleware->handle($request, fn () => 'next-7');
        } catch (AuthenticationException $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('Unauthenticated.', $message);

        // ensure session is flushed:
        $this->assertNull($session->get('password_hash_web'));
        $this->assertNull($session->get('a'));
        $this->assertNull($session->get('b'));
    }

    public function testHandleWithValidPasswordInSessionCookieIsEmptyGuardHasUser(): void
    {
        $user = new class {
            /**
             * Get the user's authentication password.
             */
            public function getAuthPassword(): string
            {
                return 'my-pass-(*&^%$#!@';
            }
        };

        $request = new Request(cookies: ['recaller-name' => 'a|b']);
        $request->setUserResolver(fn () => $user);

        $session = new Store('name', new ArraySessionHandler(1));
        $session->put('a', '1');
        $session->put('b', '2');
        $session->put('password_hash_web', 'mac:my-pass-(*&^%$#!@');
        // set session on the request:
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->once()->andReturn(false);
        $authFactory->shouldReceive('getRecallerName')->never();
        $authFactory->shouldReceive('logoutCurrentDevice')->never();
        $authFactory->shouldReceive('getDefaultDriver')->times(3)->andReturn('web');
        $authFactory->shouldReceive('user')->once()->andReturn($user);
        // expected MAC for current password:
        $authFactory->shouldReceive('hashPasswordForCookie')->times(2)->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        // act:
        $middleware = new AuthenticateSession($authFactory);
        $response = $middleware->handle($request, fn () => 'next-8');

        $this->assertSame('next-8', $response);
        // ensure session is not flushed:
        $this->assertSame('mac:my-pass-(*&^%$#!@', $session->get('password_hash_web'));
        $this->assertSame('1', $session->get('a'));
        $this->assertSame('2', $session->get('b'));
    }

    public function testGuardOverrideCanReturnAConcreteGuard(): void
    {
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthPassword')->andReturn('password-hash');

        $request = new Request;
        $request->setUserResolver(fn () => $user);
        $session = new Store('name', new ArraySessionHandler(1));
        $request->setHypervelSession($session);

        $guard = m::mock(StatefulGuard::class);
        $guard->shouldReceive('viaRemember')->once()->andReturn(false);
        $guard->shouldReceive('hashPasswordForCookie')->twice()->with('password-hash')->andReturn('password-mac');
        $guard->shouldReceive('user')->once()->andReturn(null);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('guard')->andReturn($guard);
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');

        $middleware = new class($authFactory) extends AuthenticateSession {
            /**
             * Get the guard instance that should be used by the middleware.
             */
            protected function guard(): Guard
            {
                return $this->auth->guard();
            }
        };

        $this->assertSame('next', $middleware->handle($request, fn () => 'next'));
        $this->assertSame('password-mac', $session->get('password_hash_web'));
    }

    // REMOVED: Laravel's OldFormatCookie* backward-compatibility tests,
    // including guards without hashPasswordForCookie(); only HMAC artifacts are supported.
    public function testHandleWithRawRememberCookiePasswordHashLogsOut(): void
    {
        $user = new class {
            public function getAuthPassword()
            {
                return 'my-pass-(*&^%$#!@';
            }
        };

        $request = new Request(cookies: ['recaller-name' => 'a|b|my-pass-(*&^%$#!@']);
        $request->setUserResolver(fn () => $user);

        $session = new Store('name', new ArraySessionHandler(1));
        $session->put('a', '1');
        $session->put('b', '2');
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->andReturn(true);
        $authFactory->shouldReceive('getRecallerName')->once()->andReturn('recaller-name');
        $authFactory->shouldReceive('logoutCurrentDevice')->once()->andReturn(null);
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
        $authFactory->shouldReceive('user')->andReturn(null);
        $authFactory->shouldReceive('hashPasswordForCookie')->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        $middleware = new AuthenticateSession($authFactory);

        $message = '';
        try {
            $middleware->handle($request, fn () => 'next-9');
        } catch (AuthenticationException $e) {
            $message = $e->getMessage();
        }

        $this->assertEquals('Unauthenticated.', $message);
        $this->assertNull($session->get('a'));
        $this->assertNull($session->get('b'));
    }

    public function testHandleWithRawSessionPasswordHashLogsOut(): void
    {
        $user = new class {
            public function getAuthPassword()
            {
                return 'my-pass-(*&^%$#!@';
            }
        };

        $request = new Request;
        $request->setUserResolver(fn () => $user);

        $session = new Store('name', new ArraySessionHandler(1));
        $session->put('a', '1');
        $session->put('b', '2');
        $session->put('password_hash_web', 'my-pass-(*&^%$#!@');
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->andReturn(false);
        $authFactory->shouldReceive('getRecallerName')->never();
        $authFactory->shouldReceive('logoutCurrentDevice')->once()->andReturn(null);
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
        $authFactory->shouldReceive('user')->andReturn(null);
        $authFactory->shouldReceive('hashPasswordForCookie')->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        $middleware = new AuthenticateSession($authFactory);

        $message = '';
        try {
            $middleware->handle($request, fn () => 'next-9');
        } catch (AuthenticationException $e) {
            $message = $e->getMessage();
        }

        $this->assertEquals('Unauthenticated.', $message);
        $this->assertNull($session->get('password_hash_web'));
        $this->assertNull($session->get('a'));
        $this->assertNull($session->get('b'));
    }

    public function testHandleWithMalformedSessionPasswordHashLogsOut(): void
    {
        $user = new class {
            public function getAuthPassword()
            {
                return 'my-pass-(*&^%$#!@';
            }
        };

        $request = new Request;
        $request->setUserResolver(fn () => $user);

        $session = new Store('name', new ArraySessionHandler(1));
        $session->put('a', '1');
        $session->put('b', '2');
        $session->put('password_hash_web', ['not-a-password-hash']);
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->andReturn(false);
        $authFactory->shouldReceive('getRecallerName')->never();
        $authFactory->shouldReceive('logoutCurrentDevice')->once()->andReturn(null);
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
        $authFactory->shouldReceive('user')->andReturn(null);
        $authFactory->shouldReceive('hashPasswordForCookie')->never();

        $middleware = new AuthenticateSession($authFactory);

        $message = '';
        try {
            $middleware->handle($request, fn () => 'next-10');
        } catch (AuthenticationException $e) {
            $message = $e->getMessage();
        }

        $this->assertEquals('Unauthenticated.', $message);
        $this->assertNull($session->get('password_hash_web'));
        $this->assertNull($session->get('a'));
        $this->assertNull($session->get('b'));
    }
}
