<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Attributes;

use Attribute;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Contracts\Attributes\AfterEach;
use Hypervel\Testbench\Contracts\Attributes\BeforeEach;
use Hypervel\Testbench\Foundation\Actions\CreateVendorSymlink;
use Hypervel\Testbench\Foundation\Actions\DeleteVendorSymlink;

use function Hypervel\Testbench\package_path;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class UsesVendor implements AfterEach, BeforeEach
{
    public bool $vendorSymlinkCreated = false;

    public function beforeEach(ApplicationContract $app): void
    {
        $hypervel = clone $app;

        (new CreateVendorSymlink(package_path('vendor')))->handle($hypervel);

        $this->vendorSymlinkCreated = $hypervel['TESTBENCH_VENDOR_SYMLINK'] ?? false;
    }

    public function afterEach(ApplicationContract $app): void
    {
        if ($this->vendorSymlinkCreated === true) {
            (new DeleteVendorSymlink)->handle($app);
        }
    }
}
