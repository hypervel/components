<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use Closure;
use Hypervel\Config\ProviderConfig;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;

/**
 * Bridge bootstrapper that reads ConfigProvider dependencies and registers
 * them as singletons in the container.
 *
 * This replaces the old DefinitionSourceFactory/DefinitionSource system that
 * pre-loaded dependencies into the Hyperf container before the Application
 * existed. Once all packages are migrated to ServiceProviders, this
 * bootstrapper can be removed.
 */
class RegisterDependencies
{
    /**
     * Register ConfigProvider dependencies into the container.
     */
    public function bootstrap(ApplicationContract $app): void
    {
        $dependencies = $this->loadDependencies();

        foreach ($dependencies as $abstract => $concrete) {
            if ($concrete instanceof Closure) {
                $app->singleton($abstract, $concrete);
            } elseif (is_string($concrete)) {
                $app->singleton($abstract, $concrete);
            }
        }
    }

    /**
     * Load all dependencies from ConfigProviders and dependency config files.
     */
    protected function loadDependencies(): array
    {
        $dependencies = [];

        if (class_exists(ProviderConfig::class)) {
            $dependencies = ProviderConfig::load()['dependencies'] ?? [];
        }

        $paths = [
            BASE_PATH . '/config/autoload/dependencies.php',
            BASE_PATH . '/config/dependencies.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $definitions = include $path;
                $dependencies = array_replace($dependencies, $definitions ?? []);
            }
        }

        return $dependencies;
    }
}
