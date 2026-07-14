<?php

declare(strict_types=1);

namespace Hypervel\Support\Contracts;

interface NodePackageManager
{
    /**
     * Determine if the package manager is in use.
     */
    public static function matches(): bool;

    /**
     * Get the command to run a script using the package manager.
     */
    public function getRunCommand(string $command): string;

    /**
     * Get the command to execute a package using the package manager.
     */
    public function getExecCommand(string $command): string;
}
