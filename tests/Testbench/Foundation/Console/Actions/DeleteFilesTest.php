<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Console\Actions\DeleteFiles;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class DeleteFilesTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('DeleteFilesTest');

        (new Filesystem)->deleteDirectory($this->tempDir);
        (new Filesystem)->makeDirectory($this->tempDir, 0700, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    #[Test]
    public function itCanDeleteFiles(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);

        $filesystem->shouldReceive('isFile')->once()->with('a')->andReturnTrue()
            ->shouldReceive('delete')->once()->with('a')->andReturnTrue()
            ->shouldReceive('isFile')->once()->with('b')->andReturnFalse()
            ->shouldReceive('isDirectory')->once()->with('b')->andReturnFalse()
            ->shouldReceive('delete')->never()->with('b')
            ->shouldReceive('isFile')->once()->with('c/d')->andReturnTrue()
            ->shouldReceive('delete')->once()->with('c/d')->andReturnTrue();

        $components->shouldReceive('task')->once()->with('File [a] has been deleted')->andReturnNull()
            ->shouldReceive('twoColumnDetail')->once()->with('File [b] doesn\'t exist', '<fg=yellow;options=bold>SKIPPED</>')->andReturnNull()
            ->shouldReceive('task')->once()->with('File [c/d] has been deleted')->andReturnNull();

        (new DeleteFiles(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a', 'b', 'c/d']);
    }

    #[Test]
    public function itAttemptsEveryFileBeforeReportingDeletionFailures(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);
        $filesystem->expects('isFile')->with('a')->andReturnTrue();
        $filesystem->expects('delete')->with('a')->andReturnFalse();
        $filesystem->expects('isFile')->with('b')->andReturnTrue();
        $filesystem->expects('delete')->with('b')->andReturnTrue();
        $components->shouldNotReceive('task')->with('File [a] has been deleted');
        $components->expects('task')->with('File [b] has been deleted');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to delete files [a].');

        (new DeleteFiles(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a', 'b']);
    }

    #[Test]
    public function itSkipsRealDirectoriesAndContinuesDeletingFiles(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);
        $filesystem->expects('isFile')->with('directory')->andReturnFalse();
        $filesystem->expects('isDirectory')->with('directory')->andReturnTrue();
        $filesystem->shouldNotReceive('delete')->with('directory');
        $filesystem->expects('isFile')->with('file')->andReturnTrue();
        $filesystem->expects('delete')->with('file')->andReturnTrue();
        $components->expects('twoColumnDetail')
            ->with('[directory] is a directory', '<fg=yellow;options=bold>SKIPPED</>');
        $components->expects('task')->with('File [file] has been deleted');

        (new DeleteFiles(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['directory', 'file']);
    }

    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function itDeletesDirectorySymlinksWithoutTouchingTheirTargets(): void
    {
        $filesystem = new Filesystem;
        $target = "{$this->tempDir}/target";
        $link = "{$this->tempDir}/link";
        $filesystem->makeDirectory($target);
        $filesystem->put("{$target}/file.txt", 'content');
        symlink($target, $link);

        (new DeleteFiles($filesystem))->handle([$link]);

        $this->assertFalse(is_link($link));
        $this->assertFileExists("{$target}/file.txt");
    }
}
