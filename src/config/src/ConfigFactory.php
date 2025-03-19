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
        $merged = array_merge_recursive(ProviderConfig::load(), $rootConfig, ...$autoloadConfig);

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
}
