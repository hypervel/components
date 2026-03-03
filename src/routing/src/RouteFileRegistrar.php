<?php

declare(strict_types=1);

namespace Hypervel\Routing;

class RouteFileRegistrar
{
    /**
     * Create a new route file registrar instance.
     */
    public function __construct(
        protected Router $router,
    ) {
    }

    /**
     * Require the given routes file.
     */
    public function register(string $routes): void
    {
        $router = $this->router;

        require $routes;
    }
}
