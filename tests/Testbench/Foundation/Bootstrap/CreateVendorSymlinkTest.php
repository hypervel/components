<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Actions\DeleteVendorSymlink;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Hypervel\Testbench\Foundation\Bootstrap\CreateVendorSymlink;
use Hypervel\Testbench\PHPUnit\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\default_skeleton_path;
use function Hypervel\Testbench\hypervel_vendor_exists;
use function Hypervel\Testbench\package_path;

/**
 * @internal
 * @coversNothing
 */
class CreateVendorSymlinkTest extends TestCase
{
    private ?ApplicationContract $application = null;

    #[Override]
    protected function tearDown(): void
    {
        if ($this->application !== null) {
            (new DeleteVendorSymlink())->handle($this->application);
            $this->application->flush();
        }

        TestbenchApplication::flushState($this);

        parent::tearDown();
    }

    #[Test]
    public function itCanCreateVendorSymlink(): void
    {
        $workingPath = package_path('vendor');
        $application = $this->createApplication();

        if (hypervel_vendor_exists($application, $workingPath)) {
            (new DeleteVendorSymlink())->handle($application);
        }

        (new CreateVendorSymlink($workingPath))->bootstrap($application);

        $this->assertTrue($application['TESTBENCH_VENDOR_SYMLINK']);
    }

    #[Test]
    public function itCanSkipExistingVendorSymlink(): void
    {
        $workingPath = package_path('vendor');
        $application = $this->createApplication();

        if (! hypervel_vendor_exists($application, $workingPath)) {
            (new Filesystem())->link($workingPath, $application->basePath('vendor'));
        }

        (new CreateVendorSymlink($workingPath))->bootstrap($application);

        $this->assertFalse($application['TESTBENCH_VENDOR_SYMLINK']);
    }

    private function createApplication(): ApplicationContract
    {
        return $this->application = TestbenchApplication::create((string) default_skeleton_path());
    }
}
