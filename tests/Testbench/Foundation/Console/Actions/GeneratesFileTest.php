<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Console\Actions\GeneratesFile;
use Hypervel\Tests\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function Hypervel\Filesystem\join_paths;

class GeneratesFileTest extends TestCase
{
    #[Test]
    public function itCanGeneratesFile(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);

        $filesystem->shouldReceive('exists')->once()->with('a')->andReturnTrue()
            ->shouldReceive('exists')->once()->with('b')->andReturnFalse()
            ->shouldReceive('copy')->once()->with('a', 'b')->andReturnTrue()
            ->shouldReceive('exists')->once()->with(join_paths('.', '.gitkeep'))->andReturnTrue()
            ->shouldReceive('delete')->once()->with(join_paths('.', '.gitkeep'));

        $components->shouldReceive('task')->once()->with('File [b] generated');

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $components,
        ))->handle('a', 'b');
    }

    #[Test]
    public function itCannotGeneratesFileWhenFileAlreadyGenerated(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);

        $filesystem->shouldReceive('exists')->once()->with('a')->andReturnTrue()
            ->shouldReceive('exists')->once()->with('b')->andReturnTrue()
            ->shouldReceive('copy')->never()->with('a', 'b');

        $components->shouldReceive('twoColumnDetail')->once()->with('File [b] already exists', '<fg=yellow;options=bold>SKIPPED</>');

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $components,
            force: false,
        ))->handle('a', 'b');
    }

    #[Test]
    public function itCanGeneratesFileWhenFileAlreadyGeneratedUsingForce(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);

        $filesystem->shouldReceive('exists')->once()->with('a')->andReturnTrue()
            ->shouldReceive('exists')->never()->with('b')
            ->shouldReceive('copy')->once()->with('a', 'b')->andReturnTrue()
            ->shouldReceive('exists')->once()->with(join_paths('.', '.gitkeep'))->andReturnTrue()
            ->shouldReceive('delete')->once()->with(join_paths('.', '.gitkeep'));

        $components->shouldReceive('task')->once()->with('File [b] generated');

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $components,
            force: true,
        ))->handle('a', 'b');
    }

    #[Test]
    public function itCannotGeneratesFileWhenSourceFileDoesNotExists(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);

        $filesystem->shouldReceive('exists')->once()->with('a')->andReturnFalse()
            ->shouldReceive('exists')->never()->with('b')
            ->shouldReceive('copy')->never()->with('a', 'b');

        $components->shouldReceive('twoColumnDetail')->once()->with('Source file [a] doesn\'t exist', '<fg=yellow;options=bold>SKIPPED</>');

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $components,
            force: true,
        ))->handle('a', 'b');
    }

    #[Test]
    public function itDoesNotReportSuccessOrDeleteThePlaceholderWhenCopyingFails(): void
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);
        $filesystem->expects('exists')->with('a')->andReturnTrue();
        $filesystem->expects('exists')->with('b')->andReturnFalse();
        $filesystem->expects('copy')->with('a', 'b')->andReturnFalse();
        $filesystem->shouldNotReceive('delete');
        $components->shouldNotReceive('task');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to generate file [b].');

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $components,
        ))->handle('a', 'b');
    }
}
