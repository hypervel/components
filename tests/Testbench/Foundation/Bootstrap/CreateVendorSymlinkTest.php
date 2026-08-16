<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Bootstrap;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Testbench\Foundation\Actions\CreateVendorSymlink as CreateVendorSymlinkAction;
use Hypervel\Testbench\Foundation\Actions\DeleteVendorSymlink;
use Hypervel\Testbench\Foundation\Actions\RefreshPackageDiscovery;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Hypervel\Testbench\Foundation\Bootstrap\CreateVendorSymlink;
use Hypervel\Testbench\PHPUnit\TestCase;
use Mockery as m;
use Override;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

use function Hypervel\Testbench\default_skeleton_path;
use function Hypervel\Testbench\hypervel_vendor_exists;
use function Hypervel\Testbench\package_path;

class CreateVendorSymlinkTest extends TestCase
{
    private ?ApplicationContract $application = null;

    private bool $ownsVendorDirectory = false;

    private ?string $manifestDirectory = null;

    #[Override]
    protected function tearDown(): void
    {
        if ($this->application !== null) {
            (new DeleteVendorSymlink)->handle($this->application);

            if ($this->ownsVendorDirectory) {
                (new Filesystem)->deleteDirectory($this->application->basePath('vendor'));
            }

            if ($this->manifestDirectory !== null) {
                (new Filesystem)->deleteDirectory($this->manifestDirectory);
            }

            $this->application->flush();
        }

        parent::tearDown();
    }

    #[Test]
    public function itCanCreateVendorSymlink(): void
    {
        $workingPath = package_path('vendor');
        $application = $this->createApplication();
        $config = $application->make('config');
        $terminated = false;
        $application->terminating(static function () use (&$terminated): void {
            $terminated = true;
        });

        if (hypervel_vendor_exists($application, $workingPath)) {
            (new DeleteVendorSymlink)->handle($application);
        }

        (new CreateVendorSymlink($workingPath))->bootstrap($application);

        $this->assertTrue($application->make('TESTBENCH_VENDOR_SYMLINK'));
        $this->assertSame($config, $application->make('config'));

        $application->terminate();

        $this->assertTrue($terminated);
    }

    #[Test]
    public function itCanSkipExistingVendorSymlink(): void
    {
        $workingPath = package_path('vendor');
        $application = $this->createApplication();

        if (! hypervel_vendor_exists($application, $workingPath)) {
            (new Filesystem)->link($workingPath, $application->basePath('vendor'));
        }

        (new CreateVendorSymlink($workingPath))->bootstrap($application);

        $this->assertFalse($application->make('TESTBENCH_VENDOR_SYMLINK'));
    }

    #[Test]
    public function itRemovesItsVendorLinkWhenPackageDiscoveryFails(): void
    {
        $workingPath = package_path('vendor');
        $application = $this->createApplication();
        (new DeleteVendorSymlink)->handle($application);
        $manifest = m::mock(PackageManifest::class);
        $manifest->shouldReceive('build')
            ->once()
            ->andThrow(new RuntimeException('Package discovery failed.'));
        $application->instance(PackageManifest::class, $manifest);

        try {
            (new CreateVendorSymlinkAction($workingPath))->handle($application);
            $this->fail('Expected package discovery to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Package discovery failed.', $exception->getMessage());
        }

        $this->assertFalse(is_link($application->basePath('vendor')));
        $this->assertFalse(file_exists($application->basePath('vendor')));
    }

    #[Test]
    public function itDoesNotDeleteARealVendorDirectoryWhenLinkCreationFails(): void
    {
        $workingPath = package_path('vendor');
        $application = $this->createApplication();
        $filesystem = new Filesystem;
        (new DeleteVendorSymlink)->handle($application);
        $filesystem->ensureDirectoryExists($application->basePath('vendor'));
        $filesystem->put($application->basePath('vendor/owned.txt'), 'owned');
        $this->ownsVendorDirectory = true;
        $caughtException = null;

        try {
            (new CreateVendorSymlinkAction($workingPath))->handle($application);
        } catch (Throwable $exception) {
            $caughtException = $exception;
        }

        $this->assertNotNull($caughtException);
        $this->assertFalse(is_link($application->basePath('vendor')));
        $this->assertDirectoryExists($application->basePath('vendor'));
        $this->assertFileExists($application->basePath('vendor/owned.txt'));
    }

    #[Test]
    #[RequiresOperatingSystem('Linux')]
    public function itReportsADeletionFailureThroughTheNamedException(): void
    {
        $application = m::mock(ApplicationContract::class);
        $vendorPath = '/proc/self/fd/0';
        $application->shouldReceive('basePath')->once()->with('vendor')->andReturn($vendorPath);
        $this->assertTrue(is_link($vendorPath));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to remove vendor symlink [{$vendorPath}].");

        (new DeleteVendorSymlink)->handle($application);
    }

    #[Test]
    public function itDoesNotRebuildDiscoveryWhenTheManifestCannotBeDeleted(): void
    {
        $application = $this->createApplication();
        $filesystem = new Filesystem;
        $cachedPath = $application->bootstrapPath('cache/packages.php');

        if ($filesystem->exists($cachedPath)) {
            $filesystem->delete($cachedPath);
        }

        $filesystem->ensureDirectoryExists($cachedPath);
        $this->manifestDirectory = $cachedPath;
        $manifest = m::mock(PackageManifest::class);
        $manifest->shouldNotReceive('build');
        $application->instance(PackageManifest::class, $manifest);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to delete package manifest [{$cachedPath}].");

        (new RefreshPackageDiscovery)->handle($application);
    }

    private function createApplication(): ApplicationContract
    {
        return $this->application = TestbenchApplication::create((string) default_skeleton_path());
    }
}
