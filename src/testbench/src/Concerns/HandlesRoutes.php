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

        // Only set up web routes group if the method is overridden
        // This prevents empty group registration from interfering with other routes
        $refMethod = new \ReflectionMethod($this, 'defineWebRoutes');
        if ($refMethod->getDeclaringClass()->getName() !== self::class) {
            $router->group('/', fn () => $this->defineWebRoutes($router), ['middleware' => ['web']]);
        }
    }
}
