<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Hypervel\Router\RouteFileCollector;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Bootstrapper;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $config = Bootstrapper::getConfig()['workbench']['discover'] ?? [];

        if ($config['web'] ?? false) {
            $this->app->get(RouteFileCollector::class)
                ->addRouteFile(dirname(__DIR__, 2) . '/routes/web.php');
        }

        if ($config['api'] ?? false) {
            $this->app->get(RouteFileCollector::class)
                ->addRouteFile(dirname(__DIR__, 2) . '/routes/api.php');
        }

        if ($config['commands'] ?? false) {
            require dirname(__DIR__, 2) . '/routes/console.php';
        }
    }
}
