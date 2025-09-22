<?php

declare(strict_types=1);

namespace Hypervel\Config;

use Hyperf\Collection\Arr;
use Hyperf\Config\ProviderConfig as HyperfProviderConfig;
use Hyperf\Support\Composer;
use Hypervel\Support\ServiceProvider;
use Throwable;

/**
 * Provider config allow the components set the configs to application.
 */
class ProviderConfig extends HyperfProviderConfig
{
    protected static array $providerConfigs = [];

    /**
     * Load and merge all provider configs from components.
     * Notice that this method will cached the config result into a static property,
     * call ProviderConfig::clear() method if you want to reset the static property.
     */
    public static function load(): array
    {
        if (static::$providerConfigs) {
            return static::$providerConfigs;
        }

        $packagesToIgnore = static::packagesToIgnore();
        if (in_array('*', $packagesToIgnore)) {
            return static::$providerConfigs = [];
        }

        $providers = array_map(
            fn (array $package) => array_merge(
                Arr::wrap(($package['hyperf']['config'] ?? []) ?? []),
                Arr::wrap(($package['hypervel']['config'] ?? []) ?? []),
                Arr::wrap(($package['hypervel']['providers'] ?? []) ?? []),
            ),
            Composer::getMergedExtra()
        );
        $providers = array_filter(
            $providers,
            fn ($package) => ! in_array($package, $packagesToIgnore),
            ARRAY_FILTER_USE_KEY
        );

        return static::$providerConfigs = static::loadProviders(
            Arr::flatten($providers)
        );
    }

    protected static function loadProviders(array $providers): array
    {
        $providerConfigs = [];
        foreach ($providers as $provider) {
            if (! is_string($provider) || ! class_exists($provider)) {
                continue;
            }
            if (is_subclass_of($provider, ServiceProvider::class)
                && $providerConfig = $provider::getProviderConfig()
            ) {
                $providerConfigs[] = $providerConfig;
                continue;
            }
            if (method_exists($provider, '__invoke')) {
                $providerConfigs[] = (new $provider())();
            }
        }

        return static::merge(...$providerConfigs);
    }

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
}
