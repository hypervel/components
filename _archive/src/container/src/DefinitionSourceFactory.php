<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Hyperf\Di\Exception\Exception;
use Hypervel\Config\ProviderConfig;

class DefinitionSourceFactory
{
    public function __invoke(): DefinitionSource
    {
        if (! defined('BASE_PATH')) {
            throw new Exception('BASE_PATH is not defined.');
        }

        $configFromProviders = [];
        if (class_exists(ProviderConfig::class)) {
            $configFromProviders = ProviderConfig::load();
        }

        $serverDependencies = $configFromProviders['dependencies'] ?? [];

        // make dependencies.php file to be compatible with Hyperf
        $dependenciesPaths = [
            BASE_PATH . '/config/autoload/dependencies.php',
            BASE_PATH . '/config/dependencies.php',
        ];
        foreach ($dependenciesPaths as $dependenciesPath) {
            if (file_exists($dependenciesPath)) {
                $definitions = include $dependenciesPath;
                $serverDependencies = array_replace($serverDependencies, $definitions ?? []);
            }
        }

        return new DefinitionSource($serverDependencies);
    }
}
