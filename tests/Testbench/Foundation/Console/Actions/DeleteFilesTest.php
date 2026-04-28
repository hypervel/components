<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Console\Actions\DeleteFiles;
use Hypervel\Tests\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

class DeleteFilesTest extends TestCase
{
    #[Test]
    public function itCanDeleteFiles()
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);

        $filesystem->shouldReceive('exists')->once()->with('a')->andReturnTrue()
            ->shouldReceive('delete')->once()->with('a')->andReturnTrue()
            ->shouldReceive('exists')->once()->with('b')->andReturnFalse()
            ->shouldReceive('delete')->never()->with('b')
            ->shouldReceive('exists')->once()->with('c/d')->andReturnTrue()
            ->shouldReceive('delete')->once()->with('c/d')->andReturnTrue();

        $components->shouldReceive('task')->once()->with('File [a] has been deleted')->andReturnNull()
            ->shouldReceive('twoColumnDetail')->once()->with('File [b] doesn\'t exists', '<fg=yellow;options=bold>SKIPPED</>')->andReturnNull()
            ->shouldReceive('task')->once()->with('File [c/d] has been deleted')->andReturnNull();

        (new DeleteFiles(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a', 'b', 'c/d']);
    }
}
