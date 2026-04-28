<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Concerns;

use function Hypervel\Testbench\join_paths;
use function Hypervel\Testbench\uses_default_skeleton;
use function Hypervel\Testbench\workbench_path;

trait WithHypervelBootstrapFile
{
    use InteractsWithTestCase;

    /**
     * Get application bootstrap file path (if exists).
     *
     * @internal
     */
    protected function getApplicationBootstrapFile(string $filename): string|false
    {
        $bootstrapFile = realpath(join_paths($this->getApplicationBasePath(), 'bootstrap', $filename));

        if ($this->usesTestbenchDefaultSkeleton()) {
            if (static::usesTestingConcern(WithWorkbench::class)) {
                return is_file($workbenchFile = workbench_path('bootstrap', $filename)) ? (string) realpath($workbenchFile) : false;
            }

            return false;
        }

        return $bootstrapFile;
    }

    /**
     * Determine if application is using a custom application kernels.
     *
     * @internal
     */
    protected function hasCustomApplicationKernels(): bool
    {
        return ! $this->usesTestbenchDefaultSkeleton()
            && ((static::$cacheApplicationBootstrapFile ??= $this->getApplicationBootstrapFile('app.php')) !== false);
    }

    /**
     * Determine if application is bootstrapped using Testbench's default skeleton.
     */
    protected function usesTestbenchDefaultSkeleton(): bool
    {
        return uses_default_skeleton($this->getApplicationBasePath());
    }

    /**
     * Get the application's base path.
     *
     * @api
     *
     * @return string
     */
    abstract protected function getApplicationBasePath();
}
