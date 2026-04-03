<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

class SlowTestExtension implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters
    ): void {
        $threshold = $parameters->has('threshold') ? (float) $parameters->get('threshold') : 0.5;

        $tracker = new SlowTestTracker($threshold);

        $facade->registerSubscriber(new TestPreparedSubscriber($tracker));
        $facade->registerSubscriber(new TestFinishedSubscriber($tracker));
        $facade->registerSubscriber(new ExecutionFinishedSubscriber($tracker));
    }
}
