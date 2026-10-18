<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Support\Facades\Process as ProcessFacade;
use Symfony\Component\Process\Process;

use function Hypervel\Support\artisan_binary;
use function Hypervel\Support\php_binary;

trait InteractsWithComposerPackages
{
    /**
     * Install the given Composer packages into the application.
     *
     * @param array<int, string> $packages
     */
    protected function requireComposerPackages(string $composer, array $packages, bool $pretend = false): void
    {
        if ($composer !== 'global') {
            $command = [$this->phpBinary(), $composer, 'require'];
        }

        $command = array_merge(
            $command ?? ['composer', 'require'],
            ['--with-all-dependencies'],
            $packages,
        );

        if ($pretend) {
            $command[] = '--dry-run';
        }

        (new Process($command, $this->hypervel->basePath(), ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->mustRun(function (string $type, string $output): void {
                $this->output->write($output);
            });
    }

    /**
     * Run a newly installed Artisan command in a fresh application process.
     *
     * @param array<int, string> $arguments
     */
    protected function runInstallationCommand(array $arguments): void
    {
        $process = ProcessFacade::forever()->path($this->hypervel->basePath());
        $interactive = $this->input->isInteractive() && $process->supportsTty();

        $process->tty($interactive)->run([
            php_binary(),
            artisan_binary(),
            ...$arguments,
            ...($interactive ? [] : ['--no-interaction']),
        ], function (string $type, string $output): void {
            $this->output->write($output);
        })->throw();
    }

    /**
     * Get the path to the appropriate PHP binary.
     */
    protected function phpBinary(): string
    {
        return php_binary();
    }
}
