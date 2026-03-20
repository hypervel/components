<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\ServiceProvider;

class RegisterProviders
{
    /**
     * The service providers that should be merged before registration.
     *
     * @var array<int, class-string>
     */
    protected static array $merge = [];

    /**
     * The path to the bootstrap provider configuration file.
     */
    protected static ?string $bootstrapProviderPath = null;

    /**
     * Bootstrap the given application.
     */
    public function bootstrap(ApplicationContract $app): void
    {
        if (! $app->bound('config_loaded_from_cache')
            || $app->make('config_loaded_from_cache') === false) {
            $this->mergeAdditionalProviders($app);
        }

        $app->registerConfiguredProviders();
    }

    /**
     * Merge the additional configured providers into the configuration.
     */
    protected function mergeAdditionalProviders(ApplicationContract $app): void
    {
        if (static::$bootstrapProviderPath
            && file_exists(static::$bootstrapProviderPath)) {
            $bootstrapProviders = require static::$bootstrapProviderPath;

            foreach ($bootstrapProviders as $index => $provider) {
                if (! class_exists($provider)) {
                    unset($bootstrapProviders[$index]);
                }
            }
        }

        $app->make('config')->set(
            'app.providers',
            array_merge(
                $app->make('config')->get('app.providers') ?? ServiceProvider::defaultProviders()->toArray(),
                static::$merge,
                array_values($bootstrapProviders ?? []),
            ),
        );
    }

    /**
     * Merge the given providers into the provider configuration before registration.
     *
     * @param array<int, class-string|string> $providers
     */
    public static function merge(array $providers, ?string $bootstrapProviderPath = null): void
    {
        static::$bootstrapProviderPath = $bootstrapProviderPath;

        static::$merge = array_values(array_filter(array_unique(
            array_merge(static::$merge, $providers)
        )));
    }

    /**
     * Flush the bootstrapper's global state.
     */
    public static function flushState(): void
    {
        static::$bootstrapProviderPath = null;

        static::$merge = [];
    }
}
