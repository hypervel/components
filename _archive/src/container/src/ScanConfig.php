<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Hyperf\Di\Annotation\ScanConfig as HyperfScanConfig;
use Hypervel\Config\ProviderConfig;

class ScanConfig extends HyperfScanConfig
{
    protected static function initConfigByFile(string $configDir): array
    {
        $config = [];
        $configFromProviders = [];
        $cacheable = false;
        if (class_exists(ProviderConfig::class)) {
            $configFromProviders = ProviderConfig::load();
        }

        $serverDependencies = $configFromProviders['dependencies'] ?? [];
        if (file_exists($dependenciesFile = "{$configDir}/dependencies.php")) {
            $definitions = include $dependenciesFile;
            $serverDependencies = array_replace($serverDependencies, $definitions ?? []);
        }

        $config = static::allocateConfigValue($configFromProviders['annotations'] ?? [], $config);

        // Load the config/annotations.php and merge the config
        if (file_exists($annotationsFile = "{$configDir}/annotations.php")) {
            $annotations = include $annotationsFile;
            $config = static::allocateConfigValue($annotations, $config);
        }

        // Load the config/app.php and merge the config
        if (file_exists($appFile = "{$configDir}/app.php")) {
            $configContent = include $appFile;
            $environment = $configContent['env'] ?? 'dev';
            $cacheable = value($configContent['scan_cacheable'] ?? $environment === 'production');
            if (isset($configContent['annotations'])) {
                $config = static::allocateConfigValue($configContent['annotations'], $config);
            }
        }

        return [$config, $serverDependencies, $cacheable];
    }

    protected static function allocateConfigValue(array $content, array $config): array
    {
        if (! isset($content['scan'])) {
            return $config;
        }

        foreach ($content['scan'] as $key => $value) {
            if (! isset($config[$key])) {
                $config[$key] = [];
            }
            if (! is_array($value)) {
                $value = [$value];
            }
            $config[$key] = array_merge($config[$key], $value);
        }

        return $config;
    }
}
