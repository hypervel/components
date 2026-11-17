<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Console\Actions\GeneratesFile;
use Hypervel\Tests\Console\Fixtures\ComponentsFactory;
use Hypervel\Tests\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function Hypervel\Filesystem\join_paths;

class GeneratesFileTest extends TestCase
{
    use VerifiesDoubles;

    #[Test]
    public function itCanGeneratesFile(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $filesystem->expects('exists')->with('a')->returns(true);
        $filesystem->expects('exists')->with('b')->returns(false);
        $filesystem->expects('copy')->with('a', 'b')->returns(true);
        $filesystem->expects('exists')->with(join_paths('.', '.gitkeep'))->returns(true);
        $filesystem->expects('delete')->with(join_paths('.', '.gitkeep'));

        $components->expects('task')->with('File [b] generated');

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $components,
        ))->handle('a', 'b');
    }

    #[Test]
    public function itCannotGeneratesFileWhenFileAlreadyGenerated(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $filesystem->expects('exists')->with('a')->returns(true);
        $filesystem->expects('exists')->with('b')->returns(true);
        $filesystem->expects('copy')->never()->with('a', 'b');

        $components->expects('twoColumnDetail')->with('File [b] already exists', '<fg=yellow;options=bold>SKIPPED</>');

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $components,
            force: false,
        ))->handle('a', 'b');
    }

    #[Test]
    public function itCanGeneratesFileWhenFileAlreadyGeneratedUsingForce(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $filesystem->expects('exists')->with('a')->returns(true);
        $filesystem->expects('exists')->never()->with('b');
        $filesystem->expects('copy')->with('a', 'b')->returns(true);
        $filesystem->expects('exists')->with(join_paths('.', '.gitkeep'))->returns(true);
        $filesystem->expects('delete')->with(join_paths('.', '.gitkeep'));

        $components->expects('task')->with('File [b] generated');

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $components,
            force: true,
        ))->handle('a', 'b');
    }

    #[Test]
    public function itCanPretendToGenerateFile(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $filesystem->expects('exists')->with('a')->returns(true);
        $filesystem->expects('exists')->with('b')->returns(false);
        $filesystem->expects('copy')->never();
        $filesystem->expects('delete')->never();

        $components->expects('task')->with('File [b] would be generated');

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $components,
            pretending: true,
        ))->handle('a', 'b');
    }

    #[Test]
    public function itCannotGeneratesFileWhenSourceFileDoesNotExists(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $filesystem->expects('exists')->with('a')->returns(false);
        $filesystem->expects('exists')->never()->with('b');
        $filesystem->expects('copy')->never()->with('a', 'b');

        $components->expects('twoColumnDetail')->with('Source file [a] doesn\'t exist', '<fg=yellow;options=bold>SKIPPED</>');

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $components,
            force: true,
        ))->handle('a', 'b');
    }

    #[Test]
    public function itDoesNotReportSuccessOrDeleteThePlaceholderWhenCopyingFails(): void
    {
        $filesystem = Double::for(Filesystem::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();
        $filesystem->expects('exists')->with('a')->returns(true);
        $filesystem->expects('exists')->with('b')->returns(false);
        $filesystem->expects('copy')->with('a', 'b')->returns(false);
        $filesystem->expects('delete')->never();
        $components->expects('task')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to generate file [b].');

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $components,
        ))->handle('a', 'b');
    }
}
