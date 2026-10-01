<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Console\Command;
use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Testbench\Foundation\Console\Actions\RunCommand;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class RunCommandTest extends TestCase
{
    #[Test]
    public function itCanRunCommand(): void
    {
        $console = m::mock(Command::class);
        $components = m::mock(ComponentsFactory::class);

        $console->shouldReceive('call')->once()->with('config:clear', ['--force' => true])->andReturn(Command::SUCCESS);
        $components->shouldReceive('task')->never();

        (new RunCommand(
            console: $console,
            components: $components,
        ))->handle('config:clear', ['--force' => true]);
    }

    #[Test]
    public function itCanPretendToRunCommand(): void
    {
        $console = m::mock(Command::class);
        $components = m::mock(ComponentsFactory::class);

        $console->shouldReceive('call')->never();
        $components->shouldReceive('task')->once()->with('Command [config:clear] executed')->andReturnNull();

        (new RunCommand(
            console: $console,
            components: $components,
            pretending: true,
        ))->handle('config:clear');
    }

    #[Test]
    public function itFailsWhenTheCommandFails(): void
    {
        $console = m::mock(Command::class);

        $console->shouldReceive('call')->once()->with('config:clear', [])->andReturn(Command::FAILURE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to run command [config:clear].');

        (new RunCommand($console))->handle('config:clear');
    }
}
