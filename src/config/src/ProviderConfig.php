<?php

declare(strict_types=1);

namespace Hypervel\Config;

use Hyperf\Collection\Arr;
use Hyperf\Config\ProviderConfig as HyperfProviderConfig;
use Hyperf\Di\Definition\PriorityDefinition;
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
                Arr::wrap($package['hyperf']['config'] ?? []),
                Arr::wrap($package['hypervel']['config'] ?? []),
                Arr::wrap($package['hypervel']['providers'] ?? []),
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

    /**
     * Merge provider config arrays.
     *
     * Correctly handles:
     * - Pure lists (numeric keys): appends values with deduplication
     * - Associative arrays (string keys): recursively merges, later wins for scalars
     * - Mixed arrays (e.g. listeners with priorities): appends numeric, merges string keys
     *
     * @return array<string, mixed>
     */
    protected static function merge(...$arrays): array
    {
        if (empty($arrays)) {
            return [];
        }

        $result = array_reduce(
            array_slice($arrays, 1),
            [static::class, 'mergeTwo'],
            $arrays[0]
        );

        // Special handling for dependencies with PriorityDefinition
        if (isset($result['dependencies'])) {
            $result['dependencies'] = [];
            foreach ($arrays as $item) {
                foreach ($item['dependencies'] ?? [] as $key => $value) {
                    $depend = $result['dependencies'][$key] ?? null;
                    if (! $depend instanceof PriorityDefinition) {
                        $result['dependencies'][$key] = $value;
                        continue;
                    }

                    if ($value instanceof PriorityDefinition) {
                        $depend->merge($value);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Merge two config arrays.
     *
     * Correctly handles:
     * - Pure lists (numeric keys): appends values with deduplication
     * - Associative arrays (string keys): recursively merges, later wins for scalars
     * - Mixed arrays (e.g. listeners with priorities): appends numeric, merges string keys
     *
     * This method is public so ConfigFactory can use the same merge semantics.
     *
     * @return array<string, mixed>
     */
    public static function mergeTwo(array $base, array $override): array
    {
        $result = $base;

        foreach ($override as $key => $value) {
            if (is_int($key)) {
                // Numeric key - append if not already present (deduplicate)
                if (! in_array($value, $result, true)) {
                    $result[] = $value;
                }
            } elseif (! array_key_exists($key, $result)) {
                // New string key - just add it
                $result[$key] = $value;
            } elseif (is_array($value) && is_array($result[$key])) {
                // Both are arrays - recursively merge
                $result[$key] = self::mergeTwo($result[$key], $value);
            } else {
                // Scalar or mixed types - override wins
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
