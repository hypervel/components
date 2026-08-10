<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Console\PurgeSkeletonCommand;
use Hypervel\Testbench\Foundation\Console\TerminatingConsole;
use Hypervel\Testbench\TestbenchServiceProvider;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Testbench\Concerns\PreservesSkeletonFiles;
use Hypervel\Tests\Testbench\Fixtures\Providers\Phase2ConsoleServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\package_path;

#[RequiresOperatingSystem('Linux|Darwin')]
class PurgeSkeletonCommandTest extends TestCase
{
    use PreservesSkeletonFiles;

    private Filesystem $filesystem;

    private string $commandPath;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->commandPath = ParallelTesting::tempDir('PurgeSkeletonCommandTest');
        $this->filesystem->deleteDirectory($this->commandPath);
        $this->filesystem->makeDirectory($this->commandPath, 0700, recursive: true);

        $this->preserveFiles([
            $this->app->basePath('.env'),
            $this->app->basePath('.env.backup'),
        ]);
    }

    #[Override]
    protected function tearDown(): void
    {
        TerminatingConsole::flush();
        $this->cleanUpPurgeSkeletonArtifacts();
        $this->restorePreservedFiles();
        $this->filesystem->deleteDirectory($this->commandPath);

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
    public function itCanPurgeTheSkeletonBackToACleanState(): void
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
        $retainedStorageFile = $this->app->storagePath(join_paths('app', 'retained', 'file.txt'));
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
        $this->writeFile($retainedStorageFile, 'retained');
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
        $this->assertDirectoryExists($this->app->storagePath(join_paths('app', 'public')));
        $this->assertFileExists($retainedStorageFile);
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

    #[Test]
    public function itRunsEveryClearCommandAndLaterCleanupAfterACommandFails(): void
    {
        $purgeFile = join_paths($this->commandPath, 'purge.txt');
        $this->filesystem->put($purgeFile, 'purge');
        $config = new Config([
            'purge' => ['files' => ['purge.txt'], 'directories' => []],
            'workbench' => ['sync' => []],
        ]);
        $command = new PurgeSkeletonCommandHarness(['config:clear' => PurgeSkeletonCommand::FAILURE]);
        $command->setHypervel(new Application($this->commandPath));

        $this->assertSame(PurgeSkeletonCommand::FAILURE, $command->handle($this->filesystem, $config));
        $this->assertSame(
            ['config:clear', 'event:clear', 'route:clear', 'view:clear'],
            $command->calls,
        );
        $this->assertFileDoesNotExist($purgeFile);
    }

    #[Test]
    public function itRunsLaterCleanupAfterAnActionFails(): void
    {
        $environmentFile = join_paths($this->commandPath, '.env');
        $laterFile = join_paths($this->commandPath, 'later.txt');
        $filesystem = new PurgeSkeletonFilesystem($environmentFile);
        $filesystem->put($environmentFile, 'APP_ENV=testing');
        $filesystem->put($laterFile, 'later');
        $config = new Config([
            'purge' => ['files' => ['later.txt'], 'directories' => []],
            'workbench' => ['sync' => []],
        ]);
        $command = new PurgeSkeletonCommandHarness([]);
        $command->setHypervel(new Application($this->commandPath));

        $this->assertSame(PurgeSkeletonCommand::FAILURE, $command->handle($filesystem, $config));
        $this->assertFileExists($environmentFile);
        $this->assertFileDoesNotExist($laterFile);
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
            $this->app->storagePath(join_paths('app', 'retained')),
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
}

class PurgeSkeletonCommandHarness extends PurgeSkeletonCommand
{
    /** @var array<string, int> */
    public array $statuses;

    /** @var list<string> */
    public array $calls = [];

    /**
     * @param array<string, int> $statuses
     */
    public function __construct(array $statuses)
    {
        parent::__construct();

        $this->statuses = $statuses;
    }

    public function call(SymfonyCommand|string $command, array $arguments = []): int
    {
        $name = is_string($command) ? $command : $command->getName();
        $this->calls[] = $name;

        return $this->statuses[$name] ?? self::SUCCESS;
    }
}

class PurgeSkeletonFilesystem extends Filesystem
{
    public function __construct(private string $failedPath)
    {
    }

    public function delete(array|string $paths): bool
    {
        return $paths === $this->failedPath ? false : parent::delete($paths);
    }
}
