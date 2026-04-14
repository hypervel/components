<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Ssr;

interface HasHealthCheck
{
    /**
     * Determine if the SSR server is healthy and responsive.
     */
    public function isHealthy(): bool;
}
