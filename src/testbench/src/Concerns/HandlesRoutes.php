<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Routing\Router;
use ReflectionMethod;

/**
 * Provides hooks for defining test routes.
 */
trait HandlesRoutes
{
    /**
     * Define routes setup.
     */
    protected function defineRoutes(Router $router): void
    {
        // Define routes.
    }

    /**
     * Define web routes setup.
     */
    protected function defineWebRoutes(Router $router): void
    {
        // Define web routes.
    }

    /**
     * Setup application routes.
     */
    protected function setUpApplicationRoutes(ApplicationContract $app): void
    {
        /** @var Router $router */
        $router = $app['router'];

        $this->defineRoutes($router);

        // Only set up web routes group if the method is overridden
        // This prevents empty group registration from interfering with other routes
        $refMethod = new ReflectionMethod($this, 'defineWebRoutes');
        if ($refMethod->getDeclaringClass()->getName() !== self::class) {
            $router->middleware('web')
                ->group(fn ($router) => $this->defineWebRoutes($router));
        }
    }
}
