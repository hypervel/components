<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures\Providers;

use Hypervel\Contracts\Support\DeferrableProvider;
use Hypervel\Support\ServiceProvider;

class DeferredChildServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app['child.deferred.loaded'] = true;
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['child.deferred.loaded'];
    }
}
