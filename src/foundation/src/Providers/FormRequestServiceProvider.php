<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Providers;

use Hypervel\Contracts\Validation\ValidatesWhenResolved;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Routing\Redirector;
use Hypervel\Support\ServiceProvider;

class FormRequestServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->app->afterResolving(ValidatesWhenResolved::class, function (ValidatesWhenResolved $resolved) {
            $resolved->validateResolved();
        });

        $this->app->resolving(FormRequest::class, function (FormRequest $request, $app) {
            $request = FormRequest::createFrom($app['request'], $request);

            $request->setContainer($app)->setRedirector($app->make(Redirector::class));
        });
    }
}
