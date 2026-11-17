<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console\Actions;

use Hypervel\Console\Command;
use Hypervel\Testbench\Foundation\Console\Actions\RunCommand;
use Hypervel\Tests\Console\Fixtures\ComponentsFactory;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class RunCommandTest extends TestCase
{
    use VerifiesDoubles;

    #[Test]
    public function itCanRunCommand(): void
    {
        $console = Double::for(Command::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $console->expects('call')->with('config:clear', ['--force' => true])->returns(Command::SUCCESS);
        $components->expects('task')->never();

        (new RunCommand(
            console: $console,
            components: $components,
        ))->handle('config:clear', ['--force' => true]);
    }

    #[Test]
    public function itCanPretendToRunCommand(): void
    {
        $console = Double::for(Command::class)->strict();
        $components = Double::for(ComponentsFactory::class)->strict();

        $console->expects('call')->never();
        $components->expects('task')->with('Command [config:clear] would be executed');

        (new RunCommand(
            console: $console,
            components: $components,
            pretending: true,
        ))->handle('config:clear');
    }

    #[Test]
    public function itFailsWhenTheCommandFails(): void
    {
        $console = Double::for(Command::class)->strict();

        $console->expects('call')->with('config:clear', [])->returns(Command::FAILURE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to run command [config:clear].');

        (new RunCommand($console))->handle('config:clear');
    }
}
