<?php

declare(strict_types=1);

namespace Hypervel\Pipeline;

use Hypervel\Contracts\Pipeline\Hub as PipelineHubContract;
use Hypervel\Support\ServiceProvider;

class PipelineServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(PipelineHubContract::class, Hub::class);

        $this->app->bind('pipeline', fn ($app) => new Pipeline($app));
    }
}
