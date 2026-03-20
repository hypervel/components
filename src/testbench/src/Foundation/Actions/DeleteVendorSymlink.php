<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Actions;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;

use function Hypervel\Testbench\is_symlink;

/**
 * @internal
 */
final class DeleteVendorSymlink
{
    public function handle(ApplicationContract $app): void
    {
        tap($app->basePath('vendor'), static function (string $appVendorPath): void {
            if (is_symlink($appVendorPath)) {
                windows_os() ? @rmdir($appVendorPath) : @unlink($appVendorPath);
            }

            clearstatcache(false, dirname($appVendorPath));
        });
    }
}
