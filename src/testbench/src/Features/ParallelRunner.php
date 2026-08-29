<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Features;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testing\ParallelRunner as BaseParallelRunner;
use Hypervel\Testing\ParallelTestingServiceProvider;

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

        $applicationResolver = static::$applicationResolver ?: static function (): ApplicationContract {
            Bootstrapper::bootstrap();

            $extra = Bootstrapper::getConfiguration()?->getExtraAttributes() ?? [];
            // Process callbacks belong to the runner even when package discovery is disabled.
            $extra['providers'] = array_values(array_unique([
                ...($extra['providers'] ?? []),
                ParallelTestingServiceProvider::class,
            ]));

            return container(options: ['extra' => $extra])->createApplication();
        };

        return $applicationResolver();
    }
}
