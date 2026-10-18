<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures\Workbench\Providers;

use Hypervel\Mcp\Facades\Mcp;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\Mcp\Fixtures\ExampleServer;
use Hypervel\Tests\Mcp\Fixtures\ServerWithDynamicTools;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadAiRoutes();
    }

    /**
     * Register the test servers.
     */
    public function loadAiRoutes(): void
    {
        // Used in tests
        Mcp::local('test-mcp', ExampleServer::class);
        Mcp::web('test-mcp', ExampleServer::class);
        Mcp::web('test-mcp-dynamic-tools', ServerWithDynamicTools::class);
    }
}
