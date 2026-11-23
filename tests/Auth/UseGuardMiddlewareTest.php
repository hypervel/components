<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\AuthManager;
use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Auth\Middleware\UseGuard;
use Hypervel\Auth\RequestGuard;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\HttpFoundation\Response;

use function Hypervel\Coroutine\parallel;

class UseGuardMiddlewareTest extends TestCase
{
    use VerifiesDoubles;

    public function testItSelectsTheGuardForTheCurrentRequest(): void
    {
        $auth = Double::for(AuthFactory::class)->strict();
        $auth->expects('shouldUse')->with('admin');

        $request = Request::create('/login');
        $response = new Response('ok');

        $result = (new UseGuard($auth))->handle($request, fn (Request $nextRequest): Response => $response, 'admin');

        $this->assertSame($response, $result);
    }

    public function testSelectedGuardIsCoroutineIsolated(): void
    {
        $auth = $this->makeAuthManager();

        [$adminGuard, $memberGuard] = parallel([
            function () use ($auth): string {
                (new UseGuard($auth))->handle(Request::create('/admin'), function () use ($auth): Response {
                    usleep(5000);

                    return new Response($auth->getDefaultDriver());
                }, 'admin');

                return $auth->getDefaultDriver();
            },
            function () use ($auth): string {
                (new UseGuard($auth))->handle(Request::create('/member'), function () use ($auth): Response {
                    usleep(5000);

                    return new Response($auth->getDefaultDriver());
                }, 'member');

                return $auth->getDefaultDriver();
            },
        ]);

        $this->assertSame('admin', $adminGuard);
        $this->assertSame('member', $memberGuard);
    }

    /**
     * Create an auth manager with test guards.
     */
    private function makeAuthManager(): AuthManager
    {
        $container = new Container;
        $container->instance('config', new Repository([
            'auth' => [
                'defaults' => ['guard' => 'web'],
                'guards' => [
                    'web' => ['driver' => 'web'],
                    'admin' => ['driver' => 'admin'],
                    'member' => ['driver' => 'member'],
                ],
            ],
        ]));

        $auth = new AuthManager($container);

        foreach (['web', 'admin', 'member'] as $guard) {
            $auth->extend($guard, fn (): RequestGuard => new RequestGuard(
                $guard,
                static fn (): ?Authenticatable => null,
                $container,
                Double::for(EloquentUserProvider::class)->strict(),
            ));
        }

        return $auth;
    }
}
