<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Workbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\TestCase;
use Hypervel\Testbench\Workbench\Actions\AddAssetSymlinkFolders;
use Hypervel\Testbench\Workbench\Actions\RemoveAssetSymlinkFolders;
use Hypervel\Testing\ParallelTesting;
use Override;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;

use function Hypervel\Testbench\is_symlink;
use function Hypervel\Testbench\join_paths;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\workbench_relative_path;

class ActionsTest extends TestCase
{
    /**
     * The filesystem instance.
     */
    protected Filesystem $filesystem;

    /**
     * The source asset directory.
     */
    protected string $sourcePath;

    /**
     * The published asset path.
     */
    protected string $destinationPath;

    #[Override]
    protected function setUp(): void
    {
        $this->filesystem = new Filesystem;

        $this->afterApplicationCreated(function () {
            $this->sourcePath = package_path(workbench_relative_path('resources'));
            $this->destinationPath = base_path('public/testbench-assets');
            $this->cleanupPublishedPaths();
        });

        $this->beforeApplicationDestroyed(function () {
            $this->cleanupPublishedPaths();
        });

        parent::setUp();
    }

    #[Test]
    public function itDoesNotWipeTargetDirectoryWhileRecreatingAssetSymlink(): void
    {
        $this->filesystem->ensureDirectoryExists(dirname($this->destinationPath));
        $this->filesystem->link($this->sourcePath, $this->destinationPath);

        (new AddAssetSymlinkFolders($this->filesystem, $this->configuration()))->handle();

        $this->assertTrue(is_symlink($this->destinationPath));
        $this->assertSame(realpath($this->sourcePath), realpath($this->destinationPath));
        $this->assertDirectoryExists(join_paths($this->sourcePath, 'views'));
    }

    #[Test]
    public function itDoesNotWipeTargetDirectoryWhileRemovingAssetSymlink(): void
    {
        $this->filesystem->ensureDirectoryExists(dirname($this->destinationPath));
        $this->filesystem->link($this->sourcePath, $this->destinationPath);

        (new RemoveAssetSymlinkFolders($this->filesystem, $this->configuration()))->handle();

        $this->assertFalse(is_symlink($this->destinationPath));
        $this->assertFalse($this->filesystem->exists($this->destinationPath));
        $this->assertDirectoryExists(join_paths($this->sourcePath, 'views'));
    }

    #[Test]
    public function itRestoresTheOriginalDestinationWhenPublicationFails(): void
    {
        $this->filesystem->ensureDirectoryExists($this->destinationPath);
        $this->filesystem->put(join_paths($this->destinationPath, 'original.txt'), 'original');
        $filesystem = new FailingAssetSymlinkMoveFilesystem;

        try {
            (new AddAssetSymlinkFolders($filesystem, $this->configuration()))->handle();
            $this->fail('Expected asset symlink publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame("Unable to publish symlink [{$this->destinationPath}].", $exception->getMessage());
        }

        $this->assertDirectoryExists($this->destinationPath);
        $this->assertFileExists(join_paths($this->destinationPath, 'original.txt'));
        $this->assertFileDoesNotExist($this->stagedPath());
        $this->assertFileDoesNotExist($this->backupPath());
    }

    #[Test]
    public function itOnlyReplacesRealContentInsideTheOwnedRuntimeCopy(): void
    {
        $this->filesystem->ensureDirectoryExists($this->destinationPath);
        $this->filesystem->put(join_paths($this->destinationPath, 'original.txt'), 'original');

        // Treat the destination as part of a persistent custom skeleton.
        $runtimePath = new ReflectionProperty(Bootstrapper::class, 'runtimePath');
        $ownedRuntimePath = $runtimePath->getValue();
        $runtimePath->setValue(null, null);

        try {
            (new AddAssetSymlinkFolders($this->filesystem, $this->configuration()))->handle();
            $this->fail('Expected the persistent destination to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame("Unable to replace [{$this->destinationPath}] because it is not a symlink.", $exception->getMessage());
        } finally {
            $runtimePath->setValue(null, $ownedRuntimePath);
        }

        $this->assertFalse(is_symlink($this->destinationPath));
        $this->assertSame('original', file_get_contents(join_paths($this->destinationPath, 'original.txt')));
        $this->assertFileDoesNotExist($this->stagedPath());

        // The same content inside this process's own copy is disposable.
        (new AddAssetSymlinkFolders($this->filesystem, $this->configuration()))->handle();

        $this->assertTrue(is_symlink($this->destinationPath));
        $this->assertSame(realpath($this->sourcePath), realpath($this->destinationPath));
        $this->assertFileDoesNotExist($this->backupPath());
    }

    #[Test]
    public function itDoesNotReplaceContentReachedThroughALinkOutOfTheRuntimeCopy(): void
    {
        $externalPath = ParallelTesting::tempDir('ActionsTestExternalAssets');
        $linkPath = base_path('public/testbench-external');
        $destinationPath = base_path('public/testbench-external/assets');

        $this->filesystem->deleteDirectory($externalPath);
        $this->filesystem->ensureDirectoryExists(join_paths($externalPath, 'assets'));
        $this->filesystem->put(join_paths($externalPath, 'assets', 'original.txt'), 'original');
        $this->filesystem->link($externalPath, $linkPath);

        try {
            try {
                (new AddAssetSymlinkFolders($this->filesystem, new Config([
                    'workbench' => [
                        'sync' => [[
                            'from' => workbench_relative_path('resources'),
                            'to' => 'public/testbench-external/assets',
                        ]],
                    ],
                ])))->handle();
                $this->fail('Expected the destination outside the runtime copy to be refused.');
            } catch (RuntimeException $exception) {
                $this->assertSame("Unable to replace [{$destinationPath}] because it is not a symlink.", $exception->getMessage());
            }

            $this->assertSame('original', file_get_contents(join_paths($externalPath, 'assets', 'original.txt')));
        } finally {
            if (is_symlink($linkPath)) {
                windows_os() ? @rmdir($linkPath) : $this->filesystem->delete($linkPath);
            }

            $this->filesystem->deleteDirectory($externalPath);
        }
    }

    #[Test]
    public function itKeepsThePublishedLinkAndOriginalBackupWhenBackupDeletionFails(): void
    {
        $this->filesystem->ensureDirectoryExists($this->destinationPath);
        $this->filesystem->put(join_paths($this->destinationPath, 'original.txt'), 'original');

        try {
            (new AddAssetSymlinkFolders(
                new FailingAssetBackupDeleteFilesystem,
                $this->configuration(),
            ))->handle();
            $this->fail('Expected backup deletion to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                "Unable to remove backup [{$this->backupPath()}].",
                $exception->getMessage(),
            );
        }

        $this->assertTrue(is_symlink($this->destinationPath));
        $this->assertSame(realpath($this->sourcePath), realpath($this->destinationPath));
        $this->assertDirectoryExists($this->backupPath());
        $this->assertFileExists(join_paths($this->backupPath(), 'original.txt'));
    }

    #[Test]
    public function itRejectsAnUnownedStagedPathWithoutChangingTheDestination(): void
    {
        $this->filesystem->ensureDirectoryExists($this->destinationPath);
        $this->filesystem->put(join_paths($this->destinationPath, 'original.txt'), 'original');
        $this->filesystem->ensureDirectoryExists($this->stagedPath());

        try {
            (new AddAssetSymlinkFolders($this->filesystem, $this->configuration()))->handle();
            $this->fail('Expected the unowned staged path to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                "Unable to clear staged symlink [{$this->stagedPath()}].",
                $exception->getMessage(),
            );
        }

        $this->assertDirectoryExists($this->stagedPath());
        $this->assertFileExists(join_paths($this->destinationPath, 'original.txt'));
    }

    #[Test]
    public function itClearsAnOwnedStagedLinkLeftByAnInterruptedPublication(): void
    {
        $this->filesystem->ensureDirectoryExists(dirname($this->destinationPath));
        $this->filesystem->link($this->sourcePath, $this->stagedPath());

        (new AddAssetSymlinkFolders($this->filesystem, $this->configuration()))->handle();

        $this->assertFalse(is_symlink($this->stagedPath()));
        $this->assertTrue(is_symlink($this->destinationPath));
        $this->assertSame(realpath($this->sourcePath), realpath($this->destinationPath));
    }

    #[Test]
    public function itRejectsAStageThatWasNotCreated(): void
    {
        try {
            (new AddAssetSymlinkFolders(
                new FailingAssetSymlinkLinkFilesystem,
                $this->configuration(),
            ))->handle();
            $this->fail('Expected asset symlink staging to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame("Unable to stage symlink [{$this->stagedPath()}].", $exception->getMessage());
        }

        $this->assertFalse(is_symlink($this->destinationPath));
        $this->assertFalse(is_symlink($this->stagedPath()));
    }

    #[Test]
    public function itLeavesAnUnownedDestinationLinkIntact(): void
    {
        $otherTarget = base_path('storage');
        $this->filesystem->ensureDirectoryExists(dirname($this->destinationPath));
        $this->filesystem->link($otherTarget, $this->destinationPath);

        (new RemoveAssetSymlinkFolders($this->filesystem, $this->configuration()))->handle();

        $this->assertTrue(is_symlink($this->destinationPath));
        $this->assertSame(realpath($otherTarget), realpath($this->destinationPath));
    }

    #[Test]
    public function itReportsAnOwnedLinkThatCouldNotBeRemoved(): void
    {
        $this->filesystem->ensureDirectoryExists(dirname($this->destinationPath));
        $this->filesystem->link($this->sourcePath, $this->destinationPath);
        $this->filesystem->link($this->sourcePath, $this->secondDestinationPath());

        try {
            (new RemoveAssetSymlinkFolders(
                new FailingAssetSymlinkDeleteFilesystem($this->destinationPath),
                new Config([
                    'workbench' => [
                        'sync' => [
                            ['from' => workbench_relative_path('resources'), 'to' => 'public/testbench-assets'],
                            ['from' => workbench_relative_path('resources'), 'to' => 'public/testbench-assets-second'],
                        ],
                    ],
                ]),
            ))->handle();
            $this->fail('Expected asset symlink removal to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                "Unable to remove asset symlink [{$this->destinationPath}].",
                $exception->getMessage(),
            );
        }

        $this->assertTrue(is_symlink($this->destinationPath));
        $this->assertSame(realpath($this->sourcePath), realpath($this->destinationPath));

        // One failed removal doesn't leave the remaining links behind.
        $this->assertFalse(is_symlink($this->secondDestinationPath()));
    }

    /**
     * Get the Workbench asset synchronization configuration.
     */
    protected function configuration(): ConfigContract
    {
        return new Config([
            'workbench' => [
                'sync' => [[
                    'from' => workbench_relative_path('resources'),
                    'to' => 'public/testbench-assets',
                ]],
            ],
        ]);
    }

    /**
     * Get a second published asset path.
     */
    protected function secondDestinationPath(): string
    {
        return base_path('public/testbench-assets-second');
    }

    /**
     * Get the staged asset symlink path.
     */
    protected function stagedPath(): string
    {
        return join_paths(dirname($this->destinationPath), '.' . basename($this->destinationPath) . '.staged');
    }

    /**
     * Get the asset backup path.
     */
    protected function backupPath(): string
    {
        return join_paths(dirname($this->destinationPath), '.' . basename($this->destinationPath) . '.backup');
    }

    /**
     * Remove every path owned by this test.
     */
    protected function cleanupPublishedPaths(): void
    {
        foreach ([$this->destinationPath, $this->secondDestinationPath(), $this->stagedPath(), $this->backupPath()] as $path) {
            if (is_symlink($path)) {
                windows_os() ? @rmdir($path) : $this->filesystem->delete($path);
            } elseif ($this->filesystem->isDirectory($path)) {
                $this->filesystem->deleteDirectory($path);
            } elseif ($this->filesystem->exists($path)) {
                $this->filesystem->delete($path);
            }
        }
    }
}

class FailingAssetSymlinkMoveFilesystem extends Filesystem
{
    /**
     * Move a file to a new location.
     */
    public function move(string $path, string $target): bool
    {
        return str_ends_with($path, '.staged')
            ? false
            : parent::move($path, $target);
    }
}

class FailingAssetSymlinkLinkFilesystem extends Filesystem
{
    /**
     * Create a symlink to the target file or directory.
     */
    public function link(string $target, string $link): ?bool
    {
        return false;
    }
}

class FailingAssetBackupDeleteFilesystem extends Filesystem
{
    /**
     * Fail only deletion of the displaced original.
     */
    public function deleteDirectory(string $directory, bool $preserve = false): bool
    {
        return str_ends_with($directory, '.backup')
            ? false
            : parent::deleteDirectory($directory, $preserve);
    }
}

class FailingAssetSymlinkDeleteFilesystem extends Filesystem
{
    /**
     * Construct the filesystem.
     */
    public function __construct(
        private readonly string $failingPath,
    ) {
    }

    /**
     * Delete the file at a given path.
     */
    public function delete(array|string $paths): bool
    {
        return $paths === $this->failingPath
            ? false
            : parent::delete($paths);
    }
}
