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
 * Upstream's vendor symlink and manifest rebuild are omitted: Hypervel's Testbench
 * bootstrapper links the package's vendor directory, and the manifest rebuilds itself.
 *
 * @internal
 */
class ApplicationResolver
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

        // Boot the disposable runtime copy, so analysis never writes caches into committed files, and
        // discover the package's own providers and aliases as an installing application would.
        return Testbench::create(options: [
            'extra' => $config->getExtraAttributes(),
            'discovers_root_package' => true,
        ]);
    }
}
