<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Arr;
use Hypervel\Support\Composer;
use Hypervel\Support\ServiceProvider;
use Throwable;

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
        $this->mergeAdditionalProviders($app);

        $app->registerConfiguredProviders();
    }

    /**
     * Merge the additional configured providers into the configuration.
     *
     * Merges only explicit providers (config defaults, programmatic merge,
     * bootstrap file). Discovered providers are handled separately in
     * Application::registerConfiguredProviders() where they are sandwiched
     * between framework and application providers.
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
     * Discover providers from installed packages via composer metadata.
     *
     * This is the Hypervel equivalent of Laravel's PackageManifest::providers().
     * It reads `extra.hypervel.providers` from each installed package's metadata.
     *
     * @return array<int, class-string>
     */
    public static function discoveredProviders(): array
    {
        $packagesToIgnore = static::packagesToIgnore();

        if (in_array('*', $packagesToIgnore)) {
            return [];
        }

        $providers = array_map(
            fn (array $package) => Arr::wrap($package['hypervel']['providers'] ?? []),
            Composer::getMergedExtra()
        );

        $providers = array_filter(
            $providers,
            fn ($package) => ! in_array($package, $packagesToIgnore),
            ARRAY_FILTER_USE_KEY
        );

        return Arr::flatten($providers);
    }

    /**
     * Get the packages that should not be discovered.
     *
     * @return array<int, string>
     */
    protected static function packagesToIgnore(): array
    {
        $packages = Composer::getMergedExtra('hypervel')['dont-discover'] ?? [];

        try {
            $project = Composer::getJsonContent()['extra']['hypervel']['dont-discover'] ?? [];
        } catch (Throwable) {
            $project = [];
        }

        return array_merge($packages, $project);
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
