<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\ViewCacheCommand;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Hypervel\View\Factory;
use Hypervel\View\ViewFinderInterface;
use Mockery as m;

class ViewCacheCommandTest extends TestCase
{
    protected Filesystem $files;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->tempDir = ParallelTesting::tempDir('ViewCacheCommandTest');
        $this->files->deleteDirectory($this->tempDir);
        $this->files->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testPathsCollapseNestedRootsWithoutConflatingPrefixSiblings(): void
    {
        $views = $this->tempDir . '/views';
        $nested = $views . '/nested';
        $viewsAdmin = $this->tempDir . '/views-admin';
        $missing = $this->tempDir . '/missing';
        $this->files->ensureDirectoryExists($nested);
        $this->files->ensureDirectoryExists($viewsAdmin);

        $command = $this->commandWithPaths([
            $views,
            $views . DIRECTORY_SEPARATOR,
            $nested,
            $viewsAdmin,
            $missing . DIRECTORY_SEPARATOR,
            $missing,
        ]);

        $this->assertSame([$views, $viewsAdmin, $missing], $command->viewPaths());
    }

    public function testPathsRetainFilesystemRoot(): void
    {
        $views = $this->tempDir . '/views';
        $this->files->ensureDirectoryExists($views);
        $command = $this->commandWithPaths([DIRECTORY_SEPARATOR, $views]);

        $this->assertSame([DIRECTORY_SEPARATOR], $command->viewPaths());
    }

    protected function commandWithPaths(array $paths): TestViewCacheCommand
    {
        $finder = m::mock(ViewFinderInterface::class);
        $finder->shouldReceive('getPaths')->once()->andReturn($paths);
        $finder->shouldReceive('getHints')->once()->andReturn([]);

        $factory = m::mock(Factory::class);
        $factory->shouldReceive('getFinder')->once()->andReturn($finder);

        $application = m::mock(Application::class);
        $application->shouldReceive('make')->once()->with('view')->andReturn($factory);

        $command = new TestViewCacheCommand;
        $command->setHypervel($application);

        return $command;
    }
}

class TestViewCacheCommand extends ViewCacheCommand
{
    public function viewPaths(): array
    {
        return $this->paths()->all();
    }
}
