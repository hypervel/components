<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Providers;

use Hypervel\Http\RouteDependency;
use Hypervel\Support\ServiceProvider;
use Hypervel\Validation\Contracts\ValidatesWhenResolved;

class FormRequestServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->app->get(RouteDependency::class)
            ->afterResolving(ValidatesWhenResolved::class, function (ValidatesWhenResolved $request) {
                $request->validateResolved();
            });
    }
}
