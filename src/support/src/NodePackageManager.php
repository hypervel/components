<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hypervel\Support\Contracts\NodePackageManager as NodePackageManagerContract;

class NodePackageManager
{
    /**
     * Create a new NodePackageManager manager instance.
     */
    public function __construct(protected ?NodePackageManagerContract $packageManager = null)
    {
    }

    /**
     * Get the command to execute a package using the detected package manager.
     */
    public function getExecCommand(string $command): string
    {
        return $this->packageManager()->getExecCommand($command);
    }

    /**
     * Get the command to run a script using the detected package manager.
     */
    public function getRunCommand(string $command): string
    {
        return $this->packageManager()->getRunCommand($command);
    }

    /**
     * Get the Node package manager in use.
     */
    public function packageManager(): NodePackageManagerContract
    {
        return $this->packageManager ??= $this->detect();
    }

    /**
     * Detect the current package manager.
     */
    protected function detect(): NodePackageManagerContract
    {
        $directory = getcwd();
        /** @var array<class-string<NodePackageManagerContract>, list<string>> $packageManagers */
        $packageManagers = [
            NodePackageManagers\Bun::class => ['bun.lock', 'bun.lockb'],
            NodePackageManagers\Pnpm::class => ['pnpm-lock.yaml'],
            NodePackageManagers\Yarn::class => ['yarn.lock'],
            NodePackageManagers\Npm::class => ['package-lock.json'],
        ];

        while ($directory !== false) {
            foreach ($packageManagers as $packageManager => $lockFiles) {
                if (array_any($lockFiles, fn (string $lockFile): bool => file_exists($directory . '/' . $lockFile))) {
                    return new $packageManager;
                }
            }

            $parentDirectory = dirname($directory);

            if ($parentDirectory === $directory) {
                break;
            }

            $directory = $parentDirectory;
        }

        return new NodePackageManagers\Npm;
    }
}
