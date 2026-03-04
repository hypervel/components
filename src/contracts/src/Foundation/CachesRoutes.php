<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Foundation;

interface CachesRoutes
{
    /**
     * Determine if the application routes are cached.
     */
    public function routesAreCached(): bool;

    /**
     * Get the path to the routes cache file.
     */
    public function getCachedRoutesPath(): string;
}
