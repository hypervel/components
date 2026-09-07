<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures;

use Hypervel\Contracts\Foundation\Application;

class ParallelRunnerConfiguredBootstrapper
{
    /**
     * Bootstrap the application.
     */
    public function bootstrap(Application $application): void
    {
        $application->make('config')->set('parallel-runner.configured-bootstrapper', true);
    }
}
