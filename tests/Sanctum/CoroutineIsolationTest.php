<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Auth\GenericUser;
use Hypervel\Auth\SessionGuard;
use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Factory;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Session\Session;
use Hypervel\Http\Request;
use Hypervel\Sanctum\SanctumGuard;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    use VerifiesDoubles;

    public function testSharedRequestDoesNotShareResolvedSessionUsers(): void
    {
        $container = new Container;
        $provider = Double::for(UserProvider::class)->strict();
        $sessionGuard = new SessionGuard('web', $provider, Double::for(Session::class)->strict(), $container);
        $auth = Double::for(Factory::class)->strict();
        $auth->allows('guard')->with('web')->returns($sessionGuard);
        $container->instance('auth', $auth);
        $container->bind('request', static fn (): Request => RequestContext::get());
        $guard = new SanctumGuard('sanctum', $provider, $container, ['web']);
        $request = Request::create('/');
        RequestContext::set($request);
        $parent = new GenericUser(['id' => 'parent']);
        $sessionGuard->setUser($parent);

        $results = parallel([
            function () use ($sessionGuard, $guard, $request): mixed {
                $this->assertSame($request, RequestContext::get());
                $sessionGuard->setUser(new GenericUser(['id' => 'first']));
                $user = $guard->user();
                usleep(5000);
                $this->assertSame($user, $guard->user());

                return $guard->id();
            },
            function () use ($sessionGuard, $guard, $request): mixed {
                $this->assertSame($request, RequestContext::get());
                $sessionGuard->setUser(new GenericUser(['id' => 'second']));
                $user = $guard->user();
                usleep(5000);
                $this->assertSame($user, $guard->user());

                return $guard->id();
            },
        ], copyContext: true);

        $this->assertSame(['first', 'second'], $results);
        $this->assertSame($parent, $sessionGuard->user());
        $this->assertSame($parent, $guard->user());
    }
}
