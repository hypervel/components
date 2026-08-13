<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\RequestProperties;

use Hypervel\Saloon\Http\MiddlewarePipeline;

trait HasMiddleware
{
    /**
     * The request middleware pipeline.
     */
    protected ?MiddlewarePipeline $middlewarePipeline = null;

    /**
     * Get the request middleware pipeline.
     */
    public function middleware(): MiddlewarePipeline
    {
        return $this->middlewarePipeline ??= new MiddlewarePipeline;
    }
}
