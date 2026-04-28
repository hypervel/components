<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures\Providers;

use Hypervel\Support\ServiceProvider;

class ChildServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['child.loaded'] = true;
    }
}
