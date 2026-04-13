<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\Foundation\Actions\CreateVendorSymlink as CreateVendorSymlinkAction;

/**
 * @api
 */
final class CreateVendorSymlink
{
    /**
     * Construct a new Create Vendor Symlink bootstrapper.
     */
    public function __construct(
        private readonly string $workingPath
    ) {
    }

    /**
     * Bootstrap the given application.
     */
    public function bootstrap(Application $app): void
    {
        (new CreateVendorSymlinkAction($this->workingPath))->handle($app);
    }
}
