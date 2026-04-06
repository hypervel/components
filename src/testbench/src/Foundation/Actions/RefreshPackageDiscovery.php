<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Actions;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\PackageManifest;

/**
 * @internal
 */
final class RefreshPackageDiscovery
{
    /**
     * Delete the cached package manifest and rebuild it.
     */
    public function handle(ApplicationContract $app): void
    {
        $filesystem = new Filesystem;

        $cachedPath = $app->bootstrapPath('cache/packages.php');

        if ($filesystem->exists($cachedPath)) {
            $filesystem->delete($cachedPath);
        }

        $app->make(PackageManifest::class)->build();
    }
}
