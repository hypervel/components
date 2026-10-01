<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Composer\Config as ComposerConfig;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Server\Commands\ServerStartCommand as Command;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Events\ServeCommandEnded;
use Hypervel\Testbench\Foundation\Events\ServeCommandStarted;
use Hypervel\Testbench\Workbench\Actions\AddAssetSymlinkFolders;
use Hypervel\Testbench\Workbench\Actions\RemoveAssetSymlinkFolders;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Hypervel\Testbench\package_path;

#[AsCommand(name: 'serve', description: 'Start Hypervel servers.')]
class ServeCommand extends Command
{
    /**
     * Execute the console command.
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (class_exists(ComposerConfig::class, false)) {
            ComposerConfig::disableProcessTimeout();
        }

        $workingPath = package_path();

        putenv("TESTBENCH_WORKING_PATH={$workingPath}");
        $_ENV['TESTBENCH_WORKING_PATH'] = $workingPath;
        $_SERVER['TESTBENCH_WORKING_PATH'] = $workingPath;

        $styledOutput = $output instanceof OutputStyle
            ? $output
            : new OutputStyle($input, $output);
        $components = new Factory($styledOutput);

        /** @var Dispatcher $events */
        $events = app('events');
        $files = $this->application->make(Filesystem::class);
        $config = $this->application->make(ConfigContract::class);
        $exitCode = self::FAILURE;
        $failure = null;

        try {
            if ($events->hasListeners(ServeCommandStarted::class)) {
                $events->dispatch(new ServeCommandStarted($input, $styledOutput, $components));
            }

            // Started listeners publish into the runtime skeleton before any sync
            // link exists, so they never write through a link into the package.
            (new AddAssetSymlinkFolders($files, $config))->handle();

            $exitCode = $this->startServer($input);
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        try {
            (new RemoveAssetSymlinkFolders($files, $config))->handle();
        } catch (Throwable $throwable) {
            $failure ??= $throwable;
        }

        try {
            if ($events->hasListeners(ServeCommandEnded::class)) {
                $events->dispatch(new ServeCommandEnded($input, $styledOutput, $components, $failure === null ? $exitCode : self::FAILURE));
            }
        } catch (Throwable $throwable) {
            $failure ??= $throwable;
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $exitCode;
    }
}
