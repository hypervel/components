<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use Hyperf\Collection\Arr;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Contracts\Application as ApplicationContract;
use Hypervel\Support\Composer;
use Hypervel\Support\Facades\Facade;
use Throwable;

class RegisterFacades
{
    /**
     * Load Class Aliases.
     */
    public function bootstrap(ApplicationContract $app): void
    {
        Facade::clearResolvedInstances();

        $composerAliases = [];
        try {
            $composerAliases = Arr::wrap(Composer::getJsonContent()['extra']['hypervel']['aliases']) ?? [];
        } catch (Throwable $e) {
            // do nothing
        }

        $configAliases = $app->get(ConfigInterface::class)
            ->get('app.aliases', []);
        $aliases = array_merge($composerAliases, $configAliases);

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
