<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use Dotenv\Exception\InvalidFileException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\DotenvManager;
use Hypervel\Support\Env;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class LoadEnvironmentVariables
{
    /**
     * Bootstrap the given application.
     */
    public function bootstrap(Application $app): void
    {
        if ($app->configurationIsCached()) {
            return;
        }

        $this->checkForSpecificEnvironmentFile($app);

        try {
            DotenvManager::safeLoad(
                [$app->environmentPath()],
                $app->environmentFile()
            );
        } catch (InvalidFileException $e) {
            $this->writeErrorAndDie($e);
        }
    }

    /**
     * Detect if a custom environment file matching the APP_ENV exists.
     */
    protected function checkForSpecificEnvironmentFile(Application $app): void
    {
        if ($app->runningInConsole()
            && ($input = new ArgvInput())->hasParameterOption('--env')
            && $this->setEnvironmentFilePath($app, $app->environmentFile() . '.' . $input->getParameterOption('--env'))) {
            return;
        }

        $environment = Env::get('APP_ENV');

        if (! $environment) {
            return;
        }

        $this->setEnvironmentFilePath(
            $app,
            $app->environmentFile() . '.' . $environment
        );
    }

    /**
     * Load a custom environment file.
     */
    protected function setEnvironmentFilePath(Application $app, string $file): bool
    {
        if (is_file($app->environmentPath() . '/' . $file)) {
            $app->loadEnvironmentFrom($file);

            return true;
        }

        return false;
    }

    /**
     * Write the error information to the screen and exit.
     */
    protected function writeErrorAndDie(InvalidFileException $e): never
    {
        $output = (new ConsoleOutput())->getErrorOutput();

        $output->writeln('The environment file is invalid!');
        $output->writeln($e->getMessage());

        http_response_code(500);

        exit(1);
    }
}
