<?php

declare(strict_types=1);

namespace Hypervel\Support\NodePackageManagers;

use Hypervel\Support\Contracts\NodePackageManager;

class Yarn implements NodePackageManager
{
    /**
     * Determine if the Yarn package manager is in use.
     */
    public static function matches(): bool
    {
        return file_exists(getcwd() . '/yarn.lock');
    }

    /**
     * Get the command to run a script using Yarn.
     */
    public function getRunCommand(string $command): string
    {
        return "yarn run {$command}";
    }

    /**
     * Get the command to execute a package using Yarn.
     */
    public function getExecCommand(string $command): string
    {
        return "yarn run {$command}";
    }
}
