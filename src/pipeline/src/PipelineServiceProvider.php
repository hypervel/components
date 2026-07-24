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

        $pipelineFactory = fn ($app) => new Pipeline($app);

        // Pipeline is a mutable per-operation builder, so the concrete must not
        // fall through to the container's worker-lifetime auto-singleton cache.
        $this->app->bind(Pipeline::class, $pipelineFactory);
        $this->app->bind('pipeline', $pipelineFactory);
    }
}
