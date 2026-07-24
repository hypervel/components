<?php

declare(strict_types=1);

namespace Hypervel\Support\NodePackageManagers;

use Hypervel\Support\Contracts\NodePackageManager;

class Npm implements NodePackageManager
{
    /**
     * Determine if the npm package manager is in use.
     */
    public static function matches(): bool
    {
        return file_exists(getcwd() . '/package-lock.json');
    }

    /**
     * Get the command to run a script using npm.
     */
    public function getRunCommand(string $command): string
    {
        return "npm run {$command}";
    }

    /**
     * Get the command to execute a package using npm.
     */
    public function getExecCommand(string $command): string
    {
        return "npx {$command}";
    }
}
