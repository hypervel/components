<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures\Providers;

use Hypervel\Support\AggregateServiceProvider;

class ParentServiceProvider extends AggregateServiceProvider
{
    protected array $providers = [
        ChildServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app['parent.loaded'] = true;
    }
}
