<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Wayfinder\WayfinderServiceProvider;

use function Hypervel\Filesystem\join_paths;

class PruneStaleFilesTest extends TestCase
{
    private string $tempPath;

    private Filesystem $files;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [WayfinderServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->tempPath = join_paths(sys_get_temp_dir(), 'wayfinder-prune-' . uniqid());

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

    public function testGeneratedFilesExistAfterGenerate()
    {
        $this->generate();

        $routes = join_paths($this->tempPath, 'routes');

        $this->assertDirectoryExists($routes);
        $this->assertNotEmpty($this->files->allFiles($routes));
        $this->assertFileExists(join_paths($routes, 'prune', 'test', 'index.ts'));
    }

    public function testStaleFilesAreRemovedWhileCurrentFilesAreKept()
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

    public function testEmptyDirectoriesArePruned()
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

    public function testRuntimeIndexIsWrittenBeforeActionsAndRoutes()
    {
        $this->artisan('wayfinder:generate', ['--path' => $this->tempPath])->assertSuccessful();

        $this->assertFileExists(join_paths($this->tempPath, 'wayfinder', 'index.ts'));
    }

    public function testRuntimeIndexIsSkippedWhenContentsMatch()
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

    public function testUnchangedGeneratedFilesAreNotRewritten()
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
}
