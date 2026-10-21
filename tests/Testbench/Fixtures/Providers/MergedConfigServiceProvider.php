<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures\Providers;

use Hypervel\Support\ServiceProvider;

class MergedConfigServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../with-config/testbench.php', 'testbench');
    }
}
