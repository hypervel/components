<?php

declare(strict_types=1);

namespace Hypervel\Workbench;

use Composer\InstalledVersions;
use Hypervel\Contracts\Auth\Middleware\AuthenticatesRequests;
use Hypervel\Contracts\Http\Kernel as HttpKernel;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Support\ServiceProvider;
use Hypervel\Workbench\Http\Middleware\CatchDefaultRoute;

use function Hypervel\Filesystem\join_paths;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // REMOVED: The Composer, recipe manager and Canvas preset bindings serve the excluded build and generator commands.

        AboutCommand::add('Workbench', static fn (): array => array_filter([
            'Version' => InstalledVersions::isInstalled('hypervel/workbench')
                ? InstalledVersions::getPrettyVersion('hypervel/workbench')
                : null,
        ]));
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Testbench discovers the package's Workbench routes once the application has
        // booted. Loading after them lets a package fallback answer "/" before the
        // root fallback in this file.
        $this->app->booted(function (): void {
            $this->loadRoutesFrom((string) realpath(join_paths(__DIR__, '..', 'routes', 'workbench.php')));
        });

        // The session and the authenticated user are only available inside the web
        // group, so the middleware runs there and ahead of route authentication.
        $this->app->make(HttpKernel::class)
            ->appendMiddlewareToGroup('web', CatchDefaultRoute::class)
            ->addToMiddlewarePriorityBefore(AuthenticatesRequests::class, CatchDefaultRoute::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                // REMOVED: The build command is excluded.
                Console\CreateSqliteDbCommand::class,
                Console\DropSqliteDbCommand::class,
                // REMOVED: The install and devtool commands are excluded; Testbench's package:install sets up Workbench.
                Console\PurgeSkeletonCommand::class,
                // REMOVED: Hypervel's schedule:run replaces schedule:work.
                Console\SyncSkeletonCommand::class,
            ]);
        }

        // REMOVED: Testbench syncs configured directories while serving.
    }
}
