<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Hypervel\Console\Command;
use Override;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

use function Hypervel\Testbench\is_testbench_cli;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\php_binary;

#[AsCommand(name: 'package:test', description: 'Run the package tests')]
class TestFallbackCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'package:test
        {--without-tty : Disable output to TTY}
        {--compact : Indicates whether the compact printer should be used}
        {--configuration= : Read configuration from XML file}
        {--coverage : Indicates whether the coverage information should be collected}
        {--min= : Indicates the minimum threshold enforcement for coverage}
        {--p|parallel : Indicates if the tests should run in parallel}
        {--profile : Lists top 10 slowest tests}
        {--recreate-databases : Indicates if the test databases should be re-created}
        {--drop-databases : Indicates if the test databases should be dropped}
        {--without-databases : Indicates if database configuration should be performed}
        {--c|--custom-argument : Add custom env variables}
    ';

    /**
     * The console command description.
     */
    protected string $description = 'Run the package tests';

    #[Override]
    public function configure(): void
    {
        parent::configure();

        if (! is_testbench_cli()) {
            $this->setHidden(true);
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->confirm('Running tests requires "nunomaduro/collision". Install it as a dev dependency?')) {
            return self::FAILURE;
        }

        $this->installCollisionDependencies();

        return self::SUCCESS;
    }

    /**
     * Install the required Collision dependency.
     */
    protected function installCollisionDependencies(): void
    {
        $command = sprintf('%s require "nunomaduro/collision:^8.0" --dev', $this->findComposer());
        $process = Process::fromShellCommandline($command);

        if (DIRECTORY_SEPARATOR !== '\\' && is_file('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $exception) {
                $this->output->writeln('Warning: ' . $exception->getMessage());
            }
        }

        try {
            $process->run(function (string $type, string $line): void {
                $this->output->write($line);
            });
        } catch (ProcessSignaledException $exception) {
            if (extension_loaded('pcntl') && $exception->getSignal() !== SIGINT) {
                throw $exception;
            }
        }
    }

    /**
     * Get the Composer command for the current environment.
     */
    protected function findComposer(): string
    {
        $composerPath = package_path('composer.phar');

        if (is_file($composerPath)) {
            return implode(' ', [php_binary(true), $composerPath]);
        }

        return 'composer';
    }
}
