<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Http\Kernel as HttpKernelContract;
use Hypervel\Foundation\Http\Kernel as HttpKernel;
use Hypervel\Mcp\Client\ClientManager;
use Hypervel\Mcp\Console\Commands\InspectorCommand;
use Hypervel\Mcp\Console\Commands\MakeAppResourceCommand;
use Hypervel\Mcp\Console\Commands\MakePromptCommand;
use Hypervel\Mcp\Console\Commands\MakeResourceCommand;
use Hypervel\Mcp\Console\Commands\MakeServerCommand;
use Hypervel\Mcp\Console\Commands\MakeToolCommand;
use Hypervel\Mcp\Console\Commands\StartCommand;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Server\Middleware\AddAuthorizationFramingHeaders;
use Hypervel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;

class McpServiceProvider extends ServiceProvider
{
    /**
     * Register MCP configuration and services.
     */
    public function register(): void
    {
        $this->app->singleton('mcp.sdk', fn (): string => (string) file_get_contents(__DIR__ . '/../../resources/js/mcp-sdk.min.js'));

        $this->app->bind('mcp.library_scripts', fn (): string => CoroutineContext::get(RequestContext::LIBRARY_SCRIPTS_CONTEXT_KEY, ''));

        $this->mergeConfigFrom(__DIR__ . '/../../config/mcp.php', 'mcp');
    }

    /**
     * Bootstrap MCP routes, request hydration and commands.
     */
    public function boot(): void
    {
        $this->registerMcpScope();
        $this->registerGlobalMiddleware();
        $this->registerRoutes();
        $this->registerContainerCallbacks();
        $this->registerClientDisconnect();
        $this->registerViews();

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->registerPublishing();
        }
    }

    /**
     * Register the authentication challenge and authorization framing middleware.
     */
    protected function registerGlobalMiddleware(): void
    {
        $this->callAfterResolving(HttpKernelContract::class, function (mixed $kernel): void {
            if ($kernel instanceof HttpKernel) {
                $kernel->pushMiddleware(AddWwwAuthenticateHeader::class);
                $kernel->pushMiddleware(AddAuthorizationFramingHeaders::class);
            }
        });
    }

    /**
     * Register publishable MCP routes, views, stubs and configuration.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../../routes/ai.php' => base_path('routes/ai.php'),
        ], 'ai-routes');

        $this->publishes([
            __DIR__ . '/../../resources/views/mcp/authorize.blade.php' => resource_path('views/mcp/authorize.blade.php'),
            __DIR__ . '/../../resources/views/mcp/components/app.blade.php' => resource_path('views/vendor/mcp/components/app.blade.php'),
        ], 'mcp-views');

        $this->publishes([
            __DIR__ . '/../../stubs/mcp-prompt.stub' => base_path('stubs/mcp-prompt.stub'),
            __DIR__ . '/../../stubs/mcp-resource.stub' => base_path('stubs/mcp-resource.stub'),
            __DIR__ . '/../../stubs/mcp-server.stub' => base_path('stubs/mcp-server.stub'),
            __DIR__ . '/../../stubs/mcp-tool.stub' => base_path('stubs/mcp-tool.stub'),
            __DIR__ . '/../../stubs/mcp-app-resource.stub' => base_path('stubs/mcp-app-resource.stub'),
            __DIR__ . '/../../stubs/mcp-app-resource.view.stub' => base_path('stubs/mcp-app-resource.view.stub'),
        ], 'mcp-stubs');

        $this->publishes([
            __DIR__ . '/../../config/mcp.php' => config_path('mcp.php'),
        ], 'mcp-config');
    }

    /**
     * Load MCP registrations from the application's AI routes file.
     */
    protected function registerRoutes(): void
    {
        $path = base_path('routes/ai.php');

        if (! file_exists($path)) {
            return;
        }

        // Local servers and client factories must load even when HTTP routes are cached.
        Route::group([], $path);
    }

    /**
     * Hydrate resolved requests from the current invocation.
     */
    protected function registerContainerCallbacks(): void
    {
        $this->app->resolving(Request::class, function (Request $request): void {
            $currentRequest = CoroutineContext::get(Request::class);

            if ($currentRequest instanceof Request) {
                $request->setArguments($currentRequest->all());
                $request->setMeta($currentRequest->meta());
                $request->setUri($currentRequest->uri());
                $request->setSource($currentRequest->source());
            }
        });
    }

    /**
     * Disconnect clients owned by the terminating invocation.
     */
    protected function registerClientDisconnect(): void
    {
        $this->app->terminating(function (): void {
            if ($this->app->resolved(ClientManager::class)) {
                $this->app->make(ClientManager::class)->disconnectAll();
            }
        });
    }

    /**
     * Register the MCP console commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            StartCommand::class,
            MakeServerCommand::class,
            MakeToolCommand::class,
            MakePromptCommand::class,
            MakeResourceCommand::class,
            MakeAppResourceCommand::class,
            InspectorCommand::class,
        ]);
    }

    /**
     * Register the MCP views.
     */
    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views/mcp', 'mcp');
    }

    /**
     * Register the MCP authorization scope after application boot.
     */
    protected function registerMcpScope(): void
    {
        $this->app->booted(function (): void {
            Registrar::ensureMcpScope();
        });
    }
}
