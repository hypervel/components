<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Actions;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use RuntimeException;

use function Hypervel\Testbench\is_symlink;

/**
 * @internal
 */
final class DeleteVendorSymlink
{
    /**
     * Delete the vendor symlink from the application.
     */
    public function handle(ApplicationContract $app): void
    {
        tap($app->basePath('vendor'), static function (string $appVendorPath): void {
            if (is_symlink($appVendorPath)) {
                $deleted = windows_os() ? @rmdir($appVendorPath) : @unlink($appVendorPath);
                clearstatcache(false, $appVendorPath);

                if (! $deleted || is_symlink($appVendorPath) || file_exists($appVendorPath)) {
                    throw new RuntimeException("Unable to remove vendor symlink [{$appVendorPath}].");
                }
            }

            clearstatcache(false, dirname($appVendorPath));
        });
    }
}
