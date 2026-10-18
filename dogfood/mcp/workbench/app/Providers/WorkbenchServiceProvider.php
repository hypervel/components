<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Hypervel\Dogfood\Mcp\Conformance\ConformanceServer;
use Hypervel\Mcp\Facades\Mcp;
use Hypervel\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register the local conformance endpoint.
     */
    public function boot(): void
    {
        Mcp::web('/conformance', ConformanceServer::class);
    }
}
