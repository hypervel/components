<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use Hypervel\Router\Router;

/**
 * Provides hooks for defining test routes.
 */
trait HandlesRoutes
{
    /**
     * Define routes setup.
     *
     * @param \Hypervel\Router\Router $router
     */
    protected function defineRoutes($router): void
    {
        // Define routes.
    }

    /**
     * Define web routes setup.
     *
     * @param \Hypervel\Router\Router $router
     */
    protected function defineWebRoutes($router): void
    {
        // Define web routes.
    }

    /**
     * Setup application routes.
     *
     * @param \Hypervel\Foundation\Contracts\Application $app
     */
    protected function setUpApplicationRoutes($app): void
    {
        $router = $app->get(Router::class);

        $this->defineRoutes($router);

        // Wrap web routes in 'web' middleware group using Hypervel's Router API
        $router->group('/', fn () => $this->defineWebRoutes($router), ['middleware' => ['web']]);
    }
}
