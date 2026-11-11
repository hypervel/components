<?php

declare(strict_types=1);

namespace Hypervel\Data;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Console\DataMakeCommand;
use Hypervel\Data\Contracts\TransformableData;
use Hypervel\Data\Support\Creation\DataCreator;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\Transformation\DataTransformer;
use Hypervel\Data\Support\VarDumper\DataVarDumperCaster;
use Hypervel\Support\ServiceProvider;
use InvalidArgumentException;
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
    public function boot(Repository $config): void
    {
        // Build the typed configuration once during worker boot.
        $this->app->make(DataConfig::class);

        if (! $this->app->runningUnitTests()) {
            $this->app->booted(static function (Application $app): void {
                $app->make(DataCreator::class);
                $app->make(DataTransformer::class);
            });
        }

        $enableVarDumperCaster = match ($config->string('data.var_dumper_caster_mode')) {
            'enabled' => true,
            'development' => $this->app->environment('local', 'testing'),
            'disabled' => false,
            default => throw new InvalidArgumentException(
                "Configuration [data.var_dumper_caster_mode] must be 'enabled', 'disabled' or 'development'.",
            ),
        };

        if ($enableVarDumperCaster) {
            AbstractCloner::$defaultCasters[TransformableData::class]
                ??= [DataVarDumperCaster::class, 'cast'];
        }

        if ($this->app->runningInConsole()) {
            // REMOVED: data:cache-structures; worker memory is the metadata cache boundary.
            $this->commands([DataMakeCommand::class]);

            $this->publishes([
                dirname(__DIR__) . '/config/data.php' => config_path('data.php'),
            ], 'data-config');
        }
    }
}
