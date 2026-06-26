<?php

declare(strict_types=1);

namespace Hypervel\Testing\Profile;

use Hypervel\Testing\Console\TestCommandBase;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

class ProfileExtension implements Extension
{
    /**
     * Register the profile subscribers.
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (($_SERVER[TestCommandBase::PROFILE_ENV] ?? $_ENV[TestCommandBase::PROFILE_ENV] ?? false) !== '1') {
            return;
        }

        $directory = $_SERVER[TestCommandBase::PROFILE_DIRECTORY_ENV]
            ?? $_ENV[TestCommandBase::PROFILE_DIRECTORY_ENV]
            ?? null;

        if (! is_string($directory) || $directory === '') {
            return;
        }

        $tracker = new ProfileTracker;

        $facade->registerSubscriber(new TestPreparedSubscriber($tracker));
        $facade->registerSubscriber(new TestFinishedSubscriber($tracker));
        $facade->registerSubscriber(new ExecutionFinishedSubscriber($tracker, $directory));
    }
}
