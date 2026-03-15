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
        $config = Bootstrapper::getConfig()['workbench']['discovers'] ?? [];

        if ($config['web'] ?? false) {
            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        }

        if ($config['api'] ?? false) {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        }

        if ($config['commands'] ?? false) {
            require base_path('routes/console.php');
        }
    }
}
