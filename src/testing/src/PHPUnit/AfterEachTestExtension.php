<?php

declare(strict_types=1);

namespace Hypervel\Testing\PHPUnit;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

class AfterEachTestExtension implements Extension
{
    /**
     * Bootstrap the extension.
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        TestStateRegistrars::forRootInstall()->register();

        $cleanup = new AfterEachTestSubscriber;

        $facade->registerSubscriber(new AfterEachTestPreparationStartedSubscriber($cleanup));
        $facade->registerSubscriber($cleanup);
        $facade->registerSubscriber(new AfterEachTestExecutionFinishedSubscriber($cleanup));
    }
}
