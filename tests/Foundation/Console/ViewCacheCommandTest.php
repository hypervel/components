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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class ViewCacheCommandTest extends TestCase
{
    use VerifiesDoubles;

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
        $package = $this->tempDir . '/package';
        $this->files->ensureDirectoryExists($nested);
        $this->files->ensureDirectoryExists($viewsAdmin);
        $this->files->ensureDirectoryExists($package);

        $command = $this->commandWithPaths([
            $views,
            $views . DIRECTORY_SEPARATOR,
            $nested,
            $viewsAdmin,
            $missing . DIRECTORY_SEPARATOR,
            $missing,
        ], ['package' => [$package]]);

        $this->assertSame([$views, $viewsAdmin, $missing, $package], $command->viewPaths());
    }

    public function testPathsRetainFilesystemRoot(): void
    {
        $views = $this->tempDir . '/views';
        $this->files->ensureDirectoryExists($views);
        $command = $this->commandWithPaths([DIRECTORY_SEPARATOR, $views]);

        $this->assertSame([DIRECTORY_SEPARATOR], $command->viewPaths());
    }

    /**
     * Create a command with the given view roots and namespace hints.
     */
    protected function commandWithPaths(array $paths, array $hints = []): TestViewCacheCommand
    {
        $finder = Double::for(ViewFinderInterface::class)->strict();
        $finder->expects('getPaths')->returns($paths);
        $finder->expects('getHints')->returns($hints);

        $factory = Double::for(Factory::class)->strict();
        $factory->expects('getFinder')->returns($finder);

        $application = Double::for(Application::class, override: true)->strict();
        $application->expects('make')->with('view')->returns($factory);

        $command = new TestViewCacheCommand;
        $command->setHypervel($application->instance());

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
