<?php

declare(strict_types=1);

namespace Hypervel\Data;

use Hypervel\Data\Console\DataMakeCommand;
use Hypervel\Data\Contracts\TransformableData;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\VarDumper\DataVarDumperCaster;
use Hypervel\Support\ServiceProvider;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;

class DataServiceProvider extends ServiceProvider
{
    /**
     * Register data services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/config/data.php',
            'data',
        );

        // REMOVED: Livewire/Wireable integration has no Hypervel equivalent.
        // REMOVED: TypeScript integration belongs to a general reflection transformer package.
    }

    /**
     * Bootstrap data services.
     */
    public function boot(): void
    {
        // Build the typed configuration once during worker boot.
        $this->app->make(DataConfig::class);

        AbstractCloner::$defaultCasters[TransformableData::class]
            ??= [DataVarDumperCaster::class, 'cast'];

        if ($this->app->runningInConsole()) {
            // REMOVED: data:cache-structures; worker memory is the metadata cache boundary.
            $this->commands([DataMakeCommand::class]);

            $this->publishes([
                dirname(__DIR__) . '/config/data.php' => config_path('data.php'),
            ], 'data-config');
        }
    }
}
