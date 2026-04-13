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
            ->get('app.aliases', []);
        $aliases = array_merge($packageAliases, $configAliases);

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
