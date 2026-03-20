<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Bootstrap;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\Foundation\Env;
use Override;

use function Hypervel\Testbench\join_paths;

/**
 * @internal
 */
final class LoadEnvironmentVariables extends \Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables
{
    /**
     * Bootstrap the given application.
     */
    #[Override]
    public function bootstrap(Application $app): void
    {
        if ($app->configurationIsCached()) {
            return;
        }

        $this->checkForSpecificEnvironmentFile($app);

        try {
            $environmentFile = join_paths($app->environmentPath(), $app->environmentFile());

            if (! is_file($environmentFile)) {
                Dotenv::create(
                    Env::getRepository(),
                    (string) realpath(join_paths(__DIR__, 'stubs')),
                    '.env.testbench',
                )->load();

                return;
            }

            parent::bootstrap($app);
        } catch (InvalidFileException $e) {
            $this->writeErrorAndDie($e);
        }
    }
}
