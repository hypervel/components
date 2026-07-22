<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Support\Facades\Facade;

class RegisterFacades
{
    /**
     * Load Class Aliases.
     */
    public function bootstrap(ApplicationContract $app): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $packageAliases = $app->make(PackageManifest::class)->aliases();

        $configAliases = $app->make('config')
            ->array('app.aliases');
        $aliases = array_merge($packageAliases, $configAliases);

        // Hypervel intentionally supports only explicit facade aliases. Laravel's
        // runtime-generated real-time facades hide dependencies, confuse PHPStan,
        // and are a poor fit for long-lived workers.
        $this->registerAliases($aliases);
    }

    protected function registerAliases(array $aliases): void
    {
        foreach ($aliases as $alias => $class) {
            if (class_exists($alias)) {
                continue;
            }

            class_alias($class, $alias);
        }
    }
}
