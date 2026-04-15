<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Console\TerminatingConsole;
use Hypervel\Testbench\TestbenchServiceProvider;
use Hypervel\Tests\Testbench\Fixtures\Providers\Phase2ConsoleServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\package_path;

#[RequiresOperatingSystem('Linux|Darwin')]
class PurgeSkeletonCommandTest extends TestCase
{
    private Filesystem $filesystem;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
    }

    #[Override]
    protected function tearDown(): void
    {
        TerminatingConsole::flush();
        $this->cleanUpPurgeSkeletonArtifacts();

        parent::tearDown();
    }

    /**
     * Get package providers.
     *
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            TestbenchServiceProvider::class,
            Phase2ConsoleServiceProvider::class,
        ];
    }

    #[Test]
    public function itCanPurgeTheSkeletonBackToACleanState()
    {
        $config = $this->app->make(ConfigContract::class);
        $purge = $config->getPurgeAttributes();

        $config['purge'] = [
            'files' => [...$purge['files'], 'purge-me.txt', 'purge-*.log'],
            'directories' => [...$purge['directories'], 'purge-dir', 'purge-dir-*'],
        ];

        $environmentFile = $this->app->basePath('.env');
        $environmentBackupFile = $this->app->basePath('.env.backup');
        $testbenchCacheFile = $this->app->basePath(join_paths('bootstrap', 'cache', 'testbench.yaml'));
        $testbenchCacheBackupFile = $this->app->basePath(join_paths('bootstrap', 'cache', 'testbench.yaml.backup'));
        $sqliteFile = $this->app->databasePath('database.sqlite');
        $routesFile = $this->app->basePath(join_paths('routes', 'testbench-demo.php'));
        $storagePublicFile = $this->app->storagePath(join_paths('app', 'public', 'asset.txt'));
        $storageFile = $this->app->storagePath(join_paths('app', 'cache.txt'));
        $sessionFile = $this->app->storagePath(join_paths('framework', 'sessions', 'session.txt'));
        $purgeFile = $this->app->basePath('purge-me.txt');
        $purgeWildcardFile = $this->app->basePath('purge-test.log');
        $aopDirectory = $this->app->storagePath(join_paths('framework', 'aop'));
        $buildDirectory = $this->app->basePath(join_paths('public', 'build'));
        $vendorDirectory = $this->app->basePath(join_paths('public', 'vendor', 'package'));
        $purgeDirectory = $this->app->basePath('purge-dir');
        $purgeWildcardDirectory = $this->app->basePath('purge-dir-temp');
        $vendorSymlink = $this->app->basePath('vendor');

        $this->writeFile($environmentFile, 'APP_ENV=testing');
        $this->writeFile($environmentBackupFile, 'APP_ENV=backup');
        $this->writeFile($testbenchCacheFile, 'cached');
        $this->writeFile($testbenchCacheBackupFile, 'cached-backup');
        $this->writeFile($sqliteFile, 'sqlite');
        $this->writeFile($routesFile, '<?php');
        $this->writeFile($storagePublicFile, 'asset');
        $this->writeFile($storageFile, 'storage');
        $this->writeFile($sessionFile, 'session');
        $this->writeFile($purgeFile, 'purge');
        $this->writeFile($purgeWildcardFile, 'purge-wildcard');
        $this->writeFile(join_paths($aopDirectory, 'Proxy_Class.proxy.php'), '<?php');
        $this->writeFile(join_paths($buildDirectory, 'manifest.json'), '{}');
        $this->writeFile(join_paths($vendorDirectory, 'asset.txt'), 'vendor');
        $this->writeFile(join_paths($purgeDirectory, 'file.txt'), 'purge-directory');
        $this->writeFile(join_paths($purgeWildcardDirectory, 'file.txt'), 'purge-wildcard-directory');

        if (is_link($vendorSymlink)) {
            unlink($vendorSymlink);
        } elseif ($this->filesystem->isDirectory($vendorSymlink)) {
            $this->filesystem->deleteDirectory($vendorSymlink);
        }

        symlink(package_path('vendor'), $vendorSymlink);

        $this->assertTrue(is_link($vendorSymlink));

        $this->artisan('package:purge-skeleton')->assertOk();
        TerminatingConsole::handle();

        $this->assertFileDoesNotExist($environmentFile);
        $this->assertFileDoesNotExist($environmentBackupFile);
        $this->assertFileDoesNotExist($testbenchCacheFile);
        $this->assertFileDoesNotExist($testbenchCacheBackupFile);
        $this->assertFileDoesNotExist($sqliteFile);
        $this->assertFileDoesNotExist($routesFile);
        $this->assertFileDoesNotExist($storagePublicFile);
        $this->assertFileDoesNotExist($storageFile);
        $this->assertFileDoesNotExist($sessionFile);
        $this->assertFileDoesNotExist($purgeFile);
        $this->assertFileDoesNotExist($purgeWildcardFile);
        $this->assertDirectoryDoesNotExist($aopDirectory);
        $this->assertDirectoryDoesNotExist($buildDirectory);
        $this->assertDirectoryDoesNotExist($vendorDirectory);
        $this->assertDirectoryDoesNotExist($purgeDirectory);
        $this->assertDirectoryDoesNotExist($purgeWildcardDirectory);
        $this->assertFileDoesNotExist($vendorSymlink);
    }

    /**
     * Write a file into the disposable testbench application.
     */
    private function writeFile(string $path, string $contents): void
    {
        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->put($path, $contents);
    }

    /**
     * Remove every artifact this test creates inside the shared worker skeleton.
     */
    private function cleanUpPurgeSkeletonArtifacts(): void
    {
        foreach ($this->purgeSkeletonArtifactPaths() as $path) {
            $this->deletePath($path);
        }
    }

    /**
     * Get every path that this test may create.
     *
     * @return array<int, string>
     */
    private function purgeSkeletonArtifactPaths(): array
    {
        return [
            $this->app->basePath('.env'),
            $this->app->basePath('.env.backup'),
            $this->app->basePath(join_paths('bootstrap', 'cache', 'testbench.yaml')),
            $this->app->basePath(join_paths('bootstrap', 'cache', 'testbench.yaml.backup')),
            $this->app->databasePath('database.sqlite'),
            $this->app->basePath(join_paths('routes', 'testbench-demo.php')),
            $this->app->storagePath(join_paths('app', 'public', 'asset.txt')),
            $this->app->storagePath(join_paths('app', 'cache.txt')),
            $this->app->storagePath(join_paths('framework', 'sessions', 'session.txt')),
            $this->app->basePath('purge-me.txt'),
            $this->app->basePath('purge-test.log'),
            $this->app->storagePath(join_paths('framework', 'aop')),
            $this->app->basePath(join_paths('public', 'build')),
            $this->app->basePath(join_paths('public', 'vendor', 'package')),
            $this->app->basePath('purge-dir'),
            $this->app->basePath('purge-dir-temp'),
            $this->app->basePath('vendor'),
        ];
    }

    /**
     * Delete a file, directory, or symlink if it exists.
     */
    private function deletePath(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }

        if ($this->filesystem->isDirectory($path)) {
            $this->filesystem->deleteDirectory($path);

            return;
        }

        if ($this->filesystem->exists($path)) {
            $this->filesystem->delete($path);
        }
    }
}
