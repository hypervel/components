<?php

declare(strict_types=1);

namespace Hypervel\PHPStan;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Contracts\Config;
use Hypervel\Testbench\Foundation\Application as Testbench;

use function define;
use function defined;
use function getcwd;

/**
 * Upstream links the package's vendor directory into the Testbench skeleton and
 * rebuilds a missing package manifest. Hypervel's Testbench bootstrapper already
 * links the package's actual Composer vendor directory into its disposable runtime
 * copy, and the package manifest rebuilds itself when missing, so neither step is
 * repeated here.
 *
 * @internal
 */
final class ApplicationResolver
{
    /**
     * Create a package application from its Testbench configuration.
     */
    public static function resolve(): Application
    {
        /** @var non-empty-string $workingPath */
        $workingPath = getcwd();

        if (! defined('TESTBENCH_WORKING_PATH')) {
            define('TESTBENCH_WORKING_PATH', $workingPath);
        }

        Bootstrapper::bootstrap();

        /** @var Config $config bootstrap() always loads the configuration. */
        $config = Bootstrapper::getConfiguration();

        // Boot the disposable runtime copy rather than a configured skeleton, so
        // analysis never writes caches into committed files. The analysed package's
        // own providers and aliases are discovered like an installing application's;
        // the configuration's dont-discover list still decides package discovery.
        return Testbench::create(options: [
            'extra' => $config->getExtraAttributes(),
            'discovers_root_package' => true,
        ]);
    }
}
