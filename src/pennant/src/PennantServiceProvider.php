<?php

declare(strict_types=1);

namespace Hypervel\Pennant;

use Hypervel\Foundation\Events\PublishingStubs;
use Hypervel\Pennant\Commands\FeatureMakeCommand;
use Hypervel\Pennant\Commands\PurgeCommand;
use Hypervel\Support\ServiceProvider;
use Hypervel\View\Compilers\BladeCompiler;
use UnitEnum;

class PennantServiceProvider extends ServiceProvider
{
    /**
     * Register the package's services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/pennant.php', 'pennant');
    }

    /**
     * Get configuration arrays whose entries should be merged by name.
     */
    protected function mergeableOptions(string $name): array
    {
        return $name === 'pennant' ? ['stores'] : [];
    }

    /**
     * Bootstrap the package's services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->offerPublishing();

            $this->commands([
                FeatureMakeCommand::class,
                PurgeCommand::class,
            ]);
        }

        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade): void {
            $blade->if('feature', function (UnitEnum|string $feature, mixed $value = null): bool {
                if (func_num_args() === 2) {
                    return Feature::value($feature) === $value;
                }

                return Feature::active($feature);
            });

            $blade->if('featureany', function (array $features): bool {
                return Feature::someAreActive($features);
            });
        });

        $this->listenForEvents();
    }

    /**
     * Listen for the events relevant to the package.
     */
    protected function listenForEvents(): void
    {
        // Laravel's Octane and JobProcessed flush listeners aren't needed: feature values are memoized per coroutine.
        $this->app->make('events')->listen([
            PublishingStubs::class,
        ], fn (PublishingStubs $event) => $event->add(__DIR__ . '/../stubs/feature.stub', 'feature.stub'));
    }

    /**
     * Register the migrations and publishing for the package.
     */
    protected function offerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/pennant.php' => config_path('pennant.php'),
        ], 'pennant-config');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'pennant-migrations');
    }
}
