<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session\Middleware;

use Hypervel\Auth\SessionGuard;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Http\Request;
use Hypervel\Session\ArraySessionHandler;
use Hypervel\Session\Middleware\AuthenticateSession;
use Hypervel\Session\Store;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class AuthenticateSessionTest extends TestCase
{
    use VerifiesDoubles;

    public function testGuardOverrideCanReturnAConcreteGuard(): void
    {
        $user = Double::for(Authenticatable::class);
        $user->allows('getAuthPassword')->returns('password-hash');

        $request = new Request;
        $request->setUserResolver(fn (): Authenticatable => $user);
        $session = new Store('name', new ArraySessionHandler(1));
        $request->setHypervelSession($session);

        $guard = Double::for(SessionGuard::class);
        $guard->expects('viaRemember')->returns(false);
        $guard->expects('hashPasswordForCookie')->times(2)->with('password-hash')->returns('password-mac');
        $guard->expects('user')->returns(null);

        $authFactory = Double::for(AuthFactory::class);
        $authFactory->allows('guard')->returns($guard);
        $authFactory->allows('getDefaultDriver')->returns('web');

        $middleware = new class($authFactory) extends AuthenticateSession {
            /**
             * Get the guard instance that should be used by the middleware.
             */
            protected function guard(): Guard
            {
                return $this->auth->guard();
            }
        };

        $this->assertSame('next', $middleware->handle($request, fn (): string => 'next'));
        $this->assertSame('password-mac', $session->get('password_hash_web'));
    }
}
