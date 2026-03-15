<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\Command;
use Hypervel\Console\CommandMutex;
use Hypervel\Contracts\Console\Isolatable;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class CommandMutexTest extends TestCase
{
    protected Command $command;

    protected CommandMutex|m\MockInterface $commandMutex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new class extends Command implements Isolatable {
            public int $ran = 0;

            public function __invoke()
            {
                ++$this->ran;
            }
        };

        $this->commandMutex = m::mock(CommandMutex::class);

        $this->app->instance(CommandMutex::class, $this->commandMutex);
    }

    public function testCanRunIsolatedCommandIfNotBlocked()
    {
        $this->commandMutex->shouldReceive('create')
            ->andReturn(true)
            ->once();
        $this->commandMutex->shouldReceive('forget')
            ->andReturn(true)
            ->once();

        $this->runCommand();

        $this->assertEquals(1, $this->command->ran);
    }

    public function testCannotRunIsolatedCommandIfBlocked()
    {
        $this->commandMutex->shouldReceive('create')
            ->andReturn(false)
            ->once();

        $this->runCommand();

        $this->assertEquals(0, $this->command->ran);
    }

    public function testCanRunCommandAgainAfterOtherCommandFinished()
    {
        $this->commandMutex->shouldReceive('create')
            ->andReturn(true)
            ->twice();
        $this->commandMutex->shouldReceive('forget')
            ->andReturn(true)
            ->twice();

        $this->runCommand();
        $this->runCommand();

        $this->assertEquals(2, $this->command->ran);
    }

    public function testCanRunCommandAgainNonAutomated()
    {
        $this->commandMutex->shouldNotHaveBeenCalled();

        $this->runCommand(false);

        $this->assertEquals(1, $this->command->ran);
    }

    protected function runCommand(bool $withIsolated = true)
    {
        $input = new ArrayInput(['--isolated' => $withIsolated]);
        $output = new NullOutput();
        $this->command->run($input, $output);
    }
}
