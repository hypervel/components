<?php

declare(strict_types=1);

namespace Hypervel\Support\NodePackageManagers;

use Hypervel\Support\Contracts\NodePackageManager;

class Bun implements NodePackageManager
{
    /**
     * Determine if the Bun package manager is in use.
     */
    public static function matches(): bool
    {
        return array_any(['bun.lock', 'bun.lockb'], fn (string $lockFile): bool => file_exists(getcwd() . '/' . $lockFile));
    }

    /**
     * Get the command to run a script using Bun.
     */
    public function getRunCommand(string $command): string
    {
        return "bun run {$command}";
    }

    /**
     * Get the command to execute a package using Bun.
     */
    public function getExecCommand(string $command): string
    {
        return "bunx {$command}";
    }
}
