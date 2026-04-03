<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\TestbenchServiceProvider;
use Hypervel\Tests\Testbench\Fixtures\Providers\Phase2ConsoleServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\testbench_path;

/**
 * @internal
 * @coversNothing
 */
#[RequiresOperatingSystem('Linux|Darwin')]
class SyncSkeletonCommandTest extends TestCase
{
    private Filesystem $filesystem;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->cleanUpSyncSkeletonArtifacts();

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
    public function itCanSyncTheSkeletonFiles()
    {
        $config = $this->app->make(ConfigContract::class);

        $config['workbench'] = [
            'sync' => [
                [
                    'from' => 'src/testbench/workbench/storage',
                    'to' => 'public/testbench-storage',
                ],
            ],
        ];

        $testbenchCacheFile = $this->app->basePath(join_paths('bootstrap', 'cache', 'testbench.yaml'));
        $environmentFile = $this->app->basePath('.env');
        $symlinkPath = $this->app->basePath(join_paths('public', 'testbench-storage'));

        $this->deletePath($testbenchCacheFile);
        $this->deletePath($environmentFile);
        $this->deletePath($symlinkPath);

        $this->artisan('package:sync-skeleton')->assertOk();

        $this->assertFileExists($testbenchCacheFile);
        $this->assertFileExists($environmentFile);
        $this->assertTrue(is_link($symlinkPath));
        $this->assertSame(file_get_contents(testbench_path('testbench.yaml')), file_get_contents($testbenchCacheFile));
        $this->assertSame(file_get_contents($this->app->basePath('.env.example')), file_get_contents($environmentFile));
        $this->assertSame(package_path('src/testbench/workbench/storage'), readlink($symlinkPath));
    }

    #[Test]
    #[Depends('itCanSyncTheSkeletonFiles')]
    public function itDoesNotLeakMutatedWorkbenchConfigAcrossTests()
    {
        $config = $this->app->make(ConfigContract::class);

        $this->assertTrue($config->getWorkbenchAttributes()['discovers']['web']);
        $this->assertTrue($config->getWorkbenchAttributes()['discovers']['api']);
        $this->assertSame([
            [
                'from' => 'storage',
                'to' => 'workbench/storage',
                'reverse' => true,
            ],
        ], $config->getWorkbenchAttributes()['sync']);
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

    /**
     * Remove every runtime artifact this test may create inside the shared worker skeleton.
     */
    private function cleanUpSyncSkeletonArtifacts(): void
    {
        foreach ($this->syncSkeletonArtifactPaths() as $path) {
            $this->deletePath($path);
        }
    }

    /**
     * Get every runtime path that this test may create.
     *
     * @return array<int, string>
     */
    private function syncSkeletonArtifactPaths(): array
    {
        return [
            $this->app->basePath(join_paths('bootstrap', 'cache', 'testbench.yaml')),
            $this->app->basePath('.env'),
            $this->app->basePath(join_paths('public', 'testbench-storage')),
        ];
    }
}
