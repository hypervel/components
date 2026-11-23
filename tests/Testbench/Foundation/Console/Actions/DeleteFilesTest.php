<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Console\Actions\DeleteFiles;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Console\Fixtures\ComponentsFactory;
use Hypervel\Tests\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class DeleteFilesTest extends TestCase
{
    use VerifiesDoubles;

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
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $filesystem->expects('isFile')->with('a')->returns(true);
        $filesystem->expects('delete')->with('a')->returns(true);
        $filesystem->expects('isFile')->with('b')->returns(false);
        $filesystem->expects('isDirectory')->with('b')->returns(false);
        $filesystem->expects('delete')->never()->with('b');
        $filesystem->expects('isFile')->with('c/d')->returns(true);
        $filesystem->expects('delete')->with('c/d')->returns(true);

        $components->expects('task')->with('File [a] has been deleted');
        $components->expects('twoColumnDetail')->with('File [b] doesn\'t exist', '<fg=yellow;options=bold>SKIPPED</>');
        $components->expects('task')->with('File [c/d] has been deleted');

        (new DeleteFiles(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a', 'b', 'c/d']);
    }

    #[Test]
    public function itCanPretendToDeleteFiles(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $filesystem->expects('isFile')->with('a')->returns(true);
        $filesystem->expects('isFile')->with('b')->returns(false);
        $filesystem->expects('isDirectory')->with('b')->returns(false);
        $filesystem->expects('delete')->never();

        $components->expects('task')->with('File [a] would be deleted');
        $components->expects('twoColumnDetail')->with('File [b] doesn\'t exist', '<fg=yellow;options=bold>SKIPPED</>');

        (new DeleteFiles(
            filesystem: $filesystem,
            components: $components,
            pretending: true,
        ))->handle(['a', 'b']);
    }

    #[Test]
    public function itAttemptsEveryFileBeforeReportingDeletionFailures(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();
        $filesystem->expects('isFile')->with('a')->returns(true);
        $filesystem->expects('delete')->with('a')->returns(false);
        $filesystem->expects('isFile')->with('b')->returns(true);
        $filesystem->expects('delete')->with('b')->returns(true);
        $components->expects('task')->never()->with('File [a] has been deleted');
        $components->expects('task')->with('File [b] has been deleted');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to delete files [a].');

        (new DeleteFiles(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a', 'b']);
    }

    #[Test]
    public function itSkipsRealDirectoriesAndContinuesDeletingFiles(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();
        $filesystem->expects('isFile')->with('directory')->returns(false);
        $filesystem->expects('isDirectory')->with('directory')->returns(true);
        $filesystem->expects('delete')->never()->with('directory');
        $filesystem->expects('isFile')->with('file')->returns(true);
        $filesystem->expects('delete')->with('file')->returns(true);
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
