<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit extension that registers the AfterEachTestSubscriber.
 *
 * This ensures Mockery cleanup runs after every test method, eliminating
 * the need for explicit m::close() calls in individual test tearDown methods.
 */
final class AfterEachTestExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new AfterEachTestSubscriber());
    }
}
