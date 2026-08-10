<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Console\Actions\DeleteDirectories;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class DeleteDirectoriesTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('DeleteDirectoriesTest');

        (new Filesystem)->deleteDirectory($this->tempDir);
        (new Filesystem)->makeDirectory($this->tempDir, 0700, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    #[Test]
    public function itCanDeleteDirectories(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);

        $filesystem->shouldReceive('isDirectory')->once()->with('a')->andReturnTrue()
            ->shouldReceive('deleteDirectory')->once()->with('a')->andReturnTrue()
            ->shouldReceive('isDirectory')->once()->with('b')->andReturnFalse()
            ->shouldReceive('deleteDirectory')->never()->with('b')
            ->shouldReceive('isDirectory')->once()->with('c/d')->andReturnTrue()
            ->shouldReceive('deleteDirectory')->once()->with('c/d')->andReturnTrue();

        $components->shouldReceive('task')->once()->with('Directory [a] has been deleted')->andReturnNull()
            ->shouldReceive('twoColumnDetail')->once()->with('Directory [b] doesn\'t exist', '<fg=yellow;options=bold>SKIPPED</>')->andReturnNull()
            ->shouldReceive('task')->once()->with('Directory [c/d] has been deleted')->andReturnNull();

        (new DeleteDirectories(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a', 'b', 'c/d']);
    }

    #[Test]
    public function itAttemptsEveryDirectoryBeforeReportingDeletionFailures(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);
        $filesystem->expects('isDirectory')->with('a')->andReturnTrue();
        $filesystem->expects('deleteDirectory')->with('a')->andReturnFalse();
        $filesystem->expects('isDirectory')->with('b')->andReturnTrue();
        $filesystem->expects('deleteDirectory')->with('b')->andReturnTrue();
        $components->shouldNotReceive('task')->with('Directory [a] has been deleted');
        $components->expects('task')->with('Directory [b] has been deleted');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to delete directories [a].');

        (new DeleteDirectories(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a', 'b']);
    }

    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function itDeletesLiveAndBrokenLinksWithoutTouchingTheirTargets(): void
    {
        $filesystem = new Filesystem;
        $target = "{$this->tempDir}/target";
        $liveLink = "{$this->tempDir}/live-link";
        $brokenLink = "{$this->tempDir}/broken-link";
        $filesystem->makeDirectory($target);
        $filesystem->put("{$target}/file.txt", 'content');
        symlink($target, $liveLink);
        symlink("{$this->tempDir}/missing-target", $brokenLink);

        (new DeleteDirectories($filesystem))->handle([$liveLink, $brokenLink]);

        $this->assertFalse(is_link($liveLink));
        $this->assertFalse(is_link($brokenLink));
        $this->assertFileExists("{$target}/file.txt");
    }
}
