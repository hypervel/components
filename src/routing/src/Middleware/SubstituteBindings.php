<?php

declare(strict_types=1);

namespace Hypervel\Routing\Middleware;

use Closure;
use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubstituteBindings
{
    /**
     * The router instance.
     */
    protected Registrar $router;

    /**
     * Create a new bindings substitutor.
     */
    public function __construct(Registrar $router)
    {
        $this->router = $router;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        try {
            $this->router->substituteBindings($route);
            $this->router->substituteImplicitBindings($route);
        } catch (ModelNotFoundException $exception) {
            if ($route->getMissing()) {
                return $route->getMissing()($request, $exception);
            }

            throw $exception;
        }

        return $next($request);
    }
}
