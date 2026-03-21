<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Composer\Config as ComposerConfig;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Server\Commands\ServerStartCommand as Command;
use Hypervel\Testbench\Foundation\Events\ServeCommandEnded;
use Hypervel\Testbench\Foundation\Events\ServeCommandStarted;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Hypervel\Testbench\package_path;

#[AsCommand(name: 'serve', description: 'Start Hypervel servers.')]
class ServeCommand extends Command
{
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (
            class_exists(ComposerConfig::class, false)
            && method_exists(ComposerConfig::class, 'disableProcessTimeout') // @phpstan-ignore function.impossibleType
        ) {
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

        event(new ServeCommandStarted($input, $styledOutput, $components));

        try {
            $exitCode = $this->startServer();
        } catch (Throwable $throwable) {
            event(new ServeCommandEnded($input, $styledOutput, $components, self::FAILURE));

            throw $throwable;
        }

        event(new ServeCommandEnded($input, $styledOutput, $components, $exitCode));

        return $exitCode;
    }
}
