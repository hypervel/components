<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Closure;
use Hypervel\Events\Dispatcher;
use Hypervel\Foundation\Auth\Access\AuthorizesRequests;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Hypervel\Routing\Router;
use Hypervel\Tests\TestCase;

class AuthorizesResourcesTest extends TestCase
{
    public function testCreateMethod()
    {
        $controller = new AuthorizesResourcesController;

        $this->assertHasMiddleware($controller, 'create', 'can:create,App\User');

        $controller = new AuthorizesResourcesWithArrayController;

        $this->assertHasMiddleware($controller, 'create', 'can:create,App\User,App\Post');
    }

    public function testStoreMethod()
    {
        $controller = new AuthorizesResourcesController;

        $this->assertHasMiddleware($controller, 'store', 'can:create,App\User');

        $controller = new AuthorizesResourcesWithArrayController;

        $this->assertHasMiddleware($controller, 'store', 'can:create,App\User,App\Post');
    }

    public function testShowMethod()
    {
        $controller = new AuthorizesResourcesController;

        $this->assertHasMiddleware($controller, 'show', 'can:view,user');

        $controller = new AuthorizesResourcesWithArrayController;

        $this->assertHasMiddleware($controller, 'show', 'can:view,user,post');
    }

    public function testEditMethod()
    {
        $controller = new AuthorizesResourcesController;

        $this->assertHasMiddleware($controller, 'edit', 'can:update,user');

        $controller = new AuthorizesResourcesWithArrayController;

        $this->assertHasMiddleware($controller, 'edit', 'can:update,user,post');
    }

    public function testUpdateMethod()
    {
        $controller = new AuthorizesResourcesController;

        $this->assertHasMiddleware($controller, 'update', 'can:update,user');

        $controller = new AuthorizesResourcesWithArrayController;

        $this->assertHasMiddleware($controller, 'update', 'can:update,user,post');
    }

    public function testDestroyMethod()
    {
        $controller = new AuthorizesResourcesController;

        $this->assertHasMiddleware($controller, 'destroy', 'can:delete,user');

        $controller = new AuthorizesResourcesWithArrayController;

        $this->assertHasMiddleware($controller, 'destroy', 'can:delete,user,post');
    }

    /**
     * Assert that the given middleware has been registered on the given controller for the given method.
     */
    protected function assertHasMiddleware(Controller $controller, string $method, string $middleware): void
    {
        $router = new Router(new Dispatcher);

        $router->aliasMiddleware('can', AuthorizesResourcesMiddleware::class);
        $router->get($method)->uses(get_class($controller) . '@' . $method);

        $this->assertSame(
            'caught ' . $middleware,
            $router->dispatch(Request::create($method, 'GET'))->getContent(),
            "The [{$middleware}] middleware was not registered for method [{$method}]"
        );
    }
}

class AuthorizesResourcesController extends Controller
{
    use AuthorizesRequests;

    public function __construct()
    {
        $this->authorizeResource('App\User', 'user');
    }

    public function index()
    {
    }

    public function create()
    {
    }

    public function store()
    {
    }

    public function show()
    {
    }

    public function edit()
    {
    }

    public function update()
    {
    }

    public function destroy()
    {
    }
}

class AuthorizesResourcesWithArrayController extends Controller
{
    use AuthorizesRequests;

    public function __construct()
    {
        $this->authorizeResource(['App\User', 'App\Post'], ['user', 'post']);
    }

    public function index()
    {
    }

    public function create()
    {
    }

    public function store()
    {
    }

    public function show()
    {
    }

    public function edit()
    {
    }

    public function update()
    {
    }

    public function destroy()
    {
    }
}

class AuthorizesResourcesMiddleware
{
    public function handle($request, Closure $next, $method, $parameter, ...$models)
    {
        $params = array_merge([$parameter], $models);

        return "caught can:{$method}," . implode(',', $params);
    }
}
