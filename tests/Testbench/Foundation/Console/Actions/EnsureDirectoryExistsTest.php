<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Foundation\Console\Actions\EnsureDirectoryExists;
use Hypervel\Tests\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Filesystem\join_paths;

class EnsureDirectoryExistsTest extends TestCase
{
    #[Test]
    public function itCanEnsureDirectoryExists()
    {
        $filesystem = m::mock(Filesystem::class);
        $components = m::mock(ComponentsFactory::class);

        $filesystem->shouldReceive('isDirectory')->once()->with('a')->andReturnFalse()
            ->shouldReceive('ensureDirectoryExists')->once()->with('a', 493, true)->andReturnNull()
            ->shouldReceive('copy')->once()->with(m::type('string'), join_paths('a', '.gitkeep'))->andReturnTrue()
            ->shouldReceive('isDirectory')->once()->with('b')->andReturnTrue()
            ->shouldReceive('ensureDirectoryExists')->never()->with('b', 493, true)
            ->shouldReceive('copy')->never()->with(m::type('string'), join_paths('b', '.gitkeep'))
            ->shouldReceive('isDirectory')->once()->with(join_paths('c', 'd'))->andReturnFalse()
            ->shouldReceive('ensureDirectoryExists')->once()->with(join_paths('c', 'd'), 493, true)->andReturnNull()
            ->shouldReceive('copy')->once()->with(m::type('string'), join_paths('c', 'd', '.gitkeep'))->andReturnTrue();

        $components->shouldReceive('task')->once()->with('Prepare [a] directory')->andReturnNull()
            ->shouldReceive('twoColumnDetail')->once()->with('Directory [b] already exists', '<fg=yellow;options=bold>SKIPPED</>')->andReturnNull()
            ->shouldReceive('task')->once()->with(sprintf('Prepare [%s] directory', join_paths('c', 'd')))->andReturnNull();

        (new EnsureDirectoryExists(
            filesystem: $filesystem,
            components: $components,
        ))->handle(['a', 'b', join_paths('c', 'd')]);
    }
}
