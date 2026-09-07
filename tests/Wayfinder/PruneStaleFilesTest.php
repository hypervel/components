<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Wayfinder\WayfinderServiceProvider;
use RuntimeException;

use function Hypervel\Filesystem\join_paths;

class PruneStaleFilesTest extends TestCase
{
    private string $tempPath;

    private RecordingWayfinderFilesystem $files;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [WayfinderServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new RecordingWayfinderFilesystem;
        $this->tempPath = ParallelTesting::tempDir('wayfinder-prune');
        $this->files->deleteDirectory($this->tempPath);
        $this->app->instance(Filesystem::class, $this->files);

        Route::get('/prune-test/alpha', fn () => '')->name('prune.test.alpha');
        Route::get('/prune-test/beta', fn () => '')->name('prune.test.beta');
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    private function generate(): void
    {
        $this->artisan('wayfinder:generate', [
            '--path' => $this->tempPath,
            '--skip-actions' => true,
        ])->assertSuccessful();
    }

    public function testGeneratedFilesExistAfterGenerate(): void
    {
        $this->generate();

        $routes = join_paths($this->tempPath, 'routes');

        $this->assertDirectoryExists($routes);
        $this->assertNotEmpty($this->files->allFiles($routes));
        $this->assertFileExists(join_paths($routes, 'prune', 'test', 'index.ts'));
    }

    public function testStaleFilesAreRemovedWhileCurrentFilesAreKept(): void
    {
        $this->generate();

        $current = collect($this->files->allFiles(join_paths($this->tempPath, 'routes')))
            ->map(fn ($file) => $file->getPathname());

        $this->assertNotEmpty($current);

        $stale = join_paths($this->tempPath, 'routes', 'definitely-not-a-real-route.ts');
        $this->files->put($stale, '// stale');
        $this->assertFileExists($stale);

        $this->generate();

        $this->assertFileDoesNotExist($stale);
        $current->each(fn ($path) => $this->assertFileExists($path));
    }

    public function testStaleDeleteFailureIsReported(): void
    {
        $this->generate();

        $stale = join_paths($this->tempPath, 'routes', 'stale.ts');
        $this->files->put($stale, '// stale');
        $this->files->failDeletes = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to delete stale generated file [{$stale}].");

        $this->generate();
    }

    public function testConcurrentlyDisappearedStaleFileIsAccepted(): void
    {
        $this->generate();

        $stale = join_paths($this->tempPath, 'routes', 'stale.ts');
        $this->files->put($stale, '// stale');
        $this->files->failDeletes = true;
        $this->files->removeBeforeDeleteFailure = true;

        $this->generate();

        $this->assertFileDoesNotExist($stale);
    }

    public function testEmptyDirectoriesArePruned(): void
    {
        $this->generate();

        $sibling = join_paths($this->tempPath, 'routes', 'prune', 'test', 'index.ts');
        $this->assertFileExists($sibling);

        $orphanDir = join_paths($this->tempPath, 'routes', 'orphan-dir');
        $this->files->ensureDirectoryExists($orphanDir);
        $this->files->put(join_paths($orphanDir, 'thing.ts'), '// stale');

        $this->generate();

        $this->assertDirectoryDoesNotExist($orphanDir);
        $this->assertFileExists($sibling);
    }

    public function testRuntimeIndexIsWrittenBeforeActionsAndRoutes(): void
    {
        $this->artisan('wayfinder:generate', ['--path' => $this->tempPath])->assertSuccessful();

        $this->assertFileExists(join_paths($this->tempPath, 'wayfinder', 'index.ts'));
    }

    public function testRuntimeIndexIsSkippedWhenContentsMatch(): void
    {
        $this->artisan('wayfinder:generate', ['--path' => $this->tempPath])->assertSuccessful();

        $destination = join_paths($this->tempPath, 'wayfinder', 'index.ts');
        $backdate = time() - 60;
        touch($destination, $backdate);
        clearstatcache(true, $destination);

        $this->artisan('wayfinder:generate', ['--path' => $this->tempPath])->assertSuccessful();

        clearstatcache(true, $destination);
        $this->assertSame($backdate, filemtime($destination));
    }

    public function testUnchangedGeneratedFilesAreNotRewritten(): void
    {
        $this->generate();

        $target = join_paths($this->tempPath, 'routes', 'prune', 'test', 'index.ts');
        $this->assertFileExists($target);

        $backdate = time() - 60;
        touch($target, $backdate);
        clearstatcache(true, $target);

        $this->generate();

        clearstatcache(true, $target);
        $this->assertSame($backdate, filemtime($target));
    }

    public function testChangedGeneratedFilesUseAtomicReplacement(): void
    {
        $this->generate();

        $this->files->replacements = [];
        Route::get('/prune-test/gamma', fn () => '')->name('prune.test.gamma');

        $this->generate();

        $target = join_paths($this->tempPath, 'routes', 'prune', 'test', 'index.ts');

        $this->assertContains($target, $this->files->replacements);
    }

    public function testGeneratedFilesUseOrdinaryFilePermissions(): void
    {
        $this->generate();

        $target = join_paths($this->tempPath, 'routes', 'prune', 'test', 'index.ts');
        $currentUmask = umask();
        umask($currentUmask);

        $this->assertSame(
            sprintf('%04o', 0666 & ~$currentUmask),
            $this->files->chmod($target),
        );
    }
}

class RecordingWayfinderFilesystem extends Filesystem
{
    public array $replacements = [];

    public bool $failDeletes = false;

    public bool $removeBeforeDeleteFailure = false;

    public function replace(string $path, string $content, ?int $mode = null): void
    {
        $this->replacements[] = $path;

        parent::replace($path, $content, $mode);
    }

    public function delete(array|string $paths): bool
    {
        if (! $this->failDeletes) {
            return parent::delete($paths);
        }

        if ($this->removeBeforeDeleteFailure) {
            parent::delete($paths);
        }

        return false;
    }
}
