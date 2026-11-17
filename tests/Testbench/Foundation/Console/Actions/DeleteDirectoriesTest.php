<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Console\Actions\DeleteDirectories;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Console\Fixtures\ComponentsFactory;
use Hypervel\Tests\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class DeleteDirectoriesTest extends TestCase
{
    use VerifiesDoubles;

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
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $filesystem->expects('isDirectory')->with('a')->returns(true);
        $filesystem->expects('deleteDirectory')->with('a')->returns(true);
        $filesystem->expects('isDirectory')->with('b')->returns(false);
        $filesystem->expects('deleteDirectory')->never()->with('b');
        $filesystem->expects('isDirectory')->with('c/d')->returns(true);
        $filesystem->expects('deleteDirectory')->with('c/d')->returns(true);

        $components->expects('task')->with('Directory [a] has been deleted');
        $components->expects('twoColumnDetail')->with('Directory [b] doesn\'t exist', '<fg=yellow;options=bold>SKIPPED</>');
        $components->expects('task')->with('Directory [c/d] has been deleted');

        (new DeleteDirectories(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a', 'b', 'c/d']);
    }

    #[Test]
    public function itCanPretendToDeleteDirectories(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $filesystem->expects('isDirectory')->with('a')->returns(true);
        $filesystem->expects('isDirectory')->with('b')->returns(false);
        $filesystem->expects('deleteDirectory')->never();

        $components->expects('task')->with('Directory [a] would be deleted');
        $components->expects('twoColumnDetail')->with('Directory [b] doesn\'t exist', '<fg=yellow;options=bold>SKIPPED</>');

        (new DeleteDirectories(
            filesystem: $filesystem,
            components: $components,
            pretending: true,
        ))->handle(['a', 'b']);
    }

    #[Test]
    public function itAttemptsEveryDirectoryBeforeReportingDeletionFailures(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();
        $filesystem->expects('isDirectory')->with('a')->returns(true);
        $filesystem->expects('deleteDirectory')->with('a')->returns(false);
        $filesystem->expects('isDirectory')->with('b')->returns(true);
        $filesystem->expects('deleteDirectory')->with('b')->returns(true);
        $components->expects('task')->never()->with('Directory [a] has been deleted');
        $components->expects('task')->with('Directory [b] has been deleted');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to delete directories [a].');

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
