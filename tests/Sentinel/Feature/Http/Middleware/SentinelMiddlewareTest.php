<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentinel\Feature\Http\Middleware;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Middleware\TrustProxies;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Hypervel\Sentinel\Drivers\Driver;
use Hypervel\Sentinel\Http\Middleware\SentinelMiddleware;
use Hypervel\Sentinel\SentinelManager;
use Hypervel\Testbench\TestCase;

class SentinelMiddlewareTest extends TestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        TrustProxies::at('*');

        $app->make(SentinelManager::class)->extend('testing', function (ApplicationContract $app): Driver {
            return new class(fn (): ApplicationContract => $app) extends Driver {
                /**
                 * Authorize access for the request.
                 */
                public function authorize(Request $request): bool
                {
                    return $this->authorizeAccessingViaReverseProxies($request);
                }
            };
        });
    }

    /**
     * Define the test routes.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->aliasMiddleware('sentinel', SentinelMiddleware::class);

        $router->get('debug', function (): string {
            return app()->version();
        })->middleware('sentinel:testing');
    }

    public function testItCanAuthorizeARequestUsingMiddleware(): void
    {
        $this->get('debug')
            ->assertSee(app()->version())
            ->assertOk();
    }

    public function testItCanAuthorizeARequestUsingMiddlewareAndPreventReverseProxyAccess(): void
    {
        $this->withHeaders([
            'REMOTE_ADDR' => '127.0.0.1',
            'HOST' => 'hypervel.ngrok.io',
            'X-FORWARDED-FOR' => '202.168.65.217',
            'X-FORWARDED-HOST' => 'hypervel.ngrok.io',
            'X-FORWARDED-PROTO' => 'https',
        ])->get('debug')
            ->assertUnauthorized();
    }
}
