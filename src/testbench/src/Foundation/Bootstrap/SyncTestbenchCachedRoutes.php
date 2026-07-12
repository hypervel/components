<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Collection;

use function Hypervel\Filesystem\join_paths;

class SyncTestbenchCachedRoutes
{
    /**
     * Bootstrap the given application.
     */
    public function bootstrap(Application $app): void
    {
        /** @var \Hypervel\Routing\Router $router */
        $router = $app->make('router');
        $routeFiles = glob($app->basePath(join_paths('routes', 'testbench-*.php'))) ?: [];

        /* @phpstan-ignore argument.type */
        (new Collection($routeFiles))
            ->each(static function ($routeFile) use ($app, $router) { // @phpstan-ignore closure.unusedUse, closure.unusedUse
                require $routeFile;
            });
    }
}
