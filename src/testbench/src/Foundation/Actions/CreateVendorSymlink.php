<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Actions;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

use function Hypervel\Testbench\hypervel_vendor_exists;
use function Hypervel\Testbench\is_symlink;

/**
 * @internal
 */
final class CreateVendorSymlink
{
    public function __construct(
        private readonly string $workingPath
    ) {
    }

    /**
     * Create a vendor symlink for the application.
     */
    public function handle(ApplicationContract $app): void
    {
        $filesystem = new Filesystem;
        $appVendorPath = $app->basePath('vendor');
        $vendorLinkCreated = false;

        if (! hypervel_vendor_exists($app, $this->workingPath)) {
            (new DeleteVendorSymlink)->handle($app);

            $filesystem->link($this->workingPath, $appVendorPath);

            if (! is_symlink($appVendorPath)
                || realpath($appVendorPath) !== realpath($this->workingPath)) {
                throw new RuntimeException("Unable to create vendor symlink [{$appVendorPath}].");
            }

            $vendorLinkCreated = true;

            try {
                (new RefreshPackageDiscovery)->handle($app);
            } catch (Throwable $throwable) {
                try {
                    (new DeleteVendorSymlink)->handle($app);
                } catch (Throwable) {
                    // Preserve the package-discovery failure when owned-link cleanup also fails.
                }

                throw $throwable;
            }
        }

        $app->instance('TESTBENCH_VENDOR_SYMLINK', $vendorLinkCreated);
    }
}
