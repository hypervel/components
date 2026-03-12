<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Console\Kernel as ConsoleKernelContract;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Routing\RouteCollection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'route:cache')]
class RouteCacheCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'route:cache';

    /**
     * The console command description.
     */
    protected string $description = 'Create a route cache file for faster route registration';

    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * Create a new route cache command instance.
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->callSilent('route:clear');

        $routes = $this->getFreshApplicationRoutes();

        if (count($routes) === 0) {
            $this->components->error("Your application doesn't have any routes.");

            return;
        }

        foreach ($routes as $route) {
            $route->prepareForSerialization();
        }

        $this->files->put(
            $this->hypervel->getCachedRoutesPath(),
            $this->buildRouteCacheFile($routes)
        );

        $this->components->info('Routes cached successfully.');
    }

    /**
     * Boot a fresh copy of the application and get the routes.
     */
    protected function getFreshApplicationRoutes(): RouteCollection
    {
        return tap($this->getFreshApplication()['router']->getRoutes(), function (RouteCollection $routes): void {
            $routes->refreshNameLookups();
            $routes->refreshActionLookups();
        });
    }

    /**
     * Get a fresh application instance.
     */
    protected function getFreshApplication(): Application
    {
        return tap(require $this->hypervel->bootstrapPath('app.php'), function (Application $app): void {
            $app->make(ConsoleKernelContract::class)->bootstrap();
        });
    }

    /**
     * Build the route cache file.
     */
    protected function buildRouteCacheFile(RouteCollection $routes): string
    {
        $stub = $this->files->get(__DIR__ . '/stubs/routes.stub');

        return str_replace('{{routes}}', var_export($routes->compile(), true), $stub);
    }
}
