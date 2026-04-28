<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Features;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testing\ParallelRunner as BaseParallelRunner;

use function Hypervel\Testbench\container;

/**
 * @internal
 */
class ParallelRunner extends BaseParallelRunner
{
    /**
     * Create the application.
     */
    protected function createApplication(): ApplicationContract
    {
        if (! defined('TESTBENCH_WORKING_PATH')) {
            define('TESTBENCH_WORKING_PATH', Env::get('TESTBENCH_WORKING_PATH'));
        }

        if (! isset($_ENV['TESTBENCH_APP_BASE_PATH'])) {
            $_ENV['TESTBENCH_APP_BASE_PATH'] = Env::get('TESTBENCH_APP_BASE_PATH');
        }

        $applicationResolver = static::$applicationResolver ?: static fn () => container()->createApplication();

        return $applicationResolver();
    }
}
