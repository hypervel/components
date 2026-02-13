<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Providers;

use Hypervel\Contracts\Validation\ValidatesWhenResolved;
use Hypervel\Http\RouteDependency;
use Hypervel\Support\ServiceProvider;

class FormRequestServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->app->make(RouteDependency::class)
            ->afterResolving(ValidatesWhenResolved::class, function (ValidatesWhenResolved $request) {
                $request->validateResolved();
            });
    }
}
