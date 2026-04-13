<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Workbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\TestCase;
use Hypervel\Testbench\Workbench\Actions\AddAssetSymlinkFolders;
use Hypervel\Testbench\Workbench\Actions\RemoveAssetSymlinkFolders;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\default_skeleton_path;
use function Hypervel\Testbench\is_symlink;
use function Hypervel\Testbench\join_paths;
use function Hypervel\Testbench\workbench_path;
use function Hypervel\Testbench\workbench_relative_path;

/**
 * @internal
 * @coversNothing
 */
class ActionsTest extends TestCase
{
    /**
     * The filesystem instance.
     */
    protected Filesystem $filesystem;

    /**
     * The runtime skeleton path.
     */
    protected string $skeletonPath;

    /**
     * The backup path for the workbench storage directory.
     */
    protected ?string $workbenchStorageBackupPath = null;

    #[Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem;

        $this->afterApplicationCreated(function () {
            $this->skeletonPath = (string) default_skeleton_path();

            $this->filesystem->copyDirectory(
                join_paths($this->skeletonPath, 'storage'),
                join_paths($this->skeletonPath, 'storage.bak'),
            );

            $this->ensureSymlinkExists();
        });

        $this->beforeApplicationDestroyed(function () {
            if (! default_skeleton_path('storage', 'framework')) {
                $this->filesystem->moveDirectory(
                    join_paths($this->skeletonPath, 'storage.bak'),
                    join_paths($this->skeletonPath, 'storage'),
                    true,
                );
            } else {
                $this->filesystem->deleteDirectory(join_paths($this->skeletonPath, 'storage.bak'));
            }

            $this->restoreWorkbenchStorageDirectory();
        });

        parent::setUp();
    }

    #[Test]
    public function itDoesNotWipeTargetDirectoryWhileRecreatingAssetSymlink()
    {
        (new AddAssetSymlinkFolders($this->filesystem, static::cachedConfigurationForWorkbench()))->handle();

        $this->assertDirectoryExists(join_paths(default_skeleton_path(), 'storage', 'framework'));
    }

    #[Test]
    public function itDoesNotWipeTargetDirectoryWhileRemovingAssetSymlink()
    {
        (new RemoveAssetSymlinkFolders($this->filesystem, static::cachedConfigurationForWorkbench()))->handle();

        $this->assertDirectoryExists(join_paths(default_skeleton_path(), 'storage', 'framework'));
    }

    /**
     * Ensure symlink directory exists for the test.
     */
    protected function ensureSymlinkExists(): void
    {
        $workbenchStoragePath = workbench_path('storage');

        if (is_symlink($workbenchStoragePath)) {
            return;
        }

        if ($this->filesystem->isDirectory($workbenchStoragePath)) {
            $this->workbenchStorageBackupPath = workbench_path('storage.bak');

            if ($this->filesystem->isDirectory($this->workbenchStorageBackupPath)) {
                $this->filesystem->deleteDirectory($this->workbenchStorageBackupPath);
            }

            $this->filesystem->moveDirectory($workbenchStoragePath, $this->workbenchStorageBackupPath, true);
        }

        $this->filesystem->link(join_paths($this->skeletonPath, 'storage'), $workbenchStoragePath);
    }

    /**
     * Restore the workbench storage directory after the test.
     */
    protected function restoreWorkbenchStorageDirectory(): void
    {
        $workbenchStoragePath = workbench_path('storage');

        if (is_symlink($workbenchStoragePath)) {
            windows_os() ? @rmdir($workbenchStoragePath) : $this->filesystem->delete($workbenchStoragePath);
        } elseif ($this->filesystem->isDirectory($workbenchStoragePath)) {
            $this->filesystem->deleteDirectory($workbenchStoragePath);
        }

        if (
            is_string($this->workbenchStorageBackupPath)
            && $this->filesystem->isDirectory($this->workbenchStorageBackupPath)
        ) {
            $this->filesystem->moveDirectory($this->workbenchStorageBackupPath, $workbenchStoragePath, true);
        }

        $this->workbenchStorageBackupPath = null;
    }

    /**
     * Get the cached workbench configuration for the test case.
     */
    public static function cachedConfigurationForWorkbench(): ConfigContract
    {
        return new Config([
            'workbench' => [
                'sync' => [[
                    'from' => 'storage',
                    'to' => workbench_relative_path('storage'),
                    'reverse' => true,
                ]],
            ],
        ]);
    }
}
