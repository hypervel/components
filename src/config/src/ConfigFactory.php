<?php

declare(strict_types=1);

namespace Hypervel\Config;

use Hyperf\Collection\Arr;
use Psr\Container\ContainerInterface;
use Symfony\Component\Finder\Finder;

class ConfigFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $configPath = BASE_PATH . '/config';
        $loadPaths = [$configPath];
        // be compatible with hyperf folder structure
        if (file_exists($autoloadPath = "{$configPath}/autoload")) {
            $loadPaths[] = $autoloadPath;
        }

        $rootConfig = $this->readConfig($configPath . '/hyperf.php');
        $autoloadConfig = $this->readPaths($loadPaths, ['hyperf.php']);

        // Merge all config sources: provider configs + root config + autoload configs
        $allConfigs = [ProviderConfig::load(), $rootConfig, ...$autoloadConfig];
        $merged = array_reduce(
            array_slice($allConfigs, 1),
            [$this, 'mergeTwo'],
            $allConfigs[0]
        );

        return new Repository($merged);
    }

    private function readConfig(string $configPath): array
    {
        $config = [];
        if (file_exists($configPath) && is_readable($configPath)) {
            $config = require $configPath;
        }

        return is_array($config) ? $config : [];
    }

    private function readPaths(array $paths, array $excludes = []): array
    {
        $configs = [];
        $finder = new Finder();
        $finder->files()->in($paths)->name('*.php');
        foreach ($excludes as $exclude) {
            $finder->notName($exclude);
        }
        foreach ($finder as $file) {
            $config = [];
            $key = implode('.', array_filter([
                str_replace('/', '.', $file->getRelativePath()),
                $file->getBasename('.php'),
            ]));
            Arr::set($config, $key, require $file->getRealPath());
            $configs[] = $config;
        }

        return $configs;
    }

    /**
     * Merge two config arrays.
     *
     * Correctly handles:
     * - Pure lists (numeric keys): appends values with deduplication
     * - Associative arrays (string keys): recursively merges, later wins for scalars
     * - Mixed arrays (e.g. listeners with priorities): appends numeric, merges string keys
     */
    private function mergeTwo(array $base, array $override): array
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
                $result[$key] = $this->mergeTwo($result[$key], $value);
            } else {
                // Scalar or mixed types - override wins
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
