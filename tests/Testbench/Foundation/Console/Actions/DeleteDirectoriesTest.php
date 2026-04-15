<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Console\Actions\DeleteDirectories;
use Hypervel\Tests\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

class DeleteDirectoriesTest extends TestCase
{
    #[Test]
    public function itCanDeleteDirectories()
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
            ->shouldReceive('twoColumnDetail')->once()->with('Directory [b] doesn\'t exists', '<fg=yellow;options=bold>SKIPPED</>')->andReturnNull()
            ->shouldReceive('task')->once()->with('Directory [c/d] has been deleted')->andReturnNull();

        (new DeleteDirectories(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a', 'b', 'c/d']);
    }
}
