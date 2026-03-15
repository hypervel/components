<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Bootstrapper;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $config = Bootstrapper::getConfig()['workbench']['discover'] ?? [];

        if ($config['web'] ?? false) {
            Route::middleware('web')
                ->group(dirname(__DIR__, 2) . '/routes/web.php');
        }

        if ($config['api'] ?? false) {
            Route::middleware('api')
                ->prefix('api')
                ->group(dirname(__DIR__, 2) . '/routes/api.php');
        }

        if ($config['commands'] ?? false) {
            require dirname(__DIR__, 2) . '/routes/console.php';
        }
    }
}
