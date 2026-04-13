<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session\Middleware;

use BadMethodCallException;
use Hypervel\Auth\AuthenticationException;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Http\Request;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Session\Middleware\AuthenticateSession;
use Hypervel\Session\Store;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class AuthenticateSessionTest extends TestCase
{
    public function testHandleWithoutSession()
    {
        $request = new Request;
        $next = fn () => 'next-1';

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->never();

        $middleware = new AuthenticateSession($authFactory);
        $response = $middleware->handle($request, $next);
        $this->assertEquals('next-1', $response);
    }

    public function testHandleWithSessionWithoutRequestUser()
    {
        $request = new Request;

        // set session:
        $request->setHypervelSession(new Store('name', new ArraySessionHandler(1)));

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->never();

        $next = fn () => 'next-2';
        $middleware = new AuthenticateSession($authFactory);
        $response = $middleware->handle($request, $next);
        $this->assertEquals('next-2', $response);
    }

    public function testHandleWithSessionWithoutAuthPassword()
    {
        $user = new class {
            public function getAuthPassword()
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

        $this->assertEquals('next-3', $response);
    }

    public function testHandleWithSessionWithUserAuthPasswordOnRequestViaRememberFalse()
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
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->andReturn(false);
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
        $authFactory->shouldReceive('user')->andReturn(null);
        // expected MAC for current password when storing in session:
        $authFactory->shouldReceive('hashPasswordForCookie')->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        $middleware = new AuthenticateSession($authFactory);
        $response = $middleware->handle($request, fn () => 'next-4');

        $this->assertEquals('mac:my-pass-(*&^%$#!@', $session->get('password_hash_web'));
        $this->assertEquals('next-4', $response);
    }

    public function testHandleWithInvalidPasswordHash()
    {
        $user = new class {
            public function getAuthPassword()
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
        $authFactory->shouldReceive('viaRemember')->andReturn(true);
        $authFactory->shouldReceive('getRecallerName')->once()->andReturn('recaller-name');
        $authFactory->shouldReceive('logoutCurrentDevice')->once()->andReturn(null);
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
        $authFactory->shouldReceive('user')->andReturn(null);
        // expected MAC for current password (won't match cookie):
        $authFactory->shouldReceive('hashPasswordForCookie')->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

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
            $this->assertEquals('i-wanna-go-home', $e->redirectTo($request));
        }
        $this->assertEquals('Unauthenticated.', $message);

        // ensure session is flushed:
        $this->assertNull($session->get('a'));
        $this->assertNull($session->get('b'));
    }

    public function testHandleWithInvalidIncookiePasswordHashViaRememberTrue()
    {
        $user = new class {
            public function getAuthPassword()
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
        $authFactory->shouldReceive('viaRemember')->andReturn(true);
        $authFactory->shouldReceive('getRecallerName')->once()->andReturn('recaller-name');
        $authFactory->shouldReceive('logoutCurrentDevice')->once();
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
        $authFactory->shouldReceive('user')->andReturn(null);
        // expected MAC for current password (won't match cookie):
        $authFactory->shouldReceive('hashPasswordForCookie')->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        $middleware = new AuthenticateSession($authFactory);
        // act:
        try {
            $message = '';
            $middleware->handle($request, fn () => 'next-6');
        } catch (AuthenticationException $e) {
            $message = $e->getMessage();
        }
        $this->assertEquals('Unauthenticated.', $message);

        // ensure session is flushed
        $this->assertNull($session->get('password_hash_web'));
        $this->assertNull($session->get('a'));
        $this->assertNull($session->get('b'));
    }

    public function testHandleWithValidIncookieInvalidInsessionHashViaRememberTrue()
    {
        $user = new class {
            public function getAuthPassword()
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
        $authFactory->shouldReceive('viaRemember')->andReturn(true);
        $authFactory->shouldReceive('getRecallerName')->once()->andReturn('recaller-name');
        $authFactory->shouldReceive('logoutCurrentDevice')->once()->andReturn(null);
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
        $authFactory->shouldReceive('user')->andReturn(null);
        // expected MAC for current password (matches cookie but not session):
        $authFactory->shouldReceive('hashPasswordForCookie')->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        // act:
        $middleware = new AuthenticateSession($authFactory);
        try {
            $message = '';
            $middleware->handle($request, fn () => 'next-7');
        } catch (AuthenticationException $e) {
            $message = $e->getMessage();
        }
        $this->assertEquals('Unauthenticated.', $message);

        // ensure session is flushed:
        $this->assertNull($session->get('password_hash_web'));
        $this->assertNull($session->get('a'));
        $this->assertNull($session->get('b'));
    }

    public function testHandleWithValidPasswordInSessionCookieIsEmptyGuardHasUser()
    {
        $user = new class {
            public function getAuthPassword()
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
        $authFactory->shouldReceive('viaRemember')->andReturn(false);
        $authFactory->shouldReceive('getRecallerName')->never();
        $authFactory->shouldReceive('logoutCurrentDevice')->never();
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
        $authFactory->shouldReceive('user')->andReturn($user);
        // expected MAC for current password:
        $authFactory->shouldReceive('hashPasswordForCookie')->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        // act:
        $middleware = new AuthenticateSession($authFactory);
        $response = $middleware->handle($request, fn () => 'next-8');

        $this->assertEquals('next-8', $response);
        // ensure session is not flushed:
        $this->assertEquals('mac:my-pass-(*&^%$#!@', $session->get('password_hash_web'));
        $this->assertEquals('1', $session->get('a'));
        $this->assertEquals('2', $session->get('b'));
    }

    public function testHandleWithOldFormatCookieForBackwardCompatibility()
    {
        $user = new class {
            public function getAuthPassword()
            {
                return 'my-pass-(*&^%$#!@';
            }
        };

        // Cookie contains OLD format (raw password hash, not HMAC)
        $request = new Request(cookies: ['recaller-name' => 'a|b|my-pass-(*&^%$#!@']);
        $request->setUserResolver(fn () => $user);

        $session = new Store('name', new ArraySessionHandler(1));
        $session->put('a', '1');
        $session->put('b', '2');
        // Session also contains old format for this test
        $session->put('password_hash_web', 'my-pass-(*&^%$#!@');
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->andReturn(true);
        $authFactory->shouldReceive('getRecallerName')->once()->andReturn('recaller-name');
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
        $authFactory->shouldReceive('user')->andReturn($user);
        // The HMAC won't match the old format, but fallback to raw hash should work
        $authFactory->shouldReceive('hashPasswordForCookie')->with('my-pass-(*&^%$#!@')->andReturn('mac:my-pass-(*&^%$#!@');

        $middleware = new AuthenticateSession($authFactory);
        $response = $middleware->handle($request, fn () => 'next-9');

        // Should succeed because of backward compatibility fallback
        $this->assertEquals('next-9', $response);
        // Session should be updated to new format (HMAC)
        $this->assertEquals('mac:my-pass-(*&^%$#!@', $session->get('password_hash_web'));
        $this->assertEquals('1', $session->get('a'));
        $this->assertEquals('2', $session->get('b'));
    }

    public function testHandleWithOldFormatCookieAndLegacyGuard()
    {
        $user = new class {
            public function getAuthPassword()
            {
                return 'my-pass-(*&^%$#!@';
            }
        };

        // Cookie contains OLD format (raw password hash, not HMAC)
        $request = new Request(cookies: ['recaller-name' => 'a|b|my-pass-(*&^%$#!@']);
        $request->setUserResolver(fn () => $user);

        $session = new Store('name', new ArraySessionHandler(1));
        $session->put('a', '1');
        $session->put('b', '2');
        // Session also contains old format for this test
        $session->put('password_hash_web', 'my-pass-(*&^%$#!@');
        $request->setHypervelSession($session);

        $authFactory = m::mock(AuthFactory::class);
        $authFactory->shouldReceive('viaRemember')->andReturn(true);
        $authFactory->shouldReceive('getRecallerName')->once()->andReturn('recaller-name');
        $authFactory->shouldReceive('getDefaultDriver')->andReturn('web');
        $authFactory->shouldReceive('user')->andReturn($user);
        // For legacy guards without hashPasswordForCookie method, we use fallback to raw hash
        $authFactory->shouldReceive('hashPasswordForCookie')->andThrowExceptions([new BadMethodCallException]);

        $middleware = new AuthenticateSession($authFactory);
        $response = $middleware->handle($request, fn () => 'next-9');

        // Should succeed because of backward compatibility fallback
        $this->assertEquals('next-9', $response);
        // Session should stay intact
        $this->assertEquals('my-pass-(*&^%$#!@', $session->get('password_hash_web'));
        $this->assertEquals('1', $session->get('a'));
        $this->assertEquals('2', $session->get('b'));
    }
}
