<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

class RouteListCommandMiddlewareTest extends TestCase
{
    #[DataProvider('middlewareCacheStates')]
    public function testListingPreservesMiddlewareForSubsequentRequests(bool $warm): void
    {
        $router = $this->app->make(Router::class);
        $router->middlewareGroup('inspection', [RouteListCommandInspectionMiddleware::class]);
        $route = $router->get('/middleware-inspection', static fn (): string => 'OK')
            ->middleware('inspection');

        if ($warm) {
            $this->get('/middleware-inspection')->assertOk()->assertHeader('X-Route-Middleware', 'applied');
        }

        $groups = $router->getMiddlewareGroups();
        $resolvedMiddleware = $route->resolvedMiddleware;
        $pipeline = $route->middlewarePipeline;

        Artisan::call('route:list', ['--json' => true, '-v' => true, '--path' => 'middleware-inspection']);
        $routes = json_decode(Artisan::output(), true);

        $this->assertSame(['inspection'], $routes[0]['middleware']);
        $this->assertSame($groups, $router->getMiddlewareGroups());
        $this->assertSame($resolvedMiddleware, $route->resolvedMiddleware);
        $this->assertSame($pipeline, $route->middlewarePipeline);

        Artisan::call('route:list', ['--json' => true, '-vv' => true, '--path' => 'middleware-inspection']);
        $routes = json_decode(Artisan::output(), true);

        $this->assertSame([RouteListCommandInspectionMiddleware::class], $routes[0]['middleware']);
        $this->get('/middleware-inspection')->assertOk()->assertHeader('X-Route-Middleware', 'applied');
        $this->assertSame([RouteListCommandInspectionMiddleware::class], $route->resolvedMiddleware);
    }

    /**
     * Provide cold and previously dispatched routes.
     */
    public static function middlewareCacheStates(): array
    {
        return [
            'cold' => [false],
            'warm' => [true],
        ];
    }
}

class RouteListCommandInspectionMiddleware
{
    /**
     * Mark responses that pass through the route middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Route-Middleware', 'applied');

        return $response;
    }
}
