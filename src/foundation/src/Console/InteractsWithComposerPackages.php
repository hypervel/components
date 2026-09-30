<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Symfony\Component\Process\Process;

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
     * Get the path to the appropriate PHP binary.
     */
    protected function phpBinary(): string
    {
        return php_binary();
    }
}
