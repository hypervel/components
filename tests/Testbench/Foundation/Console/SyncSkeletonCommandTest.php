<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Console\SyncSkeletonCommand;
use Hypervel\Testbench\Foundation\Console\TerminatingConsole;
use Hypervel\Testbench\TestbenchServiceProvider;
use Hypervel\Tests\Testbench\Concerns\PreservesSkeletonFiles;
use Hypervel\Tests\Testbench\Fixtures\Providers\Phase2ConsoleServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionProperty;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Tester\ApplicationTester;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\testbench_path;

#[RequiresOperatingSystem('Linux|Darwin')]
class SyncSkeletonCommandTest extends TestCase
{
    use PreservesSkeletonFiles;

    private Filesystem $filesystem;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;

        $this->preserveFiles([
            $this->app->basePath('.env'),
        ]);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->cleanUpSyncSkeletonArtifacts();
        $this->restorePreservedFiles();

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
    public function itCanSyncTheSkeletonFiles(): void
    {
        $config = $this->app->make(ConfigContract::class);
        $terminatingCallbackCalled = false;

        TerminatingConsole::before(function () use (&$terminatingCallbackCalled): void {
            $terminatingCallbackCalled = true;
        });

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

        // A persistent skeleton is one this process did not create.
        $runtimePath = new ReflectionProperty(Bootstrapper::class, 'runtimePath');
        $ownedRuntimePath = $runtimePath->getValue();
        $runtimePath->setValue(null, null);

        try {
            $this->artisan('package:sync-skeleton')->assertOk();
        } finally {
            $runtimePath->setValue(null, $ownedRuntimePath);
        }

        $this->assertFileExists($testbenchCacheFile);
        $this->assertFileExists($environmentFile);
        $this->assertTrue(is_link($symlinkPath));
        $this->assertSame(file_get_contents(testbench_path('testbench.yaml')), file_get_contents($testbenchCacheFile));
        $this->assertSame(file_get_contents($this->app->basePath('.env.example')), file_get_contents($environmentFile));
        $this->assertSame(package_path('src/testbench/workbench/storage'), readlink($symlinkPath));

        TerminatingConsole::handle();

        $this->assertFalse($terminatingCallbackCalled);
    }

    #[Test]
    public function itRejectsSyncingTheRuntimeSkeletonOwnedByTheProcess(): void
    {
        $config = $this->app->make(ConfigContract::class);
        $terminatingCallbackCalled = false;

        TerminatingConsole::before(function () use (&$terminatingCallbackCalled): void {
            $terminatingCallbackCalled = true;
        });

        $config['workbench'] = [
            'sync' => [
                [
                    'from' => 'src/testbench/workbench/storage',
                    'to' => 'public/testbench-storage',
                ],
            ],
        ];

        $testbenchCacheFile = $this->app->basePath(join_paths('bootstrap', 'cache', 'testbench.yaml'));
        $symlinkPath = $this->app->basePath(join_paths('public', 'testbench-storage'));

        $this->deletePath($testbenchCacheFile);
        $this->deletePath($symlinkPath);

        $this->assertTrue(Bootstrapper::ownsRuntimePath($this->app->basePath()));

        $this->artisan('package:sync-skeleton')
            ->expectsOutputToContain('The serve command creates the configured links while it runs.')
            ->assertFailed();

        $this->assertFileDoesNotExist($testbenchCacheFile);
        $this->assertFalse(is_link($symlinkPath));

        TerminatingConsole::handle();

        $this->assertTrue($terminatingCallbackCalled);
    }

    #[Test]
    public function itRejectsSyncingARuntimeCopyBorrowedFromTheParentProcess(): void
    {
        $testbenchCacheFile = $this->app->basePath(join_paths('bootstrap', 'cache', 'testbench.yaml'));
        $this->deletePath($testbenchCacheFile);

        $this->asRemoteChild(borrowsRuntimeCopy: true, callback: function (): void {
            $this->artisan('package:sync-skeleton')
                ->expectsOutputToContain('The serve command creates the configured links while it runs.')
                ->assertFailed();
        });

        $this->assertFileDoesNotExist($testbenchCacheFile);
    }

    #[Test]
    public function itSyncsAPersistentSkeletonSharedByTheParentProcess(): void
    {
        $testbenchCacheFile = $this->app->basePath(join_paths('bootstrap', 'cache', 'testbench.yaml'));
        $this->deletePath($testbenchCacheFile);

        $this->asRemoteChild(borrowsRuntimeCopy: false, callback: function (): void {
            $this->artisan('package:sync-skeleton')->assertOk();
        });

        $this->assertFileExists($testbenchCacheFile);
    }

    #[Test]
    #[TestWith([['command' => 'list']])]
    #[TestWith([['command' => 'help', 'command_name' => 'package:sync-skeleton']])]
    public function itPreservesTerminatingCallbacksForReadOnlyConsoleActions(array $input): void
    {
        $terminatingCallbackCalled = false;

        TerminatingConsole::before(function () use (&$terminatingCallbackCalled): void {
            $terminatingCallbackCalled = true;
        });

        $application = new SymfonyApplication;
        $application->setAutoExit(false);
        $application->addCommand(new SyncSkeletonCommand);

        (new ApplicationTester($application))->run($input);
        TerminatingConsole::handle();

        $this->assertTrue($terminatingCallbackCalled);
    }

    #[Test]
    #[Depends('itCanSyncTheSkeletonFiles')]
    public function itDoesNotLeakMutatedWorkbenchConfigAcrossTests(): void
    {
        $config = $this->app->make(ConfigContract::class);

        $this->assertTrue($config->getWorkbenchAttributes()['discovers']['web']);
        $this->assertTrue($config->getWorkbenchAttributes()['discovers']['api']);
        $this->assertSame([], $config->getWorkbenchAttributes()['sync']);
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
     * Run the callback as a remote child sharing its parent's base path.
     *
     * The parent reports whether that path is its disposable runtime copy.
     *
     * @param Closure(): void $callback
     */
    private function asRemoteChild(bool $borrowsRuntimeCopy, Closure $callback): void
    {
        $runtimePath = new ReflectionProperty(Bootstrapper::class, 'runtimePath');
        $ownedRuntimePath = $runtimePath->getValue();
        $values = [
            'TESTBENCH_PACKAGE_REMOTE' => '(true)',
            'TESTBENCH_BASE_PATH' => $this->app->basePath(),
            'TESTBENCH_RUNTIME_COPY' => $borrowsRuntimeCopy ? '(true)' : null,
        ];
        $originals = [];

        foreach ($values as $key => $value) {
            $originals[$key] = $_SERVER[$key] ?? null;
            $_SERVER[$key] = $value;
        }

        // This process no longer owns the copy; it only shares its parent's base path.
        $runtimePath->setValue(null, null);

        try {
            $callback();
        } finally {
            $runtimePath->setValue(null, $ownedRuntimePath);

            foreach ($originals as $key => $original) {
                if ($original === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $original;
                }
            }
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
