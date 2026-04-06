<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Actions;

use ErrorException;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;

use function Hypervel\Testbench\hypervel_vendor_exists;

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

            try {
                $filesystem->link($this->workingPath, $appVendorPath);

                (new RefreshPackageDiscovery)->handle($app);

                $vendorLinkCreated = true;
            } catch (ErrorException) {
            }
        }

        $app->flush();
        $app->instance('TESTBENCH_VENDOR_SYMLINK', $vendorLinkCreated);
    }
}
