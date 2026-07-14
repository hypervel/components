<?php

declare(strict_types=1);

namespace Hypervel\Support\NodePackageManagers;

use Hypervel\Support\Contracts\NodePackageManager;

class Pnpm implements NodePackageManager
{
    /**
     * Determine if the pnpm package manager is in use.
     */
    public static function matches(): bool
    {
        return file_exists(getcwd() . '/pnpm-lock.yaml');
    }

    /**
     * Get the command to run a script using pnpm.
     */
    public function getRunCommand(string $command): string
    {
        return "pnpm run {$command}";
    }

    /**
     * Get the command to execute a package using pnpm.
     */
    public function getExecCommand(string $command): string
    {
        return "pnpm exec {$command}";
    }
}
